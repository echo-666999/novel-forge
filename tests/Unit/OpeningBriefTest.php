<?php

namespace Tests\Unit;

use App\Services\Ai\OutlineGenerationAgent;
use App\Services\Ai\SettingDecisionAgent;
use App\Services\Ai\SettingGenerationAgent;
use App\Services\Ai\TextRequestOptions;
use App\Services\Pipeline\OpeningBrief;
use App\Services\Pipeline\SettingSchema;
use App\Services\Pipeline\StoryProfile;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** 开篇交代清单：设定结构随冻结标记增减，清单严格校验，前三章按章取项，检查结论不合格时记为未检查。 */
class OpeningBriefTest extends TestCase
{
    /** 一份合格的清单。
     * @return array<string, mixed>
     */
    private function brief(array $chapters = [1, 1, 2]): array
    {
        return ['premise_hook' => '周小麦重生回到让出转正名额的前一天', 'items' => array_map(fn (int $chapter, int $i): array => ['topic' => '交代'.$i, 'content' => '内容'.$i, 'technique' => '回忆', 'chapter' => $chapter], $chapters, array_keys($chapters))];
    }

    /** 完整设定外壳。 */
    private function setting(?array $brief): string
    {
        $setting = ['world' => ['era' => '1984', 'background' => '小镇', 'rules' => ['记忆会失准']], 'characters' => [['name' => '周小麦', 'role' => '女主', 'goal' => '护家', 'personality' => '泼辣', 'speech_style' => '账要算清楚', 'abilities' => '卤方', 'limitations' => '没本钱']],
            'tone' => ['narrative_style' => '口语', 'pov' => '第三人称', 'pacing' => '快'], 'core_conflict' => '继母夺名额', 'preserved_details' => ['卤方'], 'open_questions' => []];

        return json_encode($brief === null ? $setting : [...$setting, 'opening_brief' => $brief], JSON_UNESCAPED_UNICODE);
    }

