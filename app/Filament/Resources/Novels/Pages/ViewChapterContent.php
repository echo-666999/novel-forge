<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\ProductionFailure;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\NovelStage;
use App\Models\ProductionRun;
use App\Services\Pipeline\CanonicalBaselines;
use App\Services\Pipeline\CanonicalCommitService;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentPromptAudit;
use App\Services\Pipeline\ContentReadingAudit;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\NovelProgressQuery;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\RevisionWorkbench;
use App\Services\Pipeline\StyleSignals;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

/** 按章节阅读及证据页面，失败正文和每版历史始终可见。 */
class ViewChapterContent extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '正文工作台';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 10;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '正文';

    protected static ?string $title = '章节候选与推进检查';

    /** 自定义视图先渲染动作弹窗，避免弹窗落入隐藏标签内的表格。 */
    protected string $view = 'filament.resources.novels.pages.view-chapter-content';

    /** 阅读指定章节，查询仍固定在当前小说范围。 */
    #[Url(as: 'chapter')]
    public int $chapterNumber = 1;

    /** 当前请求内缓存所选章节运行，切换章节时清空。 */
    private ?ChapterGenerationRun $resolvedRun = null;

    /** 当前请求内按章节号缓存正式版本，切换章节后自动失效。
     * @var array{number: int, version: ?ChapterVersion}|null
     */
    private ?array $resolvedFormalVersion = null;

    /** 路由先解析小说权限，再限制所有运行和版本的所属章节。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 本章一行结论：已提交、或按当前步骤给出下一步（与工作台进度卡同一规则）。
     * @return array{headline: string, detail: string, running: bool}
     */
    public function verdict(): array
    {
        $chapter = $this->getRecord()->chapters()->where('number', $this->chapterNumber)->first();
        if ($chapter === null) {
            return ['headline' => '本章尚未生成', 'detail' => '回到工作台按进度提示生成本章。', 'running' => false];
        }
        if ($chapter->formal_version_id !== null) {
            return ['headline' => '✅ 已正式提交', 'detail' => '正式稿只读；需要修改时请走历史修订流程。可以在阅读器中阅读本章。', 'running' => false];
        }
        $state = app(NovelProgressQuery::class)->forChapter($this->getRecord(), $chapter);

        return ['headline' => $state['headline'], 'detail' => $state['detail'], 'running' => $state['running']];
    }

    /** 同一请求缓存读取，刷新动作明确失效缓存。 */
    public function run(): ?ChapterGenerationRun
    {
        return $this->resolvedRun ??= ChapterGenerationRun::query()->whereHas('chapter', fn ($query) => $query->where('novel_id', $this->getRecord()->id)->where('number', $this->chapterNumber))->latest('id')->first();
    }

    /** 中文运行原因，不用技术错误码替代可理解的下一步。 */
    public function stopLabel(): string
    {
        return match ($this->run()?->stop_reason) {
            'b04_accepted' => ProductionRun::coveringChapter($this->getRecord()->id, $this->chapterNumber) !== null ? '自动授权下的推进检查已通过；不代表人工全文阅读，正式状态以提交回执为准' : '正文、独立检查及全文核对已通过；候选仍待评审及正式提交',
            'awaiting_review' => '推进检查通过，等待后续评审；尚未正式提交',
            'execution_limit' => '请求或小说执行额度耗尽，已停止；继续须显式调整额度并填写原因',
            'user_paused' => '作者已暂停，可在原额度内继续未执行步骤',
            'retry_limit' => '推进修订次数耗尽，已停止并保留全部版本',
            'unknown' => '调用结果未知，请先核查费用及已保存产物',
            'provider_failed' => '供应商明确失败，未自动重发',
            'invalid_schema' => '响应结构或引用证据无效，原始响应已保留',
            'length_invalid' => '正文字数不符合冻结范围，正文已保留',
            'blocked' => '检查发现核心设定冲突，已阻塞',
            'progression_insufficient' => '独立检查判定推进或钩子不足，本次恢复已停止',
            'body_quality_insufficient' => '当前有限预算内无法修复正文篇幅或写作说明问题，原文已保留',
            'audit_failed' => '费用审计保存失败，未发送模型请求；处理原因后可明确授权恢复',
            'budget_exhausted' => '预算不足或未登记单价，已在请求前停止；调整预算或登记单价后可恢复',
            'insufficient_balance' => '模型服务账户余额不足（HTTP 402）；充值后可从当前步骤恢复',
            'context_capacity' => '上下文超出容量，已在请求前停止，未产生费用；可授权整章重写按当前协议重新冻结前文',
            'reading_failed' => '全文核对未通过；意见已保存，继续修订须显式操作且受原预算约束',
            'dispatch_failed' => '队列投递失败，尚未发送模型请求，可显式继续',
            'invalid_contract' => '冻结来源或正文摘要变化，拒绝继续',
            default => '尚无停止原因，运行步骤见下方',
        };
    }

    /** 以现有 Filament 组件展示纯文本正文，所有作者和模型内容默认转义。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // 一行结论：本章现在能做什么；有在途步骤时每5秒刷新。
            Section::make(fn (): string => '第'.$this->chapterNumber.'章')->extraAttributes(fn (): array => ($this->verdict()['running'] ?? false) ? ['wire:poll.5s' => ''] : [])->schema([
                TextEntry::make('verdict_headline')->hiddenLabel()->weight('bold')->size('lg')->state(fn (): string => $this->verdict()['headline']),
                TextEntry::make('verdict_detail')->hiddenLabel()->state(fn (): string => $this->verdict()['detail']),
            ]),
            Tabs::make('chapter-workbench')->persistTabInQueryString()->tabs([
                Tab::make('正文')->schema([
                    Section::make(fn (): string => $this->formalVersion() !== null ? '正式稿正文' : '当前正文候选')->schema([
                        TextEntry::make('origin')->label('正文来源')->state(fn (): string => match (true) {
                            $this->formalVersion() !== null => '已正式提交的版本（只读）；下方检查结果来自生成运行的候选',
                            $this->candidate()?->run_id === $this->run()?->id => '本运行已保存的候选版本',
                            default => '保留的源版本；新扩写候选保存后才切换',
                        }),
                        TextEntry::make('body')->label('正文')->state(fn (): ?string => $this->displayedVersion()?->body)->extraAttributes(['style' => 'white-space: pre-wrap; line-height: 1.9']),
                        TextEntry::make('count')->label('正文可见非空白字符数')->state(fn (): ?int => $this->displayedVersion()?->character_count),
                        TextEntry::make('verdict')->label('推进检查结论')->state(fn (): string => match (data_get($this->run()?->chapter->progression_result, 'verdict')) {
                            'pass' => '通过，仍待后续评审', 'insufficient' => '推进不足', 'blocked' => '存在核心设定冲突', default => '尚无可信检查结果'
                        }),
                        TextEntry::make('reading_status')->label('本地全文复核提示')->state(fn (): string => $this->displayedVersion() !== null && ContentReadingAudit::inspect($this->displayedVersion()->body)['requires_review'] ? '发现重复或时序风险，须人工复核；模型通过不能视为全文验收通过。' : '未命中有限规则；仍须阅读全文，不能据此认定没有问题。'),
                        RepeatableEntry::make('reading_issues')->label('可定位的阅读线索')->state(fn (): array => $this->displayedVersion() === null ? [] : ContentReadingAudit::inspect($this->displayedVersion()->body)['issues'])->schema([
                            TextEntry::make('type')->label('线索类型')->formatStateUsing(fn (string $state): string => $state === 'repeated_text' ? '重复原文' : '可能的时序倒退'), TextEntry::make('reason')->label('复核说明'),
                            RepeatableEntry::make('evidence')->label('原文位置')->schema([TextEntry::make('paragraph')->label('段号'), TextEntry::make('quote')->label('重复片段或时序原文')]),
                        ]),
                        RepeatableEntry::make('style_hints')->label('跨章文风提示（仅供参考，不作为问题判定）')->visible(fn (): bool => isset($this->run()?->snapshot['style_signals']))
                            ->state(fn (): array => $this->displayedVersion() === null ? [] : array_map(fn (array $hint): array => [...$hint, 'chapters' => '第'.implode('、', $hint['chapters']).'章'], StyleSignals::inspect($this->displayedVersion()->body, $this->run()->snapshot['style_signals'])))->placeholder('当前正文未沿用近章反复出现的短语或开头结尾')->schema([
                                TextEntry::make('type')->label('提示类型')->formatStateUsing(fn (string $state): string => match ($state) {
                                    'overused_phrase' => '沿用近章反复出现的短语', 'similar_opening' => '开头与近章雷同', default => '结尾与近章雷同',
                                }),
                                TextEntry::make('text')->label('原文'), TextEntry::make('chapters')->label('出现过的章节'),
                            ]),
                        RepeatableEntry::make('points')->label('逐点检查')->state(fn (): array => data_get($this->run()?->chapter->progression_result, 'points', []))->schema([
                            TextEntry::make('id')->label('推进点'), TextEntry::make('met')->label('是否完成')->formatStateUsing(fn (bool $state): string => $state ? '完成' : '不足'), TextEntry::make('reason')->label('判断理由'),
                            RepeatableEntry::make('evidence')->label('正文证据')->schema([TextEntry::make('paragraph')->label('段号'), TextEntry::make('quote')->label('原文引文')]),
                        ]),
                        TextEntry::make('hook_result')->label('章末钩子检查')->state(fn (): ?string => data_get($this->run()?->chapter->progression_result, 'hook.reason')),
                        RepeatableEntry::make('reading_reviews')->label('逐项全文复核结果')->state(fn (): array => data_get($this->run()?->chapter->progression_result, 'reading_reviews', []))->schema([
                            TextEntry::make('id')->label('复核编号'), TextEntry::make('fixed')->label('是否消除')->formatStateUsing(fn (bool $state): string => $state ? '已消除' : '仍存在'), TextEntry::make('reason')->label('复核理由'),
                            RepeatableEntry::make('evidence')->label('当前正文证据')->schema([TextEntry::make('paragraph')->label('段号'), TextEntry::make('quote')->label('原文')]),
                        ]),
                        RepeatableEntry::make('continuity')->label('连贯性问题')->state(fn (): array => data_get($this->run()?->chapter->progression_result, 'continuity_issues', []))->schema([
                            TextEntry::make('type')->label('问题类型'), TextEntry::make('reason')->label('问题说明'),
                            RepeatableEntry::make('evidence')->label('精确原文证据')->schema([TextEntry::make('paragraph')->label('段号'), TextEntry::make('quote')->label('原文')]),
                        ]),
                        TextEntry::make('tensions')->label('正常剧情矛盾（不阻塞）')->state(fn (): array => data_get($this->run()?->chapter->progression_result, 'story_tensions', []))->listWithLineBreaks(),
                        RepeatableEntry::make('conflicts')->label('核心冲突')->state(fn (): array => data_get($this->run()?->chapter->progression_result, 'conflicts', []))->schema([
                            TextEntry::make('reason')->label('冲突说明'), RepeatableEntry::make('evidence')->label('正文证据')->schema([TextEntry::make('paragraph')->label('段号'), TextEntry::make('quote')->label('原文引文')]),
                        ]),
                    ]),
                    Section::make('本章细纲候选')->schema([
                        RepeatableEntry::make('scenes')->label('场景安排')->state(fn (): array => data_get($this->run()?->plan_data, 'scenes', []))->schema([
                            TextEntry::make('time')->label('时间'), TextEntry::make('place')->label('地点'), TextEntry::make('action')->label('具体行动'), TextEntry::make('change')->label('状态变化'), TextEntry::make('point_ids')->label('对应推进点')->listWithLineBreaks(),
                        ]),
                        TextEntry::make('hook')->label('衔接后的章末钩子')->state(fn (): ?string => data_get($this->run()?->plan_data, 'hook')),
                        TextEntry::make('completed_state')->label('钩子承接的已完成状态')->state(fn (): ?string => data_get($this->run()?->plan_data, 'hook_transition.completed_state')),
                        TextEntry::make('hook_trigger')->label('结尾触发事件')->state(fn (): ?string => data_get($this->run()?->plan_data, 'hook_transition.trigger')),
                        TextEntry::make('next_question')->label('下一章悬念')->state(fn (): ?string => data_get($this->run()?->plan_data, 'hook_transition.next_question')),
                        TextEntry::make('adjustments')->label('规划衔接调整')->state(fn (): array => data_get($this->run()?->plan_data, 'adjustments', []))->listWithLineBreaks(),
                    ]),
                ]),
                Tab::make('检查、评审与提交')->schema([
                    Section::make('评审、事件提取与正式提交')->schema([
                        TextEntry::make('submission_state')->label('提交状态')->state(fn (): string => match ($this->submission()?->status) {
                            'pending' => '准备已授权，等待执行', 'running' => '正在评审或提取', 'failed' => '准备失败，已停止且未自动重试',
                            'awaiting_approval' => '硬检查通过，等待作者批准新实体', 'ready' => '准备通过，可正式提交', 'committed' => '已正式提交', default => '尚未准备',
                        }),
                        TextEntry::make('submission_reason')->label('停止原因')->state(fn (): ?string => ProductionFailure::detailLabel($this->submission()?->stop_reason))->placeholder('无'),
                        TextEntry::make('submission_requests')->label('准备请求')->state(fn (): string => ($this->submission()?->requests_used ?? 0).' / 2'),
                        TextEntry::make('review_details')->label('基础评审及原文证据')->state(fn (): string => $this->submissionDetails('review'))->extraAttributes(['style' => 'white-space: pre-wrap']),
                        TextEntry::make('extraction_details')->label('实体定义、事件及状态差异')->state(fn (): string => $this->submissionDetails('extract'))->extraAttributes(['style' => 'white-space: pre-wrap']),
                        TextEntry::make('validation_details')->label('确定性验证与待批准实体')->state(fn (): string => $this->submissionDetails('validation'))->extraAttributes(['style' => 'white-space: pre-wrap']),
                        TextEntry::make('commit_details')->label('正式提交回执')->state(fn (): string => $this->submissionDetails('commit'))->extraAttributes(['style' => 'white-space: pre-wrap']),
                    ]),
                    Section::make('评审与定点修订工作台')->description('指定问题只生成候选；范围外原文保留，修订后独立复评，不替代正式提交评审。')->schema([
                        TextEntry::make('revision_status')->label('轮次与处理边界')->state(fn (): string => $this->run() === null ? '尚无正文候选' : app(RevisionWorkbench::class)->status($this->run())),
                        TextEntry::make('revision_issues')->label('本轮指定问题')->state(fn (): array => data_get($this->run()?->config, 'workbench.issues', []))->listWithLineBreaks(),
                        TextEntry::make('revision_source')->label('冻结来源记录编号')->state(fn (): ?int => data_get($this->run()?->config, 'workbench.version_id')),
                        TextEntry::make('revision_review')->label('独立复评结果')->state(fn (): array => explode("\n", $this->run() === null ? '尚无正文候选' : app(RevisionWorkbench::class)->reviewSummary($this->run())))->listWithLineBreaks()->extraAttributes(['style' => 'overflow-wrap: anywhere; line-height: 1.7']),
                    ]),
                ]),
                Tab::make('运行与历史')->schema([
                    Section::make('章节运行')->description(fn (): string => $this->candidate()?->id !== null && $this->run()?->chapter->formal_version_id === $this->candidate()->id ? '该版本已正式提交，事件与状态已原子保存。' : '这里是正文候选，须通过提交准备与作者批准后才成为正式稿。')->columns(3)->schema([
                        TextEntry::make('run_status')->label('运行状态')->state(fn (): string => match ($this->run()?->status) {
                            'pending' => '待处理', 'running' => '运行中', 'completed' => '候选检查完成', 'paused' => '已暂停', 'error' => '异常', default => '尚未启动'
                        }),
                        TextEntry::make('step')->label('当前步骤')->state(fn (): string => match ($this->run()?->next_step) {
                            'fine' => '章节细纲', 'draft' => '正文生成', 'check' => '独立推进检查', 'revise' => '推进点修订', 'length_repair' => '定点扩写', default => '无'
                        }),
                        TextEntry::make('requests')->label('已领用 / 请求上限')->state(fn (): string => ($this->run()?->requests_used ?? 0).' / '.($this->run()?->request_limit ?? 0)),
                        TextEntry::make('continuous_scope')->label('持续验收范围')->state(fn (): string => ($this->run()?->config['version'] ?? null) === ContentExecutionContract::VERSION ? '本授权内最多一次修订，请求上限不可通过继续操作扩大' : '历史协议，只读保留；恢复会创建有限运行'),
                        TextEntry::make('repair_mode')->label('本轮修复方式')->state(fn (): string => data_get($this->run()?->config, 'repair_mode') === 'rewrite' ? '已授权的整章候选重写' : '局部修订'),
                        TextEntry::make('shared_requests')->label('本轮质量修复合计请求')->state(fn (): string => data_get($this->run()?->config, 'shared_request_limit') === null ? '按上方本运行额度执行' : ((int) data_get($this->run()?->config, 'parent_requests_used') + $this->run()->requests_used).' / '.data_get($this->run()?->config, 'shared_request_limit')),
                        TextEntry::make('model')->label('冻结模型')->state(fn (): ?string => data_get($this->run()?->config, 'model')),
                        TextEntry::make('tokens')->label('正文输出Token上限')->state(fn (): ?int => data_get($this->run()?->config, 'max_output_tokens')),
                        TextEntry::make('budget')->label('冻结段落预算')->state(fn (): string => $this->budgetDescription()),
                        TextEntry::make('reused_fine')->label('细纲来源')->state(fn (): string => $this->fineSourceLabel()),
                        TextEntry::make('stop')->label('停止原因与下一步')->state(fn (): string => $this->stopLabel())->columnSpanFull(),
                    ]),
                    Section::make('生成与恢复历史')->schema([
                        RepeatableEntry::make('runs')->label('保留全部运行')->state(fn (): array => $this->run() === null ? [] : ChapterGenerationRun::query()->where('chapter_id', $this->run()->chapter_id)->orderBy('id')->get()->map(fn (ChapterGenerationRun $run): array => [
                            'id' => $run->id, 'parent' => $run->parent_run_id, 'source' => $run->source_version_id, 'status' => $run->status,
                            'requests' => $run->requests_used.' / '.$run->request_limit, 'hook' => data_get($run->plan_data, 'hook'),
                            'reason' => data_get($run->control_history, '0.reason'),
                        ])->all())->schema([
                            TextEntry::make('id')->label('运行编号'), TextEntry::make('parent')->label('上次暂停运行')->placeholder('首次生成'),
                            TextEntry::make('source')->label('扩写源记录编号')->placeholder('无'), TextEntry::make('requests')->label('领用 / 上限'),
                            TextEntry::make('status')->label('历史状态')->formatStateUsing(fn (string $state): string => match ($state) {
                                'pending' => '待处理', 'running' => '运行中', 'completed' => '候选检查完成', default => '已停止'
                            }),
                            TextEntry::make('hook')->label('该运行细纲钩子')->placeholder('尚未生成'), TextEntry::make('reason')->label('操作原因'),
                        ]),
                    ]),
                    Section::make('尝试与费用')->key('content-attempts')->headerActions([
                        Action::make('viewPrompts')->label('查看完整Prompt')->modalHeading('完整Prompt与请求契约')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->modalWidth('7xl')->schema(fn (): array => [
                            TextEntry::make('prompts')->label('调用前快照与已验证历史还原')->state(fn (): string => json_encode(CostLog::where('novel_id', $this->getRecord()->id)->whereHas('novelStage', fn ($query) => $query->whereNotNull('input->run_id'))->orderBy('id')->get()->map(fn (CostLog $log): array => ['cost_log_id' => $log->id, ...app(ContentPromptAudit::class)->read($log)])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']),
                        ]),
                    ])->schema([
                        RepeatableEntry::make('attempts')->label('请求历史')->state(fn (): array => $this->attempts())->schema([
                            TextEntry::make('id')->label('阶段编号'), TextEntry::make('step')->label('步骤'), TextEntry::make('status')->label('执行状态'), TextEntry::make('log_id')->label('费用日志编号'), TextEntry::make('cost')->label('费用'), TextEntry::make('error')->label('失败原因')->placeholder('无'), TextEntry::make('validation_error')->label('具体校验原因')->placeholder('无'),
                            TextEntry::make('quality_issues')->label('本地正文质量问题')->placeholder('无'),
                        ]),
                    ]),
                    EmbeddedTable::make(),
                ]),
            ]),
        ]);
    }

    /** 已正式提交的章节返回正式版本；正式稿可能是候选之后再修订的版本，不能用候选正文代替。 */
    private function formalVersion(): ?ChapterVersion
    {
        if (($this->resolvedFormalVersion['number'] ?? null) !== $this->chapterNumber) {
            $chapter = $this->getRecord()->chapters()->where('number', $this->chapterNumber)->first();
            $this->resolvedFormalVersion = ['number' => $this->chapterNumber, 'version' => $chapter?->formal_version_id === null
                ? null : ChapterVersion::query()->where('chapter_id', $chapter->id)->find($chapter->formal_version_id)];
        }

        return $this->resolvedFormalVersion['version'];
    }

    /** 正文标签展示的版本：正式稿优先，否则为当前候选。 */
    private function displayedVersion(): ?ChapterVersion
    {
        return $this->formalVersion() ?? $this->candidate();
    }

    /** 当前候选必须属于当前运行章节，不允许跨小说读取。 */
    private function candidate(): ?ChapterVersion
    {
        $run = $this->run();

        return $run === null ? null : ChapterVersion::query()->where('chapter_id', $run->chapter_id)->where(fn ($query) => $query->where('run_id', $run->id)->orWhere('id', $run->source_version_id))->find($run->chapter->current_candidate_version_id);
    }

    /** 预算预览与运行使用同一分配算法，显示可核验的整章范围。 */
    private function budgetDescription(): string
    {
        $run = $this->run();
        if ($run !== null && ($run->config['version'] ?? null) === ContentExecutionContract::VERSION && $run->plan_data !== null) {
            $budget = ContentExecutionContract::budget($run->snapshot['novel']['chapter_length_policy'], $run->plan_data, $run->config['enable_fine_outline']);

            return '整章生成预算：'.$budget['count'].'段，每段'.$budget['min'].'～'.$budget['max'].'字符；局部修订遵守整章篇幅';
        }
        if ($run === null || $this->candidate() === null) {
            return '尚无可分配的源正文';
        }
        try {
            $policy = $run->snapshot['novel']['chapter_length_policy'];
            $budget = $run->config['paragraph_budget'] ?? ContentSchema::paragraphBudget($this->candidate()->character_count, $policy['min_characters'], $policy['max_characters']);

            return $budget['count'].'处，每处'.$budget['min'].'～'.$budget['max'].'字符；整章预计'.$budget['final_min'].'～'.$budget['final_max'].'字符';
        } catch (InvalidArgumentException) {
            return '当前正文不需要篇幅扩写';
        }
    }

    /** 保留全部正文版本及检查历史，不提供直接编辑或删除动作。 */
    public function table(Table $table): Table
    {
        return $table->query(ChapterVersion::query()->where('chapter_id', $this->run()?->chapter_id ?? 0))->defaultSort('version', 'desc')->paginated([10])->columns([
            TextColumn::make('version')->label('候选版本'), TextColumn::make('title')->label('章名'), TextColumn::make('character_count')->label('正文字符数'), TextColumn::make('created_at')->label('保存时间'),
        ])->recordActions([
            Action::make('readVersion')->label('阅读历史版本')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->fillForm(fn (ChapterVersion $record): array => $record->toArray() + ['checks' => ChapterProgressionCheck::query()->where('version_id', $record->id)->get()->toArray()])->schema([
                TextEntry::make('title')->label('章名'), TextEntry::make('body')->label('历史正文')->extraAttributes(['style' => 'white-space: pre-wrap;']),
                RepeatableEntry::make('checks')->label('该版检查历史')->schema([TextEntry::make('verdict')->label('结论'), TextEntry::make('source_stage_id')->label('检查阶段'), TextEntry::make('result')->label('完整检查证据')->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : (string) $state)]),
            ]),
        ])->toolbarActions([]);
    }

    /** 成本未知保持未知，阶段状态与正文推进通过分别展示。
     * @return list<array<string, mixed>>
     */
    private function attempts(): array
    {
        if ($this->run() === null) {
            return [];
        }

        return NovelStage::query()->where('novel_id', $this->getRecord()->id)->where('input->run_id', $this->run()->id)->orderBy('id')->get()->map(function (NovelStage $stage): array {
            $log = CostLog::query()->where('novel_stage_id', $stage->id)->first();

            return ['id' => $stage->id, 'step' => match (data_get($stage->input, 'step')) {
                'fine' => '细纲', 'draft' => '正文', 'check' => '独立检查', 'revise' => '定点修订', 'length_repair' => '定点扩写', default => '未知'
            },
                'status' => $stage->status->label(), 'log_id' => $log?->id, 'cost' => $log?->billing_status === 'estimated' ? '估算 '.$log->estimated_cost.' '.$log->currency : '未知（不代表免费）', 'error' => $stage->error_message, 'validation_error' => data_get($stage->output, 'data.validation_error'),
                'quality_issues' => collect(data_get($stage->output, 'data.quality_issues', []))->map(fn (array $issue): string => ($issue['anchor_id'] ?? '').' '.$issue['reason'])->implode('；')];
        })->all();
    }

    /** 使用新旧来源字段如实展示细纲归属，关闭时不声称生成过细纲。 */
    private function fineSourceLabel(): string
    {
        if (! ($this->run()?->config['enable_fine_outline'] ?? false)) {
            return '未启用细纲，直接使用大纲中的章计划';
        }
        $id = data_get($this->run()?->snapshot, 'recovery.reused_fine_stage_id') ?? data_get($this->run()?->snapshot, 'recovery.fine_stage_id');

        return $id === null ? '本运行生成的细纲' : '复用阶段'.$id.'；本轮没有新调用细纲模型';
    }

    /** 已确认的业务拒绝在页面明确提示，不能表现为无说明的服务器错误。 */
    private function performContentAction(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title('首章操作未执行')->body($exception->getMessage())->send();
        }
    }

    /** 根据保留产物显示预算，服务仍会重新校验选择的策略和全局修订设置。 */
    private function recoveryCap(): int
    {
        if ($this->run()?->chapter->current_candidate_version_id !== null) {
            return 2;
        }

        return (($this->run()?->config['enable_fine_outline'] ?? false) && $this->run()?->plan_data === null ? 3 : 2) + 2 * min(1, app(GeneralSettings::class)->max_retries_per_chapter);
    }

    /** 仅查询当前候选的准备记录；迁移前旧页面仍可阅读。 */
    public function submission(): ?ChapterSubmissionRun
    {
        $version = $this->candidate();
        if ($version === null || ! DatabaseSchema::hasTable('chapter_submission_runs')) {
            return null;
        }

        return ChapterSubmissionRun::where('version_id', $version->id)->latest('id')->first();
    }

    /** 原文证据及定义全部作为转义纯文本展示，作者可核对实际批准对象。 */
    private function submissionDetails(string $kind): string
    {
        $run = $this->submission();
        if ($run === null) {
            return '尚无记录';
        }
        $data = match ($kind) {
            'review' => ChapterReview::where('submission_run_id', $run->id)->first()?->result,
            'extract' => ChapterEventExtraction::where('submission_run_id', $run->id)->first()?->result,
            'validation' => ChapterSubmissionValidation::where('submission_run_id', $run->id)->latest('id')->first()?->result,
            'commit' => CanonicalCommit::where('submission_run_id', $run->id)->first()?->result,
        };

        return $data === null ? '尚无记录' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /** 对每个新实体显示定义及证据，批准选择仅绑定当前冻结提取。
     * @return array<string, string>
     */
    private function entityApprovalOptions(): array
    {
        $run = $this->submission();
        $extraction = $run === null ? null : ChapterEventExtraction::where('submission_run_id', $run->id)->first();
        $options = [];
        foreach ($extraction?->result['proposed_entities'] ?? [] as $proposal) {
            $options[$proposal['key']] = $proposal['key'].' '.($proposal['core'] ? '核心实体' : '新实体').'：'.PlanningJson::encode($proposal);
        }

        return $options;
    }

    /** 刷新读库缓存并提供返回入口。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        // 自动授权范围内的章节统一从恢复台推进，避免手动与自动任务并发接管同一候选；范围外的章节使用手动工作台。
        if (ProductionRun::coveringChapter($this->getRecord()->id, $this->chapterNumber) !== null) {
            return [Action::make('selectChapter')->label('选择阅读章节')->schema([Select::make('number')->label('章节')->options(fn (): array => $this->getRecord()->chapters()->orderBy('number')->get()->mapWithKeys(fn (Chapter $chapter): array => [$chapter->number => $chapter->numberedTitle()])->all())->required()])->action(function (array $data): void {
                $this->chapterNumber = (int) $data['number'];
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
            }), Action::make('automationRun')->label('自动生成进度 / 恢复')->url(fn (): string => NovelResource::getUrl('run', ['record' => $this->getRecord()])),
                Action::make('refresh')->label('刷新状态')->action(function (): void {
                    $this->resolvedRun = null;
                    $this->resolvedFormalVersion = null;
                    $this->getRecord()->refresh();
                }), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
        }

        return [Action::make('reviseIssues')->label('针对指定问题修订')
            ->visible(fn (): bool => $this->run() !== null && $this->candidate() !== null && $this->run()->chapter->formal_version_id === null
                && in_array($this->run()->status, ['completed', 'paused', 'error'], true)
                && app(RevisionWorkbench::class)->rounds($this->run()->chapter_id) < RevisionWorkbench::ROUND_LIMIT)
            ->modalHeading('指定问题并授权一轮定点修订')
            ->modalDescription('每章工作台最多两轮；本轮最多两次真实请求：范围修订和独立复评各一次。旧正文与费用保留，失败不自动重发。')
            ->schema([
                TextInput::make('parent_id')->label('冻结运行编号')->default(fn (): ?int => $this->run()?->id)->readOnly()->required(),
                TextInput::make('version_id')->label('冻结正文记录编号')->default(fn (): ?int => $this->candidate()?->id)->readOnly()->required(),
                Textarea::make('issues')->label('指定问题（每行一条，最多10条）')->helperText('例如：人物口吻不一致，请引用具体对白并说明人物应有的表达方式。')->required()->maxLength(10000),
                TextInput::make('execution_limit')->label('确认小说累计执行上限')->integer()->required()->minValue(1)->maxValue(2147483647)->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + 2)),
                Checkbox::make('accept_request')->label('授权本轮修订与复评及对应费用')->accepted(),
            ])->action(function (array $data): void {
                // 服务层再次校验版本与轮次，防止过期弹窗修订另一份正文。
                $this->performContentAction(function () use ($data): void {
                    if ((int) $data['parent_id'] !== $this->run()?->id || (int) $data['version_id'] !== $this->candidate()?->id) {
                        throw new InvalidArgumentException('正文已变化，请刷新后重新指定问题');
                    }
                    $issues = array_values(array_filter(array_map('trim', explode("\n", $data['issues'])), fn (string $issue): bool => $issue !== ''));
                    app(ContentGenerationService::class)->recover($this->run()->id, Filament::auth()->user(), '作者指定问题修订', 2,
                        (int) $data['execution_limit'], issues: $issues, expectedVersionId: (int) $data['version_id']);
                });
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
                $this->getRecord()->refresh();
            }), Action::make('prepareSubmission')->label('准备正式提交')->visible(fn (): bool => $this->run()?->stop_reason === 'b04_accepted' && $this->submission() === null)
            ->modalHeading('授权一次评审及一次事件提取')->modalDescription('当前正文保持冻结；最多两次模型请求。任何失败立即停止，不自动续写或重试。新实体另待作者批准。')
            ->schema([TextInput::make('execution_limit')->label('小说累计执行上限')->integer()->minValue(1)->maxValue(2147483647)->required()
                ->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + 2)),
                Textarea::make('reason')->label('准备原因')->required(), Checkbox::make('accept_request')->label('批准上述最多两次请求及费用')->accepted()])
            ->action(function (array $data): void {
                $this->performContentAction(fn () => app(ChapterSubmissionService::class)->prepare($this->candidate()->id, Filament::auth()->user(),
                    app(CanonicalBaselines::class)->activeStateBefore($this->getRecord(), $this->candidate()->chapter->number), $data['reason'], (int) $data['execution_limit']));
                $this->getRecord()->refresh();
            }), Action::make('retrySubmission')->label('重跑失败评审 / 提取')
            ->visible(fn (): bool => $this->submission()?->status === 'failed')
            ->modalDescription('保留旧正文、评审、提取和费用，新建后继准备；最多重新评审和提取各一次，未知费用不冲销。')
            ->schema([TextInput::make('predecessor_id')->label('失败准备编号')->default(fn (): ?int => $this->submission()?->id)->readOnly()->required(),
                TextInput::make('execution_limit')->label('小说累计执行上限')->integer()->minValue(1)->maxValue(2147483647)->required()->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + 2)),
                Textarea::make('reason')->label('重跑原因')->required(), Checkbox::make('accept_request')->label('已核查旧调用，授权最多两次真实请求及可能重复费用')->accepted()])
            ->action(function (array $data): void {
                // 冻结弹窗打开时的失败编号，过期页面不得接管另一次失败。
                $this->performContentAction(function () use ($data): void {
                    $run = $this->submission();
                    if ($run?->status !== 'failed' || $run->id !== (int) $data['predecessor_id']) {
                        throw new InvalidArgumentException('失败准备已变化，请刷新页面');
                    }
                    app(ChapterSubmissionService::class)->prepare($run->version_id, Filament::auth()->user(), $run->expected_state_version,
                        $data['reason'], (int) $data['execution_limit'], retryOf: $run->id);
                });
                $this->getRecord()->refresh();
            }), Action::make('commitChapter')->label('正式提交当前章')->visible(fn (): bool => in_array($this->submission()?->status, ['ready', 'awaiting_approval'], true))
            ->modalHeading('批准冻结正文、实体并正式提交')->modalDescription(fn (): string => '正文摘要：'.$this->candidate()?->body_hash.'。提交原子保存事件与状态，完成章节数加1；所有硬门禁会再次校验。')
            ->schema([TextInput::make('submission_id')->label('冻结准备编号')->default(fn (): ?int => $this->submission()?->id)->readOnly()->required(),
                TextInput::make('proposal_hash')->label('冻结提议摘要')->default(fn (): string => PlanningJson::hash(ChapterEventExtraction::where('submission_run_id', $this->submission()->id)->sole()->result['proposed_entities']))->readOnly()->required(),
                TextInput::make('idempotency_key')->label('提交幂等键')->default(fn (): string => (string) Str::uuid7())->readOnly()->required(),
                CheckboxList::make('approved_entities')->label('逐项批准新实体及别名')->options(fn (): array => $this->entityApprovalOptions()),
                Textarea::make('reason')->label('正式提交原因')->required(),
                Checkbox::make('acknowledge')->label('我已核对当前正文及上方评审、事件、状态差异，并批准正式提交')->accepted()])
            ->action(function (array $data): void {
                $this->performContentAction(function () use ($data): void {
                    $run = $this->submission();
                    $extraction = ChapterEventExtraction::where('submission_run_id', $run->id)->sole();
                    if ((int) $data['submission_id'] !== $run->id || $data['proposal_hash'] !== PlanningJson::hash($extraction->result['proposed_entities'])) {
                        throw new InvalidArgumentException('批准表单已过期，请重新打开');
                    }
                    app(CanonicalCommitService::class)->commit($run->version_id, ChapterReview::where('submission_run_id', $run->id)->sole()->id,
                        $extraction->id, ChapterSubmissionValidation::where('submission_run_id', $run->id)->oldest('id')->firstOrFail()->id,
                        $run->expected_state_version, $data['idempotency_key'], Filament::auth()->user(), $data['reason'], $data['approved_entities'] ?? [], (bool) $data['acknowledge']);
                });
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
                $this->getRecord()->refresh();
            }), Action::make('recoverContent')->label('恢复首章候选')->modalHeading('显式授权有限恢复')
            ->visible(fn (): bool => data_get($this->run()?->config, 'workbench') === null && in_array($this->run()?->status, ['paused', 'error', 'completed'], true) && $this->run()?->stop_reason !== 'b04_accepted' && ! ChapterGenerationRun::where('parent_run_id', $this->run()?->id)->exists())
            ->modalDescription('旧版本与费用全部保留，新建v14有限运行。范围修订只调用修订和检查各一次；选择重写时按冻结场景生成独立候选。总预算不能无限续用，未知结果须明确确认。')
            ->schema([
                Checkbox::make('rewrite')->label('明确授权整章候选重写；不勾选时优先复用现有正文'),
                TextInput::make('model')->label('仅本次恢复使用的模型')->required()->default(fn (): ?string => data_get($this->run()?->config, 'model')),
                TextInput::make('request_limit')->label('本次请求总上限')->integer()->minValue(2)->maxValue(5)->default(fn (): int => $this->recoveryCap())->required(),
                TextInput::make('execution_limit')->label('确认本小说执行总上限')->integer()->minValue(1)->maxValue(2147483647)->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + $this->recoveryCap()))->required(),
                Textarea::make('reason')->label('恢复原因与待解决问题')->required(),
                Checkbox::make('confirm_unknown')->label('若存在未知调用，我已核查并确认独立新请求可能产生额外费用；旧费用保持未知'),
                Checkbox::make('accept_request')->label('确认上述本次有限请求及可能费用；不代表作者批准或正式提交')->accepted(),
            ])->action(function (array $data): void {
                $this->performContentAction(fn () => app(ContentGenerationService::class)->recover($this->run()->id, Filament::auth()->user(), $data['reason'], (int) $data['request_limit'], (int) $data['execution_limit'], (bool) ($data['confirm_unknown'] ?? false), (bool) ($data['rewrite'] ?? false), $data['model']));
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
                $this->getRecord()->refresh();
            }), Action::make('fullReading')->label('记录全文核对')->modalHeading('核对当前正文与独立检查')
            ->visible(fn (): bool => $this->run()?->status === 'completed' && $this->run()?->stop_reason === 'awaiting_review')
            ->modalDescription('仅记录全文核对。未通过只保存意见并暂停，不自动发送模型请求；实现验收不代表作者批准或正式提交。')
            ->schema([Checkbox::make('accepted')->label('已阅读全文，推进、钩子与时序基本完成，未发现待解决问题'), Textarea::make('notes')->label('全文核对结论或待解决问题（每行一条）')->required()])
            ->action(function (array $data): void {
                $notes = array_values(array_filter(array_map('trim', explode("\n", $data['notes']))));
                $this->performContentAction(fn () => app(ContentGenerationService::class)->recordReading($this->run()->id, Filament::auth()->user(), (bool) ($data['accepted'] ?? false), $notes));
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
                $this->getRecord()->refresh();
            }), Action::make('continueContent')->label('在原额度内继续')->modalHeading('继续未执行步骤')
            ->visible(fn (): bool => ($this->run()?->config['version'] ?? null) === ContentExecutionContract::VERSION && $this->run()?->status === 'paused' && in_array($this->run()?->stop_reason, ['execution_limit', 'dispatch_failed', 'reading_failed', 'user_paused'], true))
            ->schema([Textarea::make('reason')->label('继续原因')->required()])
            ->action(function (array $data): void {
                $this->performContentAction(fn () => app(ContentGenerationService::class)->continue($this->run()->id, reason: $data['reason'], authorId: Filament::auth()->id()));
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
                $this->getRecord()->refresh();
            }), Action::make('refresh')->label('刷新状态')->action(function (): void {
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
                $this->getRecord()->refresh();
            }),
            Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }
}
