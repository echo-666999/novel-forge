<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** 只读呈现作者原文与当前运行快照，不提供阶段恢复或正文编辑。 */
class NovelInfolist
{
    /** 原始创意完整显示，作者内容按默认文本转义，不作为 HTML 执行。 */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('创作信息')->columns(2)->columnSpanFull()
                    ->description('当前为模拟流程。模拟完成不代表小说作品已生成。')
                    ->schema([
                        TextEntry::make('title')->label('书名'),
                        TextEntry::make('genre')->label('题材')->placeholder('未填写'),
                        TextEntry::make('premise_raw')->label('原始创意')->columnSpanFull(),
                    ]),
                Section::make('篇幅计划')->columns(3)->columnSpanFull()->schema([
                    TextEntry::make('target_chapters')->label('目标章数')->suffix('章'),
                    TextEntry::make('chapter_length_policy.min_characters')->label('每章字数下限')->suffix('字')->placeholder('未设置'),
                    TextEntry::make('chapter_length_policy.max_characters')->label('每章字数上限')->suffix('字')->placeholder('未设置'),
                ]),
                Section::make('运行状态')->columns(3)->columnSpanFull()
                    ->description('执行次数达到上限会暂停；开启细纲且上限为5时，通常在导出前暂停。')
                    ->schema([
                        TextEntry::make('status')->label('状态')->badge()
                            ->formatStateUsing(fn (NovelStatus $state): string => NovelResource::statusLabel($state))
                            ->color(fn (NovelStatus $state): string => NovelResource::statusColor($state)),
                        TextEntry::make('current_stage')->label('当前阶段')->placeholder('尚未开始')
                            ->formatStateUsing(fn (?Stage $state): ?string => $state?->label()),
                        TextEntry::make('execution_count')->label('执行次数 / 上限')
                            ->formatStateUsing(fn (Novel $record): string => $record->execution_count.' / '.$record->max_execution_count),
                        TextEntry::make('progress')->label('模拟进度')->suffix('%'),
                        TextEntry::make('created_at')->label('创建时间')->dateTime('Y-m-d H:i:s'),
                        TextEntry::make('updated_at')->label('最近活动')->dateTime('Y-m-d H:i:s'),
                    ]),
            ]);
    }
}
