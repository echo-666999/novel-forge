<?php

namespace App\Services\Ai;

/** 根据冻结价格计算估算值；不把供应商用量估算称为实际账单。 */
final class CostEstimator
{
    /** 使用十进制字符串计费，缓存从输入子集中扣除，推理已包含在输出中。
     * @param  array<string, mixed>|null  $price
     * @return array{currency: ?string, price_version: ?string, price_snapshot: ?array, estimated_cost: ?string, actual_cost: ?string, billing_status: string}
     */
    public function estimate(TextGenerationResult $result, string $mode, ?array $price): array
    {
        $unknown = ['currency' => null, 'price_version' => null, 'price_snapshot' => null, 'estimated_cost' => null, 'actual_cost' => null, 'billing_status' => 'unknown'];
        if ($mode === 'fake') {
            return [...$unknown, 'estimated_cost' => '0.00000000', 'actual_cost' => '0.00000000', 'billing_status' => 'simulated'];
        }
        if ($price === null || ! function_exists('bcadd') || ! is_string($price['version'] ?? null) || trim($price['version']) === '' || ! is_string($price['currency'] ?? null) || ! preg_match('/\A[A-Z]{3}\z/', $price['currency'])) {
            return $unknown;
        }
        $snapshot = [...$unknown, 'currency' => $price['currency'], 'price_version' => $price['version'], 'price_snapshot' => $price];
        $usage = $result->usage;
        if ($usage['input_tokens'] === null || $usage['output_tokens'] === null) {
            return $snapshot;
        }
        $read = $usage['cache_read_tokens'] ?? 0;
        $write = $usage['cache_write_tokens'] ?? 0;
        $categories = ['input_per_million' => $usage['input_tokens'] - $read - $write, 'output_per_million' => $usage['output_tokens']];
        if ($read > 0) {
            $categories['cache_read_per_million'] = $read;
        }
        if ($write > 0) {
            $categories['cache_write_per_million'] = $write;
        }
        $total = '0';
        foreach ($categories as $rate => $tokens) {
            $value = $price[$rate] ?? null;
            if (! is_string($value) || ! preg_match('/\A\d{1,8}(?:\.\d{1,8})?\z/', $value) || $tokens < 0) {
                return $snapshot;
            }
            $total = bcadd($total, bcmul((string) $tokens, $value, 16), 16);
        }
        // 正数金额保留八位小数并四舍五入，全程不经过二进制浮点数。
        $cost = bcadd(bcdiv($total, '1000000', 16), '0.000000005', 8);
        if (bccomp($cost, '999999999999.99999999', 8) > 0) {
            return $snapshot;
        }

        return [...$snapshot, 'estimated_cost' => $cost, 'billing_status' => 'estimated'];
    }
}
