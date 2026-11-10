<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\ProductionFailure;
use App\Enums\Stage;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\NovelStage;
use App\Models\ProductionRun;
use App\Services\Ai\ModelProfiles;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\ProductionRunService;
use App\Services\Pipeline\RetrySettingService;
use App\Services\Pipeline\RunControlService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
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
use InvalidArgumentException;

/** 集中查看流水线与失败尝试，恢复动作复用原服务，不改写已有产物。 */
class ViewNovelRun extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 页面所属小说资源。 */
    protected static string $resource = NovelResource::class;

    /** 面向作者的运行入口标题。 */
    protected static ?string $title = '运行详情与恢复台';

    /** 每次访问均通过资源权限校验，后续查询固定在该小说范围。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 展示固定阶段及真实尝试；未执行不伪装成成功或失败。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('当前运行')->columns(3)->schema([
                TextEntry::make('novel_title')->label('小说')->state(fn (): string => $this->getRecord()->title),
                TextEntry::make('run_status')->label('状态')->state(fn (): string => NovelResource::statusLabel($this->getRecord()->status, $this->getRecord())),
                TextEntry::make('pause_state')->label('暂停请求')->state(fn (): string => $this->getRecord()->pause_requested ? '已请求：在途调用完成后不再启动下一步' : '未请求'),
                TextEntry::make('count')->label('执行次数 / 上限')->state(fn (): string => $this->getRecord()->execution_count.' / '.$this->getRecord()->max_execution_count),
                TextEntry::make('formal')->label('正式章数 / 故事版本')->state(fn (): string => $this->getRecord()->chapters_done.' / '.$this->getRecord()->state_version),
            ]),
            Section::make('自动生产运行与进度')->visible(fn (): bool => $this->automation() !== null)->columns(2)->schema([
                TextEntry::make('automation_chapters')->label('授权范围 / 当前章')->state(fn (): string => '第'.$this->automation()?->start_chapter.'～'.$this->automation()?->chapter_limit.'章 / 第'.($this->automation()?->artifacts['chapter_number'] ?? $this->automation()?->start_chapter).'章'),
                TextEntry::make('automation_state')->label('当前步骤')->state(fn (): string => self::stepLabel($this->automation()?->step).' · '.self::statusLabel($this->automation()?->status)),
                TextEntry::make('automation_requests')->label('本次运行真实 AI 请求（含失败及未知）')->state(fn (): int => $this->automation() ? app(ProductionRunService::class)->requestCount($this->automation()) : 0),
                TextEntry::make('automation_error')->label('停止原因')->state(fn (): string => ProductionFailure::describe($this->automation()?->error, $this->automation()?->step)['label'])->placeholder('无'),
                TextEntry::make('automation_advice')->label('建议下一步')->visible(fn (): bool => filled($this->automation()?->error))->state(fn (): string => ProductionFailure::describe($this->automation()?->error, $this->automation()?->step)['advice'])->columnSpanFull(),
                RepeatableEntry::make('automation_runs')->label('本书全部生产运行')->columnSpanFull()->state(fn (): array => ProductionRun::where('novel_id', $this->getRecord()->id)->orderByDesc('id')->get()
                    ->map(fn (ProductionRun $run): array => ['id' => $run->id, 'range' => '第'.$run->start_chapter.'～'.$run->chapter_limit.'章', 'status' => self::statusLabel($run->status), 'error' => $run->error])->all())
                    ->table([TableColumn::make('运行编号'), TableColumn::make('授权范围'), TableColumn::make('状态'), TableColumn::make('原始错误码')])
                    ->schema([TextEntry::make('id'), TextEntry::make('range'), TextEntry::make('status'), TextEntry::make('error')->placeholder('无')]),
                Section::make('技术详情')->collapsed()->columnSpanFull()->schema([
                    TextEntry::make('automation_raw')->label('原始步骤 / 状态 / 错误码')->state(fn (): string => ($this->automation()?->step ?? '').' / '.($this->automation()?->status ?? '').' / '.($this->automation()?->error ?? '无')),
                    TextEntry::make('automation_evidence')->label('自动授权与完整断点证据（非人工阅读）')->state(fn (): string => PlanningJson::encode($this->automation()?->only(['id', 'author_id', 'start_chapter', 'chapter_limit', 'policy', 'policy_hash', 'artifacts', 'history']) ?? [])),
                ]),
            ]),
            Section::make('阶段流水线')->description('显示每阶段最后一次尝试；下方保留全部历史。阶段记录不代表正式提交；正式导出从小说详情单独执行，不计作生成阶段。')->schema([
                RepeatableEntry::make('stages')->label('阶段状态')->hiddenLabel()->state(fn (): array => $this->stageSummary())->columns(3)->schema([
                    TextEntry::make('name')->label('阶段'), TextEntry::make('status')->label('状态'), TextEntry::make('attempts')->label('尝试总数'),
                    TextEntry::make('error')->label('最近错误')->placeholder('无')->columnSpanFull(),
                ]),
            ]),
            Section::make('已有生成与提交产物')->description('保留独立运行的停止原因；正文修复和评审重跑请进入“正文产物 / AI修复”。')->schema([
                RepeatableEntry::make('runs')->label('运行记录')->hiddenLabel()->state(fn (): array => $this->runSummary())->columns(3)->schema([
                    TextEntry::make('kind')->label('类型'), TextEntry::make('id')->label('编号'), TextEntry::make('status')->label('状态'),
                    TextEntry::make('reason')->label('停止原因 / 错误码')->placeholder('无'), TextEntry::make('requests')->label('已用 / 授权请求数')->placeholder('按小说执行额度'),
                ]),
            ]),
            EmbeddedTable::make(),
            Section::make('运行控制记录')->schema([
                RepeatableEntry::make('controls')->label('操作记录')->hiddenLabel()->state(fn (): array => $this->getRecord()->run_control_history ?? [])->schema([
                    TextEntry::make('action')->label('操作')->formatStateUsing(fn (string $state): string => $state === 'pause' ? '暂停' : '恢复'), TextEntry::make('operator_id')->label('操作人'), TextEntry::make('reason')->label('原因'), TextEntry::make('at')->label('时间'),
                ]),
            ]),
        ]);
    }

    /** 运行步骤的中文名称。 */
    private static function stepLabel(?string $step): string
    {
        return match ($step) {
            'setting' => '生成设定', 'decision' => '设定决策', 'outline' => '生成大纲', 'content' => '正文生成与检查',
            'submission' => '评审与事件提取', 'commit' => '正式提交', 'completed' => '已完成', default => $step ?? '无',
        };
    }

    /** 运行状态的中文名称。 */
    private static function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => '排队中', 'running' => '运行中', 'failed' => '已停止，等你处理', 'recovering' => '正在准备恢复',
            'reconciling' => '正在对账', 'completed' => '已完成', default => $status ?? '无',
        };
    }

    /** 本书最新一次生产运行，不查询其他小说记录。 */
    private function automation(): ?ProductionRun
    {
        return ProductionRun::latestFor($this->getRecord()->id);
    }

    /** 历史分页展示，详情弹窗完整读取该小说的输入输出，默认转义所有文本。 */
    public function table(Table $table): Table
    {
        return $table->query(NovelStage::where('novel_id', $this->getRecord()->id))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('尝试编号'),
            TextColumn::make('stage')->label('阶段')->formatStateUsing(fn (Stage $state): string => $state->label()),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn ($state): string => $state->label()),
            TextColumn::make('execution_count')->label('阶段尝试次数'),
            TextColumn::make('error_message')->label('错误')->wrap()->placeholder('无'),
            TextColumn::make('duration_ms')->label('耗时（毫秒）')->placeholder('未知'),
        ])->recordActions([
            Action::make('inspect')->label('查看输入与产物')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->modalWidth('7xl')->schema(fn (NovelStage $record): array => [
                TextEntry::make('input')->label('完整输入快照')->state(PlanningJson::encode($record->input)),
                TextEntry::make('output')->label('完整输出及已有产物')->state(PlanningJson::encode($record->output)),
                TextEntry::make('cost')->label('费用记录（未知不等于免费）')->state(PlanningJson::encode($record->cost)),
            ]),
        ]);
    }

    /** 操作资格只控制展示，服务还会加锁复核，拒绝过期页面重复恢复。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('retryAutomaticFirstChapter')->label('恢复自动生成')->visible(fn (): bool => ($this->automation()?->status === 'failed' || ($this->automation()?->status === 'running' && $this->automation()?->lease_expires_at?->isPast())))
                ->modalDescription('保留已有产物与费用，从失败断点恢复；之后继续在原授权范围内自动生成并正式提交。')
                ->schema([Checkbox::make('confirm_unknown')->label('已核查未知响应，接受可能重复计费')->default(false), Checkbox::make('rewrite')->label('正文恢复时授权整章重写为新版本，保留旧正文')->default(false), Select::make('model')->label('本次正文或提交恢复模型（可选）')->options(ModelProfiles::options())->native(false)->placeholder('沿用原运行模型')])
                ->action(fn (array $data) => $this->control(function () use ($data): void {
                    $service = app(ProductionRunService::class);
                    $run = $service->retry($this->automation()->id, Filament::auth()->user(), (bool) ($data['confirm_unknown'] ?? false), (bool) ($data['rewrite'] ?? false), filled($data['model'] ?? null) ? $data['model'] : null);
                    $service->dispatch($run);
                })),
            Action::make('pauseRun')->label('暂停运行')->visible(fn (): bool => in_array($this->getRecord()->status, [NovelStatus::Pending, NovelStatus::Running], true))
                ->modalDescription('当前已发送的调用会正常保存结果；暂停只阻止下一步，不取消供应商计费。')
                ->schema([Textarea::make('reason')->label('暂停原因')->required()])
                ->action(fn (array $data) => $this->control(fn () => app(RunControlService::class)->pause($this->getRecord()->id, Filament::auth()->user(), $data['reason']))),
            Action::make('resumeRun')->label('重跑 / 继续模拟')->visible(fn (): bool => $this->getRecord()->generation_mode === GenerationMode::Simulation && in_array($this->getRecord()->status, [NovelStatus::Paused, NovelStatus::Error], true))
                ->schema([TextInput::make('limit')->label('执行总上限')->integer()->minValue(1)->maxValue(2147483647)->default(fn (): int => $this->getRecord()->max_execution_count)->required(), Textarea::make('reason')->label('恢复原因')->required()])
                ->action(fn (array $data) => $this->control(fn () => app(RunControlService::class)->resumeSimulation($this->getRecord()->id, Filament::auth()->user(), (int) $data['limit'], $data['reason']))),
            Action::make('retrySetting')->label('重跑设定')->visible(fn (): bool => $this->automation() === null && $this->getRecord()->generation_mode === GenerationMode::SettingReal && in_array($this->getRecord()->status, [NovelStatus::Paused, NovelStatus::Error], true) && ! $this->getRecord()->stages()->whereIn('status', ['running', 'completed'])->exists())
                ->modalDescription('按原冻结参数创建新尝试，保留旧结果和费用；将调用真实模型。执行上限不自动增加。')
                ->schema([Checkbox::make('accept_request')->label('确认发送真实请求并产生费用')->accepted(), Checkbox::make('confirm_unknown')->label('已核查未知请求，接受可能重复计费')->default(false)])
                ->action(fn (array $data) => $this->control(function () use ($data): void {
                    $result = app(RetrySettingService::class)->retry($this->getRecord()->id, (bool) ($data['confirm_unknown'] ?? false));
                    if (! $result->startupConfirmed) {
                        throw new InvalidArgumentException('投递未确认，请核查记录后再操作。');
                    }
                })),
            Action::make('outlineRecovery')->label('大纲产物 / 继续')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))->visible(fn (): bool => $this->getRecord()->outlinePlans()->exists()),
            Action::make('contentRecovery')->label('正文产物 / AI修复')->url(fn (): string => NovelResource::getUrl('content', ['record' => $this->getRecord()]))->visible(fn (): bool => $this->getRecord()->chapters()->exists()),
            Action::make('refreshRun')->label('刷新状态')->action(fn () => $this->getRecord()->refresh()),
            Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    /** 将服务拒绝原因呈现在当前页，成功后重新读取状态和操作资格。 */
    private function control(callable $operation): void
    {
        abort_unless(NovelResource::canView($this->getRecord()), 403);
        try {
            $operation();
            Notification::make()->success()->title('运行操作已受理')->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title('运行操作未执行')->body($exception->getMessage())->send();
        }
        $this->getRecord()->refresh();
    }

    /** 汇总独立运行状态，避免评审失败因没有阶段行而在恢复台中消失。
     * @return list<array<string, mixed>>
     */
    private function runSummary(): array
    {
        $novel = $this->getRecord();
        $plans = $novel->outlinePlans()->latest('id')->limit(10)->get()->map(fn ($plan): array => ['kind' => '大纲', 'id' => $plan->id, 'status' => $plan->statusLabel(), 'reason' => $plan->stop_reason, 'requests' => null])->all();
        $runs = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id))->latest('id')->limit(10)->get();
        $submissions = ChapterSubmissionRun::where('novel_id', $novel->id)->latest('id')->limit(10)->get();
        foreach (['正文' => $runs, '评审与事件提取' => $submissions] as $kind => $items) {
            foreach ($items as $run) {
                $plans[] = ['kind' => $kind, 'id' => $run->id, 'status' => $run->status, 'reason' => $run->stop_reason, 'requests' => $run->requests_used.' / '.$run->request_limit];
            }
        }

        return $plans;
    }

    /** 一次查询归并各阶段尝试，保留失败和运行中的真实标签。
     * @return list<array<string, mixed>>
     */
    private function stageSummary(): array
    {
        $attempts = $this->getRecord()->stages()->orderBy('id')->get()->groupBy(fn (NovelStage $stage): string => $stage->stage->value);

        return array_map(function (Stage $stage) use ($attempts): array {
            $rows = $attempts->get($stage->value, collect());

            return ['name' => $stage->label(), 'status' => $rows->last()?->status->label() ?? '未执行', 'attempts' => $rows->count(), 'error' => $rows->last()?->error_message];
        }, Stage::cases());
    }
}
