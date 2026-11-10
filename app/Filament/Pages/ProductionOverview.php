<?php

namespace App\Filament\Pages;

use App\Enums\NovelStatus;
use App\Enums\ProductionFailure;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\AcceptanceBatch;
use App\Models\ChapterSubmissionRun;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Services\Pipeline\WorkerHeartbeat;
use App\Services\Search\SearchIndexStatus;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** 后台首页：待我处理、正在运行和最近的书；只读汇总，不触发生成、嵌入或自动恢复。 */
class ProductionOverview extends Dashboard implements HasTable
{
    use InteractsWithTable;

    /** 侧栏与页面标题。 */
    protected static ?string $title = '生产总览';

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '创作工作台';

    /** 置于分组首位。 */
    protected static ?int $navigationSort = 0;

    /** 有在途运行时自动刷新，便于离开后回来看到真实状态。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('待我处理')->description('需要你决定或处理的事项；处理入口直接打开对应页面。')
                ->extraAttributes(fn (): array => ProductionRun::whereIn('status', ['pending', 'running'])->exists() ? ['wire:poll.10s' => ''] : [])
                ->schema([
                    RepeatableEntry::make('todo')->hiddenLabel()->state(fn (): array => $this->todo())->placeholder('目前没有待处理事项。')
                        ->table([TableColumn::make('小说'), TableColumn::make('事项'), TableColumn::make('建议'), TableColumn::make('入口')])
                        ->schema([
                            TextEntry::make('novel'), TextEntry::make('item'), TextEntry::make('advice'),
                            TextEntry::make('url')->url(fn (?string $state): ?string => $state)->formatStateUsing(fn (): string => '去处理'),
                        ]),
                ]),
            Section::make('正在运行')->schema([
                RepeatableEntry::make('running')->hiddenLabel()->state(fn (): array => $this->running())->placeholder('当前没有在途的自动生成。')
                    ->table([TableColumn::make('小说'), TableColumn::make('授权范围'), TableColumn::make('当前'), TableColumn::make('入口')])
                    ->schema([
                        TextEntry::make('novel'), TextEntry::make('range'), TextEntry::make('current'),
                        TextEntry::make('url')->url(fn (?string $state): ?string => $state)->formatStateUsing(fn (): string => '查看进度'),
                    ]),
            ]),
            Section::make('生产与服务状态')->columns(3)->collapsible()->schema([
                TextEntry::make('novels')->label('小说数')->state(fn (): int => Novel::count()),
                TextEntry::make('formal')->label('正式章节数')->state(fn (): int => (int) Novel::sum('chapters_done')),
                TextEntry::make('unknown')->label('金额未知的真实请求')->state(fn (): int => CostLog::where('mode', 'real')->where('billing_status', 'unknown')->count()),
                TextEntry::make('health')->label('Worker心跳')->state(fn (): string => app(WorkerHeartbeat::class)->summary())->columnSpanFull(),
                TextEntry::make('index')->label('检索投影覆盖')->state(fn (): string => app(SearchIndexStatus::class)->summary())->columnSpanFull(),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    /** 最近的书：状态、正式章数和常用入口。 */
    public function table(Table $table): Table
    {
        return $table->query(Novel::query()->whereNull('archived_at'))->heading('最近的书')->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('title')->label('书名')->searchable(),
            TextColumn::make('chapters_done')->label('正式章数')->formatStateUsing(fn (Novel $record): string => $record->chapters_done.' / '.$record->target_chapters),
            TextColumn::make('status')->label('运行状态')->formatStateUsing(fn ($state, Novel $record): string => NovelResource::statusLabel($state, $record)),
            TextColumn::make('updated_at')->label('最近活动')->dateTime('Y-m-d H:i')->sortable(),
        ])->filters([SelectFilter::make('status')->label('状态')->options(collect(NovelStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->label()])->all())])
            ->recordActions([
                Action::make('open')->label('打开工作台')->url(fn (Novel $record): string => NovelResource::getUrl('view', ['record' => $record])),
                Action::make('chapters')->label('章节目录')->url(fn (Novel $record): string => NovelResource::getUrl('chapters', ['record' => $record])),
                Action::make('recover')->label('运行与恢复')->url(fn (Novel $record): string => NovelResource::getUrl('run', ['record' => $record])),
            ])
            ->emptyStateHeading('尚无小说')->emptyStateDescription('点击右上角“创建小说”开始，空态不会调用模型。');
    }

    /** 待处理事项：停止的生产运行、待批准的新实体、待人工评分的验收批次、结果未知的请求。
     * @return list<array{novel: string, item: string, advice: string, url: ?string}>
     */
    public function todo(): array
    {
        $items = [];
        foreach (ProductionRun::where('status', 'failed')->latest('id')->limit(20)->get() as $run) {
            $novel = Novel::find($run->novel_id);
            $failure = ProductionFailure::describe($run->error, $run->step);
            $items[] = ['novel' => $novel?->title ?? '#'.$run->novel_id, 'item' => '自动生成停止：'.$failure['label'], 'advice' => $failure['advice'], 'url' => NovelResource::getUrl('run', ['record' => $run->novel_id])];
        }
        foreach (ChapterSubmissionRun::where('status', 'awaiting_approval')->with('chapter.novel')->latest('id')->limit(20)->get() as $submission) {
            if (ProductionRun::coveringChapter($submission->novel_id, $submission->chapter->number, true) !== null) {
                continue;
            }
            $items[] = ['novel' => $submission->chapter->novel->title, 'item' => '第'.$submission->chapter->number.'章有新实体等待批准', 'advice' => '核对新实体后批准并正式提交。',
                'url' => NovelResource::getUrl('content', ['record' => $submission->novel_id, 'chapter' => $submission->chapter->number])];
        }
        foreach (AcceptanceBatch::whereIn('status', ['human_pending', 'system_pass'])->latest('id')->limit(20)->get() as $batch) {
            $items[] = ['novel' => $batch->snapshot['title'] ?? '#'.$batch->novel_id, 'item' => $batch->status === 'system_pass' ? '验收证据已齐，等待你明确认可' : '验收批次等待人工评分',
                'advice' => $batch->status === 'system_pass' ? '在验收工作台确认关闭。' : '逐章阅读并评分，补齐外部证据。',
                'url' => $batch->status === 'system_pass' ? NovelResource::getUrl('acceptance', ['record' => $batch->novel_id]) : NovelResource::getUrl('acceptanceReading', ['record' => $batch->novel_id, 'batch' => $batch->id])];
        }
        $unknown = CostLog::where('mode', 'real')->whereIn('status', ['pending', 'unknown'])->count();
        if ($unknown > 0) {
            $items[] = ['novel' => '全部', 'item' => $unknown.' 次真实请求结果未知或尚未返回', 'advice' => '恢复前先在对应小说的成本明细核对，避免重复计费。', 'url' => null];
        }

        return $items;
    }

    /** 在途的生产运行与当前章节、步骤。
     * @return list<array{novel: string, range: string, current: string, url: string}>
     */
    public function running(): array
    {
        $steps = ['setting' => '生成设定', 'decision' => '设定决策', 'outline' => '生成大纲', 'content' => '正文生成与检查', 'submission' => '评审与事件提取', 'commit' => '正式提交'];

        return ProductionRun::whereIn('status', ['pending', 'running', 'recovering', 'reconciling'])->latest('id')->get()->map(fn (ProductionRun $run): array => [
            'novel' => Novel::find($run->novel_id)?->title ?? '#'.$run->novel_id,
            'range' => '第'.$run->start_chapter.'～'.$run->chapter_limit.'章',
            'current' => '第'.($run->artifacts['chapter_number'] ?? $run->start_chapter).'章 · '.($steps[$run->step] ?? $run->step),
            'url' => NovelResource::getUrl('run', ['record' => $run->novel_id]),
        ])->all();
    }

    /** 创建与书架入口。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('创建小说')->url(NovelResource::getUrl('create')),
            Action::make('novels')->label('小说书架')->color('gray')->url(NovelResource::getUrl('index'))];
    }
}
