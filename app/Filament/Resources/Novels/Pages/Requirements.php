<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Support\HelpHint;
use App\Models\NovelOutlineChapter;
use App\Models\RequirementVersion;
use App\Services\Pipeline\RequirementCoverage;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** 原始创意只读，提炼和覆盖决定通过独立不可变版本保存。 */
class Requirements extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 使用小说的私有权限和面包屑。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '原始要求与覆盖';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 1;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '规划';

    /** 页面中文名称。 */
    protected static ?string $title = '原始要求与覆盖矩阵';

    /** 先检查归属及授权，未找到小说返回404。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 直接展示原文，不以自动提炼替代作者输入。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('原始输入')->compact()->description('覆盖是作者基于计划证据的决定，不是正式剧情。')->headerActions([HelpHint::action('覆盖代表作者基于计划证据作出的决定，不是已实现的正式剧情，也不会改写既有运行快照。')])->schema([
                TextEntry::make('premise')->label('原始创意（只读）')->state(fn (): string => $this->getRecord()->premise_raw),
            ]), EmbeddedTable::make(),
        ]);
    }

    /** 默认显示每项要求的最新版本，历史版本可单独筛选查看。 */
    public function table(Table $table): Table
    {
        // 同一要求的历史连续排列，版本号来自已保存的 revision，不随筛选重算。
        return $table->query(RequirementVersion::where('novel_id', $this->getRecord()->id))->defaultSort(fn (Builder $query): Builder => $query->orderBy('requirement_key')->orderByDesc('revision')->orderByDesc('id'))->columns([
            TextColumn::make('requirement_key')->label('要求标识')->wrap(),
            TextColumn::make('id')->label('记录编号')->prefix('#')->color('gray'),
            TextColumn::make('source_quote')->label('要求原句')->wrap()->searchable(),
            TextColumn::make('description')->label('提炼内容')->wrap()->searchable(),
            TextColumn::make('category')->label('归类'),
            TextColumn::make('strength')->label('强度')->formatStateUsing(fn (string $state): string => $state === 'hard' ? '硬约束' : '软要求'),
            TextColumn::make('status')->label('覆盖状态')->formatStateUsing(fn (string $state): string => self::statuses()[$state]),
            TextColumn::make('revision')->label('要求版本')->formatStateUsing(fn ($state): string => 'V'.$state),
            TextColumn::make('outline_chapter_id')->label('承接计划编号')->placeholder('无')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label('决定时间')->since()->dateTimeTooltip('Y-m-d H:i:s')->color('gray'),
        ])->filters([
            SelectFilter::make('versions')->label('版本范围')->options(['latest' => '当前版本', 'all' => '所有历史版本'])->default('latest')
                ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? 'latest') === 'all' ? $query : $query->whereIn('id', RequirementVersion::selectRaw('MAX(id)')->where('novel_id', $this->getRecord()->id)->groupBy('requirement_key'))),
            SelectFilter::make('strength')->label('强度')->options(['hard' => '硬约束', 'soft' => '软要求']),
            SelectFilter::make('status')->label('覆盖状态')->options(self::statuses()),
        ])->recordActions([
            Action::make('inspect')->label('决定与证据')->modalSubmitAction(false)->schema(fn (RequirementVersion $record): array => [
                TextEntry::make('reason')->label('作者决定原因')->state($record->reason),
                TextEntry::make('quote')->label('计划证据原句')->state($record->evidence_quote)->placeholder('未关联'),
                TextEntry::make('source')->label('原始输入摘要')->state($record->source_hash),
                TextEntry::make('actor')->label('决定账号')->state($record->actor_id),
            ]),
            Action::make('revise')->label('修订为新版本')->fillForm(fn (RequirementVersion $record): array => [...$record->toArray(), 'acknowledged' => false])->schema(fn (): array => $this->fields())
                ->action(function (RequirementVersion $record, array $data): void {
                    app(RequirementCoverage::class)->save($this->getRecord()->id, Filament::auth()->user(), $data, $record->id);
                    Notification::make()->success()->title('已保存新要求版本')->send();
                }),
            Action::make('plan')->label('查看承接计划')->visible(fn (RequirementVersion $record): bool => $record->outline_chapter_id !== null)->url(fn (): string => NovelResource::getUrl('outline', ['record' => $this->getRecord()])),
        ])->emptyStateHeading('尚未登记提炼要求')->emptyStateDescription('新增要求时引用原始创意中的原句，旧小说不会自动被标记为已覆盖。');
    }

    /** 新增要求、原工作台及章节计划均有实际入口。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('add')->label('新增提炼要求')->schema(fn (): array => $this->fields())->action(function (array $data): void {
            app(RequirementCoverage::class)->save($this->getRecord()->id, Filament::auth()->user(), $data);
            Notification::make()->success()->title('要求版本已保存')->send();
        }), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** 所有决定都要求明确确认；硬约束豁免没有自动默认勾选。 */
    private function fields(): array
    {
        return [
            Textarea::make('source_quote')->label('原始创意原句')->required()->maxLength(10000),
            Textarea::make('description')->label('提炼描述')->required()->maxLength(10000),
            TextInput::make('category')->label('归类')->required()->maxLength(100),
            Select::make('strength')->label('强度')->options(['hard' => '硬约束', 'soft' => '软要求'])->default('hard')->required(),
            Select::make('status')->label('覆盖状态')->options(self::statuses())->default('unresolved')->required(),
            Select::make('outline_chapter_id')->label('承接章节计划')->options(fn (): array => NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $this->getRecord()->id))->orderByDesc('id')->get()->mapWithKeys(fn ($plan): array => [$plan->id => '#'.$plan->id.' 第'.$plan->chapter_number.'章 '.$plan->title])->all())->searchable(),
            Textarea::make('evidence_quote')->label('计划证据原句（已覆盖必填）')->maxLength(10000),
            Textarea::make('reason')->label('决定原因（含冲突或豁免说明）')->required()->maxLength(2000),
            Checkbox::make('acknowledged')->label('我确认此要求及覆盖决定；豁免由我明确批准，不代表正式剧情已经实现。')->accepted()->default(false),
        ];
    }

    /** 中文状态名称与服务端枚举一致。 */
    private static function statuses(): array
    {
        return ['unresolved' => '待决定 / 未覆盖', 'covered' => '计划已覆盖', 'conflict' => '存在冲突', 'waived' => '作者豁免'];
    }
}
