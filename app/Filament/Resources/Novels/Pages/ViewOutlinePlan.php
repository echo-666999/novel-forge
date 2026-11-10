<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\CostLog;
use App\Models\NovelOutlineChapter;
use App\Models\NovelOutlinePlan;
use App\Models\NovelStage;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
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
            Section::make('大纲计划')->description('此处是候选规划，不是已生成正文或正式故事事实。')->columns(3)->schema([
                TextEntry::make('plan_status')->label('计划状态')->state(fn (): string => $this->plan()?->statusLabel() ?? '尚无计划'),
                TextEntry::make('confirmation')->label('确认设定记录编号')->state(fn (): ?int => $this->plan()?->setting_confirmation_id),
                TextEntry::make('completed')->label('已接纳 / 目标章数')->state(fn (): string => ($this->plan() === null ? 0 : $this->plan()->next_chapter - 1).' / '.($this->plan()?->target_chapters ?? $this->getRecord()->target_chapters)),
                TextEntry::make('model')->label('请求模型')->state(fn (): ?string => data_get($this->plan()?->config, 'model')),
                TextEntry::make('next_chapter')->label('下批起始章')->state(fn (): string => $this->plan()?->status === 'completed' ? '无，全部完成' : (string) ($this->plan()?->next_chapter ?? '尚未开始')),
                TextEntry::make('stop_reason')->label('停止原因')->state(fn (): string => match ($this->plan()?->stop_reason) {
                    'user_paused' => '作者已暂停，成功批次保留，可显式继续', 'execution_limit' => '执行额度耗尽，请显式调整后继续', 'batch_failed' => '本批失败，前面成功章节已保留', 'queue_interrupted' => '任务中断，先核查未知费用', 'dispatch_unconfirmed' => '投递未确认，请先核查状态', 'awaiting_next_stage' => '大纲完成，等待后续阶段', default => '无'
                }),
                TextEntry::make('roadmap')->label('全书走向候选')->state(fn (): ?string => data_get($this->plan()?->roadmap, 'whole_story_summary'))->columnSpanFull(),
                TextEntry::make('ending')->label('终局方向候选')->state(fn (): ?string => data_get($this->plan()?->roadmap, 'ending_direction'))->columnSpanFull(),
            ]),
            Section::make('批次尝试与费用')->schema([
                RepeatableEntry::make('attempts')->label('大纲批次')->state(fn (): array => $this->attempts())->columns(3)->schema([
                    TextEntry::make('id')->label('阶段编号'), TextEntry::make('range')->label('章序范围'), TextEntry::make('status')->label('执行状态'),
                    TextEntry::make('duration')->label('耗时'), TextEntry::make('cost')->label('费用'), TextEntry::make('cost_log_id')->label('调用日志编号')->placeholder('尚无记录'),
                    TextEntry::make('error')->label('失败说明')->placeholder('无')->columnSpanFull(),
                ]),
            ]),
            EmbeddedTable::make(),
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
            ])->recordActions([
                Action::make('readChapter')->label('查看推进点')->modalHeading(fn (NovelOutlineChapter $record): string => '第'.$record->chapter_number.'章 · '.$record->title)
                    ->fillForm(fn (NovelOutlineChapter $record): array => $record->toArray())->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                    ->schema([
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
            ])->toolbarActions([]);
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
            'duration' => $stage->duration_ms === null ? '未知' : $stage->duration_ms.'毫秒',
            'cost' => data_get($stage->cost, 'billing_status') === 'estimated' ? '估算 '.data_get($stage->cost, 'estimated_cost').' '.data_get($stage->cost, 'currency') : '未知（不代表免费）',
            'cost_log_id' => data_get($stage->output, 'data.cost_log_id') ?? $logs->get($stage->id)?->id, 'error' => $stage->error_message,
        ])->all();
    }
}
