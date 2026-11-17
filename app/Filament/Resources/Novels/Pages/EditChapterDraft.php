<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterReview;
use App\Models\ChapterVersion;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentQualityContract;
use App\Services\Pipeline\ContentReadingAudit;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\ManualContentDrafts;
use App\Services\Pipeline\ManualEditIssues;
use App\Services\Pipeline\ParagraphDiff;
use App\Services\Pipeline\RevisionWorkbench;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/** 人工编辑工作区：自动保存可变草稿，明确动作才另建不可变候选。 */
class EditChapterDraft extends Page
{
    use HasUnsavedDataChangesAlert;
    use InteractsWithRecord;

    /** 所属小说及编辑器视图。 */
    protected static string $resource = NovelResource::class;

    /** 中文标题。 */
    protected static ?string $title = '人工修改正文';

    /** 编辑器使用后台原生组件。 */
    protected string $view = 'filament.pages.edit-chapter-draft';

    /** 未完成自动保存时离开页面给出提醒。 */
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    /** 编辑字段，与来源令牌分离。 */
    public array $data = [];

    /** 当前章号不接受客户端修改。 */
    #[Locked]
    public int $chapterNumber = 1;

    /** 编辑来源版本由服务器确定。 */
    #[Locked]
    public ?int $versionId = null;

    /** 工作草稿的并发修订号。 */
    #[Locked]
    public int $revision = 0;

    /** 旧来源草稿必须由作者显式合并。 */
    #[Locked]
    public bool $stale = false;

    /** 自动保存和异常状态可读提示。 */
    public string $message = '';

