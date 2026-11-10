<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\SettingConfirmationForm;
use App\Models\NovelSettingConfirmation;
use App\Models\RequirementVersion;
use App\Services\Pipeline\BodyVersionDiff;
use App\Services\Pipeline\ConfirmSettingService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use UnitEnum;

/** 已确认蓝图的来源、完整内容与版本对照，所有批准沿用既有确认契约。 */
class BlueprintVersions extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 归属于小说的权限与导航。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '设定与蓝图';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 2;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '规划';

    /** 中文页面标题。 */
    protected static ?string $title = '蓝图与设定版本';

    /** 已校验的对照版本不可被客户端直接改写。 */
    #[Locked]
    public array $comparison = [];

    /** 限定本书查询。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 对照包含完整前后文，不隐藏旧版本中移除的内容。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('版本边界')->description('确认设定是候选规划来源，不是正式剧情。新增确认版本不会替换既有大纲及章节冻结来源；原始生成结果始终保留。')->schema([]),
            EmbeddedTable::make(),
            Section::make('完整区段对照')->visible(fn (): bool => count($this->comparison) === 2)->schema([
                RepeatableEntry::make('diff')->hiddenLabel()->state(function (): array {
                    if (count($this->comparison) !== 2) {
                        return [];
                    }
                    [$old, $new] = array_map(fn (int $id): NovelSettingConfirmation => NovelSettingConfirmation::where('novel_id', $this->getRecord()->id)->findOrFail($id), $this->comparison);

                    return BodyVersionDiff::compare(self::describe($old), self::describe($new));
                })->schema([TextEntry::make('kind')->label('差异类型')->badge(), TextEntry::make('text')->label('内容')->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state))))]),
            ]),
        ]);
    }

    /** 版本列表展示摘要检查，来源损坏不会被显示为有效批准。 */
    public function table(Table $table): Table
    {
        return $table->query(NovelSettingConfirmation::where('novel_id', $this->getRecord()->id))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('确认记录编号')->sortable(),
            TextColumn::make('source_stage_id')->label('生成来源阶段'),
            TextColumn::make('confirmed_by')->label('确认账号'),
            TextColumn::make('confirmed_at')->label('确认时间')->dateTime(),
            TextColumn::make('integrity')->label('摘要校验')->state(fn (NovelSettingConfirmation $record): string => $record->hasValidHash() ? '有效' : '来源损坏，禁止使用'),
        ])->recordActions([
            Action::make('inspect')->label('查看完整设定')->modalSubmitAction(false)->schema(fn (NovelSettingConfirmation $record): array => [
                TextEntry::make('body')->label('设定与作者决定')->state(self::describe($record))->formatStateUsing(fn (string $state): HtmlString => new HtmlString(nl2br(e($state)))),
                TextEntry::make('plans')->label('引用此确认记录的大纲记录编号')->state(fn (): string => $this->getRecord()->outlinePlans()->where('setting_confirmation_id', $record->id)->orderByDesc('id')->pluck('id')->implode('、') ?: '尚无'),
            ]),
            Action::make('compare')->label('与另一版本对照')->schema([Select::make('other')->label('旧版 / 对照版本')->options(fn (): array => $this->getRecord()->settingConfirmations()->orderByDesc('id')->pluck('id', 'id')->map(fn ($id): string => '确认记录 #'.$id)->all())->required()])
                ->action(function (NovelSettingConfirmation $record, array $data): void {
                    $old = NovelSettingConfirmation::where('novel_id', $this->getRecord()->id)->findOrFail((int) $data['other']);
                    $this->comparison = [$old->id, $record->id];
                }),
        ])->emptyStateHeading('尚无作者确认版本')->emptyStateDescription('先在小说工作台生成设定候选，再审阅修订并确认。');
    }

    /** 批准前展示覆盖缺失及受影响计划；不自动替换任何既有计划。 */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')->label('审阅并保存确认版本')
                ->visible(fn (): bool => $this->getRecord()->generation_mode->value === 'setting_real' && $this->getRecord()->stages()->where('stage', 'setting')->where('status', 'completed')->exists())
                ->fillForm(fn (): array => app(ConfirmSettingService::class)->draft($this->getRecord()))
                ->schema([
                    TextEntry::make('coverage')->label('要求覆盖待办')->state(function (): string {
                        $latest = RequirementVersion::where('novel_id', $this->getRecord()->id)->whereIn('id', RequirementVersion::selectRaw('MAX(id)')->where('novel_id', $this->getRecord()->id)->groupBy('requirement_key'))->get();

                        return $latest->isEmpty() ? '尚未登记覆盖矩阵，不能据此认定无缺失。' : $latest->whereIn('status', ['unresolved', 'conflict'])->map(fn ($r): string => '#'.$r->id.' '.$r->description)->implode("\n");
                    }),
                    TextEntry::make('impact')->label('已有大纲（不会自动替换）')->state(fn (): string => $this->getRecord()->outlinePlans()->pluck('id')->implode('、') ?: '尚无'),
                    ...SettingConfirmationForm::components(),
                ])->action(function (array $data): void {
                    app(ConfirmSettingService::class)->confirm($this->getRecord(), Filament::auth()->user(), $data);
                    Notification::make()->success()->title('确认版本已保存，既有运行来源保持不变')->send();
                }),
            Action::make('requirements')->label('覆盖矩阵')->url(fn (): string => NovelResource::getUrl('requirements', ['record' => $this->getRecord()])),
            Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /** 用中文字段展示完整设定及决定，避免把原始结构化键名当业务表单。 */
    private static function describe(NovelSettingConfirmation $version): string
    {
        $labels = ['world' => '世界', 'era' => '时代', 'background' => '背景', 'rules' => '规则', 'characters' => '人物', 'name' => '姓名', 'role' => '角色', 'goal' => '目标', 'personality' => '性格', 'speech_style' => '说话方式', 'abilities' => '能力', 'limitations' => '限制', 'tone' => '基调', 'narrative_style' => '文风', 'pov' => '视角', 'pacing' => '节奏', 'core_conflict' => '核心矛盾', 'preserved_details' => '保留细节', 'open_questions' => '待决定问题', 'question' => '问题', 'impact' => '影响', 'blocking' => '阻塞', 'answer' => '作者决定'];
        $walk = function (array $value, string $prefix = '') use (&$walk, $labels): array {
            $lines = [];
            foreach ($value as $key => $item) {
                $label = $prefix.(is_int($key) ? (string) ($key + 1) : ($labels[$key] ?? $key));
                if (is_array($item)) {
                    $lines = [...$lines, ...$walk($item, $label.' / ')];
                } else {
                    $lines[] = $label.'：'.(is_bool($item) ? ($item ? '是' : '否') : $item);
                }
            }

            return $lines;
        };

        return implode("\n", [...$walk($version->setting), ...$walk($version->decisions, '决定 / ')]);
    }
}
