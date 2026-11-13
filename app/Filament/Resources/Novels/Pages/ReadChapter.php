<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Services\Pipeline\BodyVersionDiff;
use App\Services\Pipeline\ReadingRecordService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use UnitEnum;

/** 按书及不可变版本隔离的阅读页，作者阅读标记不替代提交评审。 */
class ReadChapter extends Page
{
    use InteractsWithRecord;

    /** 所属小说资源。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '阅读器';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 11;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '正文';

    /** 阅读页标题。 */
    protected static ?string $title = '正文阅读';

    /** URL确定的章号不可在请求中伪造。 */
    #[Locked]
    public int $chapterNumber = 1;

    /** 指定历史版本；空值默认正式版本，其次当前候选。 */
    #[Locked]
    public ?int $versionId = null;

    /** 对照版本只能由服务端校验后的动作设置。 */
    #[Locked]
    public ?int $compareVersionId = null;

    /** 阅读字体大小仅限服务端枚举。 */
    public int $fontSize = 20;

    /** 验证小说、章节及版本必须属于同一来源。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
        $this->chapterNumber = max(1, request()->integer('chapter', 1));
        $this->versionId = request()->has('version') ? request()->integer('version') : null;
        $this->version();
    }

    /** 读取指定章，不因编号缺失悄悄切换到其他章。 */
    public function chapter(): ?Chapter
    {
        return Chapter::where('novel_id', $this->getRecord()->id)->where('number', $this->chapterNumber)->first();
    }

    /** 显式历史版本必须属于本章；自动选择优先正式稿。 */
    public function version(): ?ChapterVersion
    {
        $chapter = $this->chapter();
        $id = $this->versionId ?? $chapter?->formal_version_id ?? $chapter?->current_candidate_version_id;
        if ($id === null) {
            return null;
        }

        return ChapterVersion::where('chapter_id', $chapter?->id)->findOrFail($id);
    }

    /** 正文按段落排版并自动转义；版本、字数与阅读状态压成标题下一排标签，历史记录不暴露其他账号私有阅读备注。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (): string => $this->chapter() === null ? '第'.$this->chapterNumber.'章' : (new Chapter(['number' => $this->chapterNumber, 'title' => $this->version()?->title ?? $this->chapter()->title]))->numberedTitle())
                ->schema([
                    Flex::make([
                        TextEntry::make('source_kind')->hiddenLabel()->badge()->grow(false)
                            ->state(fn (): ?string => $this->version() === null ? null : ($this->chapter()->formal_version_id === $this->version()->id ? '正式版本' : '候选 / 历史版本'))
                            ->color(fn (string $state): string => $state === '正式版本' ? 'success' : 'warning'),
                        TextEntry::make('source_version')->hiddenLabel()->badge()->grow(false)->color('gray')->state(fn (): ?string => $this->version()?->displayVersion()),
                        TextEntry::make('source_characters')->hiddenLabel()->badge()->grow(false)->color('gray')->state(fn (): ?string => $this->version() === null ? null : number_format($this->version()->character_count).' 字'),
                        TextEntry::make('read')->hiddenLabel()->badge()->grow(false)->visible(fn (): bool => $this->version() !== null)
                            ->state(fn (): string => $this->isRead() ? '我已读完' : '我未标记读完')->color(fn (string $state): string => $state === '我已读完' ? 'success' : 'gray')
                            ->tooltip('阅读标记只记录你读完此版本，不代表验收通过或正式提交批准。'),
                    ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                    TextEntry::make('body')->hiddenLabel()->state(fn (): ?string => $this->version()?->body)->placeholder('本章尚无正文，请先在工作台生成候选。')
                        // 每个原文段落独立成段并首行缩进；正文先完整转义，不渲染任何作者或模型 HTML。
                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(collect(preg_split('/\R/u', $state ?? ''))->filter(fn (string $paragraph): bool => trim($paragraph) !== '')
                            ->map(fn (string $paragraph): string => '<p style="margin:0 0 .9em;text-indent:2em">'.e(trim($paragraph)).'</p>')->implode('')))
                        ->extraAttributes(fn (): array => ['style' => 'max-width:42em;margin:1rem auto 0;overflow-wrap:anywhere;line-height:1.9;font-size:'.(in_array($this->fontSize, [16, 20, 24, 28], true) ? $this->fontSize : 20).'px']),
                ]),
            Section::make('正文区段对照')->compact()->description('旧版为选择的对照版本，新版为当前阅读版本；未变化的段落默认折叠。')->visible(fn (): bool => $this->compareVersionId !== null)->schema([
                ViewComponent::make('filament.resources.novels.partials.body-diff')->viewData(function (): array {
                    if ($this->compareVersionId === null || $this->version() === null) {
                        return ['diff' => BodyVersionDiff::paragraphs('', ''), 'oldLabel' => '', 'newLabel' => ''];
                    }
                    $old = ChapterVersion::where('chapter_id', $this->chapter()?->id)->findOrFail($this->compareVersionId);

                    return ['diff' => BodyVersionDiff::paragraphs($old->body, $this->version()->body), 'oldLabel' => '记录 #'.$old->id, 'newLabel' => '当前阅读 #'.$this->version()->id];
                }),
            ]),
            Section::make(fn (): string => '我的原文问题（'.count($this->issues()).'）')->compact()->collapsible()->visible(fn (): bool => $this->issues() !== [])->schema([
                RepeatableEntry::make('issues')->hiddenLabel()->state(fn (): array => $this->issues())->schema([
                    TextEntry::make('note')->hiddenLabel()->weight('bold'),
                    TextEntry::make('quote')->hiddenLabel()->color('gray')->extraAttributes(['style' => 'border-left:3px solid rgba(127,127,127,.35);padding-left:.75rem;white-space:pre-line']),
                    TextEntry::make('created_at')->hiddenLabel()->color('gray')->size('sm'),
                ]),
            ]),
        ]);
    }

    /** 当前账号是否已标记读完此版本。 */
    private function isRead(): bool
    {
        return DB::table('reading_records')->where(['actor_id' => Filament::auth()->id(), 'version_id' => $this->version()?->id, 'kind' => 'read'])->exists();
    }

