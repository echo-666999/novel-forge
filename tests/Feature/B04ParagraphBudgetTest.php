<?php

namespace Tests\Feature;

use App\Services\Pipeline\ContentSchema;
use Tests\TestCase;

/** 历史产物夹具与纯协议验证；新业务保护由B04ExecutionTest覆盖。 */
class B04ParagraphBudgetTest extends TestCase
{
    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function sourceBody(): string
    {
        $body = B04ContentTest::body();
        $missing = 2059 - ContentSchema::count($body);

        return $body."\n".mb_substr(str_repeat('隔离源文计数', (int) ceil($missing / 6)), 0, $missing);
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public static function patches(int $length): array
    {
        $lines = explode("\n", self::sourceBody());
        $patches = [];
        foreach ([1, 2, 3] as $number) {
            $seed = ['甲处核查', '乙处比对', '丙处取证'][$number - 1];
            $patches[] = ['paragraph' => $number, 'anchor' => $lines[$number - 1], 'text' => mb_substr(str_repeat($seed, (int) ceil($length / 4)), 0, $length), 'reason' => '隔离协议字数验证'];
        }

        return ['insertions' => $patches];
    }

    /** 冻结历史夹具或纯契约回归，不通过旧业务路径生成运行。 */
    public function test_budget_allocation_guarantees_frozen_final_range(): void
    {
        $this->assertSame(['count' => 3, 'min' => 300, 'max' => 350, 'source_count' => 2059, 'final_min' => 2959, 'final_max' => 3109], ContentSchema::paragraphBudget(2059, 2500, 3500));
        foreach ([[1, 2, 2], [1082, 2500, 3500], [2499, 2500, 3500]] as [$count, $min, $max]) {
            $budget = ContentSchema::paragraphBudget($count, $min, $max);
            $this->assertGreaterThanOrEqual($min, $budget['final_min']);
            $this->assertLessThanOrEqual($max, $budget['final_max']);
        }
    }
}
