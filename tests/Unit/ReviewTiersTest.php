<?php

namespace Tests\Unit;

use App\Enums\ReviewStrictness;
use App\Models\Novel;
use App\Services\Pipeline\ReviewTiers;
use App\Services\Pipeline\SubmissionContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** 评审分级：非硬伤阻塞降为瑕疵，严格书不分级，结构校验随分级增减字段。 */
class ReviewTiersTest extends TestCase
{
    private const BODY = '林舟走进旧塔。';

    private const LOOSE = ['version' => ReviewTiers::VERSION, 'strictness' => 'loose'];

    private const STANDARD = ['version' => ReviewTiers::VERSION, 'strictness' => 'standard'];

    /** 严格书不冻结分级，宽松和标准冻结版本与严格度。 */
    public function test_config_freezes_only_tiered_strictness(): void
    {
        $this->assertNull(ReviewTiers::config(new Novel(['review_strictness' => ReviewStrictness::Strict])));
        $this->assertSame(self::LOOSE, ReviewTiers::config(new Novel(['review_strictness' => ReviewStrictness::Loose])));
        $this->assertSame(self::STANDARD, ReviewTiers::config(new Novel(['review_strictness' => ReviewStrictness::Standard])));
    }

    /** 宽松：细节矛盾、待核台账与文学表达降级，硬伤保留阻断。 */
    public function test_loose_keeps_only_hard_defects_blocking(): void
    {
        $review = SubmissionContract::review($this->review([
            $this->finding('continuity', 'none'), $this->finding('rule', 'open_item'), $this->finding('literary', 'pov'), $this->finding('continuity', 'causality'),
        ]), self::BODY, self::LOOSE);
        $this->assertSame(['advisory', 'advisory', 'advisory', 'blocking'], array_column($review['findings'], 'severity'));
        $this->assertSame('needs_revision', $review['verdict']);
        $this->assertArrayNotHasKey('tier_adjusted_from', $review);
    }

    /** 标准：待核台账与已揭示事实仍然阻断。 */
    public function test_standard_blocks_ledger_defects(): void
    {
        $review = SubmissionContract::review($this->review([$this->finding('rule', 'open_item'), $this->finding('continuity', 'repeated_reveal')]), self::BODY, self::STANDARD);
        $this->assertSame(['blocking', 'blocking'], array_column($review['findings'], 'severity'));
        $this->assertSame('needs_revision', $review['verdict']);
    }

    /** 阻塞问题全部降级后评审改为通过，保留原结论与降级标记。 */
    public function test_all_downgraded_review_passes_with_original_verdict(): void
    {
        $review = SubmissionContract::review($this->review([$this->finding('continuity', 'none')], 'reject'), self::BODY, self::LOOSE);
        $this->assertSame('pass', $review['verdict']);
        $this->assertSame('reject', $review['tier_adjusted_from']);
        $this->assertTrue($review['findings'][0]['downgraded']);
    }

    /** 分级运行要求硬伤类别且取值受限；未分级运行不接受该字段。 */
    public function test_defect_field_follows_tier(): void
    {
        $plain = ['kind' => 'continuity', 'severity' => 'blocking', 'reason' => '缺类别', 'evidence_ids' => ['p1']];
        foreach ([[$this->review([$plain]), self::LOOSE], [$this->review([$this->finding('continuity', 'typo')]), self::LOOSE], [$this->review([$this->finding('continuity', 'none')]), null]] as [$data, $tier]) {
            try {
                SubmissionContract::review($data, self::BODY, $tier);
                $this->fail('应拒绝不符合分级结构的评审');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('needs_revision', SubmissionContract::review($this->review([$plain]), self::BODY)['verdict']);
    }

    /** 提示词列出本书阻断类别；宽松书说明待核台账只记为瑕疵。 */
    public function test_instructions_list_blocking_defects(): void
    {
        $loose = ReviewTiers::instructions(self::LOOSE);
        $this->assertStringContainsString('causality：', $loose);
        $this->assertStringNotContainsString('open_item：', $loose);
        $this->assertStringContainsString('本书只记为advisory', $loose);
        $this->assertStringContainsString('open_item：', ReviewTiers::instructions(self::STANDARD));
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function review(array $findings, string $verdict = 'needs_revision'): array
    {
        return ['review' => ['verdict' => $verdict, 'summary' => '隔离评审', 'evidence_ids' => ['p1'], 'findings' => $findings]];
    }

    /** @return array<string, mixed> */
    private function finding(string $kind, string $defect): array
    {
        return ['kind' => $kind, 'severity' => 'blocking', 'reason' => '隔离问题'.$defect, 'evidence_ids' => ['p1'], 'defect' => $defect];
    }
}
