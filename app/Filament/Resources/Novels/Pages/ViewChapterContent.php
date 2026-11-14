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
use App\Models\Foreshadow;
use App\Models\NovelStage;
use App\Models\ProductionRun;
use App\Models\StoryEntity;
use App\Services\Pipeline\CanonicalBaselines;
use App\Services\Pipeline\CanonicalCommitService;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentPromptAudit;
use App\Services\Pipeline\ContentReadabilityMetrics;
use App\Services\Pipeline\ContentReadingAudit;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\NovelProgressQuery;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\RevisionWorkbench;
use App\Services\Pipeline\StyleSignals;
use App\Services\Pipeline\SubmissionPresenter;
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
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
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
        // 未指定章号时打开最近一章尚未正式提交的章节，全部已提交时打开最后一章，避免总停在第一章找不到待处理的候选。
        if (request()->query('chapter') === null) {
            $chapters = $this->getRecord()->chapters()->orderBy('number')->get(['number', 'formal_version_id']);
            $this->chapterNumber = $chapters->whereNull('formal_version_id')->last()?->number ?? $chapters->last()?->number ?? 1;
        }
    }

    /** 两种工作台共用的章节切换。 */
    private function selectChapterAction(): Action
    {
        return Action::make('selectChapter')->label('选择阅读章节')->schema([Select::make('number')->label('章节')
            ->options(fn (): array => $this->getRecord()->chapters()->orderBy('number')->get()->mapWithKeys(fn (Chapter $chapter): array => [$chapter->number => $chapter->numberedTitle()])->all())
            ->default(fn (): int => $this->chapterNumber)->required()])->action(function (array $data): void {
                $this->chapterNumber = (int) $data['number'];
                $this->resolvedRun = null;
                $this->resolvedFormalVersion = null;
            });
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
        // 卡片标题已是本章章名，去掉总览用的“第N章：”前缀；说明指向本页按钮而不是“正文工作台”。
        $detail = $this->run()?->stop_reason === 'awaiting_review' && $state['step'] === 'production'
            ? '读完正文后点右上角「记录全文核对」，勾选已阅读全文并写下结论；通过后右上角会出现「准备正式提交」。'
            : str_replace('在正文工作台', '在本页右上角', $state['detail']);

        return ['headline' => preg_replace('/^第\d+章：/u', '', $state['headline']), 'detail' => $detail, 'running' => $state['running']];
    }

    /** 同一请求缓存读取，刷新动作明确失效缓存。 */
    public function run(): ?ChapterGenerationRun
    {
        return $this->resolvedRun ??= ChapterGenerationRun::query()->whereHas('chapter', fn ($query) => $query->where('novel_id', $this->getRecord()->id)->where('number', $this->chapterNumber))->latest('id')->first();
    }

    /** 中文运行原因，不用技术错误码替代可理解的下一步。 */
    /** 逐点检查按冻结细纲还原推进点内容，作者无需对照内部编号。
     * @return list<array{title: string, verdict: string, plan: ?string, reason: string, evidence: list<string>}>
     */
    private function progressionPointRows(): array
    {
        $definitions = collect(data_get($this->run()?->snapshot, 'source_chapter.progression_points', []))->keyBy('id');
        $types = ['information' => '信息', 'decision' => '决定', 'relationship' => '关系', 'resource' => '资源', 'position' => '位置', 'risk' => '风险'];

        return collect(data_get($this->run()?->chapter->progression_result, 'points', []))->values()->map(function (array $point, int $index) use ($definitions, $types): array {
            $definition = $definitions->get($point['id']);
            $subject = $definition === null ? '' : ' · '.$definition['subject'].'（'.($types[$definition['change_type']] ?? '其他').'变化）';

            return [
                'title' => '推进点'.($index + 1).$subject,
                'verdict' => $point['met'] ? '完成' : '不足',
                'plan' => $definition === null ? null : $definition['before'].' → '.$definition['after'],
                'reason' => $point['reason'],
                'evidence' => $this->evidenceLines($point['evidence'] ?? []),
            ];
        })->all();
    }

    /** 复核结果回查运行冻结的原风险说明，编号只在原记录缺失时兜底显示。
     * @return list<array{title: string, verdict: string, finding: ?string, reason: string, evidence: list<string>}>
     */
    private function readingReviewRows(): array
    {
        $findings = data_get($this->run()?->config, 'recovery_findings', []);
        $types = [
            'repeated_text' => '重复原文', 'echoed_dialogue' => '叙述转述对白', 'possible_time_order' => '可能的时序倒退', 'check' => '独立检查问题',
            'review' => '上轮复核遗留', 'progression' => '推进不足', 'meta_text' => '写作说明混入正文', 'plan_copy' => '照抄细纲',
            'length' => '篇幅问题', 'readability_dialogue' => '对白占比偏低', 'readability_process' => '流程词偏多', 'whole_chapter' => '全章核对',
        ];

        return collect(data_get($this->run()?->chapter->progression_result, 'reading_reviews', []))->values()->map(function (array $review, int $index) use ($findings, $types): array {
            $finding = $findings[$review['id']] ?? null;

            return [
                'title' => '复核'.($index + 1).' · '.($finding === null ? $review['id'] : ($types[$finding['type'] ?? ''] ?? '其他风险')),
                'verdict' => match ($review['disposition'] ?? null) {
                    'resolved' => '已解决', 'benign' => '复核为合理表达', 'unresolved' => '未解决', default => $review['fixed'] ? '已解决' : '未解决',
                },
                'finding' => $finding['reason'] ?? null,
                'reason' => $review['reason'],
                'evidence' => $this->evidenceLines($review['evidence'] ?? []),
            ];
        })->all();
    }

    /** 章末钩子作为单条结果展示，对照本运行细纲钩子判断是否按计划触发。
     * @return list<array{title: string, verdict: string, plan: ?string, reason: string, evidence: list<string>}>
     */
    private function hookRows(): array
    {
        $hook = data_get($this->run()?->chapter->progression_result, 'hook');
        if ($hook === null) {
            return [];
        }

        return [[
            'title' => '章末钩子',
            'verdict' => $hook['met'] ? '已触发' : '未触发',
            'plan' => data_get($this->run()?->plan_data, 'hook') ?? data_get($this->run()?->snapshot, 'source_chapter.hook'),
            'reason' => $hook['reason'],
            'evidence' => $this->evidenceLines($hook['evidence'] ?? []),
        ]];
    }

    /** 逐项复核卡片的共用结构，全文复核与工作台复评保持同一种显示。
     * @return list<Component>
     */
    private function readingReviewSchema(): array
    {
        return [
            Flex::make([
                TextEntry::make('title')->hiddenLabel()->weight('bold'),
                TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => match ($state) {
                    '已解决' => 'success', '复核为合理表达' => 'info', default => 'danger',
                }),
            ])->verticallyAlignCenter(),
            TextEntry::make('finding')->label('原风险')->placeholder('原风险记录未保留'),
            TextEntry::make('reason')->label('复核理由'),
            TextEntry::make('evidence')->label('当前正文证据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
        ];
    }

    /** 工作台轮次拆成标题、状态标签和处理边界说明。
     * @return array{title: string, verdict: string, note: string}
     */
    private function revisionRounds(): array
    {
        $run = $this->run();
        if ($run === null) {
            return ['title' => '尚无正文候选', 'verdict' => '不可修订', 'note' => '生成正文候选后才能指定问题修订'];
        }
        $workbench = app(RevisionWorkbench::class);
        $count = $workbench->rounds($run->chapter_id);

        return [
            'title' => '已授权 '.$count.' / '.RevisionWorkbench::ROUND_LIMIT.' 轮',
            'verdict' => match (true) {
                $run->chapter->formal_version_id !== null => '正式版本只读',
                $count >= RevisionWorkbench::ROUND_LIMIT => '转人工处理',
                default => '可发起修订',
            },
            'note' => $workbench->status($run),
        ];
    }

    /** 连贯性问题按类型给出中文标题，命中即须修订。
     * @return list<array{title: string, verdict: string, reason: string, evidence: list<string>}>
     */
    private function continuityRows(): array
    {
        $types = ['meta_text' => '写作说明混入正文', 'repeated_action' => '重复动作', 'time_order' => '时序倒退', 'hook_mismatch' => '钩子错位'];

        return collect(data_get($this->run()?->chapter->progression_result, 'continuity_issues', []))->values()->map(fn (array $issue, int $index): array => [
            'title' => '问题'.($index + 1).' · '.($types[$issue['type'] ?? ''] ?? '其他连贯性问题'),
            'verdict' => '需修订',
            'reason' => $issue['reason'],
            'evidence' => $this->evidenceLines($issue['evidence'] ?? []),
        ])->all();
    }

    /** 核心冲突拆出被违反的设定规则与具体说明；旧格式无法拆分时整段作为说明。
     * @return list<array{title: string, verdict: string, rule: ?string, reason: string, evidence: list<string>}>
     */
    private function conflictRows(): array
    {
        $result = $this->run()?->chapter->progression_result;
        $violations = data_get($result, 'setting_violations', []);

        return collect(data_get($result, 'conflicts', []))->values()->map(function (array $conflict, int $index) use ($violations): array {
            $rule = null;
            $reason = $conflict['reason'];
            foreach ($violations as $violation) {
                $prefix = '规则'.$violation['rule_id'].'：';
                $suffix = '；'.$violation['reason'];
                if (str_starts_with($reason, $prefix) && str_ends_with($reason, $suffix)) {
                    $rule = mb_substr($reason, mb_strlen($prefix), mb_strlen($reason) - mb_strlen($prefix) - mb_strlen($suffix));
                    $reason = $violation['reason'];
                    break;
                }
            }

            return [
                'title' => '冲突'.($index + 1).($rule === null ? '' : ' · 违反设定规则'),
                'verdict' => '核心冲突',
                'rule' => $rule,
                'reason' => $reason,
                'evidence' => $this->evidenceLines($conflict['evidence'] ?? []),
            ];
        })->all();
    }

    /** 跨章文风提示按类型给出中文标题，只作参考。
     * @return list<array{title: string, verdict: string, text: string, chapters: string}>
     */
    private function styleHintRows(): array
    {
        if ($this->displayedVersion() === null) {
            return [];
        }
        $types = ['overused_phrase' => '沿用近章反复出现的短语', 'similar_opening' => '开头与近章雷同', 'similar_ending' => '结尾与近章雷同'];

        return collect(StyleSignals::inspect($this->displayedVersion()->body, $this->run()->snapshot['style_signals']))->values()->map(fn (array $hint, int $index): array => [
            'title' => '提示'.($index + 1).' · '.($types[$hint['type']] ?? '文风雷同'),
            'verdict' => '仅供参考',
            'text' => $hint['text'],
            'chapters' => '第'.implode('、', $hint['chapters']).'章',
        ])->all();
    }

    /** 细纲场景以“时间 · 地点”作标题，推进点编号换成与逐点检查一致的序号。
     * @return list<array<string, ?string>>
     */
    private function sceneRows(): array
    {
        return collect(data_get($this->run()?->plan_data, 'scenes', []))->values()->map(fn (array $scene, int $index): array => [
            'title' => '场景'.($index + 1).' · '.$scene['time'].' · '.$scene['place'],
            'points' => $this->pointLabels($scene['point_ids'] ?? []),
            'goal' => $scene['goal'] ?? null,
            'action' => $scene['action'],
            'obstacle' => $scene['obstacle'] ?? null,
            'key_dialogue' => $scene['key_dialogue'] ?? null,
            'emotional_beat' => $scene['emotional_beat'] ?? null,
            'change' => $scene['change'],
        ])->all();
    }

    /** 细纲钩子与衔接说明合成一条，便于对照章末钩子检查。
     * @return list<array<string, ?string>>
     */
    private function planHookRows(): array
    {
        $plan = $this->run()?->plan_data;
        if (! isset($plan['hook'])) {
            return [];
        }

        return [[
            'title' => '章末钩子',
            'points' => $this->pointLabels(data_get($plan, 'hook_transition.point_ids', [])),
            'hook' => $plan['hook'],
            'completed_state' => data_get($plan, 'hook_transition.completed_state'),
            'trigger' => data_get($plan, 'hook_transition.trigger'),
            'next_question' => data_get($plan, 'hook_transition.next_question'),
        ]];
    }

    /** 推进点编号按冻结细纲顺序显示为“推进点N”，未知编号原样保留。
     * @param  list<string>  $pointIds
     */
    private function pointLabels(array $pointIds): ?string
    {
        if ($pointIds === []) {
            return null;
        }
        $order = collect(data_get($this->run()?->snapshot, 'source_chapter.progression_points', []))->pluck('id')->flip();

        return '对应'.implode('、', array_map(fn (string $id): string => $order->has($id) ? '推进点'.($order->get($id) + 1) : $id, $pointIds));
    }

    /** 证据压缩成“第N段：原文”单行，避免嵌套卡片占满页面。
     * @param  list<array{paragraph: ?int, quote: string}>  $evidence
     * @return list<string>
     */
    private function evidenceLines(array $evidence): array
    {
        return array_map(fn (array $item): string => (($item['paragraph'] ?? null) === null ? '' : '第'.$item['paragraph'].'段：').$item['quote'], $evidence);
    }

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
            Section::make(fn (): string => $this->getRecord()->chapters()->where('number', $this->chapterNumber)->first()?->numberedTitle() ?? '第'.$this->chapterNumber.'章')->extraAttributes(fn (): array => ($this->verdict()['running'] ?? false) ? ['wire:poll.5s' => ''] : [])->schema([
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
                        RepeatableEntry::make('readability')->label('可读性指标')->state(fn (): array => $this->readabilityRows())->placeholder('尚无正文')
                            ->helperText('对白低于'.round(100 * ContentReadabilityMetrics::MIN_DIALOGUE_SHARE).'%或流程词每千字超过'.ContentReadabilityMetrics::MAX_PROCESS_DENSITY.'时自动送修订；其余指标只作观察。')->schema([
                                Flex::make([
                                    TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                    TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => match ($state) {
                                        '正常' => 'success', '仅观察' => 'gray', default => 'danger',
                                    }),
                                ])->verticallyAlignCenter(),
                                TextEntry::make('note')->label('说明'),
                            ]),
                        TextEntry::make('verdict')->label('推进检查结论')->state(fn (): string => match (data_get($this->run()?->chapter->progression_result, 'verdict')) {
                            'pass' => '通过，仍待后续评审', 'insufficient' => '推进不足', 'blocked' => '存在核心设定冲突', default => '尚无可信检查结果'
                        }),
                        RepeatableEntry::make('reading_issues')->label('本地全文复核提示')->state(fn (): array => $this->readingClueRows())
                            ->placeholder('未命中有限规则；仍须阅读全文，不能据此认定没有问题。')
                            ->helperText(fn (): ?string => $this->readingClueRows() === [] ? null : '发现重复或时序风险，须人工复核；模型通过不能视为全文验收通过。')->schema([
                                Flex::make([
                                    TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                    TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color('warning'),
                                ])->verticallyAlignCenter(),
                                TextEntry::make('reason')->label('复核说明'),
                                TextEntry::make('evidence')->label('原文位置')->listWithLineBreaks()->bulleted()->extraAttributes(['style' => 'line-height: 1.8']),
                            ]),
                        RepeatableEntry::make('style_hints')->label('跨章文风提示')->helperText('仅供参考，不作为问题判定。')->visible(fn (): bool => isset($this->run()?->snapshot['style_signals']))
                            ->state(fn (): array => $this->styleHintRows())->placeholder('当前正文未沿用近章反复出现的短语或开头结尾')->schema([
                                Flex::make([
                                    TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                    TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color('gray'),
                                ])->verticallyAlignCenter(),
                                TextEntry::make('text')->label('本章原文'),
                                TextEntry::make('chapters')->label('近章出现过的章节'),
                            ]),
                        RepeatableEntry::make('points')->label('逐点检查')->state(fn (): array => $this->progressionPointRows())->placeholder('尚无逐点检查结果')->schema([
                            Flex::make([
                                TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => $state === '完成' ? 'success' : 'danger'),
                            ])->verticallyAlignCenter(),
                            TextEntry::make('plan')->label('计划变化')->placeholder('冻结细纲中未找到该推进点'),
                            TextEntry::make('reason')->label('判断理由'),
                            TextEntry::make('evidence')->label('正文证据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                        ]),
                        RepeatableEntry::make('hook_result')->label('章末钩子检查')->state(fn (): array => $this->hookRows())->placeholder('尚无章末钩子检查结果')->schema([
                            Flex::make([
                                TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => $state === '已触发' ? 'success' : 'danger'),
                            ])->verticallyAlignCenter(),
                            TextEntry::make('plan')->label('计划钩子')->placeholder('冻结细纲中未找到钩子'),
                            TextEntry::make('reason')->label('判断理由'),
                            TextEntry::make('evidence')->label('正文证据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                        ]),
                        RepeatableEntry::make('reading_reviews')->label('逐项全文复核结果')->state(fn (): array => $this->readingReviewRows())->placeholder('本次检查没有需要复核的旧风险')->schema($this->readingReviewSchema()),
                        RepeatableEntry::make('continuity')->label('连贯性问题')->state(fn (): array => $this->continuityRows())->placeholder('未发现连贯性问题')->schema([
                            Flex::make([
                                TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color('warning'),
                            ])->verticallyAlignCenter(),
                            TextEntry::make('reason')->label('问题说明'),
                            TextEntry::make('evidence')->label('正文证据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                        ]),
                        TextEntry::make('tensions')->label('正常剧情矛盾（不阻塞）')->state(fn (): array => data_get($this->run()?->chapter->progression_result, 'story_tensions', []))->listWithLineBreaks(),
                        RepeatableEntry::make('conflicts')->label('核心冲突')->state(fn (): array => $this->conflictRows())->placeholder('未发现核心冲突')->schema([
                            Flex::make([
                                TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color('danger'),
                            ])->verticallyAlignCenter(),
                            TextEntry::make('rule')->label('违反的设定')->placeholder('未关联具体设定规则'),
                            TextEntry::make('reason')->label('冲突说明'),
                            TextEntry::make('evidence')->label('正文证据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                        ]),
                    ]),
                    Section::make('本章细纲候选')->schema([
                        RepeatableEntry::make('scenes')->label('场景安排')->state(fn (): array => $this->sceneRows())->placeholder('尚未生成细纲')->schema([
                            Flex::make([
                                TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                TextEntry::make('points')->hiddenLabel()->badge()->grow(false)->color('info'),
                            ])->verticallyAlignCenter(),
                            TextEntry::make('goal')->label('人物目标')->visible(fn (?string $state): bool => filled($state)),
                            TextEntry::make('action')->label('具体行动'),
                            TextEntry::make('obstacle')->label('阻碍')->visible(fn (?string $state): bool => filled($state)),
                            TextEntry::make('key_dialogue')->label('关键对白')->visible(fn (?string $state): bool => filled($state)),
                            TextEntry::make('emotional_beat')->label('情绪变化')->visible(fn (?string $state): bool => filled($state)),
                            TextEntry::make('change')->label('状态变化'),
                        ]),
                        RepeatableEntry::make('plan_hook')->label('章末钩子安排')->state(fn (): array => $this->planHookRows())->placeholder('尚未生成细纲')->schema([
                            Flex::make([
                                TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                TextEntry::make('points')->hiddenLabel()->badge()->grow(false)->color('info'),
                            ])->verticallyAlignCenter(),
                            TextEntry::make('hook')->label('衔接后的章末钩子'),
                            TextEntry::make('completed_state')->label('承接的已完成状态')->placeholder('无'),
                            TextEntry::make('trigger')->label('结尾触发事件')->placeholder('无'),
                            TextEntry::make('next_question')->label('下一章悬念')->placeholder('无'),
                        ]),
                        TextEntry::make('adjustments')->label('规划衔接调整')->state(fn (): array => data_get($this->run()?->plan_data, 'adjustments', []))->listWithLineBreaks()->bulleted()->placeholder('无调整')->extraAttributes(['style' => 'line-height: 1.8']),
                    ]),
                ]),
                Tab::make('检查、评审与提交')->schema([
                    Section::make('评审、事件提取与正式提交')->schema([
                        Flex::make([
                            TextEntry::make('submission_title')->hiddenLabel()->weight('bold')->state(fn (): string => $this->submission() === null ? '提交准备' : '提交准备 #'.$this->submission()->id.' · 已用请求 '.$this->submission()->requests_used.' / 2'),
                            TextEntry::make('submission_state')->hiddenLabel()->badge()->grow(false)->state(fn (): string => match ($this->submission()?->status) {
                                'pending' => '准备已授权，等待执行', 'running' => '正在评审或提取', 'failed' => '准备失败，已停止且未自动重试',
                                'awaiting_approval' => '硬检查通过，等待作者批准新实体', 'ready' => '准备通过，可正式提交', 'committed' => '已正式提交', default => '尚未准备',
                            })->color(fn (): string => match ($this->submission()?->status) {
                                'committed', 'ready' => 'success', 'failed' => 'danger', 'awaiting_approval' => 'warning', 'pending', 'running' => 'info', default => 'gray',
                            }),
                        ])->verticallyAlignCenter(),
                        TextEntry::make('submission_reason')->label('停止原因')->state(fn (): ?string => ProductionFailure::detailLabel($this->submission()?->stop_reason))->visible(fn (): bool => $this->submission()?->stop_reason !== null),
                        Section::make('基础评审')->compact()->schema([
                            TextEntry::make('review_verdict')->hiddenLabel()->badge()->state(fn (): string => $this->submissionView()['review']['verdict'] ?? '尚无记录')
                                ->color(fn (string $state): string => match ($state) {
                                    '评审通过' => 'success', '需要修订' => 'danger', default => 'gray',
                                }),
                            TextEntry::make('review_summary')->label('评审摘要')->state(fn (): ?string => $this->submissionView()['review']['summary'] ?? null)->visible(fn (): bool => filled($this->submissionView()['review']['summary'] ?? null)),
                            RepeatableEntry::make('review_findings')->label('评审问题（阻塞在前）')->state(fn (): array => $this->reviewFindingRows())->placeholder('没有评审问题')
                                ->visible(fn (): bool => $this->submissionView()['review'] !== null)->schema([
                                    Flex::make([
                                        TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                        TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => $state === '阻塞' ? 'danger' : 'warning'),
                                    ])->verticallyAlignCenter(),
                                    TextEntry::make('reason')->label('问题说明'),
                                    TextEntry::make('evidence')->label('原文依据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                                ]),
                        ]),
                        Section::make('事件提取')->compact()->collapsible()->description(fn (): ?string => $this->extractionCounts())->schema([
                            TextEntry::make('extraction_empty')->hiddenLabel()->state('尚无记录')->color('gray')->visible(fn (): bool => $this->submissionView()['extraction'] === null),
                            RepeatableEntry::make('extraction_foreshadows')->label('伏笔流转')->state(fn (): array => $this->extractionRows('foreshadows'))
                                ->visible(fn (): bool => $this->extractionRows('foreshadows') !== [])->schema([
                                    Flex::make([
                                        TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                        TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color('primary'),
                                    ])->verticallyAlignCenter(),
                                    TextEntry::make('reason')->label('说明'),
                                    TextEntry::make('evidence')->label('原文依据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                                ]),
                            RepeatableEntry::make('extraction_entities')->label('新实体（需批准）')->state(fn (): array => $this->extractionRows('entities'))
                                ->visible(fn (): bool => $this->extractionRows('entities') !== [])->schema([
                                    Flex::make([
                                        TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                        TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color('warning'),
                                    ])->verticallyAlignCenter(),
                                    TextEntry::make('reason')->label('实体定义'),
                                    TextEntry::make('evidence')->label('原文依据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                                ]),
                            RepeatableEntry::make('extraction_changes')->label('状态变化')->state(fn (): array => $this->extractionRows('changes'))
                                ->visible(fn (): bool => $this->extractionRows('changes') !== [])->schema([
                                    Flex::make([
                                        TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                        TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color('gray'),
                                    ])->verticallyAlignCenter(),
                                    TextEntry::make('reason')->label('变化'),
                                ]),
                            RepeatableEntry::make('extraction_events')->label('事件（按叙事顺序）')->state(fn (): array => $this->extractionRows('events'))->placeholder('未提取到事件。')
                                ->visible(fn (): bool => $this->submissionView()['extraction'] !== null)->schema([
                                    Flex::make([
                                        TextEntry::make('title')->hiddenLabel()->weight('bold'),
                                        TextEntry::make('verdict')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => $state === '当前' ? 'gray' : 'warning'),
                                    ])->verticallyAlignCenter(),
                                    TextEntry::make('reason')->label('事件经过'),
                                    TextEntry::make('people')->label('主体与对象'),
                                    TextEntry::make('evidence')->label('原文依据')->listWithLineBreaks()->bulleted()->placeholder('无引文')->extraAttributes(['style' => 'line-height: 1.8']),
                                ]),
                        ]),
                        Section::make('确定性验证')->compact()->schema([
                            TextEntry::make('validation_verdict')->hiddenLabel()->badge()->state(fn (): string => match ($this->submissionView()['validation']['passed'] ?? null) {
                                true => '通过', false => '未通过', default => '尚无记录',
                            })->color(fn (string $state): string => match ($state) {
                                '通过' => 'success', '未通过' => 'danger', default => 'gray',
                            }),
                            TextEntry::make('validation_errors')->label('校验错误')->state(fn (): array => $this->submissionView()['validation']['errors'] ?? [])->listWithLineBreaks()->bulleted()->color('danger')
                                ->visible(fn (): bool => ($this->submissionView()['validation']['errors'] ?? []) !== []),
                            TextEntry::make('validation_approvals')->label('需要作者批准')->state(fn (): array => $this->submissionView()['validation']['approvals'] ?? [])->listWithLineBreaks()->bulleted()
                                ->visible(fn (): bool => ($this->submissionView()['validation']['approvals'] ?? []) !== []),
                            TextEntry::make('validation_clean')->hiddenLabel()->state('没有校验错误，也没有需要作者批准的实体。')->color('gray')
                                ->visible(fn (): bool => $this->submissionView()['validation'] !== null && $this->submissionView()['validation']['errors'] === [] && $this->submissionView()['validation']['approvals'] === []),
                        ]),
                        Section::make('正式提交回执')->compact()->schema([
                            TextEntry::make('commit_state')->hiddenLabel()->badge()->state(fn (): string => $this->submissionView()['commit'] === null ? '尚未正式提交' : '已提交')
                                ->color(fn (string $state): string => $state === '已提交' ? 'success' : 'gray'),
                            TextEntry::make('commit_rows')->hiddenLabel()->state(fn (): array => array_map(fn (array $row): string => $row['label'].'：'.$row['value'], $this->submissionView()['commit'] ?? []))
                                ->listWithLineBreaks()->bulleted()->extraAttributes(['style' => 'overflow-wrap: anywhere; line-height: 1.8'])->visible(fn (): bool => $this->submissionView()['commit'] !== null),
                        ]),
                    ]),
                    Section::make('评审与定点修订工作台')->description('指定问题只生成候选；范围外原文保留，修订后独立复评，不替代正式提交评审。')->schema([
                        Flex::make([
                            TextEntry::make('revision_rounds')->hiddenLabel()->weight('bold')->state(fn (): string => $this->revisionRounds()['title']),
                            TextEntry::make('revision_round_state')->hiddenLabel()->badge()->grow(false)->state(fn (): string => $this->revisionRounds()['verdict'])
                                ->color(fn (string $state): string => match ($state) {
                                    '可发起修订' => 'success', '转人工处理' => 'warning', default => 'gray',
                                }),
                        ])->verticallyAlignCenter(),
                        TextEntry::make('revision_status')->label('处理边界')->state(fn (): string => $this->revisionRounds()['note']),
                        TextEntry::make('revision_issues')->label('本轮指定问题')->state(fn (): array => data_get($this->run()?->config, 'workbench.issues', []))
                            ->hint(fn (): ?string => data_get($this->run()?->config, 'workbench.version_id') === null ? null : '正文版本 #'.data_get($this->run()?->config, 'workbench.version_id'))
                            ->listWithLineBreaks()->bulleted()->placeholder('当前运行不是工作台修订，尚未指定问题')->extraAttributes(['style' => 'line-height: 1.8']),
                        Flex::make([
                            TextEntry::make('revision_review_title')->hiddenLabel()->weight('bold')->state(fn (): string => '复评版本 #'.data_get($this->run()?->chapter->progression_result, 'version_id')),
                            TextEntry::make('revision_review_verdict')->hiddenLabel()->badge()->grow(false)->state(fn (): string => match (data_get($this->run()?->chapter->progression_result, 'verdict')) {
                                'pass' => '复评通过，等待全文核对', 'blocked' => '存在阻塞问题', default => '仍有待解决问题',
                            })->color(fn (string $state): string => match ($state) {
                                '复评通过，等待全文核对' => 'success', '存在阻塞问题' => 'danger', default => 'warning',
                            }),
                        ])->verticallyAlignCenter()->visible(fn (): bool => $this->run() !== null && app(RevisionWorkbench::class)->reviewCompleted($this->run())),
                        RepeatableEntry::make('revision_review')->label('独立复评结果')
                            ->state(fn (): array => $this->run() !== null && app(RevisionWorkbench::class)->reviewCompleted($this->run()) ? $this->readingReviewRows() : [])
                            ->placeholder(fn (): string => $this->run() === null ? '尚无正文候选' : (app(RevisionWorkbench::class)->reviewCompleted($this->run()) ? '本轮复评没有逐项结论' : '本轮独立复评尚未完成'))
                            ->schema($this->readingReviewSchema()),
                    ]),
                ]),
                Tab::make('运行与历史')->schema([
                    // 第一屏只放运行状态、当前步骤、细纲来源与停止原因；请求、额度与冻结参数统一收进底部记录。
                    Section::make('章节运行')->compact()->description(fn (): string => $this->candidate()?->id !== null && $this->run()?->chapter->formal_version_id === $this->candidate()->id ? '该版本已正式提交。' : '正文候选须经提交准备与作者批准才成为正式稿。')->schema([
                        Flex::make([
                            TextEntry::make('run_status')->hiddenLabel()->badge()->grow(false)->state(fn (): string => self::runStatusLabel($this->run()?->status))
                                ->color(fn (): string => match ($this->run()?->status) {
                                    'completed' => 'success', 'running', 'pending' => 'info', 'paused' => 'warning', 'error' => 'danger', default => 'gray'
                                }),
                            TextEntry::make('step')->hiddenLabel()->badge()->grow(false)->color('gray')->state(fn (): string => '当前步骤：'.match ($this->run()?->next_step) {
                                'fine' => '章节细纲', 'draft' => '正文生成', 'check' => '独立推进检查', 'revise' => '推进点修订', 'length_repair' => '定点扩写', default => '无'
                            }),
                            TextEntry::make('reused_fine')->hiddenLabel()->color('gray')->size('sm')->state(fn (): string => $this->fineSourceLabel()),
                        ])->verticallyAlignCenter()->extraAttributes(['style' => 'flex-wrap: wrap']),
                        TextEntry::make('stop')->label('停止原因与下一步')->weight('medium')->state(fn (): string => $this->stopLabel()),
                    ]),
                    EmbeddedTable::make(),
                    Section::make('请求与费用记录')->key('content-attempts')->collapsible()->collapsed()->compact()
                        ->description(fn (): string => '本轮已领用 '.($this->run()?->requests_used ?? 0).' / '.($this->run()?->request_limit ?? 0).' 次 · 提交准备 '.($this->submission()?->requests_used ?? 0).' / 2 次 · 共 '.count($this->attempts()).' 次生成请求')
                        ->headerActions([
                            Action::make('viewPrompts')->label('查看完整Prompt')->link()->modalHeading('完整Prompt与请求契约')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->modalWidth('7xl')->schema(fn (): array => [
                                TextEntry::make('prompts')->label('调用前快照与已验证历史还原')->state(fn (): string => json_encode(CostLog::where('novel_id', $this->getRecord()->id)->whereHas('novelStage', fn ($query) => $query->whereNotNull('input->run_id'))->orderBy('id')->get()->map(fn (CostLog $log): array => ['cost_log_id' => $log->id, ...app(ContentPromptAudit::class)->read($log)])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']),
                            ]),
                        ])->schema([
                            Grid::make(['default' => 1, 'md' => 3])->schema([
                                TextEntry::make('requests')->label('已领用 / 请求上限')->state(fn (): string => ($this->run()?->requests_used ?? 0).' / '.($this->run()?->request_limit ?? 0)),
                                TextEntry::make('submission_requests')->label('提交准备请求（评审与提取）')->state(fn (): string => ($this->submission()?->requests_used ?? 0).' / 2'),
                                TextEntry::make('shared_requests')->label('本轮质量修复合计请求')->state(fn (): string => data_get($this->run()?->config, 'shared_request_limit') === null ? '按本运行额度执行' : ((int) data_get($this->run()?->config, 'parent_requests_used') + $this->run()->requests_used).' / '.data_get($this->run()?->config, 'shared_request_limit')),
                                TextEntry::make('repair_mode')->label('本轮修复方式')->state(fn (): string => $this->repairModeLabel()),
                                TextEntry::make('model')->label('冻结模型')->state(fn (): ?string => data_get($this->run()?->config, 'model'))->placeholder('—'),
                                TextEntry::make('tokens')->label('正文输出Token上限')->state(fn (): ?int => data_get($this->run()?->config, 'max_output_tokens'))->placeholder('—'),
                                TextEntry::make('budget')->label('冻结段落预算')->state(fn (): string => $this->budgetDescription())->columnSpanFull(),
                                TextEntry::make('continuous_scope')->label('持续验收范围')->color('gray')->state(fn (): string => ($this->run()?->config['version'] ?? null) === ContentExecutionContract::VERSION ? '本授权内最多一次修订，请求上限不可通过继续操作扩大' : '历史协议，只读保留；恢复会创建有限运行')->columnSpanFull(),
                            ]),
                            RepeatableEntry::make('attempts')->label('逐次请求')->placeholder('尚无请求')->state(fn (): array => $this->attempts())->schema([
                                Flex::make([
                                    TextEntry::make('step')->hiddenLabel()->weight('bold')->grow(false),
                                    TextEntry::make('status')->hiddenLabel()->badge()->color('gray')->grow(false),
                                    TextEntry::make('cost')->hiddenLabel()->color('gray')->size('sm')->grow(false),
                                ])->verticallyAlignCenter()->extraAttributes(['style' => 'flex-wrap: wrap']),
                                TextEntry::make('error')->hiddenLabel()->color('danger')->visible(fn (?string $state): bool => filled($state)),
                                TextEntry::make('validation_error')->label('具体校验原因')->visible(fn (?string $state): bool => filled($state)),
                                TextEntry::make('quality_issues')->label('本地正文质量问题')->visible(fn (?string $state): bool => filled($state)),
                            ]),
                            RepeatableEntry::make('runs')->label('历次运行')->state(fn (): array => $this->run() === null ? [] : ChapterGenerationRun::query()->where('chapter_id', $this->run()->chapter_id)->orderBy('id')->get()->values()->map(fn (ChapterGenerationRun $run, int $index): array => [
                                'line' => '第 '.($index + 1).' 次运行 · '.self::runStatusLabel($run->status, true).' · 领用 '.$run->requests_used.' / '.$run->request_limit
                                    .' · #'.$run->id.($run->parent_run_id === null ? '' : ' ← #'.$run->parent_run_id).($run->source_version_id === null ? '' : ' · 扩写源 #'.$run->source_version_id),
                                'hook' => data_get($run->plan_data, 'hook'), 'reason' => data_get($run->control_history, '0.reason'),
                            ])->all())->schema([
                                TextEntry::make('line')->hiddenLabel(),
                                TextEntry::make('hook')->label('细纲钩子')->inlineLabel()->color('gray')->visible(fn (?string $state): bool => filled($state)),
                                TextEntry::make('reason')->label('操作原因')->inlineLabel()->color('gray')->visible(fn (?string $state): bool => filled($state)),
                            ]),
                        ]),
                ]),
            ]),
        ]);
    }

    /** 运行状态中文名；历史列表把其余状态统一称为“已停止”。 */
    private static function runStatusLabel(?string $status, bool $history = false): string
    {
        return match ($status) {
            'pending' => '待处理', 'running' => '运行中', 'completed' => '候选检查完成', 'paused' => $history ? '已停止' : '已暂停', 'error' => $history ? '已停止' : '异常', null => '尚未启动', default => '已停止'
        };
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

    /** 可读性逐项展示实测值；只有对白占比和流程词密度会触发修订，其余仅观察。
     * @return list<array{title: string, verdict: string, note: string}>
     */
    private function readabilityRows(): array
    {
        $body = $this->displayedVersion()?->body;
        if ($body === null) {
            return [];
        }
        $metrics = ContentReadabilityMetrics::measure($body);
        $issues = collect(ContentReadabilityMetrics::issues($metrics))->keyBy('type');

        return [
            ['title' => '直接对白占比：'.round(100 * $metrics['dialogue_share'], 1).'%', 'verdict' => $issues->has('readability_dialogue') ? '过低' : '正常',
                'note' => $issues->get('readability_dialogue')['reason'] ?? '不低于'.round(100 * ContentReadabilityMetrics::MIN_DIALOGUE_SHARE).'%即可，低于时自动送修订'],
            ['title' => '流程词密度：每千字'.$metrics['process_density'].'个', 'verdict' => $issues->has('readability_process') ? '过高' : '正常',
                'note' => $issues->get('readability_process')['reason'] ?? '核对、登记、编号等流程词每千字不超过'.ContentReadabilityMetrics::MAX_PROCESS_DENSITY.'个即可，超过时自动送修订'],
            ['title' => '“没……也没……”句式：'.$metrics['disclaimers'].'处', 'verdict' => '仅观察', 'note' => '连续否定句过多会显得拖沓，只作观察，不触发修订'],
            ['title' => '转述对白：约'.$metrics['reported_speech'].'处', 'verdict' => '仅观察', 'note' => '按“说、问、提醒”等动词估算，转述多可考虑改成直接对白；只作观察，不触发修订'],
        ];
    }

    /** 本地规则命中的阅读线索；只提示人工复核位置，不判定问题成立。
     * @return list<array{title: string, verdict: string, reason: string, evidence: list<string>}>
     */
    private function readingClueRows(): array
    {
        $body = $this->displayedVersion()?->body;
        if ($body === null) {
            return [];
        }

        return collect(ContentReadingAudit::inspect($body)['issues'])->values()->map(fn (array $issue, int $index): array => [
            'title' => '线索'.($index + 1).' · '.($issue['type'] === 'repeated_text' ? '重复原文' : '可能的时序倒退'),
            'verdict' => '须人工复核',
            'reason' => $issue['reason'],
            'evidence' => $this->evidenceLines($issue['evidence']),
        ])->all();
    }

    /** 预算预览与运行使用同一分配算法，显示可核验的整章范围。 */
    private function budgetDescription(): string
    {
        $run = $this->run();
        if ($run !== null && ($run->config['version'] ?? null) === ContentExecutionContract::VERSION && $run->plan_data !== null) {
            $budget = ContentExecutionContract::budget($run->snapshot['novel']['chapter_length_policy'], $run->plan_data, $run->config['enable_fine_outline']);

            return '整章生成预算：'.$budget['count'].($run->config['enable_fine_outline'] ? '个场景，每个场景' : '个分节，每节').$budget['min'].'～'.$budget['max'].'字符；局部修订遵守整章篇幅';
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
                'status' => $stage->status->label(), 'log_id' => $log?->id, 'cost' => ($log?->billing_status === 'estimated' ? '估算 '.$log->estimated_cost.' '.$log->currency : '金额未知').' · #'.$stage->id.($log === null ? '' : ' / 日志 #'.$log->id), 'error' => $stage->error_message, 'validation_error' => data_get($stage->output, 'data.validation_error'),
                'quality_issues' => collect(data_get($stage->output, 'data.quality_issues', []))->map(fn (array $issue): string => ($issue['anchor_id'] ?? '').' '.$issue['reason'])->implode('；')];
        })->all();
    }

    /** 本轮实际的修复方式：按运行起始步骤与来源判断，不读取未写入的字段。 */
    private function repairModeLabel(): string
    {
        $run = $this->run();
        $recovering = $run?->parent_run_id !== null;

        return match (true) {
            $run === null => '尚未启动',
            ($run->config['initial_step'] ?? null) === 'revise' => '局部修订',
            ($run->config['initial_step'] ?? null) === 'check' => '只做复查',
            $recovering && data_get($run->snapshot, 'recovery.source_version_id') !== null => '整章重写',
            $recovering && isset($run->config['recovery_draft']) => '按失败草稿逐场景修复',
            $recovering => '重新生成',
            default => '首次生成',
        };
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
    private function recoveryCap(bool $rewrite = false): int
    {
        // 已有候选时默认定点修订，只需修订和检查各一次；整章重写按生成的完整额度，便于不合格时在运行内修复。
        if (! $rewrite && $this->run()?->chapter->current_candidate_version_id !== null) {
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

    /** 提交准备展示数据按准备记录的编号与更新时间缓存，避免每个条目重复查询；记录变化后自动重算。 */
    private ?string $submissionViewKey = null;

    /** @var array{review: ?array<string, mixed>, extraction: ?array<string, mixed>, validation: ?array<string, mixed>, commit: ?list<array{label: string, value: string}>}|null */
    private ?array $submissionViewCache = null;

    /** 评审、提取、验证与提交回执的可读分区数据；尚未准备时各分区为空。
     * @return array{review: ?array<string, mixed>, extraction: ?array<string, mixed>, validation: ?array<string, mixed>, commit: ?list<array{label: string, value: string}>}
     */
    private function submissionView(): array
    {
        $run = $this->submission();
        $key = $run === null ? 'none' : $run->id.'|'.$run->status.'|'.$run->updated_at?->toIso8601String();
        if ($this->submissionViewCache !== null && $this->submissionViewKey === $key) {
            return $this->submissionViewCache;
        }
        $this->submissionViewKey = $key;
        if ($run === null) {
            return $this->submissionViewCache = ['review' => null, 'extraction' => null, 'validation' => null, 'commit' => null];
        }
        $novelId = $this->getRecord()->id;

        return $this->submissionViewCache = SubmissionPresenter::present(
            ChapterReview::where('submission_run_id', $run->id)->first()?->result,
            ChapterEventExtraction::where('submission_run_id', $run->id)->first()?->result,
            ChapterSubmissionValidation::where('submission_run_id', $run->id)->latest('id')->first()?->result,
            CanonicalCommit::where('submission_run_id', $run->id)->first()?->result,
            StoryEntity::where('novel_id', $novelId)->pluck('name', 'id')->all(),
            Foreshadow::where('novel_id', $novelId)->pluck('title', 'id')->all(),
        );
    }

    /** 评审问题卡片：阻塞在前，类型作标题。
     * @return list<array{title: string, verdict: string, reason: string, evidence: list<string>}>
     */
    private function reviewFindingRows(): array
    {
        return collect($this->submissionView()['review']['findings'] ?? [])->values()->map(fn (array $finding, int $index): array => [
            'title' => '问题'.($index + 1).' · '.$finding['kind'],
            'verdict' => $finding['blocking'] ? '阻塞' : '建议',
            'reason' => $finding['reason'],
            'evidence' => $this->evidenceLines($finding['quotes']),
        ])->all();
    }

    /** 事件提取四类结果统一成“标题 + 标签 + 说明 + 证据”卡片。
     * @return list<array<string, mixed>>
     */
    private function extractionRows(string $kind): array
    {
        $items = collect($this->submissionView()['extraction'][$kind] ?? [])->values();

        return match ($kind) {
            'foreshadows' => $items->map(fn (array $item, int $index): array => ['title' => '伏笔'.($index + 1).' · '.$item['title'], 'verdict' => $item['action'],
                'reason' => $item['explanation'], 'evidence' => $this->evidenceLines($item['quotes'])])->all(),
            'entities' => $items->map(fn (array $item, int $index): array => ['title' => '新实体'.($index + 1), 'verdict' => '待批准',
                'reason' => $item['label'], 'evidence' => $this->evidenceLines($item['quotes'])])->all(),
            'changes' => $items->map(fn (array $item): array => ['title' => $item['entity'].' · '.$item['property'], 'verdict' => $item['category'] ?? '状态',
                'reason' => ($item['before'] !== null ? $item['before'].' → ' : '').$item['after']])->all(),
            default => $items->map(fn (array $item, int $index): array => [
                'title' => '事件'.($item['order'] ?? $index + 1).collect([$item['type'], $item['category']])->filter()->map(fn (string $label): string => ' · '.$label)->implode(''),
                'verdict' => $item['time'] ?? '当前',
                'reason' => $item['summary'],
                'people' => ($item['actor'] ?? '未标主体').($item['targets'] !== [] ? ' → '.implode('、', $item['targets']) : ''),
                'evidence' => $this->evidenceLines($item['quotes']),
            ])->all(),
        };
    }

    /** 事件提取分区标题下的数量概览。 */
    private function extractionCounts(): ?string
    {
        $extraction = $this->submissionView()['extraction'];
        if ($extraction === null) {
            return null;
        }

        return collect(['事件' => $extraction['events'], '状态变化' => $extraction['changes'], '伏笔流转' => $extraction['foreshadows'], '新实体' => $extraction['entities']])
            ->filter(fn (array $items, string $label): bool => $items !== [] || in_array($label, ['事件', '状态变化'], true))
            ->map(fn (array $items, string $label): string => $label.' '.count($items))->implode(' · ');
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
            $options[$proposal['key']] = SubmissionPresenter::proposalLabel($proposal);
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
            return [$this->selectChapterAction(), Action::make('automationRun')->label('自动生成进度 / 恢复')->url(fn (): string => NovelResource::getUrl('run', ['record' => $this->getRecord()])),
                Action::make('refresh')->label('刷新状态')->action(function (): void {
                    $this->resolvedRun = null;
                    $this->resolvedFormalVersion = null;
                    $this->getRecord()->refresh();
                }), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
        }

        return [$this->selectChapterAction(), Action::make('reviseIssues')->label('针对指定问题修订')
            ->visible(fn (): bool => $this->run() !== null && $this->candidate() !== null && $this->run()->chapter->formal_version_id === null
                && in_array($this->run()->status, ['completed', 'paused', 'error'], true)
                && app(RevisionWorkbench::class)->rounds($this->run()->chapter_id) < RevisionWorkbench::ROUND_LIMIT)
            ->modalHeading('指定问题并授权一轮定点修订')
            ->modalDescription('每章工作台最多两轮；本轮最多两次真实请求：范围修订和独立复评各一次。旧正文与费用保留，失败不自动重发。')
            ->schema([
                TextInput::make('parent_id')->label('冻结运行编号')->default(fn (): ?int => $this->run()?->id)->readOnly()->required(),
                TextInput::make('version_id')->label('冻结正文版本')->default(fn (): ?int => $this->candidate()?->id)->readOnly()->required(),
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
            }), Action::make('submitInOneStep')->label('一键正式提交')->color('success')->visible(fn (): bool => $this->run()?->stop_reason === 'b04_accepted' && $this->submission() === null)
            ->modalHeading('评审、提取并正式提交')
            ->modalDescription('依次做一次独立评审和一次事件提取（最多两次模型请求）。评审通过且没有新实体待批准时自动正式提交；评审未通过或有新实体时停下，在本页处理。')
            ->schema([TextInput::make('execution_limit')->label('小说累计执行上限')->integer()->minValue(1)->maxValue(2147483647)->required()
                ->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + 2)),
                Textarea::make('reason')->label('提交原因')->required(),
                Checkbox::make('acknowledge')->label('我已核对当前正文，批准最多两次请求及费用，并授权评审通过后自动正式提交')->accepted()])
            ->action(function (array $data): void {
                $this->performContentAction(fn () => app(ChapterSubmissionService::class)->prepare($this->candidate()->id, Filament::auth()->user(),
                    app(CanonicalBaselines::class)->activeStateBefore($this->getRecord(), $this->candidate()->chapter->number), $data['reason'], (int) $data['execution_limit'],
                    autoCommit: ['reason' => $data['reason']]));
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
            }), Action::make('recoverContent')->label('恢复本章候选')->modalHeading('显式授权有限恢复')
            ->visible(fn (): bool => data_get($this->run()?->config, 'workbench') === null && in_array($this->run()?->status, ['paused', 'error', 'completed'], true) && $this->run()?->stop_reason !== 'b04_accepted' && ! ChapterGenerationRun::where('parent_run_id', $this->run()?->id)->exists())
            ->modalDescription('旧版本与费用全部保留，新建v14有限运行。范围修订只调用修订和检查各一次；选择重写时按冻结场景生成独立候选。总预算不能无限续用，未知结果须明确确认。')
            ->schema([
                // 整章重写需要为运行内修复留出额度，勾选后请求上限默认值随之调整，仍可手动修改。
                Checkbox::make('rewrite')->label('明确授权整章候选重写；不勾选时优先复用现有正文')->live()
                    ->afterStateUpdated(function (?bool $state, Set $set): void {
                        $set('request_limit', $this->recoveryCap((bool) $state));
                        $set('execution_limit', max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + $this->recoveryCap((bool) $state)));
                    }),
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
