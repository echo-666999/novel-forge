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
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
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
            // 服务状态压成一行标签：正常只显示绿色“服务正常”，异常项单独给出说明。
            Section::make('生产与服务状态')->compact()->schema([
                Flex::make([
                    TextEntry::make('novels')->hiddenLabel()->badge()->grow(false)->color('gray')->state(fn (): string => Novel::count().' 本小说'),
                    TextEntry::make('formal')->hiddenLabel()->badge()->grow(false)->color('primary')->state(fn (): string => '正式 '.(int) Novel::sum('chapters_done').' 章'),
                    TextEntry::make('service')->hiddenLabel()->badge()->grow(false)->color(fn (): string => $this->serviceIssues() === [] ? 'success' : 'warning')
                        ->state(fn (): string => $this->serviceIssues() === [] ? '服务正常' : count($this->serviceIssues()).' 项服务需排查'),
                    TextEntry::make('unknown')->hiddenLabel()->badge()->grow(false)->color('warning')->visible(fn (): bool => $this->unknownRequests() > 0)
                        ->state(fn (): string => $this->unknownRequests().' 次真实请求结果未知')->tooltip('恢复前先在对应小说的费用明细核对，避免重复计费。'),
                ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                TextEntry::make('service_issues')->hiddenLabel()->color('warning')->size('sm')->listWithLineBreaks()->bulleted()
                    ->visible(fn (): bool => $this->serviceIssues() !== [])->state(fn (): array => $this->serviceIssues()),
            ]),
            Section::make('待我处理')->compact()
                ->extraAttributes(fn (): array => ProductionRun::whereIn('status', ['pending', 'running'])->exists() ? ['wire:poll.10s' => ''] : [])
                ->schema([
                    RepeatableEntry::make('todo')->hiddenLabel()->state(fn (): array => $this->todo())->placeholder('目前没有待处理事项。')
                        ->grid(['default' => 1, 'lg' => 2])->schema([
                            Flex::make([
                                TextEntry::make('novel')->hiddenLabel()->weight('bold'),
                                TextEntry::make('url')->hiddenLabel()->grow(false)->url(fn (?string $state): ?string => $state)->color('primary')->formatStateUsing(fn (): string => '去处理 →'),
                            ])->verticallyAlignCenter(),
                            TextEntry::make('item')->hiddenLabel(),
                            TextEntry::make('advice')->hiddenLabel()->color('gray')->size('sm'),
                        ]),
                ]),
            Section::make('正在运行')->compact()->schema([
                RepeatableEntry::make('running')->hiddenLabel()->state(fn (): array => $this->running())->placeholder('当前没有在途的自动生成。')
                    ->grid(['default' => 1, 'lg' => 2])->schema([
                        Flex::make([
                            TextEntry::make('novel')->hiddenLabel()->weight('bold'),
                            TextEntry::make('url')->hiddenLabel()->grow(false)->url(fn (?string $state): ?string => $state)->color('primary')->formatStateUsing(fn (): string => '查看进度 →'),
                        ])->verticallyAlignCenter(),
                        TextEntry::make('current')->hiddenLabel()->badge()->color('info'),
                        TextEntry::make('range')->hiddenLabel()->color('gray')->size('sm')->formatStateUsing(fn (string $state): string => '授权范围 '.$state),
                    ]),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    /** 最近的书：状态、正式章数和常用入口。 */
    public function table(Table $table): Table
    {
        return $table->query(Novel::query()->whereNull('archived_at'))->heading('最近的书')->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('title')->label('书名')->searchable(),
            TextColumn::make('chapters_done')->label('正式章数')->badge()->color('gray')->formatStateUsing(fn (Novel $record): string => $record->chapters_done.' / '.$record->target_chapters.' 章'),
            TextColumn::make('status')->label('运行状态')->badge()->formatStateUsing(fn ($state, Novel $record): string => NovelResource::statusLabel($state, $record))
                ->color(fn ($state): string => NovelResource::statusColor($state)),
            TextColumn::make('updated_at')->label('最近活动')->since()->dateTimeTooltip('Y-m-d H:i')->sortable()->color('gray'),
        ])->filters([SelectFilter::make('status')->label('状态')->options(collect(NovelStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->label()])->all())])
            ->recordActions([
                Action::make('open')->label('打开工作台')->url(fn (Novel $record): string => NovelResource::getUrl('view', ['record' => $record])),
                Action::make('chapters')->label('章节目录')->url(fn (Novel $record): string => NovelResource::getUrl('chapters', ['record' => $record])),
                Action::make('recover')->label('运行与恢复')->url(fn (Novel $record): string => NovelResource::getUrl('run', ['record' => $record])),
            ])
            ->emptyStateHeading('尚无小说')->emptyStateDescription('点击右上角“创建小说”开始，空态不会调用模型。');
    }

    /** 待处理事项：停止的生产运行、待批准的新实体、待人工评分的验收批次；结果未知的请求改由状态标签提醒。
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

        return $items;
    }

    /** 结果未知或尚未返回的真实请求数，改由状态标签提醒。 */
    public function unknownRequests(): int
    {
        return CostLog::where('mode', 'real')->whereIn('status', ['pending', 'unknown'])->count();
    }

    /** 需要排查的服务项说明；消费者心跳过期或缺失、检索投影有缺口时列出原文说明。
     * @return list<string>
     */
    public function serviceIssues(): array
    {
        $issues = [];
        $worker = app(WorkerHeartbeat::class)->summary();
        if (! str_starts_with($worker, '最近观测')) {
            $issues[] = 'Worker 心跳：'.$worker;
        }
        $index = app(SearchIndexStatus::class)->summary();
        if (! str_starts_with($index, '未发现当前代词法块的正式章数：0；') || ! str_contains($index, '待嵌入块：0。')) {
            $issues[] = '检索投影：'.$index;
        }

        return $issues;
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