    /** 故事根基另有主角处境和二至五级目标阶梯，各自可选校验。 */
    public function test_situation_and_goal_ladder(): void
    {
        $properties = array_keys((new JsonSchemaTypeFactory)->object(SettingSchema::schema(new JsonSchemaTypeFactory, true, true))->toArray()['properties']);
        $this->assertContains('protagonist_situation', $properties);
        $this->assertContains('goal_ladder', $properties);
        $ladder = [['goal' => '支起卤菜挑子', 'done_when' => '第一挑卖空', 'tradeoff' => '下班后才能摆'], ['goal' => '开小饭馆', 'done_when' => '盘下后厨', 'tradeoff' => '辞掉厂里的活']];
        $setting = json_decode($this->setting(null), true);
        $parsed = SettingSchema::parse(json_encode([...$setting, 'protagonist_situation' => '罐头厂临时工，住继母家', 'goal_ladder' => $ladder], JSON_UNESCAPED_UNICODE));
        $this->assertSame($ladder, $parsed['goal_ladder']);
        foreach ([[$ladder[0]], [[...$ladder[0], 'extra' => 'x'], $ladder[1]], [[...$ladder[0], 'goal' => ' '], $ladder[1]]] as $bad) {
            try {
                SettingSchema::parse(json_encode([...$setting, 'goal_ladder' => $bad], JSON_UNESCAPED_UNICODE));
                $this->fail('不合格目标阶梯应被拒绝');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        SettingSchema::parse(json_encode([...$setting, 'protagonist_situation' => ''], JSON_UNESCAPED_UNICODE));
    }

    /** 待确认问题的取舍方案写成文字列表时合成一段；混入非文字仍拒绝。 */
    public function test_open_question_impact_list_is_joined(): void
    {
        $setting = json_decode($this->setting(null), true);
        $question = ['question' => '名额和摆摊怎么并存', 'impact' => ['下班后摆摊（推荐）', '拿到名额后辞职'], 'blocking' => false];
        $this->assertSame('下班后摆摊（推荐）；拿到名额后辞职', SettingSchema::parse(json_encode([...$setting, 'open_questions' => [$question]], JSON_UNESCAPED_UNICODE))['open_questions'][0]['impact']);
        $this->expectException(InvalidArgumentException::class);
        SettingSchema::parse(json_encode([...$setting, 'open_questions' => [[...$question, 'impact' => [1]]]], JSON_UNESCAPED_UNICODE));
    }

    /** 冻结标记决定设定结构是否带清单；旧设定没有清单仍能通过校验。 */
    public function test_schema_follows_flag_and_parse_accepts_both_shapes(): void
    {
        $properties = fn (bool $opening): array => array_keys((new JsonSchemaTypeFactory)->object(SettingSchema::schema(new JsonSchemaTypeFactory, true, $opening))->toArray()['properties']);
        $this->assertNotContains('opening_brief', $properties(false));
        $this->assertContains('opening_brief', $properties(true));
        $this->assertArrayNotHasKey('opening_brief', SettingSchema::parse($this->setting(null)));
        $parsed = SettingSchema::parse($this->setting([...$this->brief(), 'items' => [...$this->brief()['items'], ['topic' => '表单', 'content' => '作者手填', 'technique' => '对话', 'chapter' => '3']]]));
        $this->assertSame([1, 1, 2, 3], array_column($parsed['opening_brief']['items'], 'chapter'));
    }

    /** 章号越界、第1章没有交代项、项数不足或字段缺失都不通过。 */
    public function test_parse_rejects_invalid_briefs(): void
    {
        foreach ([$this->brief([1, 2, 4]), $this->brief([2, 2, 3]), $this->brief([1, 1]), ['premise_hook' => ' ', 'items' => $this->brief()['items']], [...$this->brief(), 'extra' => 1]] as $brief) {
            try {
                OpeningBrief::parse($brief);
                $this->fail('不合格清单应被拒绝');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** 前三章各取本章的项，第4章起为空；没有清单的设定为空。 */
    public function test_chapter_items(): void
    {
        $setting = ['opening_brief' => OpeningBrief::parse($this->brief([1, 2, 2]))];
        $this->assertSame(['交代0'], array_column(OpeningBrief::chapterItems($setting, 1), 'topic'));
        $this->assertSame(['交代1', '交代2'], array_column(OpeningBrief::chapterItems($setting, 2), 'topic'));
        $this->assertSame([], OpeningBrief::chapterItems($setting, 3));
        $this->assertSame([], OpeningBrief::chapterItems($setting, 4));
        $this->assertSame([], OpeningBrief::chapterItems([], 1));
        $this->assertStringContainsString('「交代0」内容0（写法：回忆）', OpeningBrief::chapterInstructions(OpeningBrief::chapterItems($setting, 1), 'draft'));
        $this->assertStringContainsString('细纲要把每一项落到具体场景里', OpeningBrief::chapterInstructions(OpeningBrief::chapterItems($setting, 1), 'fine'));
    }

    /** 检查结论条数须与清单一致，证据只保留当前正文里的段落；主题以清单为准。 */
    public function test_parse_review(): void
    {
        $items = OpeningBrief::chapterItems(['opening_brief' => OpeningBrief::parse($this->brief([1, 1, 2]))], 1);
        $review = OpeningBrief::parseReview([['topic' => '乱写', 'coverage' => 'covered', 'reason' => '写清了', 'evidence_ids' => ['p1', 'p9']], ['topic' => 'x', 'coverage' => 'missing', 'reason' => '没写', 'evidence_ids' => []]], $items, ['p1' => '段落']);
        $this->assertSame([['topic' => '交代0', 'coverage' => 'covered', 'reason' => '写清了', 'evidence' => ['p1']], ['topic' => '交代1', 'coverage' => 'missing', 'reason' => '没写', 'evidence' => []]], $review);
        $this->expectException(InvalidArgumentException::class);
        OpeningBrief::parseReview([['topic' => 'x', 'coverage' => 'covered', 'reason' => '', 'evidence_ids' => []]], $items, []);
    }

    /** 设定与大纲请求的指令随冻结标记附加；题材包必备项写进设定指令。 */
    public function test_agent_instructions(): void
    {
        $setting = new SettingGenerationAgent(TextRequestOptions::setting(0.7, true)->withStoryFoundation(true, ['前世怎么走到结局']));
        $this->assertStringContainsString('本书题材要求的必备项：前世怎么走到结局。', $setting->instructions());
        $this->assertStringContainsString('每个冲突列入open_questions', $setting->instructions());
        $decision = (new SettingDecisionAgent(TextRequestOptions::decision()->withStoryFoundation(true)))->instructions();
        $this->assertStringContainsString('不要新增待答问题', $decision);
        $this->assertStringNotContainsString('每个冲突列入open_questions', $decision);
        $this->assertStringNotContainsString('开篇交代清单', (new SettingGenerationAgent(TextRequestOptions::setting(0.7, true)))->instructions());
        $outline = TextRequestOptions::outline(0.7, 1, 10, 100);
        $this->assertStringContainsString('confirmed_setting.opening_brief', (new OutlineGenerationAgent($outline->withStoryFoundation(true)))->instructions());
        // 后续批次只按目标阶梯安排，不再要求前三章交代。
        $later = (new OutlineGenerationAgent(TextRequestOptions::outline(0.7, 11, 20, 100)->withStoryFoundation(true)))->instructions();
        $this->assertStringContainsString('confirmed_setting.goal_ladder', $later);
        $this->assertStringNotContainsString('confirmed_setting.opening_brief', $later);
        $this->assertStringNotContainsString('opening_brief', (new OutlineGenerationAgent($outline))->instructions());
    }

    /** 题材包与叠加元素的必备项合并去重。 */
    public function test_checklist_merges_genre_and_elements(): void
    {
        $pack = fn (array $checklist): array => ['content' => ['opening_checklist' => $checklist]];
        $profile = ['genre' => $pack(['身份', '处境']), 'elements' => [$pack(['怎么穿来的', '处境'])]];
        $this->assertSame(['身份', '处境', '怎么穿来的'], StoryProfile::openingChecklist($profile));
        $this->assertSame([], StoryProfile::openingChecklist(null));
    }
}
