<?php

namespace Tests\Feature;

use App\Services\Pipeline\ContentContinuationContract;
use App\Services\Pipeline\ContentQualityContract;
use App\Services\Pipeline\ContentSchema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 历史产物夹具与纯协议验证；新业务保护由B04ExecutionTest覆盖。 */
class B04ContinuationTest extends TestCase
{
    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function source(): string
    {
        return ContentQualityContract::revise(B04QualityRecoveryTest::actualReadingPatches(), ContentQualityContract::revise(B04QualityRecoveryTest::actualQualityPatches(), B04QualityRecoveryTest::failedBody()));
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    private static function patches(string $finding): array
    {
        $lines = explode("\n", self::source());

        return ['ranges' => [
            ['start_id' => 'p6', 'end_id' => 'p8', 'text' => $lines[5].str_repeat('隔离补充', 150), 'reason' => '覆盖完整范围，消除旧p8', 'addresses' => [$finding]],
            ['start_id' => 'p24', 'end_id' => 'p29', 'text' => $lines[23]."\n".$lines[27], 'reason' => '合并确认场景', 'addresses' => [$finding]],
            ['start_id' => 'p33', 'end_id' => 'p36', 'text' => $lines[32]."\n".$lines[34], 'reason' => '合并到访场景', 'addresses' => [$finding]],
        ]];
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    private static function repairedBody(): string
    {
        return ContentContinuationContract::revise(self::patches('f'), self::source(), ['f' => []]);
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public function test_ranges_replace_all_endpoints_and_preserve_outside(): void
    {
        $body = "第一段保留\n甲段\n\n乙段\n末段保留";
        $data = ['ranges' => [['start_id' => 'p2', 'end_id' => 'p4', 'text' => '甲乙合并为一次行动', 'reason' => '测试合并', 'addresses' => ['f']]]];
        $this->assertSame("第一段保留\n甲乙合并为一次行动\n末段保留", ContentContinuationContract::revise($data, $body, ['f' => []]));
        $data['ranges'][] = ['start_id' => 'p5', 'end_id' => 'p5', 'text' => '末段保留', 'reason' => '保持原样', 'addresses' => ['f']];
        $this->assertSame("第一段保留\n甲乙合并为一次行动\n末段保留", ContentContinuationContract::revise($data, $body, ['f' => []]));
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    #[DataProvider('invalidRanges')]
    public function test_invalid_ranges_are_rejected(array $ranges): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContentContinuationContract::revise(['ranges' => $ranges], "第一段\n第二段\n\n第四段\n第五段", ['f' => []]);
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function invalidRanges(): array
    {
        $range = ['start_id' => 'p2', 'end_id' => 'p4', 'text' => '替换后内容', 'reason' => '隔离校验', 'addresses' => ['f']];

        return ['empty' => [[]], 'blank_anchor' => [[array_replace($range, ['start_id' => 'p3'])]], 'unknown' => [[array_replace($range, ['end_id' => 'p99'])]],
            'reverse' => [[array_replace($range, ['start_id' => 'p4', 'end_id' => 'p2'])]], 'overlap' => [[$range, array_replace($range, ['start_id' => 'p4', 'end_id' => 'p5'])]],
            'whole_body' => [[array_replace($range, ['start_id' => 'p1', 'end_id' => 'p5'])]], 'unknown_finding' => [[array_replace($range, ['addresses' => ['other']])]],
            'duplicate_finding' => [[array_replace($range, ['addresses' => ['f', 'f']])]], 'no_change' => [[array_replace($range, ['text' => "第二段\n\n第四段"])]],
            'carriage_return' => [[array_replace($range, ['text' => "新段\r文本"])]], 'missing_finding' => [[array_replace($range, ['addresses' => []])]]];
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public function test_finding_dispositions_and_missing_ids(): void
    {
        $data = ['points' => [['id' => 'p', 'met' => true, 'reason' => '行动证据', 'evidence_ids' => ['p1']]],
            'hook' => ['met' => true, 'reason' => '触发证据', 'evidence_ids' => ['p1']], 'setting_violations' => [], 'continuity_issues' => [], 'story_tensions' => [],
            'finding_reviews' => [['id' => 'f', 'disposition' => 'benign', 'reason' => '明确回忆，不是重复动作', 'evidence_ids' => ['p1']]]];
        $checked = ContentContinuationContract::check($data, '她回想昨日的行动。', ['p'], ['world-1' => '规则'], ['f' => []]);
        $this->assertSame('pass', $checked['verdict']);
        $this->assertSame('benign', $checked['reading_reviews'][0]['disposition']);
        $this->assertSame('她回想昨日的行动。', $checked['reading_reviews'][0]['evidence'][0]['quote']);
        $data['finding_reviews'][0]['disposition'] = 'unresolved';
        $this->assertSame('insufficient', ContentContinuationContract::check($data, '她回想昨日的行动。', ['p'], ['world-1' => '规则'], ['f' => []])['verdict']);
        $data['finding_reviews'] = [];
        $this->expectException(InvalidArgumentException::class);
        ContentContinuationContract::check($data, '她回想昨日的行动。', ['p'], ['world-1' => '规则'], ['f' => []]);
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public function test_rewrite_scene_budget_and_bounds(): void
    {
        $budget = ['scene_ids' => ['scene-1', 'scene-2', 'scene-3', 'scene-4'], 'count' => 8, 'min' => 344, 'max' => 406];
        $data = ['title' => '隔离场景预算', 'paragraphs' => array_combine(ContentContinuationContract::slots($budget), array_map(fn (int $index): string => str_repeat(mb_chr(0x4E00 + $index), 375), range(0, 7)))];
        $result = ContentContinuationContract::rewrite($data, $budget);
        $this->assertSame(3000, ContentSchema::count($result['body']));
        $this->assertCount(8, explode("\n", $result['body']));
        $reordered = $data;
        $reordered['paragraphs'] = array_reverse($data['paragraphs'], true);
        $this->assertSame($result, ContentContinuationContract::rewrite($reordered, $budget));
        $data['paragraphs']['scene_1_part_1'] = str_repeat('字', 343);
        try {
            ContentContinuationContract::rewrite($data, $budget);
            $this->fail('不足单段预算不能通过');
        } catch (InvalidArgumentException) {
            $data['paragraphs']['scene_1_part_1'] = str_repeat('字', 375);
        }
        unset($data['paragraphs']['scene_1_part_1']);
        $data['paragraphs']['unknown_scene'] = str_repeat('字', 375);
        $this->expectException(InvalidArgumentException::class);
        ContentContinuationContract::rewrite($data, $budget);
    }
}
