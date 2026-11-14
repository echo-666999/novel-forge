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
use Filament\Actions\ActionGroup;
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
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

/** 集中查看流水线与失败尝试，恢复动作复用原服务，不改写已有产物。 */
class ViewNovelRun extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 页面所属小说资源。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '运行与恢复';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 12;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '生产';

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
            // 第一屏：状态标签、自动生成进度、阶段一行与停止提示；有在途运行时每5秒刷新。
            Section::make('当前运行')->compact()
                ->extraAttributes(fn (): array => in_array($this->automation()?->status, ['pending', 'running', 'recovering', 'reconciling'], true) ? ['wire:poll.5s' => ''] : [])
                ->schema([
                    Flex::make([
                        TextEntry::make('run_status')->hiddenLabel()->badge()->grow(false)
                            ->state(fn (): string => NovelResource::statusLabel($this->getRecord()->status, $this->getRecord()))
                            ->color(fn (): string => NovelResource::statusColor($this->getRecord()->status)),
                        TextEntry::make('formal')->hiddenLabel()->badge()->grow(false)->color('primary')
                            ->state(fn (): string => '已正式 '.$this->getRecord()->chapters_done.' 章 · 故事版本 '.$this->getRecord()->state_version),
                        TextEntry::make('pause_state')->hiddenLabel()->badge()->grow(false)->color('warning')->visible(fn (): bool => (bool) $this->getRecord()->pause_requested)
                            ->state('已请求暂停')->tooltip('在途调用完成后不再启动下一步'),
                        TextEntry::make('automation_chapters')->hiddenLabel()->badge()->grow(false)->color('gray')->visible(fn (): bool => $this->automation() !== null)
                            ->state(fn (): string => '授权范围 第'.$this->automation()?->start_chapter.'～'.$this->automation()?->chapter_limit.'章'),
                        TextEntry::make('automation_state')->hiddenLabel()->badge()->grow(false)->visible(fn (): bool => $this->automation() !== null)
                            ->state(fn (): string => '第'.($this->automation()?->artifacts['chapter_number'] ?? $this->automation()?->start_chapter).'章 · '.self::stepLabel($this->automation()?->step).' · '.self::statusLabel($this->automation()?->status))
                            ->color(fn (): string => match ($this->automation()?->status) {
                                'completed' => 'success', 'failed', 'error' => 'danger', 'paused' => 'warning', default => 'info'
                            }),
                    ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                    TextEntry::make('automation_error')->hiddenLabel()->weight('bold')->color('danger')->visible(fn (): bool => filled($this->automation()?->error))
                        ->state(fn (): string => '停止原因：'.ProductionFailure::describe($this->automation()?->error, $this->automation()?->step)['label']),
                    TextEntry::make('automation_advice')->hiddenLabel()->visible(fn (): bool => filled($this->automation()?->error))
                        ->state(fn (): string => '建议：'.ProductionFailure::describe($this->automation()?->error, $this->automation()?->step)['advice']),
                    TextEntry::make('stage_line')->hiddenLabel()->color('gray')->size('sm')
                        ->state(fn (): string => collect($this->stageSummary())->map(fn (array $stage): string => $stage['name'].' '.$stage['status'])->implode(' → ')),
                ]),
            // 请求次数、阶段尝试、运行记录、技术详情与操作记录统一收起，标题描述给出一行摘要。
            Section::make('请求与费用记录')->collapsible()->collapsed()->compact()
                ->description(fn (): string => implode(' · ', array_filter([
                    $this->automation() ? '本次运行真实请求 '.app(ProductionRunService::class)->requestCount($this->automation()).' 次' : null,
                    '执行 '.$this->getRecord()->execution_count.' / '.$this->getRecord()->max_execution_count,
                    '阶段尝试 '.$this->getRecord()->stages()->count().' 次',
                ])))
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextEntry::make('count')->label('执行次数 / 上限')->state(fn (): string => $this->getRecord()->execution_count.' / '.$this->getRecord()->max_execution_count),
                        TextEntry::make('automation_requests')->visible(fn (): bool => $this->automation() !== null)->label('本次运行真实 AI 请求（含失败及未知）')->state(fn (): int => $this->automation() ? app(ProductionRunService::class)->requestCount($this->automation()) : 0),
                    ]),
                    TextEntry::make('stages_text')->label('各阶段最后一次尝试')->listWithLineBreaks()->bulleted()
                        ->state(fn (): array => collect($this->stageSummary())->map(fn (array $stage): string => $stage['name'].' · '.$stage['status'].' · 共 '.$stage['attempts'].' 次'.(filled($stage['error']) ? ' · '.$stage['error'] : ''))->all()),
                    TextEntry::make('runs_text')->label('生成与提交运行')->listWithLineBreaks()->bulleted()->placeholder('无')
                        ->state(fn (): array => collect($this->runSummary())->map(fn (array $run): string => $run['kind'].' · '.$run['status'].($run['requests'] === null ? '' : ' · 请求 '.$run['requests']).(filled($run['reason']) ? ' · '.$run['reason'] : '').' · #'.$run['id'])->all()),
                    TextEntry::make('automation_runs')->label('本书全部生产运行')->listWithLineBreaks()->bulleted()->visible(fn (): bool => $this->automation() !== null)
                        ->state(fn (): array => ProductionRun::where('novel_id', $this->getRecord()->id)->orderByDesc('id')->get()
                            ->map(fn (ProductionRun $run): string => '第'.$run->start_chapter.'～'.$run->chapter_limit.'章 · '.self::statusLabel($run->status).' · 错误码 '.($run->error ?? '无').' · #'.$run->id)->all()),
                    TextEntry::make('controls')->label('运行控制记录')->listWithLineBreaks()->bulleted()->placeholder('无')
                        ->state(fn (): array => collect($this->getRecord()->run_control_history ?? [])->map(fn (array $control): string => ($control['action'] === 'pause' ? '暂停' : '恢复').' · '.($control['reason'] ?? '').' · 操作人 #'.($control['operator_id'] ?? '?').' · '.($control['at'] ?? ''))->all()),
                    TextEntry::make('automation_raw')->label('原始步骤 / 状态 / 错误码')->color('gray')->visible(fn (): bool => $this->automation() !== null)
                        ->state(fn (): string => ($this->automation()?->step ?? '').' / '.($this->automation()?->status ?? '').' / '.($this->automation()?->error ?? '无')),
                    TextEntry::make('automation_evidence')->label('自动授权与完整断点证据（非人工阅读）')->visible(fn (): bool => $this->automation() !== null)
                        ->state(fn (): string => PlanningJson::encode($this->automation()?->only(['id', 'author_id', 'start_chapter', 'chapter_limit', 'policy', 'policy_hash', 'artifacts', 'history']) ?? []))->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere; font-size: .8125rem;']),
                    EmbeddedTable::make(),
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
            'reconciling' => '正在对账', 'completed' => '已完成', 'paused' => '已暂停', 'error' => '异常', 'ready' => '可正式提交',
            'awaiting_approval' => '等待批准新实体', 'committed' => '已正式提交', default => $status ?? '无',
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
            TextColumn::make('id')->label('编号')->color('gray'),
            TextColumn::make('stage')->label('阶段')->formatStateUsing(fn (Stage $state): string => $state->label()),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn ($state): string => $state->label()),
            TextColumn::make('execution_count')->label('阶段尝试次数')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('error_message')->label('错误')->wrap()->placeholder('无'),
            TextColumn::make('duration_ms')->label('耗时（毫秒）')->placeholder('未知')->toggleable(isToggledHiddenByDefault: true),
        ])->recordActions([
            Action::make('inspect')->label('查看输入与产物')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->modalWidth('7xl')->schema(fn (NovelStage $record): array => [
                TextEntry::make('input')->label('完整输入快照')->state(PlanningJson::encode($record->input)),
                TextEntry::make('output')->label('完整输出及已有产物')->state(PlanningJson::encode($record->output)),
                TextEntry::make('cost')->label('费用记录（未知不等于免费）')->state(PlanningJson::encode($record->cost)),
            ]),
        ]);
    }

    /** 操作资格只控制展示，服务还会加锁复核，拒绝过期页面重复恢复。
     * @return list<Action|ActionGroup>
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
            // 跳转与刷新收进一个菜单，页头只露出恢复、暂停等主操作。
            ActionGroup::make([
                Action::make('outlineRecovery')->label('大纲产物 / 继续')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))->visible(fn (): bool => $this->getRecord()->outlinePlans()->exists()),
                Action::make('contentRecovery')->label('正文产物 / AI修复')->url(fn (): string => NovelResource::getUrl('content', ['record' => $this->getRecord()]))->visible(fn (): bool => $this->getRecord()->chapters()->exists()),
                Action::make('refreshRun')->label('刷新状态')->action(fn () => $this->getRecord()->refresh()),
                Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()])),
            ])->label('前往')->icon('heroicon-m-chevron-down')->button()->color('gray'),
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
        $plans = $novel->outlinePlans()->latest('id')->limit(10)->get()->map(fn ($plan): array => ['kind' => '大纲', 'id' => $plan->id, 'status' => $plan->statusLabel(), 'reason' => ProductionFailure::detailLabel($plan->stop_reason), 'requests' => null])->all();
        $runs = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id))->latest('id')->limit(10)->get();
        $submissions = ChapterSubmissionRun::where('novel_id', $novel->id)->latest('id')->limit(10)->get();
        foreach (['正文' => $runs, '评审与事件提取' => $submissions] as $kind => $items) {
            foreach ($items as $run) {
                $plans[] = ['kind' => $kind, 'id' => $run->id, 'status' => self::statusLabel($run->status), 'reason' => ProductionFailure::detailLabel($run->stop_reason), 'requests' => $run->requests_used.' / '.$run->request_limit];
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

        // 润色和导出只是模拟流程的独立阶段；真实流程中润色在每章修订步骤内完成、导出单独执行，没有记录时不列出。
        $stages = array_filter(Stage::cases(), fn (Stage $stage): bool => ! in_array($stage, [Stage::Polish, Stage::Export], true) || $attempts->has($stage->value));

        return array_values(array_map(function (Stage $stage) use ($attempts): array {
            $rows = $attempts->get($stage->value, collect());

            return ['name' => $stage->label(), 'status' => $rows->last()?->status->label() ?? '未执行', 'attempts' => $rows->count(), 'error' => $rows->last()?->error_message];
        }, $stages));
    }
}