    /** 空章节和不可编辑状态显示说明，不渲染可写表单。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
        $this->chapterNumber = max(1, request()->integer('chapter', 1));
        $chapter = $this->getRecord()->chapters()->where('number', $this->chapterNumber)->first();
        if ($chapter?->current_candidate_version_id === null) {
            $this->message = '本章还没有可以修改的正文，请先回正文工作台生成。';

            return;
        }
        try {
            $draft = app(ManualContentDrafts::class)->open($this->getRecord()->id, $chapter->current_candidate_version_id, Filament::auth()->user());
            $this->versionId = $chapter->current_candidate_version_id;
            $this->revision = $draft['revision'];
            $this->stale = $draft['stale'];
            $this->form->fill(['title' => $draft['title'], 'body' => self::displayBody($draft['body'])]);
            $this->message = $this->stale ? '这份草稿是在较早的候选上改的，之后本章又有了新候选。先点“查看改动”对比，再选“保留旧草稿继续改”或“丢弃旧草稿，载入当前候选”。' : '停止输入 2 秒后自动保存草稿；改完点右上角“保存并重新提交”。';
            $this->rememberData();
            // 从工作台“以此版本人工编辑”进入时，载入指定历史版本作为编辑起点。
            if (! $this->stale && request()->integer('from_version') > 0) {
                $this->loadVersion(request()->integer('from_version'), false);
            }
        } catch (ValidationException $error) {
            $this->message = collect($error->errors())->flatten()->implode('；');
        } catch (InvalidArgumentException $error) {
            $this->versionId = null;
            $this->message = '本章的生成记录校验未通过，暂不能编辑：'.$error->getMessage();
        }
    }

    /** 两秒防抖只写工作草稿，不建立生成运行；大段正文保持足够编辑高度。 */
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('title')->label('候选标题')->maxLength(255)->live(debounce: 2000)->afterStateUpdated(fn () => $this->saveDraft()),
            // 正文框按推进检查与本地复核线索给需要调整的原文加底色，改写后标记自动消失。
            ViewField::make('body')->label('工作正文')->view('filament.forms.highlight-textarea')->viewData(fn (): array => ['source' => $this->markSource()])
                ->afterStateUpdated(fn () => $this->saveDraft()),
        ]);
    }

    /** 自动保存失败时保留本地输入和未保存提示，避免数据被默默丢弃。 */
    public function saveDraft(): void
    {
        abort_if($this->versionId === null, 403);
        if ($this->stale) {
            $this->message = '请先选择保留还是丢弃旧草稿，选好前不会自动保存。';

            return;
        }
        try {
            $this->revision = app(ManualContentDrafts::class)->save($this->getRecord()->id, $this->versionId, Filament::auth()->user(), (string) ($this->data['title'] ?? ''), self::storedBody((string) ($this->data['body'] ?? '')), $this->revision);
            $this->message = '草稿已自动保存 · '.now()->format('H:i:s').'（还没有提交，改完点“保存并重新提交”）';
            $this->rememberData();
        } catch (ValidationException $error) {
            $this->message = '保存未完成：'.collect($error->errors())->flatten()->implode('；');
            throw $error;
        }
    }

    /** 编辑框需要标记的原文片段：草稿所依据版本的推进检查问题（前后矛盾、未解决复核项、未完成推进点与钩子、设定冲突）及当前工作正文的本地复核线索。
     * @return list<array{kind: string, label: string, quote: string}>
     */
    public function issueMarks(): array
    {
        return $this->markSource()['marks'];
    }

    /** 编辑页的问题来源：草稿对应版本走到的最后一关。一键提交失败时列评审、确定性验证或事件提取的问题，否则列推进检查问题；另加当前正文的本地复核线索。
     * 草稿与某个版本正文完全一致时直接用该版；修改中的草稿取问题引文在正文里仍能找到最多的版本，同分时用当前候选。
     *
     * @return array{version: ?int, origin: string, title: string, notice: ?string, issues: list<array<string, mixed>>, marks: list<array{kind: string, label: string, quote: string}>}
     */
    public function markSource(): array
    {
        $version = $this->versionId === null ? null : ChapterVersion::find($this->versionId);
        if ($version === null) {
            return ['version' => null, 'origin' => 'none', 'title' => '', 'notice' => null, 'issues' => [], 'marks' => []];
        }
        $body = self::storedBody((string) ($this->data['body'] ?? $version->body));
        $issues = app(ManualEditIssues::class);
        $same = ChapterVersion::where('chapter_id', $version->chapter_id)->where('body_hash', hash('sha256', $body))->latest('id')->first();
        if ($same !== null) {
            $best = $issues->forVersion($same);
        } else {
            $best = $issues->forVersion($version);
            $score = ManualEditIssues::score($best['issues'], $body);
            foreach (ChapterVersion::where('chapter_id', $version->chapter_id)->whereKeyNot($version->id)->orderByDesc('id')->get() as $candidate) {
                $source = $issues->forVersion($candidate);
                if (($candidateScore = ManualEditIssues::score($source['issues'], $body)) > $score) {
                    [$best, $score] = [$source, $candidateScore];
                }
            }
        }
        $marks = [];
        foreach ($best['issues'] as $issue) {
            $kind = $best['origin'] === 'check' ? 'check' : ($issue['severity'] === 'blocking' ? 'block' : 'advise');
            foreach ($issue['quotes'] as $quote) {
                $marks[] = self::mark($kind, $issue['category'].'：'.$issue['reason'], $quote);
            }
        }
        foreach (ContentReadingAudit::inspect($body)['issues'] as $issue) {
            if (($issue['type'] ?? null) === 'repeated_text') {
                $marks[] = self::mark('repeat', '重复原文', self::repeatedSpan($body, $issue['evidence'] ?? []));
            } else {
                foreach ($issue['evidence'] ?? [] as $item) {
                    $marks[] = self::mark('timeline', '可能的时序倒退', (string) ($item['quote'] ?? ''));
                }
            }
        }
        $unique = [];
        foreach (array_filter($marks) as $mark) {
            $unique[$mark['kind']."\0".$mark['quote']] ??= $mark;
        }

        return [...$best, 'marks' => array_values($unique)];
    }

    /** 当前正文的本地检查，随自动保存实时刷新，不调用模型：字数范围、混入写作说明、叙述先转述对白原话、逐字照搬细纲。
     * 后两类正是生成正文时格式校验会打回的问题，保存前先在这里改掉。
     *
     * @return array{count: int, min: ?int, max: ?int, length_ok: bool, problems: list<array{label: string, quote: string}>, rejected: ?array{version: int, review: int}}|null
     */
    public function localChecks(): ?array
    {
        $version = $this->versionId === null ? null : ChapterVersion::find($this->versionId);
        if ($version === null) {
            return null;
        }
        $run = ChapterGenerationRun::find($version->run_id);
        $snapshot = is_array($run?->snapshot) ? $run->snapshot : [];
        $policy = $snapshot['novel']['chapter_length_policy'] ?? [];
        $body = self::storedBody((string) ($this->data['body'] ?? $version->body));
        $count = ContentSchema::count($body);
        $min = isset($policy['min_characters']) ? (int) $policy['min_characters'] : null;
        $max = isset($policy['max_characters']) ? (int) $policy['max_characters'] : null;
        $problems = [];
        foreach (ContentQualityContract::issues($body, ['min_characters' => 0, 'max_characters' => PHP_INT_MAX]) as $issue) {
            if ($issue['type'] === 'meta_text') {
                $problems[] = ['label' => 'p'.$issue['paragraph'].' 混入写作说明，须删去', 'quote' => (string) $issue['quotes'][0]];
            }
        }
        foreach (ContentQualityContract::echoedDialogue($body, ContentQualityContract::characterNames($snapshot)) as $issue) {
            $problems[] = ['label' => 'p'.$issue['paragraph'].' 叙述先转述了 p'.$issue['dialogue_paragraph'].' 对白的原话，删去叙述里的转述', 'quote' => (string) $issue['quotes'][0]];
        }
        foreach (ContentQualityContract::planCopies($body, $run?->plan_data) as $issue) {
            $problems[] = ['label' => 'p'.$issue['paragraph'].' 逐字照搬细纲句子，改写为人物言行或自然叙述', 'quote' => (string) $issue['quotes'][0]];
        }

        // 与已被评审驳回的版本逐字相同时提前提示，保存时会被拒绝。
        $same = ChapterVersion::where('chapter_id', $version->chapter_id)->where('body_hash', hash('sha256', $body))->orderByDesc('version')->get();
        $review = ChapterReview::whereIn('version_id', $same->modelKeys())->where('verdict', 'needs_revision')->latest('id')->first();
        $rejected = $review === null ? null : ['version' => $same->firstWhere('id', $review->version_id)->version, 'review' => $review->id];

        return ['count' => $count, 'min' => $min, 'max' => $max, 'length_ok' => ($min === null || $count >= $min) && ($max === null || $count <= $max), 'problems' => $problems, 'rejected' => $rejected];
    }

    /** 单条标记；过短的引文不标记。
     * @return array{kind: string, label: string, quote: string}|null
     */
    private static function mark(string $kind, string $label, string $quote): ?array
    {
        $quote = trim($quote);

        return mb_strlen($quote) >= 2 ? ['kind' => $kind, 'label' => mb_strimwidth($label, 0, 120, '…'), 'quote' => $quote] : null;
    }

    /** 编辑框显示格式：每段首行缩进两个全角空格，段间空一行。 */
    public static function displayBody(string $body): string
    {
        $lines = array_values(array_filter(preg_split('/\R/u', $body) ?: [], fn (string $line): bool => trim($line) !== ''));

        return implode("\n\n", array_map(fn (string $line): string => '　　'.$line, $lines));
    }

    /** 保存格式：去掉每段首尾空白（含全角空格）与空行，每段一行。 */
    public static function storedBody(string $text): string
    {
        $lines = array_map(fn (string $line): string => (string) preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $line), preg_split('/\R/u', $text) ?: []);

        return implode("\n", array_values(array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    /** 本地复核只给出两处相同的前12个字：沿两处位置向前后延伸到不再相同为止，并去掉首尾标点，得到完整的重复片段。
     * @param  list<array{start?: int, quote?: string}>  $evidence
     */
    private static function repeatedSpan(string $body, array $evidence): string
    {
        if (count($evidence) < 2 || ! isset($evidence[0]['start'], $evidence[1]['start'], $evidence[0]['quote'])) {
            return (string) ($evidence[0]['quote'] ?? '');
        }
        $characters = mb_str_split($body);
        $first = $evidence[0]['start'];
        $second = $evidence[1]['start'];
        $length = mb_strlen($evidence[0]['quote']);
        while ($first > 0 && $characters[$first - 1] !== "\n" && ($characters[$first - 1] ?? null) === ($characters[$second - 1] ?? null) && $first - 1 + $length < $second) {
            $first--;
            $second--;
            $length++;
        }
        while (isset($characters[$second + $length]) && $characters[$first + $length] !== "\n" && $characters[$first + $length] === $characters[$second + $length] && $first + $length < $second) {
            $length++;
        }

        return (string) preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', implode('', array_slice($characters, $first, $length)));
    }

    /** 把本章某个历史候选的标题与正文载入编辑区并保存为工作草稿；只是编辑起点，仍须“保存为新候选”并授权检查。 */
    public function loadVersion(int $id, bool $reload = true): void
    {
        abort_if($this->versionId === null || $this->stale, 403);
        $version = ChapterVersion::where('chapter_id', ChapterVersion::findOrFail($this->versionId)->chapter_id)->findOrFail($id);
        $this->form->fill(['title' => $version->title, 'body' => self::displayBody($version->body)]);
        $this->saveDraft();
        $this->message = '已载入版本 '.$version->version.' 作为编辑起点（草稿已保存）；改完点“保存并重新提交”。';
        if (! $reload) {
            return;
        }
        Notification::make()->success()->title('已载入版本 '.$version->version)->body('草稿已保存；改完点“保存并重新提交”。')->send();
        // 编辑框由前端独立维护标记，载入后重新打开编辑页，按新正文重新计算标记。
        $this->redirect(NovelResource::getUrl('editor', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
    }

    /** 主按钮“保存并重新提交”一步走完保存、检查与一键提交；分步操作收进“分步操作”。 */
    protected function getHeaderActions(): array
    {
        $editable = fn (): bool => $this->versionId !== null && ! $this->stale;

        return [
            Action::make('saveAndSubmit')->label('保存并重新提交')->color('success')->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $editable() && $this->manualRun() === null && ! $this->checkedAndUnchanged())
                ->modalHeading('保存并重新提交')
                ->modalDescription(fn (): string => '依次：保存为新候选 → 推进检查（1 次请求）→ 通过后自动评审与事件提取（各 1 次）→ 评审通过且没有新实体待批准时正式提交。任何一关没过就停下，回到本页按新问题修改。'
                    .'最多 '.ContentGenerationService::MANUAL_SUBMIT_REQUESTS.' 次请求'.$this->executionLimitNote(ContentGenerationService::MANUAL_SUBMIT_REQUESTS).'。')
                ->schema([
                    Textarea::make('reason')->label('修改说明')->default(fn (): string => $this->defaultReason())->required(),
                    Checkbox::make('confirm')->label('我已通读修改后的正文；授权最多 3 次请求及费用，推进检查通过即视为全文核对通过，评审通过后自动正式提交')->accepted(),
                ])
                ->action(function (array $data): void {
                    $this->saveDraft();
                    try {
                        app(ContentGenerationService::class)->saveManualAndSubmit($this->getRecord()->id, $this->versionId, Filament::auth()->user(), $this->revision, $data['reason']);
                        Notification::make()->success()->title('已开始检查')->body('推进检查通过后会自动评审并提交；没通过时再打开人工编辑，页面会列出新的问题。')->send();
                        $this->redirect(NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
                    } catch (InvalidArgumentException $error) {
                        Notification::make()->danger()->title('未能开始')->body($error->getMessage())->send();
                    }
                }),
            Action::make('submitChecked')->label('提交（推进检查已通过）')->color('success')->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $editable() && $this->checkedAndUnchanged())
                ->modalHeading('评审、提取并正式提交')
                ->modalDescription(fn (): string => '当前候选的推进检查已通过，编辑区也没有改动。接着做一次独立评审和一次事件提取（最多 2 次请求'.$this->executionLimitNote(2).'），评审通过且没有新实体待批准时正式提交；没过就停下，再打开本页查看问题。')
                ->schema([
                    Textarea::make('reason')->label('提交说明')->default('采纳修订后提交')->required(),
                    Checkbox::make('confirm')->label('我已通读当前正文；授权最多 2 次请求及费用，并在评审通过后自动正式提交')->accepted(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(ContentGenerationService::class)->submitCheckedCandidate($this->latestRun()->id, Filament::auth()->user(), $data['reason']);
                        Notification::make()->success()->title('已开始评审')->body('评审通过后自动正式提交；没通过时再打开本页查看问题。')->send();
                        $this->redirect(NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
                    } catch (InvalidArgumentException $error) {
                        Notification::make()->danger()->title('未能开始')->body($error->getMessage())->send();
                    }
                }),
            Action::make('aiRevise')->label('让 AI 改选中的问题')->color('warning')->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => $editable() && $this->manualRun() === null && $this->aiIssueOptions() !== [])
                ->disabled(fn (): bool => app(RevisionWorkbench::class)->rounds($this->chapterId()) >= app(RevisionWorkbench::class)->hardLimit($this->chapterId()))
                ->tooltip(fn (): ?string => app(RevisionWorkbench::class)->rounds($this->chapterId()) >= app(RevisionWorkbench::class)->hardLimit($this->chapterId()) ? '本章已用完 '.app(RevisionWorkbench::class)->hardLimit($this->chapterId()).' 轮 AI 修订，只能人工修改' : null)
                ->modalHeading('让 AI 只改选中的问题')
                ->modalDescription(fn (): string => '编辑区有改动时先保存为新候选；AI 只改问题所在段落及前后文，再做一次推进检查（最多 3 次请求'.$this->executionLimitNote(3).'）。'
                    .'改完后再打开本页，用“查看改动”看 AI 改了哪些：满意就提交或继续改，不满意点“不采纳，回到上一版”。本章已用 AI 修订 '.app(RevisionWorkbench::class)->rounds($this->chapterId()).' / '.app(RevisionWorkbench::class)->hardLimit($this->chapterId()).' 轮。')
                ->schema([
                    CheckboxList::make('issues')->label('要 AI 修改的问题（最多 10 条）')->options(fn (): array => array_map(fn (array $option): string => $option['label'], $this->aiIssueOptions()))
                        ->default(fn (): array => array_keys(array_filter($this->aiIssueOptions(), fn (array $option): bool => $option['blocking'])))->minItems(1)->maxItems(10)->required(),
                    Checkbox::make('extra_round')->label('已超过默认 '.RevisionWorkbench::ROUND_LIMIT.' 轮，确认追加一轮（会产生额外费用）')
                        ->visible(fn (): bool => app(RevisionWorkbench::class)->rounds($this->chapterId()) >= RevisionWorkbench::ROUND_LIMIT)->accepted(),
                    Checkbox::make('accept_request')->label('授权最多 3 次请求及可能费用；修改结果先作为新候选，不会自动提交')->accepted(),
                ])
                ->action(function (array $data): void {
                    $this->saveDraft();
                    $options = $this->aiIssueOptions();
                    $issues = array_values(array_map(fn (int|string $key): string => $options[$key]['text'], array_filter($data['issues'], fn (int|string $key): bool => isset($options[$key]))));
                    try {
                        app(ContentGenerationService::class)->reviseSelectedIssues($this->getRecord()->id, $this->versionId, Filament::auth()->user(), $this->revision, $issues,
                            '人工编辑页指定问题 AI 修订', (bool) ($data['extra_round'] ?? false));
                        Notification::make()->success()->title('AI 修订已开始')->body('完成后再打开人工修改页，用“查看改动”看 AI 改了哪些。')->send();
                        $this->redirect(NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
                    } catch (InvalidArgumentException $error) {
                        Notification::make()->danger()->title('AI 修订未开始')->body($error->getMessage())->send();
                    }
                }),
            Action::make('revertAi')->label('不采纳，回到上一版')->color('gray')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => $editable() && $this->aiRevision() !== null)
                ->requiresConfirmation()->modalDescription(fn (): string => '把版本 '.($this->aiRevision()['parent'] ?? '').' 的正文载入编辑区，在它的基础上人工修改；AI 修改的版本仍保留在历史版本中。')
                ->action(fn () => $this->loadVersion(ChapterVersion::where('chapter_id', $this->chapterId())->where('version', $this->aiRevision()['parent'])->firstOrFail()->id)),
            Action::make('check')->label('检查已保存的候选')->color('success')->visible(fn (): bool => $this->manualRun() !== null)
                ->modalDescription(fn (): string => '对已保存的人工候选做一次推进检查（1 次请求）'.$this->executionLimitNote(1).'；不自动修订，也不提交。通过后回工作台一键提交。未保存为候选的工作草稿不会发送。')
                ->schema([TextInput::make('execution_limit')->label('小说执行总上限')->integer()->minValue(1)->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + 1))->required(),
                    Textarea::make('reason')->label('检查原因')->default(fn (): string => $this->defaultReason())->required(), Checkbox::make('approve')->label('确认发送已保存人工候选，执行一次付费检查')->accepted()])
                ->action(function (array $data): void {
                    try {
                        $run = $this->manualRun();
                        abort_if($run === null, 409);
                        app(ContentGenerationService::class)->continue($run->id, executionLimit: (int) $data['execution_limit'], reason: $data['reason'], authorId: Filament::auth()->id());
                        $this->redirect(NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
                    } catch (InvalidArgumentException $error) {
                        Notification::make()->danger()->title('检查未启动')->body($error->getMessage())->send();
                    }
                }),
            Action::make('compare')->label('查看改动')->color('gray')->icon('heroicon-o-arrows-right-left')->visible(fn (): bool => $this->versionId !== null)
                ->modalHeading(fn (): string => $this->compareBase()['heading'])->slideOver()
                ->modalContent(fn () => view('filament.partials.draft-diff', $this->compareBase()))
                ->modalSubmitAction(false)->modalCancelActionLabel('关闭'),
            Action::make('loadVersion')->label('换一个版本开始改')->color('gray')->visible($editable)
                ->modalDescription(fn (): string => '用所选版本的标题和正文替换编辑区（会自动保存为工作草稿），不改变当前候选。'.(($tip = $this->versionChoices()['tip']) === null ? '' : $tip))
                ->schema([Select::make('version_id')->label('从哪个版本开始')->required()->native(false)
                    ->options(fn (): array => $this->versionChoices()['options'])->default(fn (): ?int => $this->versionChoices()['recommended'])])
                ->action(fn (array $data) => $this->loadVersion((int) $data['version_id'])),
            Action::make('rebase')->label('保留旧草稿继续改')->visible(fn (): bool => $this->stale)->requiresConfirmation()
                ->modalDescription('编辑区保留旧草稿的正文，以当前候选为新的编辑起点。建议先点“查看改动”对比旧草稿与当前候选；此操作不发布候选。')->action(function (): void {
                    $this->revision = app(ManualContentDrafts::class)->rebase($this->getRecord()->id, $this->versionId, Filament::auth()->user(), $this->revision);
                    $this->stale = false;
                    $this->saveDraft();
                }),
            Action::make('discardDraft')->label('丢弃旧草稿，载入当前候选')->color('gray')->visible(fn (): bool => $this->stale)->requiresConfirmation()
                ->modalDescription('删除基于旧候选的工作草稿，编辑区改为当前候选的标题和正文；旧草稿不可恢复，候选与历史版本不受影响。')->action(function (): void {
                    app(ManualContentDrafts::class)->discard($this->getRecord()->id, $this->versionId, Filament::auth()->user(), $this->revision);
                    $this->redirect(NovelResource::getUrl('editor', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
                }),
            ActionGroup::make([
                Action::make('publish')->label('只保存为新候选（不检查）')->visible($editable)
                    ->modalDescription('把编辑区正文保存为新候选，之后需要单独授权检查，再回工作台一键提交。')
                    ->schema([Textarea::make('reason')->label('修改说明')->default(fn (): string => $this->defaultReason())->required()])->action(function (array $data): void {
                        $this->saveDraft();
                        try {
                            app(ContentGenerationService::class)->saveManualCandidate($this->getRecord()->id, $this->versionId, Filament::auth()->user(), $this->revision, $data['reason']);
                            $this->redirect(NovelResource::getUrl('editor', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
                        } catch (InvalidArgumentException $error) {
                            Notification::make()->danger()->title('候选未建立')->body($error->getMessage())->send();
                        }
                    }),
                Action::make('back')->label('回正文与提交工作台')->url(fn (): string => NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber])),
            ])->label('更多')->icon('heroicon-o-ellipsis-horizontal')->color('gray')->button(),
        ];
    }

    /** 默认修改说明：写明按哪一关的问题修改。 */
    private function defaultReason(): string
    {
        $source = $this->markSource();

        return $source['issues'] === [] ? '人工修改正文' : '按'.$source['title'].'意见人工修改';
    }

    /** 执行上限不足时说明将自动调到多少。 */
    private function executionLimitNote(int $requests): string
    {
        $novel = $this->getRecord();
        $needed = $novel->execution_count + $requests;

        return $needed > $novel->max_execution_count ? '；本书执行次数上限将从 '.$novel->max_execution_count.' 调到 '.$needed : '；本书执行次数已用 '.$novel->execution_count.' / '.$novel->max_execution_count;
    }

    /** “查看改动”的对比对象：旧草稿对比当前候选；否则对比问题所来自的版本，没有时对比当前候选。
     * @return array{heading: string, rows: list<array<string, mixed>>}
     */
    public function compareBase(): array
    {
        $candidate = ChapterVersion::findOrFail($this->versionId);
        $body = self::storedBody((string) ($this->data['body'] ?? ''));
        // 编辑区没改动时看本版相对上一版改了哪些，例如 AI 修订的结果。
        if (! $this->stale && $body === $candidate->body && ($parent = ChapterVersion::find($candidate->parent_version_id)) !== null) {
            return ['heading' => '版本 '.$candidate->version.'（当前候选）相对上一版（版本 '.$parent->version.'）的改动', 'rows' => ParagraphDiff::compare($parent->body, $candidate->body)];
        }
        $base = $candidate;
        if (! $this->stale && ($number = $this->markSource()['version']) !== null && $number !== $candidate->version) {
            $base = ChapterVersion::where('chapter_id', $candidate->chapter_id)->where('version', $number)->first() ?? $candidate;
        }
        $heading = $this->stale ? '旧草稿与当前候选（版本 '.$candidate->version.'）的差异' : '编辑区与版本 '.$base->version.($base->is($candidate) ? '（当前候选）' : '').' 的差异';

        return ['heading' => $heading, 'rows' => ParagraphDiff::compare($base->body, self::storedBody((string) ($this->data['body'] ?? '')))];
    }

    /** 可作为编辑起点的版本：标出检查与评审结论，并推荐一个。
     * 推荐规则：未被评审驳回的版本中，推进检查通过的最新一版；都没通过时取检查问题最少的最新一版。
     *
     * @return array{options: array<int, string>, recommended: ?int, tip: ?string}
     */
    public function versionChoices(): array
    {
        $chapterId = ChapterVersion::findOrFail($this->versionId)->chapter_id;
        $rejected = ChapterReview::where('chapter_id', $chapterId)->where('verdict', 'needs_revision')->pluck('id', 'version_id');
        $rows = [];
        foreach (ChapterVersion::where('chapter_id', $chapterId)->whereKeyNot($this->versionId)->orderByDesc('version')->get() as $version) {
            $check = ChapterProgressionCheck::where('version_id', $version->id)->latest('id')->first();
            $issues = $check === null ? null : count(ManualEditIssues::checkIssues(is_array($check->result) ? $check->result : []));
            $fixed = $check === null ? 0 : collect($check->result['reading_reviews'] ?? [])->filter(fn (array $item): bool => str_starts_with((string) ($item['id'] ?? ''), 'submission-review-') && ($item['fixed'] ?? false) === true)->count();
            $status = match (true) {
                isset($rejected[$version->id]) => '已被评审 #'.$rejected[$version->id].' 驳回',
                $check === null => '未检查',
                $check->verdict === 'pass' => '推进检查通过',
                default => '推进检查 '.$issues.' 条问题',
            };
            $rows[] = ['version' => $version, 'label' => '版本 '.$version->version.' · '.$version->character_count.' 字 · '.$status.($fixed > 0 ? ' · 已修复评审问题 '.$fixed.' 条' : ''),
                'rank' => isset($rejected[$version->id]) || $check === null ? null : ($check->verdict === 'pass' ? 0 : $issues)];
        }
        $ranked = array_values(array_filter($rows, fn (array $row): bool => $row['rank'] !== null));
        usort($ranked, fn (array $a, array $b): int => [$a['rank'], -$a['version']->version] <=> [$b['rank'], -$b['version']->version]);
        $recommended = $ranked[0]['version'] ?? null;
        $options = [];
        foreach ($rows as $row) {
            $options[$row['version']->id] = ($recommended?->is($row['version']) ? '★ 推荐 · ' : '').$row['label'];
        }

        return ['options' => $options, 'recommended' => $recommended?->id,
            'tip' => $recommended === null ? null : '推荐版本 '.$recommended->version.'：没有被评审驳回，且'.($ranked[0]['rank'] === 0 ? '推进检查已通过。' : '推进检查问题最少。')];
    }

    /** 本章编号。 */
    private function chapterId(): int
    {
        return ChapterVersion::findOrFail($this->versionId)->chapter_id;
    }

    /** 本章最新的生成运行。 */
    private function latestRun(): ?ChapterGenerationRun
    {
        return $this->versionId === null ? null : ChapterGenerationRun::where('chapter_id', $this->chapterId())->latest('id')->first();
    }

    /** 当前候选推进检查已通过（等待全文核对），编辑区也没有改动：可直接提交。 */
    public function checkedAndUnchanged(): bool
    {
        $run = $this->latestRun();
        $candidate = $this->versionId === null ? null : ChapterVersion::find($this->versionId);

        return $run !== null && $candidate !== null && $run->status === 'completed' && $run->stop_reason === 'awaiting_review'
            && self::storedBody((string) ($this->data['body'] ?? '')) === $candidate->body && (string) ($this->data['title'] ?? '') === $candidate->title;
    }

    /** 当前候选是否为 AI 按指定问题修订的结果；返回问题条数、上一版版本号与检查结论。
     * @return array{issues: int, parent: int, verdict: ?string}|null
     */
    public function aiRevision(): ?array
    {
        $run = $this->latestRun();
        $candidate = $this->versionId === null ? null : ChapterVersion::find($this->versionId);
        $parent = $candidate === null ? null : ChapterVersion::find($candidate->parent_version_id);
        if ($run === null || $parent === null || ! isset($run->config['workbench']) || $candidate->run_id !== $run->id
            || self::storedBody((string) ($this->data['body'] ?? '')) !== $candidate->body) {
            return null;
        }

        return ['issues' => count($run->config['workbench']['issues'] ?? []), 'parent' => $parent->version,
            'verdict' => ChapterProgressionCheck::where('version_id', $candidate->id)->latest('id')->value('verdict')];
    }

    /** 可交给 AI 修改的问题：改正文能解决的评审、验证或检查问题，每条写成带原文的修订指令。
     * @return array<int, array{label: string, text: string, blocking: bool}>
     */
    public function aiIssueOptions(): array
    {
        $options = [];
        foreach ($this->markSource()['issues'] as $index => $issue) {
            if ($issue['scope'] !== 'text') {
                continue;
            }
            $quotes = $issue['quotes'] === [] ? '' : '。原文：'.implode('', array_map(fn (string $quote): string => '“'.mb_strimwidth($quote, 0, 120, '…').'”', array_slice($issue['quotes'], 0, 3)));
            $text = $issue['category'].'：'.$issue['reason'].(filled($issue['hint']) ? '。建议：'.$issue['hint'] : '').$quotes;
            $options[$index] = ['label' => ($issue['severity'] === 'blocking' ? '【阻塞】' : '【建议】').$issue['category'].'：'.mb_strimwidth($issue['reason'], 0, 140, '…'),
                'text' => mb_strimwidth($text, 0, 1000, '…'), 'blocking' => $issue['severity'] === 'blocking'];
        }

        return $options;
    }

    /** 已保存为候选、还没检查。 */
    public function hasPendingCheck(): bool
    {
        return $this->manualRun() !== null;
    }

    /** 只显示本账号尚未发送的人工检查，不为失败请求提供隐式重发按钮。 */
    private function manualRun(): ?ChapterGenerationRun
    {
        if ($this->versionId === null) {
            return null;
        }

        return ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('novel_id', $this->getRecord()->id)->where('current_candidate_version_id', $this->versionId))
            ->where('config->manual_edit->author_id', Filament::auth()->id())->where('stop_reason', 'manual_draft_saved')->where('status', 'paused')->first();
    }
}
