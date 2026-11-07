<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\ChapterGenerationRun;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ManualContentDrafts;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            $this->form->fill(['title' => $draft['title'], 'body' => $draft['body']]);
            $this->message = $this->stale ? '候选已更新：当前显示保留的旧工作草稿，请核对后明确合并。' : '编辑停止输入后自动保存工作草稿；不会自动检查或正式提交。';
            $this->rememberData();
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
            Textarea::make('body')->label('工作正文')->rows(24)->maxLength(1000000)->live(debounce: 2000)->afterStateUpdated(fn () => $this->saveDraft()),
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
            $this->revision = app(ManualContentDrafts::class)->save($this->getRecord()->id, $this->versionId, Filament::auth()->user(), (string) ($this->data['title'] ?? ''), (string) ($this->data['body'] ?? ''), $this->revision);
            $this->message = '工作草稿已保存 · '.now()->format('H:i:s').' · 修订 '.$this->revision.'（尚未成为新候选）';
            $this->rememberData();
        } catch (ValidationException $error) {
            $this->message = '保存未完成：'.collect($error->errors())->flatten()->implode('；');
            throw $error;
        }
    }

    /** 发布与检查分开，冻结检查仅一请求且不自动修订或正式提交。 */
    protected function getHeaderActions(): array
    {
        return [
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
