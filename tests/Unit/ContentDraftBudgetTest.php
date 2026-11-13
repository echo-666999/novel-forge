<?php

namespace Tests\Unit;

use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\ContentQualityContract;
use App\Services\Pipeline\ContentReadabilityMetrics;
use App\Services\Pipeline\ContentSchema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 字数反馈必须可用于后继修复，不能放宽冻结的场景限制。 */
class ContentDraftBudgetTest extends TestCase
{
    /** 叙述段夹具。
     * @return array{kind: string, text: string}
     */
    private static function narration(string $text): array
    {
        return ['kind' => 'narration', 'text' => $text];
    }

    /** 对白段夹具。
     * @return array{kind: string, text: string}
     */
    private static function dialogue(string $text): array
    {
        return ['kind' => 'dialogue', 'text' => $text];
    }

    /** 同时报告过短与过长的槽位，计数包含中文标点。 */
    public function test_reports_all_failed_paragraph_lengths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('p1实际2字，要求3至4字；p2实际5字，要求3至4字');
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['p1' => [self::narration('短。')], 'p2' => [self::narration('这一段长。')]]], ['slots' => ['p1', 'p2'], 'min' => 3, 'max' => 4]);
    }

    /** 合法边界原样组装，程序不补字、不截断、不改正文。 */
    public function test_accepts_exact_bounds_without_modifying_prose(): void
    {
        $data = ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['p1' => [self::narration('她来了。')], 'p2' => [self::dialogue('“走吧。”')]]], ['slots' => ['p1', 'p2'], 'min' => 3, 'max' => 6, 'complete_paragraphs' => true]);
        $this->assertSame("她来了。\n“走吧。”", $data['body']);
    }

    /** 叙述段与对白段按顺序各成一行，拼接后每段成为独立证据段。 */
    public function test_scene_paragraphs_join_in_order(): void
    {
        $scene = [self::narration('母亲把钥匙推回来，指节在茶几上敲了两下，像在催她拿主意。'), self::dialogue('母亲压低声音：“别让你舅知道。”'), self::narration('她点头。')];
        $data = ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => $scene, 'scene_2' => [self::narration('门响了。')]]], ['slots' => ['scene_1', 'scene_2'], 'min' => 4, 'max' => 60, 'complete_paragraphs' => true]);
        $this->assertSame(implode("\n", [...array_column($scene, 'text'), '门响了。']), $data['body']);
        $this->assertCount(4, ContentSchema::anchors($data['body']));
        $this->assertSame([], ContentExecutionContract::incompleteParagraphs($data['body']));
    }

    /** 分段规则逐条拒绝：叙述夹带原话、对白挤多轮、对白混入长叙述、对白无引语、段内换行或Markdown。 */
    #[DataProvider('invalidParagraphs')]
    public function test_rejects_paragraph_rule_violations(array $paragraph, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scene_1第1段'.$message);
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => [$paragraph]]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 400]);
    }

    /** 每种违规各取一例。
     * @return array<string, array{array{kind: string, text: string}, string}>
     */
    public static function invalidParagraphs(): array
    {
        return [
            '叙述夹带原话' => [self::narration('她拨回去，说：“妈，这不是随手锉的。”母亲沉默了几秒。'), '是叙述段却夹带人物原话'],
            '叙述转引原话' => [self::narration('母亲已经说了句“先这样，我这边水开了”，挂了。听筒里只剩忙音。'), '是叙述段却夹带人物原话'],
            '对白挤多轮' => [self::dialogue('“钥匙呢？”母亲问。“我想留两天。”她说。“留它干什么？”'), '有3处引语'],
            '对白混入长叙述' => [self::dialogue('“放回去。”'.str_repeat('母亲把木箱钥匙收进抽屉，又转身去厨房关火。', 4)), '引号外叙述'],
            '对白无引语' => [self::dialogue('母亲让她把钥匙放回去。'), '是对白段却没有中文引号原话'],
            '段内换行' => [self::narration("她来了。\n门响了。"), '不得含换行'],
            'Markdown' => [self::narration('**她来了。**'), '不得含换行、其他空白或Markdown'],
        ];
    }

    /** 叙述里引用标题、栏目或记录原文不算对白；一次发言可被说话动作隔成两处引语。 */
    public function test_allows_term_quotes_and_split_utterance(): void
    {
        $scene = [self::narration('她翻到“备用钥匙锉记”那一栏，铅笔尖停在纸面上，半天没有挪开。'),
            self::narration('登记簿上那一栏写着“旧案专柜备用钥匙，锉记17”，备注字迹被水渍洇开，只剩半个“柜”字。'), self::dialogue('“走吧，”母亲说，“别等了。”')];
        $data = ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => $scene]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 100]);
        $this->assertCount(3, explode("\n", $data['body']));
    }

    /** 一个槽位最多两段短叙述，超过即视为一句一段。 */
    public function test_rejects_sentence_per_paragraph_narration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scene_1有3段叙述不足40字');
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => [self::narration('她来了。'), self::narration('门响了。'), self::narration('灯灭了。')]]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 100]);
    }

    /** 叙述段较多时按四分之一放宽短段，完整的过渡短句不被误判为一句一段。 */
    public function test_short_narration_allowance_scales(): void
    {
        $this->assertSame(2, ContentExecutionContract::allowedShortNarration(3));
        $this->assertSame(4, ContentExecutionContract::allowedShortNarration(16));
        $long = self::narration(str_repeat('灯', 59).'。');
        $short = self::narration('修复室暖气片发出轻响，窗缝里的风把复印件边角掀起，她用手掌压住。');
        $scene = [...array_fill(0, 12, $long), ...array_fill(0, 4, $short)];
        $data = ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => $scene]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 2000]);
        $this->assertCount(16, explode("\n", $data['body']));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scene_1有5段叙述不足40字，最多4段');
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => [...array_fill(0, 11, $long), ...array_fill(0, 5, $short)]]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 2000]);
    }

    /** 自动流程要求每段都完整收束，不能把半句留在段尾。 */
    public function test_complete_mode_checks_every_paragraph(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => [self::narration('她说'), self::dialogue('“走吧。”')]]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 50, 'complete_paragraphs' => true]);
    }

    /** v15按换行切分的旧草稿字符串仍能计数，但不是段落列表。 */
    public function test_slot_text_reads_lists_and_legacy_strings(): void
    {
        $this->assertSame("她来了。\n“走吧。”", ContentExecutionContract::slotText([self::narration('她来了。'), self::dialogue('“走吧。”')]));
        $this->assertSame("旧稿。\n“旧对白。”", ContentExecutionContract::slotText("旧稿。\n“旧对白。”"));
    }

    /** 每个场景一个槽位，只限定场景总字数，合计必然落在整章篇幅内。 */
    public function test_budget_assigns_one_slot_per_scene(): void
    {
        $plan = ['scenes' => [[], [], []]];
        $budget = ContentExecutionContract::budget(['min_characters' => 2500, 'max_characters' => 3500], $plan, true);
        $this->assertSame(['scene_1', 'scene_2', 'scene_3'], $budget['slots']);
        $this->assertSame([834, 1166], [$budget['min'], $budget['max']]);
        $this->assertGreaterThanOrEqual(2500, 3 * $budget['min']);
        $this->assertLessThanOrEqual(3500, 3 * $budget['max']);
        $sections = ContentExecutionContract::budget(['min_characters' => 2500, 'max_characters' => 3500], null, false);
        $this->assertSame(['section_1', 'section_2', 'section_3'], $sections['slots']);
    }

    /** 场景数随篇幅增长，短章不再排出每场只够写流程的密集场景。 */
    public function test_scene_range_follows_length(): void
    {
        $this->assertSame(['min' => 3, 'max' => 3], ContentExecutionContract::sceneRange(['min_characters' => 2500, 'max_characters' => 3500]));
        $this->assertSame(['min' => 3, 'max' => 3], ContentExecutionContract::sceneRange(['min_characters' => 500, 'max_characters' => 800]));
        $this->assertSame(['min' => 3, 'max' => 5], ContentExecutionContract::sceneRange(['min_characters' => 4000, 'max_characters' => 6000]));
    }

    /** 新细纲必须带写戏字段并遵守场景数；v14细纲仍可被只读核验。 */
    public function test_fine_requires_drama_fields_and_scene_range(): void
    {
        $scene = ['time' => '傍晚', 'place' => '库房', 'action' => '母女争执遗物去留', 'change' => '母亲松口', 'point_ids' => ['p1']];
        $legacy = ['scenes' => [$scene, $scene, $scene], 'hook' => '钥匙齿痕', 'adjustments' => [],
            'hook_transition' => ['completed_state' => '留下钥匙', 'trigger' => '齿痕异常', 'next_question' => '谁锉的', 'point_ids' => ['p1']]];
        $policy = ['min_characters' => 2500, 'max_characters' => 3500];
        $this->assertSame($legacy, ContentSchema::fine($legacy, ['p1']));
        $drama = ['goal' => '沈禾想留下钥匙', 'obstacle' => '母亲急着处理遗物', 'emotional_beat' => '母亲由烦躁转为心软', 'key_dialogue' => '“别让你舅知道。”'];
        $current = [...$legacy, 'scenes' => array_fill(0, 3, [...$scene, ...$drama])];
        $this->assertSame($current, ContentExecutionContract::fine($current, ['p1'], $policy));
        try {
            ContentExecutionContract::fine($legacy, ['p1'], $policy);
            $this->fail('缺少写戏字段的新细纲不能通过');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('goal', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('本章篇幅应规划3至3个场景，实际4个');
        ContentExecutionContract::fine([...$current, 'scenes' => array_fill(0, 4, [...$scene, ...$drama])], ['p1'], $policy);
    }

    /** 照搬细纲叙述句按段报告；台词要点、引号内设想和短片段不算照搬。 */
    public function test_plan_copies_detect_narrator_sentences_only(): void
    {
        $plan = ['scenes' => [[
            'action' => '母亲在电话里说“你先别跟你舅说”，沈禾想要一个能查下去的物证起点',
            'goal' => '留下钥匙', 'obstacle' => '母亲不愿深究', 'emotional_beat' => '由迟疑转为决意', 'change' => '获得默许',
            'key_dialogue' => '母亲：“你外婆一辈子要脸面，别翻出些没影的事。”',
        ]]];
        $body = "沈禾想要一个能查下去的物证起点。\n“你外婆一辈子要脸面，别翻出些没影的事。”\n“你先别跟你舅说。”";
        $issues = ContentQualityContract::planCopies($body, $plan);
        $this->assertCount(1, $issues);
        $this->assertSame(['type' => 'plan_copy', 'paragraph' => 1, 'anchor_id' => 'p1', 'quotes' => ['沈禾想要一个能查下去的物证起点']], array_diff_key($issues[0], ['reason' => true]));
        $this->assertSame([], ContentQualityContract::planCopies($body, null));
    }

    /** 首稿建议值取区间上四分位并给出段数与自检要求；单场景修复仍以区间中点为目标。 */
    public function test_first_draft_targets_upper_quartile(): void
    {
        $budget = ['slots' => ['scene_1', 'scene_2', 'scene_3'], 'min' => 834, 'max' => 1166];
        $draft = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget]);
        $this->assertStringContainsString('建议约1083字', $draft);
        $this->assertStringContainsString('按约18段规划', $draft);
        $this->assertStringContainsString('不足834字就在本场景内继续补写', $draft);
        $repair = ContentExecutionContract::outputInstructions('draft', ['budget' => [...$budget, 'slots' => ['scene_1']], 'draft_repair_slot' => 'scene_1', 'repair_direction' => 'expand', 'repair_current_count' => 769]);
        $this->assertStringContainsString('建议约1000字', $repair);
        $this->assertStringNotContainsString('按约', $repair);
    }

    /** 无问题编号的修订范围被丢弃，重复编号去重；全部无编号时整次修订无效。 */
    public function test_authorized_ranges_drop_unaddressed_ranges(): void
    {
        $fixed = ['start_id' => 'p1', 'end_id' => 'p2', 'text' => '改。', 'reason' => '修', 'addresses' => ['f1', 'f1']];
        $extra = ['start_id' => 'p3', 'end_id' => 'p4', 'text' => '润。', 'reason' => '顺手', 'addresses' => []];
        [$data, $dropped] = ContentExecutionContract::authorizedRanges(['ranges' => [$fixed, $extra]]);
        $this->assertSame([[...$fixed, 'addresses' => ['f1']]], $data['ranges']);
        $this->assertSame([$extra], $dropped);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('所有修订范围都没有对应问题编号');
        ContentExecutionContract::authorizedRanges(['ranges' => [$extra]]);
    }

    /** 修订范围的段落列表按正文规则校验后拼成文本，交给范围替换；叙述夹带原话同样被拒绝。 */
    public function test_revision_text_uses_paragraph_rules(): void
    {
        $range = ['start_id' => 'p1', 'end_id' => 'p2', 'reason' => '修', 'addresses' => ['f1'],
            'text' => [self::narration('她把手机换到另一只耳朵，听见那头锅铲停了，抽油烟机还在低低地响。'), self::dialogue('“你先别跟你舅说。”')]];
        $data = ContentExecutionContract::revisionText(['ranges' => [$range]], true);
        $this->assertSame("她把手机换到另一只耳朵，听见那头锅铲停了，抽油烟机还在低低地响。\n“你先别跟你舅说。”", $data['ranges'][0]['text']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('第1处修订第1段是叙述段却夹带人物原话');
        ContentExecutionContract::revisionText(['ranges' => [[...$range, 'text' => [self::narration('她对着电话说：“妈，这不是随手锉的。”母亲沉默了。')]]]], false);
    }

    /** 可读性指标：引用的栏目不算对白，转述只作估计；只有对白过低或流程词过密才成为修订问题。 */
    public function test_readability_metrics_flag_only_extremes(): void
    {
        $scene = "母亲在电话里问她什么时候回来。她翻到“备用钥匙锉记”那一栏。\n“妈，这不是随手锉的。”\n“你先别跟你舅说。”";
        $metrics = ContentReadabilityMetrics::measure($scene);
        $this->assertSame(ContentSchema::count('“妈，这不是随手锉的。”“你先别跟你舅说。”'), (int) round($metrics['dialogue_share'] * $metrics['characters']));
        $this->assertSame(1, $metrics['reported_speech']);
        $this->assertSame([], ContentReadabilityMetrics::issues($metrics));
        $flat = ContentReadabilityMetrics::measure(str_repeat('她核对登记簿编号后签字交接。', 20));
        $this->assertSame(['readability_dialogue', 'readability_process'], array_column(ContentReadabilityMetrics::issues($flat), 'type'));
    }

    /** v14至v16冻结摘要保持不变，旧运行可核验但新运行使用当前指令。 */
    public function test_frozen_protocols_stay_readable(): void
    {
        $current = ContentExecutionContract::protocol(ContentExecutionContract::VERSION);
        foreach (ContentExecutionContract::FROZEN_PROTOCOLS as $version => $hashes) {
            $this->assertTrue(ContentExecutionContract::readable($version));
            $this->assertSame($hashes, ContentExecutionContract::protocol($version));
            $this->assertSame($hashes['check'], $current['check']);
            $this->assertNotSame($hashes['revise'], $current['revise']);
        }
        $this->assertSame(ContentExecutionContract::FROZEN_PROTOCOLS['b04-content-v15']['fine'], $current['fine']);
        $this->assertFalse(ContentExecutionContract::readable('b04-content-v13'));
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::protocol('b04-content-v13');
    }

    /** 单场景恢复替换合法槽位且逐字保留其余正文。 */
    public function test_partial_repair_preserves_other_slots_and_budget(): void
    {
        $budget = ['slots' => ['p1', 'p2'], 'min' => 3, 'max' => 4, 'complete_paragraphs' => true];
        $draft = ['title' => '标题', 'paragraphs' => ['p1' => [self::narration('她来了。')], 'p2' => [self::narration('走。')]]];
        $result = ContentExecutionContract::mergeParagraphRepair($draft, ['title' => '标题', 'paragraphs' => ['p2' => [self::narration('走吧。')]]], $budget, 'p2');
        $this->assertSame($draft['paragraphs']['p1'], $result['paragraphs']['p1']);
        $this->assertSame([self::narration('走吧。')], $result['paragraphs']['p2']);
        $this->assertSame(3, ContentExecutionContract::repairBudget($budget, 'p2')['min']);
        $instructions = ContentExecutionContract::outputInstructions('draft', ['budget' => ['slots' => ['p2'], 'min' => 344, 'max' => 406], 'draft_repair_slot' => 'p2', 'repair_direction' => 'format']);
        $this->assertStringContainsString('建议约375字', $instructions);
        $this->assertStringContainsString('344至406', $instructions);
        $this->assertStringContainsString('不要一句一段', $instructions);
        $this->assertStringContainsString('对白段是一个人的一次发言', $instructions);
        $this->assertStringContainsString('不得照抄其中的句子', $instructions);
        $this->assertStringContainsString('只按previous_response_failure修正段落格式', $instructions);
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::mergeParagraphRepair($draft, ['title' => '标题', 'paragraphs' => ['p2' => [self::narration('走。')]]], $budget, 'p2');
    }
}
