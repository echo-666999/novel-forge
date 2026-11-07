<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Models\StoryOutboxEvent;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\WorkerHeartbeat;
use App\Services\Search\SearchIndexStatus;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 消费者观测与事务通知审计，只读检查不会重发提交或删除历史。 */
class SystemHealth extends Page implements HasTable
{
    use InteractsWithTable;

    /** 系统观测页面中文标题。 */
    protected static ?string $title = '系统健康与事务通知';

    /** 位于业务运行入口之后。 */
    protected static ?int $navigationSort = 3;

    /** 仅报告实际观测和积压，不给出没有依据的健康绿灯。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('系统观测')->description('通知投递时间是全局标记；检索订阅者不会更新此字段，不能用它判断检索是否已经消费。')->schema([
            TextEntry::make('worker')->label('消费者心跳')->state(fn (): string => app(WorkerHeartbeat::class)->summary()),
            TextEntry::make('index')->label('检索投影覆盖')->state(fn (): string => app(SearchIndexStatus::class)->summary()),
            TextEntry::make('outbox')->label('未标记投递的事务通知')->state(fn (): int => StoryOutboxEvent::whereNull('published_at')->count()),
            TextEntry::make('oldest')->label('最早未标记投递时间')->state(fn (): ?string => StoryOutboxEvent::whereNull('published_at')->min('created_at'))->placeholder('没有待投递通知'),
        ]), EmbeddedTable::make()]);
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
