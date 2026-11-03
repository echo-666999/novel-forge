<?php

namespace App\Filament\Resources\Novels\Tables;

use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
            ->columns([
                TextColumn::make('title')->label('书名')->searchable()->limit(35),
                TextColumn::make('genre')->label('题材')->placeholder('未填写'),
                TextColumn::make('target_chapters')->label('目标章数')->suffix('章'),
                TextColumn::make('status')->label('状态')->badge()
                    ->formatStateUsing(fn (NovelStatus $state): string => NovelResource::statusLabel($state))
                    ->color(fn (NovelStatus $state): string => NovelResource::statusColor($state)),
                TextColumn::make('current_stage')->label('当前阶段')->placeholder('尚未开始')
                    ->formatStateUsing(fn (?Stage $state): ?string => $state?->label()),
                TextColumn::make('execution_count')->label('执行次数 / 上限')
                    ->formatStateUsing(fn (Novel $record): string => $record->execution_count.' / '.$record->max_execution_count),
                TextColumn::make('updated_at')->label('最近活动')->dateTime('Y-m-d H:i:s')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('状态')
                    ->options(collect(NovelStatus::cases())->mapWithKeys(fn (NovelStatus $status): array => [$status->value => NovelResource::statusLabel($status)])->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('查看'),
            ])
            ->toolbarActions([]);
    }
}