    /** 当前账号在此版本记录的原文问题，只读本人记录。
     * @return list<array<string, mixed>>
     */
    private function issues(): array
    {
        return DB::table('reading_records')->where(['actor_id' => Filament::auth()->id(), 'version_id' => $this->version()?->id, 'kind' => 'issue'])->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    /** 相邻章、字体、版本和阅读证据动作均不触发模型请求。 */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('previous')->label('上一章')->visible(fn (): bool => $this->getRecord()->chapters()->where('number', $this->chapterNumber - 1)->exists())->url(fn (): string => $this->readingUrl($this->chapterNumber - 1)),
            Action::make('next')->label('下一章')->visible(fn (): bool => $this->getRecord()->chapters()->where('number', $this->chapterNumber + 1)->exists())->url(fn (): string => $this->readingUrl($this->chapterNumber + 1)),
            Action::make('markRead')->label('标记此版本已读')->color('success')->visible(fn (): bool => $this->version() !== null && ! $this->isRead())->requiresConfirmation()->modalDescription('仅记录你读完此版本，不代表质量验收或正式提交批准。')->action(function (): void {
                app(ReadingRecordService::class)->record($this->getRecord()->id, $this->version()->id, Filament::auth()->user(), 'read');
                Notification::make()->success()->title('此版本已标记读完')->send();
            }),
            Action::make('issue')->label('记录原文问题')->visible(fn (): bool => $this->version() !== null)->schema([
                Textarea::make('quote')->label('原文摘录')->helperText('支持多处摘录，请用空行分隔，每处内部须连续；不含章名。复制产生的空格和换行不影响匹配，文字与标点须一致。')->required()->maxLength(5000), Textarea::make('note')->label('问题说明')->required()->maxLength(2000),
            ])->action(function (array $data, Schema $schema): void {
                try {
                    app(ReadingRecordService::class)->record($this->getRecord()->id, $this->version()->id, Filament::auth()->user(), 'issue', $data['quote'], $data['note']);
                } catch (ValidationException $error) {
                    // 服务层使用业务字段名；映射到当前弹窗路径，确保失败可见且保留输入供修改。
                    Notification::make()->danger()->title('原文问题未保存')->body(implode('；', $error->validator->errors()->all()))->send();
                    $messages = [];
                    foreach ($error->errors() as $field => $errors) {
                        $messages[$schema->getStatePath().'.'.$field] = $errors;
                    }
                    throw ValidationException::withMessages($messages);
                }
                Notification::make()->success()->title('原文问题已保存')->send();
            }),
            ActionGroup::make([
                Action::make('versions')->label('选择版本')->schema([Select::make('version')->label('版本')->options(fn (): array => ChapterVersion::where('chapter_id', $this->chapter()?->id)->orderByDesc('version')->get()->mapWithKeys(fn (ChapterVersion $version): array => [$version->id => $version->displayVersion()])->all())->required()])->action(function (array $data): void {
                    $this->redirect(NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber, 'version' => $data['version']]));
                }),
                Action::make('compare')->label('对照历史版本')->visible(fn (): bool => $this->version() !== null)->schema([
                    Select::make('version')->label('旧版 / 对照版本')->options(fn (): array => ChapterVersion::where('chapter_id', $this->chapter()?->id)->orderByDesc('version')->get()->mapWithKeys(fn (ChapterVersion $version): array => [$version->id => $version->displayVersion()])->all())->required(),
                ])->action(function (array $data): void {
                    $this->compareVersionId = ChapterVersion::where('chapter_id', $this->chapter()?->id)->findOrFail((int) $data['version'])->id;
                }),
                Action::make('font')->label('阅读字号')->schema([Select::make('size')->label('字号')->options([16 => '16px', 20 => '20px', 24 => '24px', 28 => '28px'])->default(20)->required()])->action(function (array $data): void {
                    $this->fontSize = (int) $data['size'];
                }),
            ])->label('版本与字号')->icon('heroicon-m-adjustments-horizontal')->button()->color('gray'),
            ActionGroup::make([
                Action::make('workbench')->label('正文与提交工作台')->url(fn (): string => NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber])),
                Action::make('directory')->label('章节目录')->url(fn (): string => NovelResource::getUrl('chapters', ['record' => $this->getRecord()])),
            ])->label('前往')->icon('heroicon-m-arrow-top-right-on-square')->button()->color('gray'),
        ];
    }

    /** 构造本书阅读链接，章节切换不沿用旧章版本编号。 */
    private function readingUrl(int $number): string
    {
        return NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'chapter' => $number]);
    }
}
