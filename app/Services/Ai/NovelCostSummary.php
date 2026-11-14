<?php

namespace App\Services\Ai;

use App\Models\CostLog;

/** 小说成本只读投影：不同币种不相加，未知费用不归零，模拟不计入真实支出。 */
final class NovelCostSummary
{
    /** 只读取计费列并使用十进制字符串累加；失败和未知请求同样保留在汇总中。
     * @return array{real_requests: int, simulated_requests: int, unknown_amounts: int, pending_requests: int, currencies: list<array<string, mixed>>}
     */
    public function forNovel(int $novelId): array
    {
        $summary = ['real_requests' => 0, 'simulated_requests' => 0, 'unknown_amounts' => 0, 'pending_requests' => 0, 'currencies' => []];
        $currencies = [];
        foreach (CostLog::where('novel_id', $novelId)->select(['id', 'mode', 'status', 'currency', 'billing_status', 'actual_cost', 'estimated_cost'])->orderBy('id')->cursor() as $log) {
            if ($log->mode === 'fake') {
                $summary['simulated_requests']++;

                continue;
            }
            $summary['real_requests']++;
            $summary['pending_requests'] += $log->status === 'pending' ? 1 : 0;
            // 有实际账单时优先采用实际金额，不再次累加同一请求的估算。
            $actual = $log->actual_cost !== null;
            $amount = $actual ? $log->actual_cost : ($log->billing_status === 'estimated' ? $log->estimated_cost : null);
            if ($amount === null || ! is_string($log->currency) || preg_match('/\A[A-Z]{3}\z/', $log->currency) !== 1 || ! function_exists('bcadd')) {
                $summary['unknown_amounts']++;

                continue;
            }
            $currency = $log->currency;
            $currencies[$currency] ??= ['currency' => $currency, 'actual' => '0.00000000', 'estimated' => '0.00000000', 'actual_count' => 0, 'estimated_count' => 0];
            $key = $actual ? 'actual' : 'estimated';
            $currencies[$currency][$key] = bcadd($currencies[$currency][$key], $amount, 8);
            $currencies[$currency][$key.'_count']++;
        }
        ksort($currencies);
        $summary['currencies'] = array_values($currencies);

        return $summary;
    }

    /** 累计费用摘要明确标记未结算部分，缺失账单不显示为零。
     * @param  array{real_requests: int, simulated_requests: int, unknown_amounts: int, pending_requests: int, currencies: list<array<string, mixed>>}  $summary
     */
    public function describe(array $summary): string
    {
        $lines = array_map(static fn (array $row): string => $row['currency'].'：实际 '.($row['actual_count'] > 0 ? $row['actual'] : '暂无账单').'；未结算估算 '.($row['estimated_count'] > 0 ? $row['estimated'] : '暂无估算'), $summary['currencies']);

        return ($lines === [] ? '暂无已知金额（不代表零费用）' : implode('；', $lines))
            .'。真实请求 '.$summary['real_requests'].' 次，金额未知 '.$summary['unknown_amounts'].' 次，待返回 '.$summary['pending_requests'].' 次；模拟调用 '.$summary['simulated_requests'].' 次（不计入费用）。';
    }

    /** 一行摘要，用于默认收起区块的标题描述：真实请求数、各币种合计金额（含估算时标“约”）与金额未知次数。
     * @param  array{real_requests: int, simulated_requests: int, unknown_amounts: int, pending_requests: int, currencies: list<array<string, mixed>>}  $summary
     */
    public function brief(array $summary): string
    {
        $parts = ['真实请求 '.$summary['real_requests'].' 次'];
        foreach ($summary['currencies'] as $row) {
            $total = function_exists('bcadd') ? bcadd($row['actual'], $row['estimated'], 2) : number_format((float) $row['actual'] + (float) $row['estimated'], 2, '.', '');
            $parts[] = $row['currency'].' '.($row['estimated_count'] > 0 ? '约 ' : '').$total;
        }
        if ($summary['unknown_amounts'] > 0) {
            $parts[] = '金额未知 '.$summary['unknown_amounts'].' 次';
        }
        if ($summary['simulated_requests'] > 0) {
            $parts[] = '模拟 '.$summary['simulated_requests'].' 次';
        }

        return implode(' · ', $parts);
    }
}
