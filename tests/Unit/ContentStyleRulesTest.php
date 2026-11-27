<?php

namespace Tests\Unit;

use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\ContentReadabilityMetrics;
use App\Services\Pipeline\ContentStyleRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Feature\B04ContentTest;

/** 冻结文风规则的运行：按章轮换开场方式，章首不以时间起句；套话只作观察；旧运行不受影响。 */
class ContentStyleRulesTest extends TestCase
{
    private const POLICY = ['min_characters' => 2500, 'max_characters' => 3500];

    /** 相邻三章的开场方式互不相同，章号循环。 */
    public function test_opening_mode_rotates_by_chapter(): void
    {
        $modes = array_map(fn (int $chapter): string => ContentStyleRules::openingMode($chapter), range(1, 8));

        foreach (range(0, 5) as $index) {
            $this->assertCount(3, array_unique(array_slice($modes, $index, 3)));
        }
        $this->assertSame(ContentStyleRules::openingMode(1), ContentStyleRules::openingMode(1 + count(ContentStyleRules::OPENING_MODES)));
    }

    /** 第22本书中常见的时间起句都被识别；对白、动作和普通叙述开头不受限。
     * @return array<string, array{string, bool}>
     */
    public static function openings(): array
    {
        return [
            'clock' => ['上午九点刚过，修复室的长桌上摊开一沓捆数单。', true],
            'next_day' => ['次日上午九点，库房外间登记台的灯管有一支不亮。', true],
            'clock_minutes' => ['九点差十分，修复室窗台上的湿度计还停在偏高的位置。', true],
            'evening' => ['傍晚六点多，沈禾推门进屋。', true],
            'colloquial_next_day' => ['转天上午，周小麦上了厂部楼。', true],
            'colloquial_dusk' => ['天擦黑收摊，匾里还剩七八块卤豆干。', true],
            'dialogue' => ['“这钱您揣兜里一年了，兜不嫌沉？”', false],
            'action' => ['瓜子壳落在门槛上，她往门框上一靠。', false],
            'dawn_word_later' => ['她一直等到清晨才睡着。', false],
        ];
    }

    #[DataProvider('openings')]
    public function test_time_led_openings_are_detected(string $opening, bool $expected): void
    {
        $types = array_column(ContentStyleRules::openingIssues($opening."\n后文继续。"), 'type');

        $this->assertSame($expected, in_array('time_opening', $types, true));
    }

    /** 章首问题定位到第一个非空段，并给出改法。 */
    public function test_time_opening_issue_points_at_first_paragraph(): void
    {
        $issues = ContentStyleRules::openingIssues("\n次日上午九点，她推开门。\n后文。");

        $this->assertSame('p2', $issues[0]['anchor_id']);
        $this->assertSame(['次日上午九点，她推开门。'], $issues[0]['quotes']);
        $this->assertStringContainsString('开场方式', $issues[0]['reason']);
    }

    /** 场景质量门禁只在调用方要求时检查章首，且只查第一个场景。 */
    public function test_slot_quality_checks_only_the_first_scene_of_style_runs(): void
    {
        $paragraphs = [
            'scene_1' => [['kind' => 'narration', 'text' => '上午九点刚过，她把门推开，屋里一股潮气扑过来，像谁刚泼过一盆水。']],
            'scene_2' => [['kind' => 'narration', 'text' => '下午两点，她又回到这里，桌上的东西一样没少，她却觉得哪里不对。']],
        ];

        $this->assertSame([], ContentExecutionContract::slotQuality($paragraphs, ['scene_1', 'scene_2'], null));
        $errors = ContentExecutionContract::slotQuality($paragraphs, ['scene_1', 'scene_2'], null, [], true);

        $this->assertSame(['scene_1'], array_keys($errors));
        $this->assertStringContainsString('以时间起句', $errors['scene_1'][0]);
    }

    /** 冻结文风规则的细纲允许两个场景；旧运行仍至少三个。 */
    public function test_style_runs_allow_two_scenes(): void
    {
        $this->assertSame(['min' => 3, 'max' => 3], ContentExecutionContract::sceneRange(self::POLICY));
        $this->assertSame(['min' => 2, 'max' => 3], ContentExecutionContract::sceneRange(self::POLICY, true));

        $fine = B04ContentTest::fine();
        array_pop($fine['scenes']);
        $this->assertCount(2, ContentExecutionContract::fine($fine, ['chapter-1-point-1'], self::POLICY, true, true)['scenes']);
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::fine($fine, ['chapter-1-point-1'], self::POLICY, true);
    }

    /** 开场方式、套话清单与规则防火墙只进入冻结文风规则的细纲、首稿、文字修复与修订指令。 */
    public function test_instructions_carry_style_rules_only_when_frozen(): void
    {
        $budget = ['slots' => ['scene_1', 'scene_2'], 'min' => 1250, 'max' => 1750];
        $mode = ContentStyleRules::openingMode(3);

        $draft = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget, 'opening_mode' => $mode]);
        $this->assertStringContainsString('本章开场方式：'.ContentStyleRules::OPENING_MODES[$mode], $draft);
        $this->assertStringContainsString('全章第一句不得以时间', $draft);
        $this->assertStringContainsString('指腹', $draft);
        $this->assertStringContainsString('人物并不知道这些规则', $draft);

        $fine = ContentExecutionContract::outputInstructions('fine', ['scene_range' => ['min' => 2, 'max' => 3], 'opening_mode' => $mode]);
        $this->assertStringContainsString('time只写与上一场相比的时间变化', $fine);

        $repair = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget, 'opening_mode' => $mode, 'draft_repair_slot' => 'scene_1', 'repair_direction' => 'quality']);
        $this->assertStringContainsString('按本章开场方式重写第一段', $repair);

        $revise = ContentExecutionContract::outputInstructions('revise', ['anchors' => ['p1' => []], 'style_rules' => true]);
        $this->assertStringContainsString('人物并不知道这些规则', $revise);

        foreach ([
            ContentExecutionContract::outputInstructions('draft', ['budget' => $budget]),
            ContentExecutionContract::outputInstructions('fine', ['scene_range' => ['min' => 3, 'max' => 3]]),
            ContentExecutionContract::outputInstructions('revise', ['anchors' => ['p1' => []]]),
        ] as $legacy) {
            $this->assertStringNotContainsString('本章开场方式', $legacy);
            $this->assertStringNotContainsString('人物并不知道这些规则', $legacy);
        }
    }

    /** 只列出整章超过上限的套话，且不进入质量问题。 */
    public function test_overused_cliches_are_observations_only(): void
    {
        $body = "她指腹压住纸边。\n她指腹按了按。\n她的指腹发凉，屏幕暗下来，她还答不上来。";

        $this->assertSame(['指腹' => 3], ContentStyleRules::overusedCliches($body));
        $this->assertSame(['指腹' => 3, '屏幕暗下来' => 1, '按了按' => 1, '还答不上来' => 1], ContentStyleRules::clicheCounts($body));
        $this->assertSame([], ContentStyleRules::openingIssues($body));
        $this->assertSame([], ContentStyleRules::overusedCliches('她把门推开。'));
    }

    /** 台词里的流程词只统计引号内说出口的话。 */
    public function test_dialogue_process_words_count_only_spoken_lines(): void
    {
        $metrics = ContentReadabilityMetrics::measure("她把登记簿合上。\n“我得再核对一遍，签字以后才算数。”");

        $this->assertSame(2, $metrics['dialogue_process_words']);
        $this->assertSame(ContentReadabilityMetrics::VERSION, $metrics['version']);
    }
}
