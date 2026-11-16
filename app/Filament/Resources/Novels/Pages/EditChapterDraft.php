<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentReadingAudit;
use App\Services\Pipeline\ManualContentDrafts;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
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
    protected static ?string $title = '人工正文编辑';

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
            $this->message = '本章尚无可编辑候选，请返回正文工作台。';

            return;
        }
        try {
            $draft = app(ManualContentDrafts::class)->open($this->getRecord()->id, $chapter->current_candidate_version_id, Filament::auth()->user());
            $this->versionId = $chapter->current_candidate_version_id;
            $this->revision = $draft['revision'];
            $this->stale = $draft['stale'];
            $this->form->fill(['title' => $draft['title'], 'body' => self::displayBody($draft['body'])]);
            $this->message = $this->stale ? '候选已更新：当前显示保留的旧工作草稿，请核对后明确合并。' : '编辑停止输入后自动保存工作草稿；不会自动检查或正式提交。';
            $this->rememberData();
            // 从工作台“以此版本人工编辑”进入时，载入指定历史版本作为编辑起点。
            if (! $this->stale && request()->integer('from_version') > 0) {
                $this->loadVersion(request()->integer('from_version'), false);
            }
        } catch (ValidationException $error) {
            $this->message = collect($error->errors())->flatten()->implode('；');
        } catch (InvalidArgumentException $error) {
            $this->versionId = null;
            $this->message = '冻结来源校验失败：'.$error->getMessage();
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
            $this->message = '尚未确认旧工作草稿与当前候选的合并，未自动保存。';

            return;
        }
        try {
            $this->revision = app(ManualContentDrafts::class)->save($this->getRecord()->id, $this->versionId, Filament::auth()->user(), (string) ($this->data['title'] ?? ''), self::storedBody((string) ($this->data['body'] ?? '')), $this->revision);
            $this->message = '工作草稿已保存 · '.now()->format('H:i:s').' · 修订 '.$this->revision.'（尚未成为新候选）';
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

    /** 草稿依据的版本：本章各版本推进检查所引用的原文，在当前草稿中出现最多的那一版；都对不上时用当前候选。
     * 载入历史版本、改到一半再打开时，问题仍对应正文实际来自的版本。
     *
     * @return array{version: ?int, marks: list<array{kind: string, label: string, quote: string}>}
     */
    public function markSource(): array
    {
        $version = $this->versionId === null ? null : ChapterVersion::find($this->versionId);
        if ($version === null) {
            return ['version' => null, 'marks' => []];
        }
        $body = self::storedBody((string) ($this->data['body'] ?? $version->body));
        $best = ['version' => $version->version, 'marks' => [], 'score' => 0];
        $checks = ChapterProgressionCheck::where('chapter_id', $version->chapter_id)->orderByDesc('id')->get()->unique('version_id');
        // 草稿与某个版本正文完全一致时直接用该版检查（即使它已通过、没有问题可标），不被内容相近的其他版本抢走。
        $same = ChapterVersion::where('chapter_id', $version->chapter_id)->where('body_hash', hash('sha256', $body))->latest('id')->first();
        if ($same !== null) {
            $check = $checks->firstWhere('version_id', $same->id);
            $checks = collect();
            $best = ['version' => $same->version, 'marks' => $check === null ? [] : self::checkMarks(is_array($check->result) ? $check->result : []), 'score' => PHP_INT_MAX];
        }
        foreach ($checks as $check) {
            $marks = self::checkMarks(is_array($check->result) ? $check->result : []);
            $score = count(array_filter($marks, fn (array $mark): bool => str_contains($body, $mark['quote'])));
            if ($score > $best['score'] || ($best['marks'] === [] && $check->version_id === $version->id)) {
                $best = ['version' => ChapterVersion::find($check->version_id)?->version, 'marks' => $marks, 'score' => $score];
            }
        }
        $marks = $best['marks'];
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

        return ['version' => $best['version'], 'marks' => array_values($unique)];
    }

    /** 一次推进检查结果里需要标记的原文。
     * @param  array<string, mixed>  $result
     * @return list<array{kind: string, label: string, quote: string}>
     */
    private static function checkMarks(array $result): array
    {
        $marks = [];
        $collect = function (string $label, mixed $evidence) use (&$marks): void {
            foreach (is_array($evidence) ? $evidence : [] as $item) {
                $marks[] = self::mark('check', $label, (string) ($item['quote'] ?? ''));
            }
        };
        foreach ($result['continuity_issues'] ?? [] as $issue) {
            $collect('前后重复或时序矛盾：'.($issue['reason'] ?? ''), $issue['evidence'] ?? []);
        }
        foreach ($result['setting_violations'] ?? [] as $issue) {
            $collect('设定冲突：'.($issue['reason'] ?? ''), $issue['evidence'] ?? []);
        }
        foreach ($result['reading_reviews'] ?? [] as $review) {
            if (($review['disposition'] ?? null) === 'unresolved') {
                $collect('未解决：'.($review['reason'] ?? ''), $review['evidence'] ?? []);
            }
        }
        foreach ($result['points'] ?? [] as $point) {
            if (($point['met'] ?? true) === false) {
                $collect('推进点未完成：'.($point['reason'] ?? ''), $point['evidence'] ?? []);
            }
        }
        if (($result['hook']['met'] ?? true) === false) {
            $collect('章末钩子不足：'.($result['hook']['reason'] ?? ''), $result['hook']['evidence'] ?? []);
        }

        return array_values(array_filter($marks));
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
        $this->message = '已载入候选版本 '.$version->version.' 的正文作为编辑起点（工作草稿已保存）；修改后点“保存为新候选”，再授权检查。';
        if (! $reload) {
            return;
        }
        Notification::make()->success()->title('已载入候选版本 '.$version->version)->body('工作草稿已保存；修改后点“保存为新候选”，再授权检查。')->send();
        // 编辑框由前端独立维护标记，载入后重新打开编辑页，按新正文重新计算标记。
        $this->redirect(NovelResource::getUrl('editor', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
    }

    /** 发布与检查分开，冻结检查仅一请求且不自动修订或正式提交。 */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('loadVersion')->label('载入历史版本')->color('gray')->visible(fn (): bool => $this->versionId !== null && ! $this->stale)
                ->modalDescription('用所选历史版本的标题和正文替换编辑区内容并保存为工作草稿；不会改变当前候选，需“保存为新候选”后授权检查。')
                ->schema([Select::make('version_id')->label('历史版本')->required()->native(false)->options(fn (): array => ChapterVersion::where('chapter_id', ChapterVersion::findOrFail($this->versionId)->chapter_id)
                    ->whereKeyNot($this->versionId)->orderByDesc('version')->get()
                    ->mapWithKeys(fn (ChapterVersion $version): array => [$version->id => '版本 '.$version->version.' · '.$version->character_count.' 字 · '.match (ChapterProgressionCheck::where('version_id', $version->id)->latest('id')->value('verdict')) {
                        'pass' => '推进检查通过', 'insufficient' => '推进不足', 'blocked' => '阻塞', null => '未检查', default => '其他',
                    }])->all())])
                ->action(fn (array $data) => $this->loadVersion((int) $data['version_id'])),
            Action::make('rebase')->label('确认合并旧工作草稿')->visible(fn (): bool => $this->stale)->requiresConfirmation()->modalDescription('保留编辑区工作正文，关联到当前候选作为新的编辑起点。请先核对新候选内容；此操作不发布候选。')->action(function (): void {
                $this->revision = app(ManualContentDrafts::class)->rebase($this->getRecord()->id, $this->versionId, Filament::auth()->user(), $this->revision);
                $this->stale = false;
                $this->saveDraft();
            }),
            Action::make('publish')->label('保存为新候选')->visible(fn (): bool => $this->versionId !== null && ! $this->stale)
                ->schema([Textarea::make('reason')->label('人工修改原因')->required()])->action(function (array $data): void {
                    $this->saveDraft();
                    try {
                        app(ContentGenerationService::class)->saveManualCandidate($this->getRecord()->id, $this->versionId, Filament::auth()->user(), $this->revision, $data['reason']);
                        $this->redirect(NovelResource::getUrl('editor', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]));
                    } catch (InvalidArgumentException $error) {
                        Notification::make()->danger()->title('候选未建立')->body($error->getMessage())->send();
                    }
                }),
            Action::make('check')->label('授权检查人工候选')->visible(fn (): bool => $this->manualRun() !== null)
                ->modalDescription('向当前冻结模型发送人工候选及上下文，执行一次独立推进检查并记录费用；不自动修订，不正式提交。未发布的工作草稿不会发送。')
                ->schema([TextInput::make('execution_limit')->label('小说执行总上限')->integer()->minValue(1)->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + 1))->required(),
                    Textarea::make('reason')->label('检查原因')->required(), Checkbox::make('approve')->label('确认发送已保存人工候选，执行一次付费检查')->accepted()])
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
            Action::make('back')->label('正文与提交工作台')->url(fn (): string => NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber])),
        ];
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
