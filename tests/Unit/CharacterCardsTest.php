<?php

namespace Tests\Unit;

use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\SettingSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** 扩展人物卡：结构随冻结标记增减，两种卡都能通过校验，正文只带本章出场人物的声纹。 */
class CharacterCardsTest extends TestCase
{
    /** 一张完整的扩展人物卡。
     * @return array<string, mixed>
     */
    private function card(string $name, string $tier = 'protagonist'): array
    {
        return ['name' => $name, 'role' => '主簿', 'goal' => '保住官位', 'personality' => '嘴硬心软', 'speech_style' => '爱说“按理说”', 'abilities' => '算账', 'limitations' => '半吊子史识',
            'tier' => $tier, 'desire' => '把穷县盘活', 'fear' => '先知出错害死人', 'flaw' => '爱逞口舌', 'secret' => '是穿越者', 'relation_to_protagonist' => '本人', 'arc' => '混日子→担当',
            'quirks' => ['心虚挠耳朵'], 'voice_samples' => ['按理说，这账不该这么算。', '大人，下官有个不成熟的想法。']];
    }

    /** 完整设定外壳。
     * @param  list<array<string, mixed>>  $characters
     */
    private function setting(array $characters): string
    {
        return json_encode(['world' => ['era' => '北宋', 'background' => '穷县', 'rules' => ['不改写正史']], 'characters' => $characters,
            'tone' => ['narrative_style' => '幽默', 'pov' => '第三人称', 'pacing' => '快'], 'core_conflict' => '穷', 'preserved_details' => ['三十七贯'], 'open_questions' => []], JSON_UNESCAPED_UNICODE);
    }

    /** 冻结标记决定结构是否带扩展字段。 */
    public function test_schema_follows_flag(): void
    {
        $properties = fn (bool $cards): array => array_keys((new JsonSchemaTypeFactory)->object(SettingSchema::schema(new JsonSchemaTypeFactory, $cards))->toArray()['properties']['characters']['items']['properties']);
        $this->assertNotContains('voice_samples', $properties(false));
        $this->assertContains('voice_samples', $properties(true));
        $this->assertContains('tier', $properties(true));
    }

    /** 扩展卡与旧七项卡都能通过；扩展卡缺字段、层级无效或台词超过三句时拒绝。 */
    public function test_parse_accepts_both_card_shapes(): void
    {
        $legacy = array_intersect_key($this->card('韩知白'), array_flip(SettingSchema::CHARACTER_FIELDS));
        $this->assertCount(2, SettingSchema::parse($this->setting([$this->card('沈砚之'), $legacy]))['characters']);
        $missing = $this->card('沈砚之');
        unset($missing['fear']);
        foreach ([$missing, [...$this->card('沈砚之'), 'tier' => 'hero'], [...$this->card('沈砚之'), 'voice_samples' => ['一', '二', '三', '四']], [...$this->card('沈砚之'), 'quirks' => []]] as $bad) {
            try {
                SettingSchema::parse($this->setting([$bad]));
                $this->fail('应拒绝');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** 声纹只取本章计划里出现的扩展卡人物；一个都没出现时取主角；旧卡不产生声纹。 */
    public function test_voice_cards_follow_chapter_plan(): void
    {
        $setting = ['characters' => [$this->card('沈砚之'), $this->card('张铁牛', 'core'), $this->card('方员外', 'antagonist')]];
        $this->assertSame(['张铁牛', '方员外'], array_column(SettingSchema::voiceCards($setting, '张铁牛拎刀堵住方员外的管事'), 'name'));
        $this->assertSame(['沈砚之'], array_column(SettingSchema::voiceCards($setting, '县衙里没人'), 'name'));
        $this->assertSame([], SettingSchema::voiceCards(['characters' => [array_intersect_key($this->card('沈砚之'), array_flip(SettingSchema::CHARACTER_FIELDS))]], '沈砚之'));
        $this->assertSame(['按理说，这账不该这么算。', '大人，下官有个不成熟的想法。'], SettingSchema::voiceCards($setting, '沈砚之')[0]['voice_samples']);
    }

    /** 首稿与修订指令在冻结声纹时附加声纹要求。 */
    public function test_voice_instructions_reach_draft_and_revise(): void
    {
        $this->assertStringContainsString('遮住名字也能认出', ContentExecutionContract::outputInstructions('revise', ['anchors' => ['p1' => [], 'p2' => []], 'voice_cards' => true]));
        $this->assertStringNotContainsString('人物声纹', ContentExecutionContract::outputInstructions('revise', ['anchors' => ['p1' => [], 'p2' => []]]));
    }
}
