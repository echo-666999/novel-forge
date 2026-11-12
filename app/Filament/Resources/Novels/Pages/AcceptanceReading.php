<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\AcceptanceBatch;
use App\Models\ChapterVersion;
use App\Services\Pipeline\AcceptanceWorkbench as Workbench;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** 验收阅读：左侧冻结正文、右侧逐章评分，评分规则与证据仍由验收服务校验，不替作者打分。 */
class AcceptanceReading extends Page
{
    use InteractsWithRecord;

    /** 所属小说资源。 */
    protected static string $resource = NovelResource::class;

    /** 中文标题。 */
    protected static ?string $title = '逐章验收阅读';

    /** 双栏阅读评分视图。 */
    protected string $view = 'filament.resources.novels.pages.acceptance-reading';

    /** 批次编号来自路由，不接受客户端修改。 */
    #[Locked]
    public int $batchId;

    /** 当前章号只能来自本批次冻结清单。 */
    #[Locked]
    public int $chapterNumber = 1;

    /** 阅读字号仅限固定选项。 */
    public int $fontSize = 20;

    /** 评分表单状态。 */
    public array $data = [];

    /** 校验小说与批次归属；未指定章号时打开下一章未达标章节。 */
    public function mount(int|string $record, int|string $batch): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
        $model = AcceptanceBatch::where('novel_id', $this->getRecord()->id)->findOrFail((int) $batch);
        $this->batchId = $model->id;
        $numbers = array_column($model->snapshot['manifest'], 'number');
        $requested = request()->has('chapter') ? request()->integer('chapter') : (app(Workbench::class)->nextChapterToRate($model, 0) ?? $numbers[0] ?? 1);
        abort_unless(in_array($requested, $numbers, true), 404);
        $this->chapterNumber = $requested;
        // 评分不预填分数或阅读确认，避免无意认可；上次结论另行展示。
        $this->form->fill();
    }

    /** 每次读取冻结批次，避免使用页面缓存的旧状态。 */
    public function batch(): AcceptanceBatch
    {
        return AcceptanceBatch::where('novel_id', $this->getRecord()->id)->findOrFail($this->batchId);
    }

    /** 当前章节在冻结清单中的条目。
     * @return array{number: int, title: string, version_id: int, body_hash: string}
     */
    public function entry(): array
    {
        return collect($this->batch()->snapshot['manifest'])->firstWhere('number', $this->chapterNumber);
    }

    /** 冻结正文版本，正文摘要不一致时页面明确提示。 */
    public function version(): ChapterVersion
    {
        return ChapterVersion::findOrFail($this->entry()['version_id']);
    }

    /** 冻结正文是否仍与批次记录的摘要一致。 */
    public function sourceIntact(): bool
    {
        $version = $this->version();

        return hash_equals($this->entry()['body_hash'], $version->body_hash) && hash_equals($version->body_hash, hash('sha256', $version->body));
    }

    /** 批次进度，供进度条和章节跳转使用。
     * @return array<string, mixed>
     */
    public function progress(): array
    {
        return app(Workbench::class)->progress($this->batch());
    }

    /** 当前章最新一次评分结论，没有时返回空。
     * @return array<string, mixed>|null
     */
    public function currentRating(): ?array
    {
        $chapter = collect($this->progress()['chapters'])->firstWhere('number', $this->chapterNumber);

        return $chapter['pass'] === null ? null : $chapter;
    }

    /** 只有批次负责人且批次未关闭时可以写入评分。 */
    public function canRate(): bool
    {
        $batch = $this->batch();

        return $batch->actor_id === Filament::auth()->id() && $batch->status !== 'accepted';
    }

    /** 评分表单：七项分数按钮组、选中引用原文、原因、修复期待及阅读确认。 */
    public function form(Schema $schema): Schema
    {
        $anchors = collect(Workbench::SCORE_ANCHORS)->map(fn (string $text, int $score): string => $score.' = '.$text)->implode('；');
        $fields = [];
        foreach (Workbench::DIMENSIONS as $key => $label) {
            $fields[] = ToggleButtons::make('scores.'.$key)->label($label)->options(array_combine(range(1, 5), array_map('strval', range(1, 5))))
                ->inline()->grouped()->required()->validationMessages(['required' => '请为“'.$label.'”打分。']);
        }

        return $schema->statePath('data')->components([
            ...$fields,
            // 点击脚本原样写入双引号属性 x-on:click，脚本内只能使用单引号，否则属性被截断、点击无反应。
            Textarea::make('quote')->label('本章原文依据')->rows(5)->required()->maxLength(10000)
                ->helperText('在左侧正文中选中文字后点击“引用选中文字”，可多次引用，系统自动用空行分隔。评分锚点：'.$anchors.'。')
                ->hintAction(Action::make('quoteSelection')->label('引用选中文字')->icon('heroicon-m-chat-bubble-bottom-center-text')->alpineClickHandler(<<<'JS'
                    const body = document.querySelector('[data-acceptance-body]');
                    const selection = window.getSelection();
                    let text = (selection && selection.rangeCount && body && body.contains(selection.getRangeAt(0).commonAncestorContainer)) ? selection.toString().trim() : '';
                    if (! text) { text = (window.novelForgeAcceptanceSelection || '').trim(); }
                    if (! text) { new FilamentNotification().title('请先在左侧正文中选中要引用的文字').warning().send(); return; }
                    const current = ($wire.get('data.quote') || '').trim();
                    $wire.set('data.quote', current ? current + '\n\n' + text : text);
                    window.novelForgeAcceptanceSelection = '';
                    JS)),
            Textarea::make('reason')->label('评分原因')->rows(3)->required()->maxLength(10000),
            Textarea::make('repair')->label('修复期待（任一项低于3分时必填）')->rows(2)->maxLength(10000),
            ToggleButtons::make('blocking')->label('是否存在严重冲突、知情越界、因果断裂或重复拼接')->options([0 => '没有发现', 1 => '存在阻塞'])->inline()->required(),
            Checkbox::make('read_confirmed')->label('我已完整阅读本章，并明确记录本人评分')->accepted()->validationMessages(['accepted' => '请确认已完整阅读本章。']),
        ]);
    }

    /** 保存评分并留在本章。 */
    public function save(): void
    {
        $this->persist(false);
    }

    /** 保存评分并打开下一章未达标章节。 */
    public function saveAndNext(): void
    {
        $this->persist(true);
    }

    /** 服务层校验原文摘录与阈值；字段错误映射回表单，输入保留以便修改。 */
    private function persist(bool $advance): void
    {
        abort_unless($this->canRate(), 403);
        $state = $this->form->getState();
        try {
            $evidence = app(Workbench::class)->rate($this->getRecord()->id, $this->batchId, Filament::auth()->user(), [...$state, 'chapter' => $this->chapterNumber]);
        } catch (ValidationException $error) {
            Notification::make()->danger()->title('人工评分未保存')->body(implode('；', $error->validator->errors()->all()))->send();
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
        Notification::make()->success()->title('第'.$this->chapterNumber.'章评分已保存')
            ->body($evidence->payload['pass'] ? '本章达标；批次仍需你最终明确认可。' : '本章未达标，已记录修复期待。')->send();
        $next = $advance ? app(Workbench::class)->nextChapterToRate($this->batch(), $this->chapterNumber) : $this->chapterNumber;
        if ($advance && $next === null) {
            Notification::make()->success()->title('全部章节均已达标')->body('请回到验收工作台补齐外部证据，并由你明确关闭批次。')->send();
            $next = $this->chapterNumber;
        }
        $this->redirect($this->chapterUrl($next));
    }

    /** 章节跳转、字号与返回动作，不触发模型请求。 */
    protected function getHeaderActions(): array
    {
        $numbers = array_column($this->batch()->snapshot['manifest'], 'number');
        $index = array_search($this->chapterNumber, $numbers, true);

        return [
            Action::make('previous')->label('上一章')->visible($index > 0)->url(fn (): string => $this->chapterUrl($numbers[$index - 1])),
            Action::make('next')->label('下一章')->visible($index < count($numbers) - 1)->url(fn (): string => $this->chapterUrl($numbers[$index + 1])),
            Action::make('font')->label('阅读字号')->schema([Select::make('size')->label('字号')->options([16 => '16px', 20 => '20px', 24 => '24px', 28 => '28px'])->default($this->fontSize)->required()])
                ->action(function (array $data): void {
                    $this->fontSize = in_array((int) $data['size'], [16, 20, 24, 28], true) ? (int) $data['size'] : 20;
                }),
            Action::make('workbench')->label('返回验收工作台')->url(fn (): string => NovelResource::getUrl('acceptance', ['record' => $this->getRecord()])),
        ];
    }

    /** 本批次指定章节的阅读评分链接。 */
    public function chapterUrl(int $number): string
    {
        return NovelResource::getUrl('acceptanceReading', ['record' => $this->getRecord(), 'batch' => $this->batchId, 'chapter' => $number]);
    }
}
