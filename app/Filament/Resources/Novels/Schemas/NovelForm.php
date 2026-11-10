<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Services\Ai\ModelProfiles;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;

/** 建书向导：创意输入 → 篇幅 → 生产方式 → 确认启动。运行字段不进入作者输入，模拟流程只保留给命令行自检。 */
class NovelForm
{
    /** 三种生产方式；“逐章确认”是默认推荐。 */
    public const MODES = [
        'manual' => '逐章确认（推荐）：先生成设定，等你确认后再生成大纲，之后每一章都由你批准提交',
        'auto_first' => '自动生成并正式提交第1章：自动决定设定问题、采纳通过校验的新实体',
        'auto_ten' => '自动生成并正式提交前10章：同上，目标章数至少10章',
    ];

    /** 向导的四个步骤。
     * @return list<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make('创意输入')->description('原文完整保存')->schema([
                TextInput::make('title')->label('书名')->required()->maxLength(255)->regex('/(*UCP)\S/u')
                    ->validationMessages(['required' => '请输入书名。', 'max' => '书名不得超过255个字符。', 'regex' => '书名不能只包含空白。']),
                TextInput::make('genre')->label('题材')->maxLength(255)->placeholder('例如：玄幻、都市、悬疑'),
                Textarea::make('premise_raw')->label('原始创意')->required()->rows(10)
                    ->placeholder('写下故事背景、人物目标和主要矛盾，保留你希望实现的具体细节。')
                    ->helperText('原始创意会完整保存，不做摘要或改写。')->regex('/(*UCP)\S/u')
                    ->validationMessages(['required' => '请输入原始创意。', 'regex' => '原始创意不能只包含空白。']),
            ]),
            Step::make('篇幅')->columns(3)->schema([
                self::integerField('target_chapters', '目标章数', 10)->suffix('章'),
                self::integerField('chapter_length_policy.min_characters', '每章字数下限', 2500)->suffix('字'),
                self::integerField('chapter_length_policy.max_characters', '每章字数上限', 3500)->suffix('字')
                    ->gte('chapter_length_policy.min_characters'),
            ]),
            Step::make('生产方式')->schema([
                Radio::make('production_mode')->label('生产方式')->options(self::MODES)->default('manual')->required()->live(),
                Select::make('automatic_model')->label('模型（可选）')->options(ModelProfiles::options())->native(false)
                    ->placeholder(fn (): string => '使用通用设置的默认模型：'.app(GeneralSettings::class)->default_model)
                    ->helperText('只对这本书生效，不修改全局设置。'),
            ]),
            Step::make('确认启动')->schema([
                TextEntry::make('summary')->label('即将启动')->state(fn (Get $get): array => self::summary($get))->listWithLineBreaks(),
                TextEntry::make('cost_notice')->hiddenLabel()->state('启动后会立即调用模型并产生费用。当前未配置模型单价，金额会显示为“未知”，不代表免费。所有请求和失败都会记录在“费用明细”。'),
            ]),
        ];
    }

    /** 启动前的冻结配置摘要与预计请求次数。
     * @return list<string>
     */
    public static function summary(Get $get): array
    {
        $chapters = (int) $get('target_chapters');
        $mode = $get('production_mode') ?? 'manual';
        $model = $get('automatic_model') ?: app(GeneralSettings::class)->default_model;
        $batches = max(1, (int) ceil($chapters / 10));
        $lines = [
            '书名：'.($get('title') ?: '（未填写）'),
            '篇幅：'.$chapters.'章，每章'.$get('chapter_length_policy.min_characters').'～'.$get('chapter_length_policy.max_characters').'字',
            '生产方式：'.(self::MODES[$mode] ?? $mode),
            '模型：'.$model,
        ];
        $lines[] = match ($mode) {
            'auto_first' => '预计请求：设定、设定决策约2次，大纲'.$batches.'批，第1章约5～7次；失败会自动恢复一次，再失败则停下等你处理。',
            'auto_ten' => '预计请求：设定、设定决策约2次，大纲'.$batches.'批，前10章每章约5～7次（合计约50～70次）；失败会自动恢复一次，再失败则停下等你处理。',
            default => '本次只发送1次设定请求；之后每一步都由你在工作台确认再启动。',
        };

        return $lines;
    }

    /** 统一正整数边界与中文反馈，JSON 内的数值在应用服务中转换为整数。 */
    private static function integerField(string $name, string $label, int $default): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->integer()->required()
            ->minValue(1)->maxValue(2147483647)->default($default)
            ->validationMessages([
                'required' => '请填写:attribute。', 'integer' => ':attribute必须为整数。',
                'min' => ':attribute必须大于0。', 'max' => ':attribute超出允许范围。',
                'gte' => '每章字数上限不得小于下限。',
            ]);
    }
}
