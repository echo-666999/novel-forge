<?php

namespace Tests\Unit;

use App\Services\Pipeline\StageResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 验证阶段费用契约及兼容默认值，不启动应用或访问模型供应商。 */
class StageResultTest extends TestCase
{
    /** 旧调用的缺省费用保持未知，成功和失败的结构化结果均不丢失。 */
    public function test_existing_factories_keep_unknown_cost_and_complete_output(): void
    {
        $output = ['设定' => ['人物' => ['名字' => '归舟']], '列表' => [1, 2, 3]];
        foreach ([StageResult::completed($output), StageResult::failed('失败', $output)] as $result) {
            $this->assertSame($output, $result->output);
            $this->assertSame(['billing_status' => 'unknown', 'currency' => null, 'estimated_cost' => null, 'actual_cost' => null], $result->cost);
        }
    }

    /** 定点费用保留全部八位小数，估算不伪装成已确认扣费。 */
    public function test_estimate_and_simulated_cost_are_preserved_exactly(): void
    {
        $cost = ['billing_status' => 'estimated', 'currency' => 'USD', 'estimated_cost' => '123456789012.12345678', 'actual_cost' => null];
        $this->assertSame($cost, StageResult::completed([], $cost)->cost);
        $zero = ['billing_status' => 'simulated', 'currency' => null, 'estimated_cost' => '0.00000000', 'actual_cost' => '0.00000000'];
        $this->assertSame($zero, StageResult::failed('模拟失败', [], $zero)->cost);
    }

    /** 非法费用在结果边界拒绝，避免不可比较的金额或敏感额外字段落库。
     * @param  array<string, mixed>  $cost
     */
    #[DataProvider('invalidCosts')]
    public function test_invalid_cost_is_rejected(array $cost): void
    {
        $this->expectException(InvalidArgumentException::class);
        StageResult::completed([], $cost);
    }

    /** 提供数值格式、可信度和额外字段的边界样本。
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidCosts(): array
    {
        return [
            '浮点' => [['estimated_cost' => 0.1]],
            '负数' => [['estimated_cost' => '-1.00000000']],
            '精度不足' => [['estimated_cost' => '0.1']],
            '科学计数' => [['estimated_cost' => '1e-8']],
            '金额溢出' => [['estimated_cost' => '1000000000000.00000000']],
            '非法币种' => [['currency' => 'usd']],
            '未知状态' => [['billing_status' => 'confirmed']],
            '未知不能记零' => [['actual_cost' => '0.00000000']],
            '模拟不得收费' => [['billing_status' => 'simulated', 'estimated_cost' => '1.00000000', 'actual_cost' => '1.00000000']],
            '估算缺少币种' => [['billing_status' => 'estimated', 'estimated_cost' => '1.00000000']],
            '估算不得宣称实扣' => [['billing_status' => 'estimated', 'currency' => 'USD', 'estimated_cost' => '1.00000000', 'actual_cost' => '1.00000000']],
            '额外字段' => [['api_key' => '不得落库']],
        ];
    }
}
