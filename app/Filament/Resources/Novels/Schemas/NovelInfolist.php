<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use Filament\Infolists\Components\RepeatableEntry;
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
                    ->description(fn (Novel $record): string => $record->generation_mode === GenerationMode::SettingReal ? '真实生成的设定是候选，尚未获得作者批准；成功后暂停等待大纲阶段。' : '当前为模拟流程。模拟完成不代表小说作品已生成。')
                    ->schema([
                        TextEntry::make('generation_mode')->label('生成模式')->formatStateUsing(fn (GenerationMode $state): string => $state->label())->columnSpanFull(),
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
                    ->description(fn (Novel $record): string => $record->generation_mode === GenerationMode::SettingReal ? '只执行设定阶段；失败时保留历史。重试需显式操作且消耗剩余执行次数。' : '执行次数达到上限会暂停；开启细纲且上限为5时，通常在导出前暂停。')
                    ->schema([
                        TextEntry::make('status')->label('状态')->badge()
                            ->formatStateUsing(fn (NovelStatus $state, Novel $record): string => NovelResource::statusLabel($state, $record))
                            ->color(fn (NovelStatus $state): string => NovelResource::statusColor($state)),
                        TextEntry::make('current_stage')->label('当前阶段')->placeholder('尚未开始')
                            ->formatStateUsing(fn (?Stage $state): ?string => $state?->label()),
                        TextEntry::make('execution_count')->label('执行次数 / 上限')
                            ->formatStateUsing(fn (Novel $record): string => $record->execution_count.' / '.$record->max_execution_count),
                        TextEntry::make('progress')->label(fn (Novel $record): string => $record->generation_mode === GenerationMode::SettingReal ? '流水线进度' : '模拟进度')->suffix('%'),
                        TextEntry::make('created_at')->label('创建时间')->dateTime('Y-m-d H:i:s'),
                        TextEntry::make('updated_at')->label('最近活动')->dateTime('Y-m-d H:i:s'),
                    ]),
                Section::make('设定执行记录')->columnSpanFull()
                    ->visible(fn (Novel $record): bool => $record->generation_mode === GenerationMode::SettingReal)
                    ->description('保留每次尝试。只有结构校验通过的结果才显示为设定候选，未知费用不代表免费。')
                    ->schema([
                        RepeatableEntry::make('setting_attempts')->label('设定尝试')->hiddenLabel()->state(fn (Novel $record): array => self::attempts($record))
                            ->placeholder('任务尚未领取，请稍后刷新。')->columns(3)->schema([
                                TextEntry::make('id')->label('阶段编号'),
                                TextEntry::make('attempt')->label('尝试次数'),
                                TextEntry::make('status')->label('执行状态'),
                                TextEntry::make('model')->label('请求模型'),
                                TextEntry::make('duration')->label('耗时'),
                                TextEntry::make('cost')->label('费用'),
                                TextEntry::make('cost_log_id')->label('调用日志编号')->placeholder('尚无调用记录'),
                                TextEntry::make('error')->label('失败说明')->placeholder('无')->columnSpanFull(),
                                TextEntry::make('world.era')->label('时代')->placeholder('尚无有效候选'),
                                TextEntry::make('world.background')->label('世界背景')->placeholder('尚无有效候选')->columnSpanFull(),
                                TextEntry::make('world.rules')->label('世界规则')->listWithLineBreaks()->columnSpanFull(),
                                RepeatableEntry::make('characters')->label('人物卡')->columnSpanFull()->columns(2)->schema([
                                    TextEntry::make('name')->label('姓名'), TextEntry::make('role')->label('角色'),
                                    TextEntry::make('goal')->label('目标'), TextEntry::make('personality')->label('性格'),
                                    TextEntry::make('speech_style')->label('语言习惯'), TextEntry::make('abilities')->label('能力'),
                                    TextEntry::make('limitations')->label('限制'),
                                ]),
                                TextEntry::make('tone.narrative_style')->label('文风'),
                                TextEntry::make('tone.pov')->label('叙事视角'),
                                TextEntry::make('tone.pacing')->label('节奏'),
                                TextEntry::make('core_conflict')->label('核心矛盾')->columnSpanFull(),
                                TextEntry::make('preserved_details')->label('保留的创意细节')->listWithLineBreaks()->columnSpanFull(),
                                RepeatableEntry::make('open_questions')->label('待作者确认')->placeholder('尚无待确认问题记录')->columnSpanFull()->schema([
                                    TextEntry::make('question')->label('问题'),
                                    TextEntry::make('impact')->label('影响'),
                                    TextEntry::make('blocking')->label('是否阻塞后续规划')->formatStateUsing(fn (bool $state): string => $state ? '是' : '否'),
                                ]),
                            ]),
                    ]),
            ]);
    }

    /** 一次性读取有序尝试并投影可展示字段，认证信息和原始供应商响应不进入页面。
     * @return list<array<string, mixed>>
     */
    private static function attempts(Novel $novel): array
    {
        $logs = CostLog::query()->where('novel_id', $novel->id)->orderBy('id')->get()->keyBy('novel_stage_id');

        return $novel->stages()->where('stage', Stage::Setting->value)->orderBy('id')->get()->map(function (NovelStage $stage) use ($logs): array {
            $setting = data_get($stage->output, 'data.setting', []);
            $cost = $stage->cost;

            return [
                ...$setting, 'id' => $stage->id, 'attempt' => $stage->execution_count, 'status' => $stage->status->label(),
                'model' => data_get($stage->input, 'generation_config.model'),
                'duration' => $stage->duration_ms === null ? '未知' : $stage->duration_ms.'毫秒',
                'cost' => ($cost['billing_status'] ?? 'unknown') === 'estimated' ? '估算 '.$cost['estimated_cost'].' '.$cost['currency'] : '未知（请核查调用记录）',
                'cost_log_id' => data_get($stage->output, 'data.cost_log_id') ?? $logs->get($stage->id)?->id, 'error' => $stage->error_message,
            ];
        })->all();
    }
}
