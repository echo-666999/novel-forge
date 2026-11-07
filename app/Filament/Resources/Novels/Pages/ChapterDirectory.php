<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** 按书隔离的章节目录，手动与自动流程均可直达指定章节阅读页。 */
class ChapterDirectory extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 沿用小说资源的访问规则。 */
    protected static string $resource = NovelResource::class;

    /** 导航与面包屑标题。 */
    protected static ?string $title = '章节目录';

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
            TextColumn::make('current_candidate_version_id')->label('当前候选版本')->placeholder('无'),
            TextColumn::make('formal_version_id')->label('正式版本')->placeholder('尚未提交'),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn ($state): string => $state->label()),
        ])->filters([TernaryFilter::make('formal_version_id')->label('已正式提交')->nullable()])
            ->recordActions([Action::make('editDraft')->label('人工编辑')->visible(fn (Chapter $record): bool => $record->formal_version_id === null && $record->current_candidate_version_id !== null)->url(fn (Chapter $record): string => NovelResource::getUrl('editor', ['record' => $this->getRecord(), 'chapter' => $record->number])), Action::make('reader')->label('专注阅读')->url(fn (Chapter $record): string => NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'chapter' => $record->number])), Action::make('read')->label('阅读正文与状态')->url(fn (Chapter $record): string => NovelResource::getUrl('content', ['record' => $this->getRecord(), 'chapter' => $record->number]))])
            ->emptyStateHeading('尚无章节正文')->emptyStateDescription('返回小说查看设定和大纲，满足启动条件后生成候选。');
    }

    /** 返回当前小说，不依赖浏览器历史。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }
}
