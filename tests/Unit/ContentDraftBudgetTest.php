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

    /** 正文标题带"第N章"前缀时去掉前缀；只有前缀的占位标题仍被拒绝。 */
    public function test_draft_title_drops_chapter_prefix(): void
    {
        $scene = [self::narration(str_repeat('甲', 149).'。')];
        $budget = ['slots' => ['s1'], 'count' => 1, 'min' => 100, 'max' => 200];
        $this->assertSame('那个嘛，试一回', ContentExecutionContract::body(['title' => '第3章 那个嘛，试一回', 'paragraphs' => ['s1' => $scene]], $budget)['title']);
        $this->assertSame('夜雨', ContentExecutionContract::body(['title' => '第十二章：夜雨', 'paragraphs' => ['s1' => $scene]], $budget)['title']);
        $this->assertSame('灶膛里的卤方', ContentExecutionContract::body(['title' => '灶膛里的卤方', 'paragraphs' => ['s1' => $scene]], $budget)['title']);
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::body(['title' => '第3章', 'paragraphs' => ['s1' => $scene]], $budget);
    }

    /** 单场景字数允许偏离区间5%：区间外5%以内接受，超过5%拒绝；整章合计仍不得超出各场景区间之和。 */
    public function test_scene_length_tolerance_keeps_chapter_total(): void
    {
        $scene = fn (int $characters): array => [self::narration(str_repeat('甲', $characters - 1).'。')];
        $budget = ['slots' => ['s1', 's2'], 'count' => 2, 'min' => 100, 'max' => 200];
        $data = ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['s1' => $scene(210), 's2' => $scene(150)]], $budget);
        $this->assertSame(360, ContentSchema::count($data['body']));
        $this->assertSame([95, 210], ContentExecutionContract::tolerated($budget));
        $this->assertSame(10, ContentExecutionContract::lengthDistance(210, $budget));
        // 单场景修复只看本场景，5%以内即可合入，整章校验另行把关。
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['s1' => $scene(205)]], ContentExecutionContract::repairBudget($budget, 's1'));
        foreach ([[[211, 150], 's1实际211字，要求100至200字'], [[205, 205], '整章合计410字，要求200至400字']] as [[$first, $second], $message]) {
            try {
                ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['s1' => $scene($first), 's2' => $scene($second)]], $budget);
                $this->fail('应拒绝：'.$message);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
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
            '叙述夹带原话' => [self::narration('她拨回去，说：“妈，这不是随手锉的。”母亲沉默了几秒。一柜三格，第三格放底册；账上却写柜体锈蚀、无保存价值。前者像在指路，后者像有人拿布把路标盖住了，她越看越觉得这两页纸说的根本不是同一只柜子。'), '是叙述段却夹带人物原话'],
            '叙述转引原话' => [self::narration('沈禾没有立刻接话。小陶说“好好的，锁都还在”时，嘴角往下撇了一下，那点不信比任何解释都让她心里发沉。一柜三格，第三格放底册；账上却写柜体锈蚀、无保存价值。前者像在指路，后者像有人拿布把路标盖住了，她越看越觉得这两页纸说的根本不是同一只柜子。'), '是叙述段却夹带人物原话'],
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

    /** 紧挨对白的短叙述是对话节奏中的动作或神态，不计入短叙述上限；前后都不是对白的短叙述仍计数。 */
    public function test_short_narration_next_to_dialogue_is_not_counted(): void
    {
        $long = self::narration(str_repeat('灯', 59).'。');
        $beats = [];
        foreach (range(1, 5) as $i) {
            $beats[] = self::dialogue('“第'.$i.'句话。”');
            $beats[] = self::narration('母亲擦手的动作停了，过了几秒才开口。');
        }
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => [$long, ...$beats]]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 2000]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scene_1有3段叙述不足40字，最多2段');
        $loose = [self::narration('门响了。'), $long, self::narration('灯灭了。'), $long, self::narration('雨停了。'), $long];
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['scene_1' => $loose]], ['slots' => ['scene_1'], 'min' => 1, 'max' => 2000]);
    }

    /** 正文阶段逐场景检查照搬细纲、转述相邻对白与写作说明，问题归到所在场景及场景内段号；没有问题的场景不出现。 */
    public function test_slot_quality_locates_plan_copy_and_echo_per_scene(): void
    {
        $plan = ['scenes' => [['action' => '沈禾把信和登记簿复印件并排放在茶几上，等母亲开口。']]];
        $paragraphs = [
            'scene_1' => [self::narration('沈禾把信和登记簿复印件并排放在茶几上，灯光落在纸面，她没有立刻说话，只把杯子往旁边挪了挪。')],
            'scene_2' => [self::dialogue('“柜体锈蚀无保存价值，账上就这么写的。”'), self::narration('小陶刚才说柜体锈蚀无保存价值，她听得心里一沉，手指在桌沿上停了很久，没有接话，也没有抬头。')],
            'scene_3' => [self::narration('她把文件袋收好，起身去关窗，雨声小了一些，楼下有人在收晾着的衣服，竹竿碰得叮当响。')],
        ];
        $errors = ContentExecutionContract::slotQuality($paragraphs, ['scene_1', 'scene_2', 'scene_3'], $plan);
        $this->assertSame(['scene_1', 'scene_2'], array_keys($errors));
        $this->assertStringContainsString('scene_1第1段逐字照搬细纲句子「沈禾把信和登记簿复印件并排放在茶几上」', $errors['scene_1'][0]);
        $this->assertStringContainsString('scene_2第2段叙述把第1段对白的原话先转述了一遍', $errors['scene_2'][0]);
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

    /** 照搬清单只取细纲叙述字段中达到照搬字数的分句，去重保序；引号内台词与key_dialogue不列入，并与检测结果一致。 */
    public function test_plan_fragments_match_plan_copy_detection(): void
    {
        $plan = ['scenes' => [
            ['action' => '沈禾把裁切过的照片打印件和登记簿复印件并排放在茶几上，等母亲先看见。', 'goal' => '母亲说“你要查就查到底别查一半又缩回去”', 'key_dialogue' => '母亲让她查到底别查一半又缩回去那样更害人'],
            ['change' => '沈禾把裁切过的照片打印件和登记簿复印件并排放在茶几上', 'emotional_beat' => '母亲从迟疑转为决断'],
        ]];
        $this->assertSame(['沈禾把裁切过的照片打印件和登记簿复印件并排放在茶几上'], ContentQualityContract::planFragments($plan));
        $this->assertSame([], ContentQualityContract::planFragments(null));
        $issues = ContentQualityContract::planCopies('她把裁切过的照片放下。沈禾把裁切过的照片打印件和登记簿复印件并排放在茶几上，没有说话。', $plan);
        $this->assertSame(ContentQualityContract::planFragments($plan), $issues[0]['quotes']);
    }

    /** 首稿、文字问题修复和修订指令都写明照搬提纲与转述对白的检测阈值，数值与检测常量一致。 */
    public function test_instructions_state_text_check_thresholds(): void
    {
        $budget = ['slots' => ['scene_1', 'scene_2', 'scene_3'], 'min' => 834, 'max' => 1166];
        $copy = '任何一个'.ContentQualityContract::PLAN_COPY_CHARACTERS.'字及以上的分句';
        $echo = '对白连续'.ContentQualityContract::ECHO_CHARACTERS.'个字相同';
        $draft = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget]);
        $quality = ContentExecutionContract::outputInstructions('draft', ['budget' => [...$budget, 'slots' => ['scene_2']], 'draft_repair_slot' => 'scene_2', 'repair_direction' => 'quality']);
        $revise = ContentExecutionContract::outputInstructions('revise', ['anchors' => ['p1' => [], 'p2' => [], 'p3' => []]]);
        foreach ([$draft, $quality, $revise] as $instructions) {
            $this->assertStringContainsString($copy, $instructions);
            $this->assertStringContainsString($echo, $instructions);
            $this->assertStringContainsString('说、问、告诉', $instructions);
            $this->assertStringContainsString('key_dialogue是台词要点', $instructions);
        }
        // 文字问题修复额外提醒对照未改动的相邻对白，并按清单避开细纲原句；首稿不带这两句。
        $this->assertStringContainsString('包括本次没有改动的对白段', $quality);
        $this->assertStringContainsString('plan_sentences_to_avoid列出细纲中所有会被判为照搬的分句', $quality);
        $this->assertStringNotContainsString('plan_sentences_to_avoid', $draft);
        $this->assertStringNotContainsString('包括本次没有改动的对白段', $draft);
        $this->assertSame(['说', '问', '告诉'], array_slice(ContentQualityContract::REPORTING_VERBS, 0, 3));
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

    /** 标成叙述、但整段符合对白段规则（一次发言加紧邻动作）的段落按对白段接受，文字原样不改；正文与修订范围同样适用。 */
    public function test_narration_that_is_a_valid_dialogue_paragraph_is_accepted(): void
    {
        $texts = ['沈禾把杯子挪开，声音放轻：“几点回来的？”', '她拨回去，说：“妈，这不是随手锉的。”母亲沉默了几秒。'];
        $data = ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['p1' => array_map(fn (string $text): array => self::narration($text), $texts)]], ['slots' => ['p1'], 'min' => 1, 'max' => 200]);
        $this->assertSame(implode("\n", $texts), $data['body']);
        $range = ['start_id' => 'p1', 'end_id' => 'p1', 'reason' => '修', 'addresses' => ['f1'], 'text' => [self::narration($texts[0])]];
        $this->assertSame($texts[0], ContentExecutionContract::revisionText(['ranges' => [$range]], true)['ranges'][0]['text']);
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
        ContentExecutionContract::revisionText(['ranges' => [[...$range, 'text' => [self::narration('她对着电话说：“妈，这不是随手锉的。”母亲沉默了。一柜三格，第三格放底册；账上却写柜体锈蚀、无保存价值。前者像在指路，后者像有人拿布把路标盖住了，她越看越觉得这两页纸说的根本不是同一只柜子。')]]]], false);
    }

    /** 模型把段落写成纯字符串时按分段规则判定类型并照常复验，不因缺少kind作废整次修订。 */
    public function test_revision_text_accepts_plain_string_paragraphs(): void
    {
        $narration = '她把手机换到另一只耳朵，听见那头锅铲停了，抽油烟机还在低低地响。';
        $range = ['start_id' => 'p1', 'end_id' => 'p2', 'reason' => '修', 'addresses' => ['f1'], 'text' => [$narration, '“你先别跟你舅说。”母亲压低了声音。']];
        $this->assertSame($narration."\n“你先别跟你舅说。”母亲压低了声音。", ContentExecutionContract::revisionText(['ranges' => [$range]], true)['ranges'][0]['text']);

        // 纯字符串同样受对白规则约束：一段里三处引语仍被拒绝。
        try {
            ContentExecutionContract::revisionText(['ranges' => [[...$range, 'text' => ['“走。”“去哪？”“库房。”']]]], false);
            $this->fail('多人发言挤在一段应被拒绝');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('第1处修订第1段有3处引语', $exception->getMessage());
        }

        // 其他非对象段落给出位置与正确格式，补发时模型可据此改正。
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('第1处修订第2段必须是只含kind与text两个字段的对象');
        ContentExecutionContract::revisionText(['ranges' => [[...$range, 'text' => [$narration, ['text' => $narration]]]]], false);
    }

    /** 叙述把相邻对白的原话先转述一遍时按叙述段报告；只共用人名物名等短词不算重复。 */
    public function test_echoed_dialogue_is_reported_on_the_narration_paragraph(): void
    {
        $body = "小陶看了她一眼，让她记就记，只是别用红笔，说红笔在库房那边看着扎眼。\n“你记吧，别用红笔。红笔在库房那边看着扎眼。”\n她把“锉记十七”抄进笔记。\n“锉记十七那一行，你别动。”";
        $issues = ContentQualityContract::echoedDialogue($body);
        $this->assertCount(1, $issues);
        $this->assertSame(['type' => 'echoed_dialogue', 'paragraph' => 1, 'anchor_id' => 'p1', 'dialogue_paragraph' => 2, 'quotes' => ['红笔在库房那']], array_diff_key($issues[0], ['reason' => true]));
        $this->assertSame([], ContentQualityContract::echoedDialogue("母亲在厨房关了火。\n“你先别跟你舅说。”"));
    }

    /** 动作描写与对白共用物名、编号，或叙述抄录文书原文时不算转述；只有重合处之前用转述动词引出才报告。 */
    public function test_echoed_dialogue_requires_reporting_verb(): void
    {
        // 第510次运行的误判：叙述写比对动作，对白说出同一个物名。
        $action = "前三位对上了，后两位被锁扣遮住，她便去看柜脚的黑漆数字，又去看备注残字底下露出一截笔画。\n“您再看一眼，柜脚喷漆的号和备注残字底下的号段接得上。”";
        $this->assertSame([], ContentQualityContract::echoedDialogue($action));
        $record = "她翻到附注页，看见一行小字：开箱时开发商方面可派员到场见证。\n“附注只写可派员到场见证，没写由谁通知。”";
        $this->assertSame([], ContentQualityContract::echoedDialogue($record));
        // 问号、说明等词含转述动词用字但不表示说话。
        $words = "她只留下两个问号：技术复核科另存复印件里有没有旧案记录。\n“技术复核科另存复印件待核。”";
        $this->assertSame([], ContentQualityContract::echoedDialogue($words));
        // 同一处重合由转述动词引出时照常报告。
        $reported = "她压低声音，说备注残字底下其实还有一截号码。\n“备注残字底下还有一截号码。”";
        $this->assertSame([[1, 2]], array_map(fn (array $issue): array => [$issue['paragraph'], $issue['dialogue_paragraph']], ContentQualityContract::echoedDialogue($reported)));
    }

    /** 一段叙述同时与前后两段对白重合时分别报告，修复指令一次列全。 */
    public function test_echoed_dialogue_reports_both_neighbors(): void
    {
        $body = "“老库房那种铁皮的。”\n她停了一下，说老库房那种铁皮卷柜是分格的，第三格大多放底册和签收单。\n“第三格放底册和签收单。”";
        $issues = ContentQualityContract::echoedDialogue($body);
        $this->assertSame([[2, 1], [2, 3]], array_map(fn (array $issue): array => [$issue['paragraph'], $issue['dialogue_paragraph']], $issues));
    }

    /** 能认出两边说话人且不是同一人时，接话和复述确认不算转述；同一人或认不出说话人时照常报告。 */
    public function test_echoed_dialogue_skips_replies_between_different_speakers(): void
    {
        $exchange = "沈禾没挪开：“你再想想，老库房那种铁皮的。”\n小陶停了一下，说老库房那种铁皮卷柜是分格的，一柜三格，第三格大多放底册和签收单。\n沈禾：“一柜三格，第三格放底册和签收单？”\n小陶：“大概是这样。”";
        $this->assertSame([], ContentQualityContract::echoedDialogue($exchange));
        $this->assertSame([], ContentQualityContract::echoedDialogue("母亲说外婆只说库房那边有点事，别多问。\n沈禾声音放轻：“库房那边有点事，是什么事？”", ['沈禾']));

        $same = "小陶说账上写的是柜体锈蚀、无保存价值，那排柜子其实还好好的。\n小陶：“账上写的是柜体锈蚀、无保存价值。”";
        $this->assertCount(1, ContentQualityContract::echoedDialogue($same));
        $this->assertSame(['沈禾', '周德茂'], ContentQualityContract::characterNames(['confirmed_setting' => ['characters' => [['name' => '沈禾'], ['name' => '沈禾的外婆（已故）'], ['name' => '周德茂（证人）']]]]));
    }

    /** 修订范围只能落在带段号问题的附近，合计不超过约三分之一正文；整章或旧正文的问题不施加位置限制。 */
    public function test_revision_scope_limits_ranges(): void
    {
        $anchors = [];
        foreach (range(1, 30) as $n) {
            $anchors['p'.$n] = ['paragraph' => $n, 'text' => '段。', 'body_hash' => 'h'];
        }
        $findings = ['near' => ['type' => 'plan_copy', 'paragraph' => 20, 'source_body_hash' => 'h'], 'old' => ['type' => 'check', 'evidence_ids' => ['p2'], 'source_body_hash' => 'old'],
            'whole' => ['type' => 'whole_chapter', 'source_body_hash' => 'h']];
        $range = fn (string $start, string $end, array $addresses): array => ['start_id' => $start, 'end_id' => $end, 'addresses' => $addresses];
        ContentExecutionContract::revisionScope(['ranges' => [$range('p17', 'p23', ['near'])]], $anchors, $findings);
        ContentExecutionContract::revisionScope(['ranges' => [$range('p1', 'p8', ['old']), $range('p25', 'p26', ['whole'])]], $anchors, $findings);
        try {
            ContentExecutionContract::revisionScope(['ranges' => [$range('p2', 'p9', ['near'])]], $anchors, $findings);
            $this->fail('离问题过远的范围不能通过');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('离所处理问题的段落（p20）太远', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('合计覆盖11段，超过上限10段');
        ContentExecutionContract::revisionScope(['ranges' => [$range('p1', 'p11', ['whole'])]], $anchors, $findings);
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

    /** 文字问题可合并修复多个槽位：只替换指定槽位，其余逐字保留，指令列出全部字段。 */
    public function test_partial_repair_merges_several_slots(): void
    {
        $budget = ['slots' => ['p1', 'p2', 'p3'], 'min' => 3, 'max' => 4, 'complete_paragraphs' => true];
        $draft = ['title' => '标题', 'paragraphs' => ['p1' => [self::narration('她来了。')], 'p2' => [self::narration('他走了。')], 'p3' => [self::narration('灯亮了。')]]];
        $replacement = ['title' => '标题', 'paragraphs' => ['p3' => [self::narration('灯灭了。')], 'p1' => [self::narration('她到了。')]]];
        $result = ContentExecutionContract::mergeParagraphRepair($draft, $replacement, $budget, ['p3', 'p1']);
        $this->assertSame([self::narration('她到了。')], $result['paragraphs']['p1']);
        $this->assertSame($draft['paragraphs']['p2'], $result['paragraphs']['p2']);
        $this->assertSame([self::narration('灯灭了。')], $result['paragraphs']['p3']);
        // 按冻结目录顺序排列，计数随槽位数变化。
        $repairBudget = ContentExecutionContract::repairBudget($budget, ['p3', 'p1']);
        $this->assertSame(['p1', 'p3'], $repairBudget['slots']);
        $this->assertSame(2, $repairBudget['count']);
        $this->assertSame(3, $repairBudget['chapter_slots']);
        $instructions = ContentExecutionContract::outputInstructions('draft', ['budget' => $repairBudget, 'draft_repair_slot' => 'p1', 'draft_repair_slots' => ['p1', 'p3'], 'repair_direction' => 'quality']);
        $this->assertStringContainsString('2个分节修复', $instructions);
        $this->assertStringContainsString('指定的字段p1、p3', $instructions);
        $this->assertStringNotContainsString('唯一字段', $instructions);
        // 缺少任一指定槽位都不能合并。
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::mergeParagraphRepair($draft, ['title' => '标题', 'paragraphs' => ['p1' => [self::narration('她到了。')]]], $budget, ['p1', 'p3']);
    }

    /** 冻结目录以外的槽位不能进入修复预算。 */
    public function test_repair_budget_rejects_unknown_slots(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::repairBudget(['slots' => ['p1', 'p2'], 'min' => 3, 'max' => 4], ['p1', 'p9']);
    }

    /** 细纲action写成成段叙述时整份细纲无效，并报告每个超长场景的实测字数；指令给出节拍上限。 */
    public function test_fine_rejects_prose_length_action(): void
    {
        $policy = ['min_characters' => 2500, 'max_characters' => 3500];
        $scene = fn (string $action): array => ['time' => '傍晚', 'place' => '库房', 'action' => $action, 'change' => '母亲松口', 'goal' => '找到柜子', 'obstacle' => '母亲回避', 'emotional_beat' => '迟疑转为决意', 'key_dialogue' => '母亲让她查到底', 'point_ids' => ['p1']];
        $fine = ['hook' => '钩子', 'adjustments' => [], 'hook_transition' => ['completed_state' => '完成', 'trigger' => '触发', 'next_question' => '问题', 'point_ids' => ['p1']]];
        $limit = ContentExecutionContract::PLAN_ACTION_LIMIT;
        $data = ContentExecutionContract::fine([...$fine, 'scenes' => [$scene(str_repeat('甲', $limit)), $scene('乙'), $scene('丙')]], ['p1'], $policy);
        $this->assertCount(3, $data['scenes']);
        $instructions = ContentExecutionContract::outputInstructions('fine', ['scene_range' => ContentExecutionContract::sceneRange($policy)]);
        $this->assertStringContainsString('约'.ContentExecutionContract::PLAN_ACTION_CHARACTERS.'字以内', $instructions);
        $this->assertStringContainsString('超过'.$limit.'字整份细纲无效', $instructions);
        try {
            ContentExecutionContract::fine([...$fine, 'scenes' => [$scene('乙'), $scene(str_repeat('甲', $limit + 1)), $scene(str_repeat('丙', $limit + 5))]], ['p1'], $policy);
            $this->fail('超长action应当无效');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringStartsWith(ContentExecutionContract::PLAN_ACTION_ERROR, $exception->getMessage());
            $this->assertStringContainsString('scene_2实际'.($limit + 1).'字；scene_3实际'.($limit + 5).'字', $exception->getMessage());
            $this->assertStringNotContainsString('scene_1', $exception->getMessage());
        }
    }

    /** 多场景修复逐场景合入：合格的场景替换进原稿，未达标的保留原文；一个都不合格时整次无效，错误沿用正文前缀以便继续修复。 */
    public function test_merge_passing_repairs_keeps_progress(): void
    {
        $budget = ['slots' => ['p1', 'p2', 'p3'], 'min' => 3, 'max' => 4, 'complete_paragraphs' => true];
        $draft = ['title' => '标题', 'paragraphs' => ['p1' => [self::narration('走。')], 'p2' => [self::narration('停。')], 'p3' => [self::narration('灯亮了。')]]];
        $result = ContentExecutionContract::mergePassingRepairs($draft, ['title' => '标题', 'paragraphs' => ['p1' => [self::narration('她走了。')], 'p2' => [self::narration('停。')]]], $budget, ['p1', 'p2']);
        $this->assertSame([self::narration('她走了。')], $result['paragraphs']['p1']);
        $this->assertSame($draft['paragraphs']['p2'], $result['paragraphs']['p2']);
        $this->assertSame($draft['paragraphs']['p3'], $result['paragraphs']['p3']);
        try {
            ContentExecutionContract::mergePassingRepairs($draft, ['title' => '标题', 'paragraphs' => ['p1' => [self::narration('走。')], 'p2' => [self::narration('停。')]]], $budget, ['p1', 'p2']);
            $this->fail('全部不合格时应当无效');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('正文场景预算或格式无效：p1实际2字，要求3至4字；p2实际2字，要求3至4字', $exception->getMessage());
        }
        $this->expectExceptionMessage('单场景修复不得改变标题');
        ContentExecutionContract::mergePassingRepairs($draft, ['title' => '新标题', 'paragraphs' => ['p1' => [self::narration('她走了。')], 'p2' => [self::narration('他停了。')]]], $budget, ['p1', 'p2']);
    }

    /** 不计字数时只看分段规则：偏短但分段合格的场景可合并扩写，没有引语的对白段等分段错误不算。 */
    public function test_format_valid_ignores_length(): void
    {
        $this->assertTrue(ContentExecutionContract::formatValid([self::narration('走。')], ['complete_paragraphs' => true]));
        $this->assertFalse(ContentExecutionContract::formatValid([self::dialogue('她点点头，转身走了。')], []));
        $this->assertFalse(ContentExecutionContract::formatValid('一段纯文本', []));
    }

    /** 多场景篇幅修复给出各场景的实测字数与增删范围，不附带文字检查规则；首稿要求把节拍展开写足。 */
    public function test_length_batch_instructions(): void
    {
        $budget = ['slots' => ['scene_1', 'scene_2', 'scene_3'], 'min' => 834, 'max' => 1166];
        $batch = ContentExecutionContract::outputInstructions('draft', ['budget' => [...$budget, 'slots' => ['scene_1', 'scene_3']], 'draft_repair_slot' => 'scene_1',
            'draft_repair_slots' => ['scene_1', 'scene_3'], 'repair_direction' => 'length',
            'repair_slot_counts' => ['scene_1' => 731, 'scene_3' => 1300], 'repair_slot_directions' => ['scene_1' => 'expand', 'scene_3' => 'shorten']]);
        $this->assertStringContainsString('scene_1原实测731字，偏短，只能净增103至435字，目标净增269字', $batch);
        $this->assertStringContainsString('scene_3原实测1300字，已超长，必须净删134至466字', $batch);
        $this->assertStringContainsString('指定的字段scene_1、scene_3', $batch);
        $this->assertStringNotContainsString('程序会逐字检查', $batch);
        $expand = ContentExecutionContract::outputInstructions('draft', ['budget' => [...$budget, 'slots' => ['scene_1']], 'draft_repair_slot' => 'scene_1', 'repair_direction' => 'expand', 'repair_current_count' => 731]);
        $this->assertStringNotContainsString('程序会逐字检查', $expand);
        $draft = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget]);
        $this->assertStringContainsString('每个节拍都要展开成在场人物的对白交锋', $draft);
        $this->assertStringContainsString('估出低于834字必须先补写再输出', $draft);
        $this->assertStringNotContainsString('每个节拍都要展开', $expand);
    }

    /** 截断响应只取出已闭合的场景；正文里的引号、括号与转义不影响配对，未写完的场景记为空。 */
    public function test_salvage_truncated_keeps_only_closed_scenes(): void
    {
        $text = '{"title":"第十章\\"两把\\"钥匙","paragraphs":{"scene_1":[{"kind":"narration","text":"她把[编号]与{备注}排开。"},{"kind":"dialogue","text":"“柜子]第三格\\"在哪？”"}],"scene_2":[{"kind":"narration","text":"写到一半';
        $salvaged = ContentExecutionContract::salvageTruncated($text, ['scene_1', 'scene_2', 'scene_3']);
        $this->assertSame('第十章"两把"钥匙', $salvaged['title']);
        $this->assertSame([['kind' => 'narration', 'text' => '她把[编号]与{备注}排开。'], ['kind' => 'dialogue', 'text' => '“柜子]第三格"在哪？”']], $salvaged['paragraphs']['scene_1']);
        $this->assertSame([], $salvaged['paragraphs']['scene_2']);
        $this->assertSame([], $salvaged['paragraphs']['scene_3']);
        $this->assertSame(['scene_2', 'scene_3'], $salvaged['missing']);
        $this->assertNull(ContentExecutionContract::salvageTruncated('{"title":"空","paragraphs":{"scene_1":[{"kind":"narration","text":"未完', ['scene_1', 'scene_2']));
    }
}
