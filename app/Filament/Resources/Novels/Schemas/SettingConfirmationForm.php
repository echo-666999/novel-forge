<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Services\Pipeline\OpeningBrief;
use App\Services\Pipeline\SettingSchema;
use App\Services\Pipeline\StoryFoundation;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

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
        // 扩展人物卡（带层级）才显示新增字段；七项旧卡隐藏这些字段，保存结构不变。
        $extended = fn (Get $get): bool => filled($get('tier'));
        $characterFields[] = Select::make('tier')->label('人物层级')->options(SettingSchema::TIERS)->native(false)->required()->visible($extended);
        foreach (['desire' => '欲望（具体、可能失去）', 'fear' => '最怕什么', 'flaw' => '会让他吃亏的毛病', 'secret' => '秘密（没有写“暂无”）', 'relation_to_protagonist' => '与主角的关系及变化', 'arc' => '人物弧线（起点→终点）'] as $name => $label) {
            $characterFields[] = Textarea::make($name)->label($label)->required()->rows(2)->visible($extended);
        }
        $characterFields[] = TagsInput::make('quirks')->label('小习惯（口头禅、动作、穿着，1–3个）')->required()->visible($extended);
        $characterFields[] = Repeater::make('voice_samples')->label('代表台词（1–3句）')->simple(Textarea::make('line')->required()->rows(1))->minItems(1)->maxItems(3)->visible($extended);

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
            // 冻结了故事根基的书才有以下两节；旧设定没有这些字段，不显示也不保存。
            Section::make('主角处境与主线目标')->description('主角开篇时的身份、营生和日常安排，以及主线按先后要做成的几件事。大纲会让每一级目标有明确的达成或放弃章节；创意里互相打架的目标列在下方“待确认问题”里，请选定取舍。')
                ->visible(fn (Get $get): bool => filled($get('setting.protagonist_situation')))->schema([
                    Textarea::make('setting.protagonist_situation')->label('主角当前处境')->required()->rows(3),
                    Repeater::make('setting.goal_ladder')->label('主线目标阶梯（按先后，'.StoryFoundation::MIN_GOALS.'–'.StoryFoundation::MAX_GOALS.'级）')->schema([
                        Textarea::make('goal')->label('这一阶段要做成什么')->required()->rows(2),
                        Textarea::make('done_when')->label('读者怎么看出它做成了')->required()->rows(2),
                        Textarea::make('tradeoff')->label('为此放弃或付出什么')->required()->rows(2),
                    ])->columns(3)->minItems(StoryFoundation::MIN_GOALS)->maxItems(StoryFoundation::MAX_GOALS),
                ]),
            Section::make('开篇交代（前三章）')->description('读者在前三章必须知道的背景：重生、穿越这类设定怎么来的、主角是谁、带着什么、想要什么。大纲和前三章正文会逐项安排，检查会记录是否写清楚。')
                ->visible(fn (Get $get): bool => filled($get('setting.opening_brief.premise_hook')))->schema([
                    Textarea::make('setting.opening_brief.premise_hook')->label('本书立足的设定')->required()->rows(2),
                    Repeater::make('setting.opening_brief.items')->label('交代项（'.OpeningBrief::MIN_ITEMS.'–'.OpeningBrief::MAX_ITEMS.'项，第1章至少一项）')->schema([
                        TextInput::make('topic')->label('交代什么')->required()->maxLength(255),
                        Select::make('chapter')->label('安排在')->options(array_combine(range(1, OpeningBrief::LAST_CHAPTER), array_map(fn (int $n): string => '第'.$n.'章', range(1, OpeningBrief::LAST_CHAPTER))))->native(false)->required(),
                        Textarea::make('content')->label('具体内容')->required()->rows(3)->columnSpanFull(),
                        Textarea::make('technique')->label('建议写法')->required()->rows(2)->columnSpanFull(),
                    ])->columns(2)->minItems(OpeningBrief::MIN_ITEMS)->maxItems(OpeningBrief::MAX_ITEMS),
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
