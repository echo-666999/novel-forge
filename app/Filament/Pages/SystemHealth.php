<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Models\StoryOutboxEvent;
use App\Services\Pipeline\BackendCapabilities;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\WorkerHeartbeat;
use App\Services\Search\SearchIndexStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** 消费者观测与事务通知审计，只读检查不会重发提交或删除历史。 */
class SystemHealth extends Page implements HasTable
{
    use InteractsWithTable;

    /** 系统观测页面中文标题。 */
    protected static ?string $title = '系统健康与事务通知';

    /** 侧栏图标。 */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '配置与运维';

    /** 分组内排序。 */
    protected static ?int $navigationSort = 3;

    /** 仅报告实际观测和积压，不给出没有依据的健康绿灯。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('系统观测')->description('通知投递时间是全局标记；检索订阅者不会更新此字段，不能用它判断检索是否已经消费。')->schema([
            TextEntry::make('worker')->label('消费者心跳')->state(fn (): string => app(WorkerHeartbeat::class)->summary()),
            TextEntry::make('index')->label('检索投影覆盖')->state(fn (): string => app(SearchIndexStatus::class)->summary()),
            TextEntry::make('outbox')->label('未标记投递的事务通知')->state(fn (): int => StoryOutboxEvent::whereNull('published_at')->count()),
            TextEntry::make('oldest')->label('最早未标记投递时间')->state(fn (): ?string => StoryOutboxEvent::whereNull('published_at')->min('created_at'))->placeholder('没有待投递通知'),
        ]), EmbeddedTable::make(),
            Section::make('功能覆盖（UI01～UI32）')->description('开发验收用：PRD 后台页面的入口与当前开放范围。小说相关入口打开最近活动的小说。')->collapsed()->schema([
                RepeatableEntry::make('capabilities')->hiddenLabel()->state(fn (): array => $this->capabilities())
                    ->table([TableColumn::make('需求编号'), TableColumn::make('功能'), TableColumn::make('入口'), TableColumn::make('当前范围')])
                    ->schema([
                        TextEntry::make('id'), TextEntry::make('name'),
                        TextEntry::make('url')->url(fn (?string $state): ?string => $state)->formatStateUsing(fn (): string => '打开页面')->placeholder('尚无小说'),
                        TextEntry::make('scope'),
                    ]),
            ]),
        ]);
    }

    /** 只生成已注册的实际路由，缺少小说时不伪造记录编号。
     * @return list<array<string, mixed>>
     */
    public function capabilities(): array
    {
        $novel = Novel::latest('updated_at')->first();

        return array_map(function (array $row) use ($novel): array {
            $url = match ($row['page']) {
                'profile' => Filament::getProfileUrl(), 'overview' => ProductionOverview::getUrl(),
                'templates' => ManagePromptTemplates::getUrl(), 'settings' => ManageGeneralSettings::getUrl(), 'prices' => ManageModelPrices::getUrl(),
                'runs' => GenerationRuns::getUrl(), 'health' => static::getUrl(),
                'index' => NovelResource::getUrl('index'), null => null,
                default => $novel === null ? null : NovelResource::getUrl($row['page'], ['record' => $novel]),
            };

            return [...$row, 'url' => $url];
        }, BackendCapabilities::all());
    }

    /** 事务通知绑定原提交，筛选只影响展示和脱敏下载。 */
    public function table(Table $table): Table
    {
        return $table->query(StoryOutboxEvent::query()->join('canonical_commits', 'canonical_commits.id', '=', 'story_outbox_events.commit_id')->select(['story_outbox_events.*', 'canonical_commits.novel_id']))
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('id')->label('通知编号'), TextColumn::make('novel_id')->label('小说编号'),
                TextColumn::make('commit_id')->label('正式提交编号'), TextColumn::make('event_type')->label('通知类型'),
                TextColumn::make('created_at')->label('产生时间')->dateTime(), TextColumn::make('published_at')->label('投递时间')->dateTime()->placeholder('待投递'),
            ])->filters([
                TernaryFilter::make('published_at')->label('已投递')->nullable(),
                SelectFilter::make('novel')->label('小说')->options(fn (): array => Novel::pluck('title', 'id')->all())->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, $id): Builder => $q->where('canonical_commits.novel_id', $id))),
            ])->recordActions([Action::make('source')->label('查看原书正式状态')->url(fn (StoryOutboxEvent $record): string => NovelResource::getUrl('state', ['record' => $record->novel_id]))])
            ->emptyStateHeading('没有符合筛选的事务通知');
    }

    /** 下载仅包含安全元数据与载荷摘要，绝不导出正文、认证信息或原始请求。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('export')->label('下载脱敏通知记录')->modalDescription('导出当前筛选中最多1000条通知元数据和载荷摘要；不包含正文及凭据。')->requiresConfirmation()->action(function (): StreamedResponse {
            $rows = $this->getFilteredTableQuery()->orderByDesc('story_outbox_events.id')->limit(1000)->get()->map(fn (StoryOutboxEvent $record): array => [
                'id' => $record->id, 'novel_id' => $record->novel_id, 'commit_id' => $record->commit_id, 'type' => $record->event_type,
                'created_at' => $record->created_at?->toIso8601String(), 'published_at' => $record->published_at?->toIso8601String(), 'payload_hash' => PlanningJson::hash($record->payload),
            ])->all();
            $json = json_encode(['scope' => 'filtered_outbox_metadata', 'limit' => 1000, 'records' => $rows], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            return response()->streamDownload(function () use ($json): void {
                echo $json;
            }, 'outbox-audit.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
        })];
    }
}
