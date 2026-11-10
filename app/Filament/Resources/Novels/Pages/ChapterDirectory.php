<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\Novel;
use App\Services\Pipeline\AcceptanceComparison;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/** 按书隔离的章节目录，手动与自动流程均可直达指定章节阅读页。 */
class ChapterDirectory extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 沿用小说资源的访问规则。 */
    protected static string $resource = NovelResource::class;

    /** 导航与面包屑标题。 */
    protected static ?string $title = '章节目录';

    /** 单次页面请求内复用证据读取结果，不把正文放入客户端可修改属性。
     * @var array<int, array<string, mixed>|null>
     */
    protected array $comparisonCache = [];

    /** 对照稿来源必须属于本页小说及当前章。
     * @return array<string, mixed>|null
     */
    public function comparison(Chapter $chapter): ?array
    {
        abort_unless((int) $chapter->novel_id === (int) $this->getRecord()->id, 404);
        if (! array_key_exists($chapter->id, $this->comparisonCache)) {
            $this->comparisonCache[$chapter->id] = app(AcceptanceComparison::class)->latest($chapter);
        }

        return $this->comparisonCache[$chapter->id];
    }

    /** 只读呈现新正文，不复用旧正式版本的已读标记或人工评分。
     * @return array<Component>
     */
    protected function comparisonFields(Chapter $chapter): array
    {
        $comparison = $this->comparison($chapter);
        abort_if($comparison === null, 404);

        return [
            Hidden::make('fingerprint')->default(app(AcceptanceComparison::class)->fingerprint($comparison)),
            TextEntry::make('comparison_status')->label('验收状态')->state('待人工确认；本稿尚未正式提交，旧版评分不代表本稿验收结果。'),
            TextEntry::make('comparison_source')->label('来源')->state('原正文记录 #'.$comparison['source_version_id'].' · '.$comparison['characters'].' 字 · '.$comparison['completed_at']),
            TextEntry::make('comparison_title')->label('章名')->state($comparison['title']),
            TextEntry::make('comparison_body')->label('新对照稿正文')->state($comparison['body'])
                ->extraAttributes(['style' => 'white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.9;font-size:20px']),
            TextEntry::make('feedback_history')->label('本稿反馈记录')->state(app(AcceptanceComparison::class)->feedbackHistory($comparison))->extraAttributes(['style' => 'white-space:pre-line;overflow-wrap:anywhere']),
            Select::make('decision')->label('阅读结论')->options(['approve' => '认可本稿', 'revise' => '需要修改'])->required(),
            Textarea::make('comment')->label('反馈意见')->helperText('请填写认可的理由，或需修改的段落、问题与期待。仅记录本稿反馈，不自动通过十章验收。')->rows(5)->required()->maxLength(10000),
        ];
    }

    /** 不存在小说返回404，禁止跨小说拼接记录。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 正式和候选版本分列，不将已检查候选当作正式稿。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('按章阅读')->description('正式版本和候选版本分开展示。尚未生成时可返回工作台查看规划和启动条件。')->schema([]), EmbeddedTable::make()]);
    }

    /** 章号排序并提供正式状态筛选，空态有可行动的返回入口。 */
    public function table(Table $table): Table
    {
        return $table->query(Chapter::where('novel_id', $this->getRecord()->id))->defaultSort('number')->columns([
            TextColumn::make('number')->label('章号')->sortable(), TextColumn::make('title')->label('章名')->searchable(),
            TextColumn::make('current_candidate_version_id')->label('候选正文记录编号')->placeholder('无'),
            TextColumn::make('formal_version_id')->label('正式正文记录编号')->placeholder('尚未提交'),
            TextColumn::make('acceptance_comparison')->label('待验收对照稿')->state(function (Chapter $record): ?string {
                $comparison = $this->comparison($record);

                return $comparison === null ? null : $comparison['title'].' · '.$comparison['characters'].'字';
            })->placeholder('无')->badge()->color('warning'),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn ($state): string => $state->label()),
        ])->filters([TernaryFilter::make('formal_version_id')->label('已正式提交')->nullable()])
            ->recordActions([Action::make('editDraft')->label('人工编辑')->visible(fn (Chapter $record): bool => $record->formal_version_id === null && $record->current_candidate_version_id !== null)->url(fn (Chapter $record): string => NovelResource::getUrl('editor', ['record' => $this->getRecord(), 'chapter' => $record->number])), Action::make('reader')->label(fn (Chapter $record): string => $this->comparison($record) !== null ? ($record->formal_version_id !== null ? '阅读正式稿' : '阅读原候选稿') : '专注阅读')->url(fn (Chapter $record): string => NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'chapter' => $record->number])), Action::make('read')->label('阅读正文与状态')->url(fn (Chapter $record): string => NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $record->number]))])
            ->pushRecordActions([Action::make('comparison')->label('阅读新对照稿')
                ->visible(fn (Chapter $record): bool => $this->comparison($record) !== null)
                ->modalHeading('待验收对照稿')->modalWidth('5xl')->modalSubmitActionLabel('提交反馈')->modalCancelActionLabel('关闭')
                ->schema(fn (Chapter $record): array => $this->comparisonFields($record))
                ->action(function (Chapter $record, array $data, Schema $schema): void {
                    // 再次验证所属小说及稿件来源，不能将客户端参数当作已读稿件依据。
                    $this->comparison($record);
                    try {
                        app(AcceptanceComparison::class)->feedback($record, (int) auth()->id(), $data);
                    } catch (ValidationException $error) {
                        // 错误映射回弹层字段，并通知用户，避免出现点击提交没有反应。
                        Notification::make()->danger()->title('反馈未保存')->body(implode('；', $error->validator->errors()->all()))->send();
                        $messages = [];
                        foreach ($error->errors() as $field => $errors) {
                            $messages[$schema->getStatePath().'.'.$field] = $errors;
                        }
                        throw ValidationException::withMessages($messages);
                    }
                    Notification::make()->success()->title('反馈已保存')->body('已关联本次对照稿；未替换正式章节或自动通过整批验收。')->send();
                })])
            ->emptyStateHeading('尚无章节正文')->emptyStateDescription('返回小说查看设定和大纲，满足启动条件后生成候选。');
    }

    /** 返回当前小说，不依赖浏览器历史。 */
    protected function getHeaderActions(): array
    {
        $actions = [Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
        // 原书目录提供明确分支入口，避免读者把旧正式稿误认为新验收稿。
        $branch = Novel::where('generation_config->acceptance_branch->source_novel_id', $this->getRecord()->id)->latest('id')->first();
        if ($branch !== null && NovelResource::canView($branch)) {
            $actions[] = Action::make('acceptanceBranch')->label('查看新验收分支')->url(NovelResource::getUrl('chapters', ['record' => $branch]));
        }

        return $actions;
    }
}
