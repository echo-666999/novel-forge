<?php

namespace App\Filament\Resources\Novels\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/** 完整修订候选并回答来源问题，不预填决定或自动批准故事。 */
final class SettingConfirmationForm
{
    /** 采用既有 Filament 表单，原始模型输出始终保留。
     * @return list<Component>
     */
    public static function components(): array
    {
        $characterFields = [];
        foreach (['name' => '姓名', 'role' => '角色', 'goal' => '目标', 'personality' => '性格', 'speech_style' => '语言习惯', 'abilities' => '能力', 'limitations' => '限制'] as $name => $label) {
            $characterFields[] = Textarea::make($name)->label($label)->required()->rows(2);
        }

        return [
            Hidden::make('source_hash')->required(),
            Section::make('修订设定候选')->description('请检查世界规则、人物生死与结局方向。确认会保存独立版本，不覆盖原始候选，也不会调用模型。')->schema([
                TextInput::make('setting.world.era')->label('时代')->required(),
                Textarea::make('setting.world.background')->label('世界背景')->required()->rows(3),
                Repeater::make('setting.world.rules')->label('世界规则')->simple(Textarea::make('rule')->label('规则内容')->required()->rows(2))->minItems(1),
                Repeater::make('setting.characters')->label('人物卡')->schema($characterFields)->minItems(1)->columns(2),
                Textarea::make('setting.tone.narrative_style')->label('文风')->required()->rows(2),
                Textarea::make('setting.tone.pov')->label('叙事视角')->required()->rows(2),
                Textarea::make('setting.tone.pacing')->label('节奏')->required()->rows(2),
                Textarea::make('setting.core_conflict')->label('核心矛盾')->required()->rows(3),
                Repeater::make('setting.preserved_details')->label('必须保留的创意细节')->simple(Textarea::make('detail')->label('具体细节')->required()->rows(2))->minItems(1),
            ]),
            Repeater::make('answers')->label('来源待确认问题')->addable(false)->deletable(false)->reorderable(false)->schema([
                Textarea::make('question')->label('问题')->disabled()->dehydrated(false)->rows(2),
                Textarea::make('impact')->label('影响')->disabled()->dehydrated(false)->rows(2),
                Textarea::make('answer')->label('作者决定')->helperText('阻塞问题必须回答；请同步修订上方设定，使其与决定一致。')->rows(3),
            ]),
            Checkbox::make('acknowledged')->label('我已审阅修订内容与问题答案，并确认此版本可用于大纲规划。')->accepted(),
        ];
    }
}
