<?php

namespace App\Filament\Pages;

use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\CostLog;
use App\Models\Novel;
use App\Services\Pipeline\BackendCapabilities;
use App\Services\Pipeline\WorkerHeartbeat;
use App\Services\Search\SearchIndexStatus;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** 汇总生产事实与完整入口清单，不触发生成、嵌入或自动恢复。 */
class ProductionOverview extends Page implements HasTable
{
    use InteractsWithTable;

    /** 侧栏业务总览名称。 */
    protected static ?string $title = '生产总览与功能导航';

    /** 将生产信息置于小说入口前。 */
    protected static ?int $navigationSort = 0;

    /** 明确区别错误、未知费用和未采集的健康数据。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('生产状态')->columns(3)->schema([
                TextEntry::make('novels')->label('小说数')->state(fn (): int => Novel::count()),
                TextEntry::make('formal')->label('正式章节数')->state(fn (): int => (int) Novel::sum('chapters_done')),
                TextEntry::make('failures')->label('历史暂停 / 异常运行')->state(fn (): int => ChapterGenerationRun::whereIn('status', ['paused', 'error'])->count() + ChapterSubmissionRun::where('status', 'failed')->count()),
                TextEntry::make('unknown')->label('金额未知的真实请求')->state(fn (): int => CostLog::where('mode', 'real')->where('billing_status', 'unknown')->count()),
                TextEntry::make('pending')->label('待返回请求')->state(fn (): int => CostLog::where('status', 'pending')->count()),
                TextEntry::make('health')->label('Worker心跳')->state(fn (): string => app(WorkerHeartbeat::class)->summary()),
                TextEntry::make('index')->label('检索投影覆盖')->state(fn (): string => app(SearchIndexStatus::class)->summary())->columnSpanFull(),
            ]),
            EmbeddedTable::make(),
            Section::make('功能导航与开放范围')->description('小说相关入口打开最近活动小说；也可在上方列表选择其他小说。未开放能力明确保留说明，不提供无效操作。')->schema([
                RepeatableEntry::make('capabilities')->hiddenLabel()->state(fn (): array => $this->capabilities())->schema([
                    TextEntry::make('id')->label('需求编号'),
                    TextEntry::make('name')->label('功能'),
                    TextEntry::make('url')->label('入口')->url(fn (?string $state): ?string => $state)->formatStateUsing(fn (): string => '打开页面')->placeholder('未开放或尚无小说'),
                    TextEntry::make('scope')->label('当前范围')->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    /** 最近活动列表同时承载空态、状态筛选和明确的详情恢复入口。 */
    public function table(Table $table): Table
    {
        return $table->query(Novel::query())->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('title')->label('书名')->searchable(),
            TextColumn::make('chapters_done')->label('正式章数'),
            TextColumn::make('status')->label('运行状态')->formatStateUsing(fn ($state, Novel $record): string => NovelResource::statusLabel($state, $record)),
            TextColumn::make('updated_at')->label('最近活动')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->label('状态')->options(collect(NovelStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->label()])->all())])
            ->recordActions([Action::make('open')->label('打开工作台')->url(fn (Novel $record): string => NovelResource::getUrl('view', ['record' => $record])),
                Action::make('recover')->label('查看运行与异常')->url(fn (Novel $record): string => NovelResource::getUrl('run', ['record' => $record]))])
            ->emptyStateHeading('尚无小说')->emptyStateDescription('通过“创建小说”开始，空态不会调用模型。');
    }

    /** 只生成已注册的实际路由，缺少小说时不伪造记录编号。
     * @return list<array<string, mixed>>
     */
    public function capabilities(): array
    {
        $novel = Novel::latest('updated_at')->first();

        return array_map(function (array $row) use ($novel): array {
            $url = match ($row['page']) {
                'profile' => Filament::getProfileUrl(), 'overview' => static::getUrl(),
                'templates' => ManagePromptTemplates::getUrl(), 'settings' => ManageGeneralSettings::getUrl(),
                'runs' => GenerationRuns::getUrl(), 'health' => SystemHealth::getUrl(),
                'index' => NovelResource::getUrl('index'), null => null,
                default => $novel === null ? null : NovelResource::getUrl($row['page'], ['record' => $novel]),
            };

            return [...$row, 'link' => $row['name'].($url === null ? '（未开放或尚无小说）' : ''), 'url' => $url];
        }, BackendCapabilities::all());
    }

    /** 创建与书架动作沿用现有资源权限及流程。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('创建小说')->url(NovelResource::getUrl('create')),
            Action::make('novels')->label('小说管理')->url(NovelResource::getUrl('index'))];
    }
}
