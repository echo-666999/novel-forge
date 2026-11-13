<?php

namespace App\Filament\Resources\Novels\Tables;

use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Services\Pipeline\NovelArchiveService;
use App\Services\Pipeline\NovelProgressQuery;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** 小说列表只读查看状态，通过轻量轮询显示后台消费者的最新结果。 */
class NovelsTable
{
    /** 按最近创建排序，复用索引与分页，不提供编辑或批量删除。 */
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')->poll('5s')
            ->emptyStateHeading('还没有小说')->emptyStateDescription('点击“创建小说”，保存你的第一条创意。')
            ->recordUrl(fn (Novel $record): string => NovelResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('title')->label('小说')->searchable(['title', 'genre'])->weight('bold')->limit(35)
                    ->description(fn (Novel $record): string => collect([$record->genre ?: '题材未填写', $record->generation_mode->label(), $record->archived_at ? '已归档' : null])->filter()->implode(' · ')),
                TextColumn::make('status')->label('状态')->badge()
                    ->formatStateUsing(fn (NovelStatus $state, Novel $record): string => NovelResource::statusLabel($state, $record))
                    ->color(fn (NovelStatus $state): string => NovelResource::statusColor($state)),
                TextColumn::make('chapters_done')->label('正式章数')->sortable()->badge()->color(fn (Novel $record): string => $record->target_chapters > 0 && $record->chapters_done >= $record->target_chapters ? 'success' : 'gray')
                    ->formatStateUsing(fn (Novel $record): string => $record->chapters_done.' / '.$record->target_chapters.' 章'),
                TextColumn::make('progress')->label('下一步')->state(fn (Novel $record): string => app(NovelProgressQuery::class)->for($record)['headline'])->wrap()->lineClamp(2),
                TextColumn::make('current_stage')->label('当前阶段')->placeholder('尚未开始')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?Stage $state): ?string => $state?->label()),
                TextColumn::make('execution_count')->label('执行次数 / 上限')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (Novel $record): string => $record->execution_count.' / '.$record->max_execution_count),
                TextColumn::make('archived_at')->label('归档时间')->dateTime('Y-m-d H:i')->placeholder('未归档')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('最近活动')->since()->dateTimeTooltip('Y-m-d H:i:s')->color('gray')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('archived_at')->label('归档状态')->nullable()->trueLabel('已归档')->falseLabel('未归档')->placeholder('全部小说'),
                SelectFilter::make('status')->label('状态')
                    ->options(collect(NovelStatus::cases())->mapWithKeys(fn (NovelStatus $status): array => [$status->value => NovelResource::statusLabel($status)])->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('打开工作台'),
                ActionGroup::make([
                    Action::make('copyInput')->label('复制创作输入')->url(fn (Novel $record): string => NovelResource::getUrl('create', ['copy_from' => $record->id])),
                    // 归档只是分类；弹窗明确不改变队列运行，避免作者误以为已经暂停。
                    Action::make('archive')->label(fn (Novel $record): string => $record->archived_at ? '恢复到书架' : '归档')
                        ->modalDescription('只改变书架归档分类，保留全部正文、配置与历史；不会暂停或重新启动任何任务。')
                        ->schema([Textarea::make('reason')->label('操作原因')->required()->maxLength(1000)])
                        ->action(function (Novel $record, array $data): void {
                            app(NovelArchiveService::class)->setArchived($record->id, Filament::auth()->user(), $record->archived_at === null, $data['reason']);
                        }),
                ]),
            ])
            ->toolbarActions([]);
    }
}
