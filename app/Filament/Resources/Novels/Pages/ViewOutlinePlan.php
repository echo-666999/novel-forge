<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\CostLog;
use App\Models\NovelOutlineChapter;
use App\Models\NovelOutlinePlan;
use App\Models\NovelStage;
use App\Services\Pipeline\OutlineRevisions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/** 按小说隔离、按章分页阅读大纲候选和完整推进点，不编辑成功产物。 */
class ViewOutlinePlan extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '章节大纲';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 4;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '规划';

    protected static ?string $title = '大纲候选';

    /** 每次组件请求仅查询一次计划，轮询下一请求会重新读取。 */
    private bool $planResolved = false;

    private ?NovelOutlinePlan $resolvedPlan = null;

    /** 从资源作用域解析小说，所有后续查询均限制在该小说下。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 重读计划，避免轮询或操作后复用过期游标。 */
    public function plan(): ?NovelOutlinePlan
    {
        if (! $this->planResolved) {
            $this->resolvedPlan = $this->getRecord()->outlinePlans()->latest('id')->first();
            $this->planResolved = true;
        }

        return $this->resolvedPlan;
    }

    /** 采用现有 Filament 页面组件显示冻结输入、批次和候选状态。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // 顶部标签给出计划状态与进度，停止原因只在有值时提示；全书走向与终局方向是本页重点。
            Section::make('大纲计划')->compact()->description('候选规划，不是已生成正文或正式故事事实。')->schema([
                Flex::make([
                    TextEntry::make('plan_status')->hiddenLabel()->badge()->grow(false)->state(fn (): string => $this->plan()?->statusLabel() ?? '尚无计划')
                        ->color(fn (): string => match ($this->plan()?->status) {
                            'completed' => 'success', 'running', 'pending' => 'info', 'paused' => 'warning', 'error' => 'danger', default => 'gray'
                        }),
                    TextEntry::make('completed')->hiddenLabel()->badge()->grow(false)->color('primary')
                        ->state(fn (): string => '已接纳 '.($this->plan() === null ? 0 : $this->plan()->next_chapter - 1).' / '.($this->plan()?->target_chapters ?? $this->getRecord()->target_chapters).' 章'),
                    TextEntry::make('next_chapter')->hiddenLabel()->badge()->grow(false)->color('gray')
                        ->state(fn (): string => $this->plan()?->status === 'completed' ? '全部完成' : ($this->plan()?->next_chapter === null ? '尚未开始' : '下批从第'.$this->plan()->next_chapter.'章起')),
                ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                TextEntry::make('stop_reason')->hiddenLabel()->weight('medium')->color('warning')
                    ->visible(fn (): bool => filled($this->plan()?->stop_reason))
                    ->state(fn (): string => '停止原因：'.match ($this->plan()?->stop_reason) {
                        'user_paused' => '作者已暂停，成功批次保留，可显式继续', 'execution_limit' => '执行额度耗尽，请显式调整后继续', 'batch_failed' => '本批失败，前面成功章节已保留', 'queue_interrupted' => '任务中断，先核查未知费用', 'dispatch_unconfirmed' => '投递未确认，请先核查状态', 'awaiting_next_stage' => '大纲完成，等待后续阶段', default => (string) $this->plan()?->stop_reason
                    }),
                TextEntry::make('roadmap')->label('全书走向候选')->state(fn (): ?string => data_get($this->plan()?->roadmap, 'whole_story_summary'))->extraAttributes(['style' => 'line-height: 1.8']),
                TextEntry::make('ending')->label('终局方向候选')->state(fn (): ?string => data_get($this->plan()?->roadmap, 'ending_direction'))->extraAttributes(['style' => 'line-height: 1.8']),
            ]),
            EmbeddedTable::make(),
            // 请求模型、确认来源与批次尝试统一收起，标题描述给出一行摘要。
            Section::make('请求与费用记录')->collapsible()->collapsed()->compact()
                ->description(fn (): string => count($this->attempts()).' 个大纲批次请求'.(filled(data_get($this->plan()?->config, 'model')) ? ' · 模型 '.data_get($this->plan()?->config, 'model') : ''))
                ->schema([
                    TextEntry::make('source')->label('来源')->color('gray')->state(fn (): string => '确认设定记录 #'.($this->plan()?->setting_confirmation_id ?? '—').' · 请求模型 '.(data_get($this->plan()?->config, 'model') ?? '—')),
                    TextEntry::make('attempt_lines')->label('大纲批次')->listWithLineBreaks()->bulleted()->placeholder('尚无请求')
                        ->state(fn (): array => collect($this->attempts())->map(fn (array $attempt): string => '第'.$attempt['range'].'章 · '.$attempt['status'].' · '.$attempt['duration'].' · '.$attempt['cost']
                            .' · #'.$attempt['id'].($attempt['cost_log_id'] === null ? '' : ' / 日志 #'.$attempt['cost_log_id']).(filled($attempt['error']) ? ' · 失败：'.$attempt['error'] : ''))->all()),
                ]),
        ]);
    }

    /** 候选章节按章序分页；完整推进点通过只读弹窗查看。 */
    public function table(Table $table): Table
    {
        return $table->query(NovelOutlineChapter::query()->where('outline_plan_id', $this->plan()?->id ?? 0))->defaultSort('chapter_number')->paginated([10, 25, 50])->poll('5s')
            ->emptyStateHeading('尚无已接纳章节')->emptyStateDescription('整批结构校验通过后才会显示章节。')
            ->columns([
                TextColumn::make('chapter_number')->label('章序'), TextColumn::make('title')->label('章名')->wrap(),
                TextColumn::make('objective')->label('本章目标')->limit(70)->wrap(),
                TextColumn::make('points_count')->label('推进点数')->state(fn (NovelOutlineChapter $record): int => count($record->progression_points)),
                TextColumn::make('revision')->label('章纲修订')->state(fn (NovelOutlineChapter $record): string => ($latest = app(OutlineRevisions::class)->latest($record->id)) === null ? '原章纲' : '修订 #'.$latest->id)->badge()
                    ->color(fn (string $state): string => $state === '原章纲' ? 'gray' : 'warning'),
            ])->recordActions([
                Action::make('readChapter')->label('查看推进点')->modalHeading(fn (NovelOutlineChapter $record): string => '第'.$record->chapter_number.'章 · '.$record->title)
                    ->fillForm(fn (NovelOutlineChapter $record): array => [...$record->toArray(), ...$this->effective($record)])->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                    ->schema([
                        TextEntry::make('revision_note')->hiddenLabel()->state(fn (NovelOutlineChapter $record): string => ($latest = app(OutlineRevisions::class)->latest($record->id)) === null
                            ? '当前为大纲生成时的原章纲。' : '当前显示作者修订 #'.$latest->id.'（'.$latest->reason.'）。新运行按此冻结；已开始的旧运行仍按原快照。'),
                        TextEntry::make('summary')->label('摘要'), TextEntry::make('objective')->label('目标'),
                        TextEntry::make('conflict')->label('冲突'), TextEntry::make('hook')->label('章末钩子'),
                        RepeatableEntry::make('progression_points')->label('必须完成的情节推进点')->schema([
                            TextEntry::make('id')->label('推进点编号'), TextEntry::make('change_type')->label('变化类型')->formatStateUsing(fn (string $state): string => match ($state) {
                                'information' => '信息', 'decision' => '决定', 'relationship' => '关系', 'resource' => '资源', 'position' => '位置', 'risk' => '风险', default => '未知'
                            }),
                            TextEntry::make('subject')->label('主体'), TextEntry::make('before')->label('变化前状态'),
                            TextEntry::make('action')->label('具体行动'), TextEntry::make('after')->label('变化后状态'),
                            TextEntry::make('verification')->label('正文完成条件'),
                        ]),
                    ]),
                Action::make('reviseOutline')->label('修订章纲')->icon('heroicon-o-pencil-square')
                    ->visible(fn (NovelOutlineChapter $record): bool => $record->chapter_number > $this->getRecord()->chapters_done)
                    ->modalHeading(fn (NovelOutlineChapter $record): string => '修订第'.$record->chapter_number.'章章纲 · '.$record->title)
                    ->modalDescription('只对还没正式提交的章节生效：保存为新修订版本，原章纲和已开始运行的快照不变。之后新建或整章重写的运行按最新修订冻结。适用于章纲与前文正式正文矛盾、评审和核对互相否决的情况。')
                    ->fillForm(fn (NovelOutlineChapter $record): array => ['expected_id' => app(OutlineRevisions::class)->latest($record->id)?->id, ...$this->effective($record)])
                    ->schema(fn (NovelOutlineChapter $record): array => [
                        Hidden::make('expected_id'),
                        Textarea::make('summary')->label('摘要')->required()->rows(2)->maxLength(5000),
                        Textarea::make('objective')->label('目标')->required()->rows(2)->maxLength(5000),
                        Textarea::make('conflict')->label('冲突')->required()->rows(2)->maxLength(5000),
                        Textarea::make('hook')->label('章末钩子')->required()->rows(2)->maxLength(5000),
                        Repeater::make('progression_points')->label('推进点（编号和数量不变）')->addable(false)->deletable(false)->reorderable(false)->columns(2)
                            ->itemLabel(fn (array $state): ?string => $state['id'] ?? null)
                            ->schema([
                                Hidden::make('id'),
                                Select::make('change_type')->label('变化类型')->required()->native(false)
                                    ->options(['information' => '信息', 'decision' => '决定', 'relationship' => '关系', 'resource' => '资源', 'position' => '位置', 'risk' => '风险']),
                                TextInput::make('subject')->label('主体')->required()->maxLength(2000),
                                Textarea::make('before')->label('变化前状态')->required()->rows(2)->maxLength(2000),
                                Textarea::make('action')->label('具体行动')->required()->rows(2)->maxLength(2000),
                                Textarea::make('after')->label('变化后状态')->required()->rows(2)->maxLength(2000),
                                Textarea::make('verification')->label('正文完成条件')->required()->rows(2)->maxLength(2000),
                            ]),
                        Textarea::make('reason')->label('修订原因')->required()->rows(2)->maxLength(2000),
                    ])
                    ->action(function (NovelOutlineChapter $record, array $data): void {
                        app(OutlineRevisions::class)->revise($this->getRecord()->id, $record->id, Filament::auth()->user(), $data, $data['reason'],
                            filled($data['expected_id'] ?? null) ? (int) $data['expected_id'] : null);
                        Notification::make()->success()->title('章纲修订已保存')->body('该章下一次新建或整章重写的运行会按修订冻结。')->send();
                    }),
            ])->toolbarActions([]);
    }

    /** 当前生效的章纲内容：有修订时取最新修订，否则取原章纲。
     * @return array<string, mixed>
     */
    private function effective(NovelOutlineChapter $record): array
    {
        $latest = app(OutlineRevisions::class)->latest($record->id);
        $content = $latest === null ? app(OutlineRevisions::class)->original($record) : app(OutlineRevisions::class)->content($latest);

        return [...$content, 'progression_points' => array_values($content['progression_points'])];
    }

    /** 提供只读刷新和返回小说的入口。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('refresh')->label('刷新状态')->action(function (): void {
            $this->getRecord()->refresh();
            $this->planResolved = false;
            $this->resolvedPlan = null;
        }),
            Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** 来源阶段和费用编号从当前计划范围读取，避免混入其他版本或小说。
     * @return list<array<string, mixed>>
     */
    private function attempts(): array
    {
        $plan = $this->plan();
        if ($plan === null) {
            return [];
        }
        $logs = CostLog::query()->select(['id', 'novel_stage_id'])->where('novel_id', $this->getRecord()->id)->get()->keyBy('novel_stage_id');

        return $this->getRecord()->stages()->where('stage', 'outline')->where('input->outline_plan_id', $plan->id)->orderBy('id')->get()->map(fn (NovelStage $stage): array => [
            'id' => $stage->id, 'range' => data_get($stage->input, 'range.start').'～'.data_get($stage->input, 'range.end'), 'status' => $stage->status->label(),
            'duration' => $stage->duration_ms === null ? '耗时未知' : round($stage->duration_ms / 1000, 1).' 秒',
            'cost' => data_get($stage->cost, 'billing_status') === 'estimated' ? '估算 '.data_get($stage->cost, 'estimated_cost').' '.data_get($stage->cost, 'currency') : '金额未知',
            'cost_log_id' => data_get($stage->output, 'data.cost_log_id') ?? $logs->get($stage->id)?->id, 'error' => $stage->error_message,
        ])->all();
    }
}
