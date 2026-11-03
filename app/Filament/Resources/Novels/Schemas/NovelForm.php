<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Enums\GenerationMode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** 用原有字段收集原始创意与篇幅，不把运行状态混入作者输入。 */
class NovelForm
{
    /** 两段式表单保持创意输入和篇幅计划清楚可读。 */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('创作信息')
                    ->description('默认模拟不产生模型费用。显式选择真实设定将调用模型，成功后暂停等待大纲阶段；本阶段不生成正文。')
                    ->columns(2)->columnSpanFull()
                    ->schema([
                        Select::make('generation_mode')->label('生成模式')->required()->native()
                            ->options(collect(GenerationMode::cases())->mapWithKeys(fn (GenerationMode $mode): array => [$mode->value => $mode->label()])->all())
                            ->default(GenerationMode::Simulation->value)->columnSpanFull(),
                        TextInput::make('title')->label('书名')->required()->maxLength(255)->regex('/(*UCP)\S/u')
                            ->validationMessages(['required' => '请输入书名。', 'max' => '书名不得超过255个字符。', 'regex' => '书名不能只包含空白。']),
                        TextInput::make('genre')->label('题材')->maxLength(255)->placeholder('例如：玄幻、都市、悬疑'),
                        Textarea::make('premise_raw')->label('原始创意')->required()->rows(8)->columnSpanFull()
                            ->placeholder('写下故事背景、人物目标和主要矛盾，保留你希望实现的具体细节。')
                            ->helperText('原始创意会完整保存，不做摘要或改写。')->regex('/(*UCP)\S/u')
                            ->validationMessages(['required' => '请输入原始创意。', 'regex' => '原始创意不能只包含空白。']),
                    ]),
                Section::make('篇幅计划')->columns(3)->columnSpanFull()
                    ->schema([
                        self::integerField('target_chapters', '目标章数', 10)->suffix('章'),
                        self::integerField('chapter_length_policy.min_characters', '每章字数下限', 2500)->suffix('字'),
                        self::integerField('chapter_length_policy.max_characters', '每章字数上限', 3500)->suffix('字')
                            ->gte('chapter_length_policy.min_characters'),
                    ]),
            ]);
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
