<?php

namespace App\Services\Ai;

use Carbon\CarbonInterface;

/** 根据冻结价格计算估算值；不把供应商用量估算称为实际账单。 */
final class CostEstimator
{
    /** 按标准价计费的服务档位；其余档位（如 flex、priority）单价不同，金额保持未知。 */
    private const STANDARD_TIERS = ['default', 'standard', 'auto'];

    /** 按请求结果估算：使用响应中的用量与服务档位。
     * @param  array<string, mixed>|null  $price
     * @return array{currency: ?string, price_version: ?string, price_snapshot: ?array, estimated_cost: ?string, actual_cost: ?string, billing_status: string}
     */
    public function estimate(TextGenerationResult $result, string $mode, ?array $price, ?CarbonInterface $requestedAt = null): array
    {
        return $this->estimateUsage($result->usage, $result->rawResponse, $mode, $price, $requestedAt);
    }

    /** 使用十进制字符串计费，缓存从输入子集中扣除，推理已包含在输出中。
     * 输入超过长上下文阈值时整次请求改用长上下文单价；登记了闲时规则时，按请求开始时间换算到UTC判断是否乘闲时倍率，时间未知时按标准价。
     *
     * @param  array{input_tokens: ?int, output_tokens: ?int, cache_read_tokens: ?int, cache_write_tokens: ?int, reasoning_tokens: ?int}  $usage
     * @param  array<string, mixed>|null  $rawResponse
     * @param  array<string, mixed>|null  $price
     * @return array{currency: ?string, price_version: ?string, price_snapshot: ?array, estimated_cost: ?string, actual_cost: ?string, billing_status: string}
     */
    public function estimateUsage(array $usage, ?array $rawResponse, string $mode, ?array $price, ?CarbonInterface $requestedAt = null): array
    {
        $unknown = ['currency' => null, 'price_version' => null, 'price_snapshot' => null, 'estimated_cost' => null, 'actual_cost' => null, 'billing_status' => 'unknown'];
        if ($mode === 'fake') {
            return [...$unknown, 'estimated_cost' => '0.00000000', 'actual_cost' => '0.00000000', 'billing_status' => 'simulated'];
        }
        if ($price === null || ! function_exists('bcadd') || ! is_string($price['version'] ?? null) || trim($price['version']) === '' || ! is_string($price['currency'] ?? null) || ! preg_match('/\A[A-Z]{3}\z/', $price['currency'])) {
            return $unknown;
        }
        $snapshot = [...$unknown, 'currency' => $price['currency'], 'price_version' => $price['version'], 'price_snapshot' => $price];
        if (($usage['input_tokens'] ?? null) === null || ($usage['output_tokens'] ?? null) === null) {
            return $snapshot;
        }
        $tier = $rawResponse['service_tier'] ?? null;
        if ($tier !== null && ! in_array($tier, self::STANDARD_TIERS, true)) {
            return $snapshot;
        }
        $read = $usage['cache_read_tokens'] ?? 0;
        $write = $usage['cache_write_tokens'] ?? 0;
        $threshold = $price['long_context_threshold'] ?? null;
        $long = is_int($threshold) && $threshold > 0 && $usage['input_tokens'] > $threshold;
        if ($write > 0 && ((int) data_get($rawResponse, 'usage.input_tokens_details.cache_write_tokens_details.ttl_12h_tokens', 0) > 0 || (int) data_get($rawResponse, 'usage.cache_creation.ephemeral_1h_input_tokens', 0) > 0)) {
            // 长时缓存写入（OpenAI 12小时、Claude 1小时）单价高于默认写入价，只登记了一种写入价，不按低价猜测。
            return $snapshot;
        }
        $prefix = $long ? 'long_' : '';
        $categories = [$prefix.'input_per_million' => $usage['input_tokens'] - $read - $write, $prefix.'output_per_million' => $usage['output_tokens']];
        if ($read > 0) {
            $categories[$prefix.'cache_read_per_million'] = $read;
        }
        if ($write > 0) {
            // 长上下文写入单价未登记时下方取不到单价，金额保持未知，不按标准写入价猜测。
            $categories[$prefix.'cache_write_per_million'] = $write;
        }
        $total = '0';
        foreach ($categories as $rate => $tokens) {
            $value = $price[$rate] ?? null;
            if (! is_string($value) || ! self::isDecimal($value) || $tokens < 0) {
                return $snapshot;
            }
            $total = bcadd($total, bcmul((string) $tokens, $value, 16), 16);
        }
        $offPeak = self::isOffPeak($price, $requestedAt);
        if ($offPeak) {
            $total = bcmul($total, (string) $price['off_peak_ratio'], 16);
        }
        // 正数金额保留八位小数并四舍五入，全程不经过二进制浮点数。
        $cost = bcadd(bcdiv($total, '1000000', 16), '0.000000005', 8);
        if (bccomp($cost, '999999999999.99999999', 8) > 0) {
            return $snapshot;
        }
        $applied = ['context' => $long ? 'long' : 'standard', 'period' => isset($price['off_peak_ratio']) ? ($offPeak ? 'off_peak' : 'peak') : 'flat',
            'requested_at' => $requestedAt === null ? null : $requestedAt->copy()->utc()->toIso8601String()];

        return [...$snapshot, 'price_snapshot' => [...$price, 'applied' => $applied], 'estimated_cost' => $cost, 'billing_status' => 'estimated'];
    }

    /** 登记了闲时倍率与高峰时段时，判断请求开始时间是否落在闲时：周六周日全天闲时，工作日不在任一高峰时段内为闲时（均按UTC）。
     * @param  array<string, mixed>  $price
     */
    public static function isOffPeak(array $price, ?CarbonInterface $requestedAt): bool
    {
        $ratio = $price['off_peak_ratio'] ?? null;
        $windows = $price['peak_hours_utc'] ?? null;
        if ($requestedAt === null || ! is_string($ratio) || ! self::isDecimal($ratio) || ! is_array($windows)) {
            return false;
        }
        $utc = $requestedAt->copy()->utc();
        if ($utc->isWeekend()) {
            return true;
        }
        $clock = $utc->format('H:i');
        foreach ($windows as $window) {
            [$start, $end] = array_pad(explode('-', (string) $window, 2), 2, '');
            if ($clock >= $start && $clock < $end) {
                return false;
            }
        }

        return true;
    }

    /** 非负十进制，最多8位小数。 */
    private static function isDecimal(string $value): bool
    {
        return preg_match('/\A\d{1,8}(?:\.\d{1,8})?\z/', $value) === 1;
    }
}
