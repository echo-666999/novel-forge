<?php

namespace Tests\Unit;

use App\Services\Pipeline\OutlineRhythm;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** 爽点节奏：类型来源、结构校验、节奏提醒与正文要求。 */
class OutlineRhythmTest extends TestCase
{
    /** 有题材包时用题材包的爽点类型并补上笑点与危机解除，没有时用默认列表。 */
    public function test_config_takes_payoff_types_from_genre_pack(): void
    {
        $this->assertSame(OutlineRhythm::DEFAULT_TYPES, OutlineRhythm::config([])['payoff_types']);
        $types = OutlineRhythm::config(['story_profile' => ['genre' => ['content' => ['payoff_types' => [['name' => '打脸仇人', 'weight' => 30], ['name' => '笑点', 'weight' => 5]]]]]])['payoff_types'];
        $this->assertSame(['打脸仇人', '笑点', '危机解除'], $types);
        $this->assertNull(OutlineRhythm::frozen(['payoff_rhythm' => ['version' => 'old', 'payoff_types' => []]]));
    }

    /** 类型不在冻结列表、缺字段或多字段时整批不合格。 */
    public function test_parse_rejects_unknown_values(): void
    {
        $rhythm = OutlineRhythm::config([]);
        $valid = ['payoff' => ['type' => '打脸', 'size' => 'medium', 'description' => ' 当众打脸 '], 'emotional_peak' => '她笑了', 'hook_type' => 'crisis'];
        $this->assertSame('当众打脸', OutlineRhythm::parse($valid, $rhythm, 1)['payoff']['description']);
        foreach ([[...$valid, 'hook_type' => 'cliff'], [...$valid, 'extra' => 1], ['payoff' => [...$valid['payoff'], 'type' => '修仙'], 'emotional_peak' => '', 'hook_type' => 'crisis']] as $bad) {
            try {
                OutlineRhythm::parse($bad, $rhythm, 3);
                $this->fail('应拒绝');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('第3章', $exception->getMessage());
            }
        }
    }

    /** 只报告本批起的问题：没有兑现且没有情绪峰值、无兑现超过三成。 */
    public function test_issues_report_only_current_batch(): void
    {
        $chapter = fn (int $number, string $type, string $size, string $hook, string $peak = '有'): array => ['chapter_number' => $number,
            'rhythm' => ['payoff' => ['type' => $type, 'size' => $size, 'description' => ''], 'emotional_peak' => $peak, 'hook_type' => $hook]];
        $chapters = [$chapter(1, '打脸', 'medium', 'crisis'), $chapter(2, 'none', 'small', 'twist', ''), $chapter(3, 'none', 'small', 'crisis'), $chapter(4, '甜', 'large', 'emotion')];
        $issues = OutlineRhythm::issues($chapters, 2);
        $this->assertContains('第2章既没有兑现点也没有情绪最高点', $issues);
        $this->assertContains('本批3章里有2章没有兑现点，超过三成', $issues);
        $this->assertSame([], OutlineRhythm::issues($chapters, 5));
    }

    /** 正文要求写出兑现场面与情绪峰值；没有兑现点时只写情绪与钩子。 */
    public function test_chapter_instructions(): void
    {
        $text = OutlineRhythm::chapterInstructions(['payoff' => ['type' => '打脸', 'size' => 'medium', 'description' => '她当众念出账单。'], 'emotional_peak' => '继母拍大腿哭', 'hook_type' => 'twist']);
        $this->assertStringContainsString('本章兑现点（中兑现·打脸）：她当众念出账单。', $text);
        $this->assertStringContainsString('本章情绪最高点：继母拍大腿哭。', $text);
        $this->assertStringContainsString('章末钩子类型：反转。', $text);
        $this->assertStringNotContainsString('兑现点', OutlineRhythm::chapterInstructions(['payoff' => ['type' => 'none', 'size' => 'small', 'description' => ''], 'emotional_peak' => '', 'hook_type' => 'crisis']));
    }

    /** 兑现点检查结论：引用解析为原文，未知段落或未知结论被拒绝（由检查步骤记为未检查）。 */
    public function test_parse_review(): void
    {
        $anchors = ['p1' => ['paragraph' => 1, 'text' => '她把账本拍在桌上。']];
        $review = OutlineRhythm::parseReview(['delivery' => 'delivered', 'reason' => ' 写足了 ', 'evidence_ids' => ['p1']], $anchors);
        $this->assertSame(['delivered', '写足了', '她把账本拍在桌上。'], [$review['delivery'], $review['reason'], $review['evidence'][0]['quote']]);
        foreach ([['delivery' => 'great', 'reason' => '', 'evidence_ids' => []], ['delivery' => 'missing', 'reason' => '', 'evidence_ids' => ['p9']], null] as $bad) {
            try {
                OutlineRhythm::parseReview($bad, $anchors);
                $this->fail('应拒绝');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
