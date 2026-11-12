<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\SettingConfirmationForm;
use App\Models\NovelSettingConfirmation;
use App\Models\RequirementVersion;
use App\Services\Pipeline\ConfirmSettingService;
use App\Services\Pipeline\SettingVersionDiff;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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
            Section::make(fn (): string => count($this->comparison) === 2 ? '版本对照：确认记录 #'.$this->comparison[0].' → #'.$this->comparison[1] : '版本对照')
                ->description('按字段对照两个确认版本：人物按姓名、作者决定按问题匹配，规则与保留细节按条目比较。')
                ->visible(fn (): bool => count($this->comparison) === 2)
                ->headerActions([Action::make('closeComparison')->label('关闭对照')->link()->action(fn () => $this->comparison = [])])
                ->schema([
                    ViewComponent::make('filament.resources.novels.partials.field-diff')->viewData(function (): array {
                        [$old, $new] = array_map(fn (int $id): NovelSettingConfirmation => NovelSettingConfirmation::where('novel_id', $this->getRecord()->id)->findOrFail($id), $this->comparison);
                        $label = fn (NovelSettingConfirmation $version): string => '#'.$version->id.'（'.$version->confirmed_at?->format('Y-m-d H:i').'）';

                        return ['diff' => SettingVersionDiff::compare($old->setting, $old->decisions, $new->setting, $new->decisions), 'oldLabel' => $label($old), 'newLabel' => $label($new),
                            'sameMessage' => '两个版本的设定与作者决定完全相同，没有需要关注的变化。'];
                    }),
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
            Action::make('inspect')->label('查看完整设定')->modalHeading(fn (NovelSettingConfirmation $record): string => '完整设定 · 确认记录 #'.$record->id)
                ->modalWidth('5xl')->modalAutofocus(false)->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                ->modalContent(fn (NovelSettingConfirmation $record): View => view('filament.resources.novels.pages.setting-confirmation', [
                    'setting' => self::presentation($record),
                    'confirmation' => $record,
                    'plans' => $this->getRecord()->outlinePlans()->where('setting_confirmation_id', $record->id)->orderByDesc('id')->pluck('id')->all(),
                ])),
            Action::make('compare')->label('与另一版本对照')->modalHeading('选择要对照的版本')
                ->visible(fn (): bool => $this->getRecord()->settingConfirmations()->count() > 1)
                ->schema(fn (NovelSettingConfirmation $record): array => [Select::make('other')->label('对照版本')->helperText('编号较小的一方作为旧版显示在左侧。')->options($this->getRecord()->settingConfirmations()->whereKeyNot($record->id)->orderByDesc('id')->get()
                    ->mapWithKeys(fn (NovelSettingConfirmation $version): array => [$version->id => '确认记录 #'.$version->id.'（'.$version->confirmed_at?->format('Y-m-d H:i').'）'])->all())->required()])
                ->action(function (NovelSettingConfirmation $record, array $data): void {
                    $old = NovelSettingConfirmation::where('novel_id', $this->getRecord()->id)->findOrFail((int) $data['other']);
                    // 始终按编号先后排列，旧版在前、新版在后。
                    $this->comparison = [min($old->id, $record->id), max($old->id, $record->id)];
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

    /** “查看完整设定”的分区数据：重点内容单独列出，未识别的字段归入“其他”，不丢失任何内容。
     * @return array{core_conflict: ?string, open_questions: list<array{question: string, impact: ?string, blocking: bool}>, decisions: list<array{question: ?string, answer: string, impact: ?string, blocking: bool}>, characters: list<array{name: string, role: ?string, fields: list<array{label: string, value: string}>}>, world: array{era: ?string, background: ?string, rules: list<string>}, tone: list<array{label: string, value: string}>, preserved: list<string>, other: list<string>}
     */
    private static function presentation(NovelSettingConfirmation $version): array
    {
        $setting = $version->setting;
        $text = fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        $list = fn (mixed $value): array => is_array($value) ? array_values(array_filter(array_map(fn (mixed $item): ?string => is_scalar($item) ? $text($item) : implode('；', SettingVersionDiff::lines((array) $item)), $value))) : [];
        $question = fn (mixed $item): array => is_array($item)
            ? ['question' => $text($item['question'] ?? null), 'answer' => $text($item['answer'] ?? null), 'impact' => $text($item['impact'] ?? null), 'blocking' => (bool) ($item['blocking'] ?? false)]
            : ['question' => $text($item), 'answer' => null, 'impact' => null, 'blocking' => false];
        $characters = [];
        foreach (is_array($setting['characters'] ?? null) ? $setting['characters'] : [] as $character) {
            $character = (array) $character;
            $fields = [];
            foreach (['goal', 'personality', 'speech_style', 'abilities', 'limitations'] as $key) {
                if (($value = $text($character[$key] ?? null)) !== null) {
                    $fields[] = ['label' => SettingVersionDiff::LABELS[$key], 'value' => $value];
                }
            }
            foreach (array_diff_key($character, array_flip(['name', 'role', 'goal', 'personality', 'speech_style', 'abilities', 'limitations'])) as $key => $value) {
                $fields[] = ['label' => SettingVersionDiff::LABELS[$key] ?? (string) $key, 'value' => is_array($value) ? implode('；', SettingVersionDiff::lines($value)) : (string) $value];
            }
            $characters[] = ['name' => $text($character['name'] ?? null) ?? '未命名人物', 'role' => $text($character['role'] ?? null), 'fields' => $fields];
        }
        $world = (array) ($setting['world'] ?? []);
        $tone = [];
        foreach (['pov', 'pacing', 'narrative_style'] as $key) {
            if (($value = $text($setting['tone'][$key] ?? null)) !== null) {
                $tone[] = ['label' => SettingVersionDiff::LABELS[$key], 'value' => $value];
            }
        }
        $known = ['core_conflict', 'open_questions', 'characters', 'world', 'tone', 'preserved_details'];
        $other = SettingVersionDiff::lines(array_diff_key($setting, array_flip($known)));
        $other = [...$other, ...SettingVersionDiff::lines(array_diff_key($world, array_flip(['era', 'background', 'rules'])), '世界 / '),
            ...SettingVersionDiff::lines(array_diff_key((array) ($setting['tone'] ?? []), array_flip(['pov', 'pacing', 'narrative_style'])), '基调 / ')];

        return [
            'core_conflict' => $text($setting['core_conflict'] ?? null),
            'open_questions' => array_values(array_filter(array_map($question, is_array($setting['open_questions'] ?? null) ? $setting['open_questions'] : []), fn (array $item): bool => $item['question'] !== null)),
            'decisions' => array_values(array_filter(array_map($question, $version->decisions), fn (array $item): bool => $item['answer'] !== null || $item['question'] !== null)),
            'characters' => $characters,
            'world' => ['era' => $text($world['era'] ?? null), 'background' => $text($world['background'] ?? null), 'rules' => $list($world['rules'] ?? null)],
            'tone' => $tone,
            'preserved' => $list($setting['preserved_details'] ?? null),
            'other' => $other,
        ];
    }
}
