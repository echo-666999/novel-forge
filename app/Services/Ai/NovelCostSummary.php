<?php

namespace App\Services\Ai;

use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\CostLog;
use App\Models\NovelStage;

/** 小说成本只读投影：不同币种不相加，未知费用不归零，模拟不计入真实支出。 */
final class NovelCostSummary
{
    /** 只读取计费列并使用十进制字符串累加；失败和未知请求同样保留在汇总中。
     * @return array{real_requests: int, simulated_requests: int, unknown_amounts: int, pending_requests: int, currencies: list<array<string, mixed>>}
     */
    public function forNovel(int $novelId): array
    {
        return $this->summarize(CostLog::where('novel_id', $novelId)->select(self::COLUMNS)->orderBy('id')->cursor());
    }

    /** 按章节号汇总本书各章的全部模型开支：生成与恢复、提交评审与提取、正式稿修订都归入对应章节；只记录，不参与任何执行判断。
     * @return array<int, array{real_requests: int, simulated_requests: int, unknown_amounts: int, pending_requests: int, currencies: list<array<string, mixed>>}>
     */
    public function forChapters(int $novelId): array
    {
        $numbers = Chapter::where('novel_id', $novelId)->pluck('number', 'id');
        $generationRuns = ChapterGenerationRun::whereIn('chapter_id', $numbers->keys())->pluck('chapter_id', 'id');
        $submissionRuns = ChapterSubmissionRun::whereIn('chapter_id', $numbers->keys())->pluck('chapter_id', 'id');
        $stages = NovelStage::where('novel_id', $novelId)->whereIn('id', CostLog::where('novel_id', $novelId)->whereNotNull('novel_stage_id')->select('novel_stage_id'))->pluck('input', 'id');
        $logs = [];
        foreach (CostLog::where('novel_id', $novelId)->select([...self::COLUMNS, 'novel_stage_id', 'request_parameters'])->orderBy('id')->cursor() as $log) {
            $input = $stages->get($log->novel_stage_id) ?? [];
            $chapterId = $generationRuns->get($input['run_id'] ?? null) ?? $submissionRuns->get($log->request_parameters['submission_run_id'] ?? null);
            $number = $chapterId !== null ? $numbers->get($chapterId) : ($input['chapter_number'] ?? $log->request_parameters['chapter_number'] ?? null);
            if (is_int($number)) {
                $logs[$number][] = $log;
            }
        }

        return array_map(fn (array $rows): array => $this->summarize($rows), $logs);
    }

    /** 一章的开支压成一格：只显示已算出的各币种合计（含估算时标“约”），未知金额不显示；全部未知时显示“金额未知”，没有请求时为空。
     * @param  array{real_requests: int, simulated_requests: int, unknown_amounts: int, pending_requests: int, currencies: list<array<string, mixed>>}|null  $summary
     */
    public function cell(?array $summary): string
    {
        if ($summary === null || $summary['real_requests'] === 0) {
            return $summary !== null && $summary['simulated_requests'] > 0 ? '模拟，不计费' : '-';
        }
        $parts = [];
        foreach ($summary['currencies'] as $row) {
            $total = function_exists('bcadd') ? bcadd($row['actual'], $row['estimated'], 4) : number_format((float) $row['actual'] + (float) $row['estimated'], 4, '.', '');
            $parts[] = ($row['estimated_count'] > 0 ? '约 ' : '').$row['currency'].' '.$total;
        }

        return $parts === [] ? '金额未知' : implode(' · ', $parts);
    }

    /** 汇总计费所需的列。 */
    private const COLUMNS = ['id', 'mode', 'status', 'currency', 'billing_status', 'actual_cost', 'estimated_cost'];

    /** 按请求逐条累加：有实际账单时优先采用实际金额，不再次累加同一请求的估算。
     * @param  iterable<CostLog>  $logs
     * @return array{real_requests: int, simulated_requests: int, unknown_amounts: int, pending_requests: int, currencies: list<array<string, mixed>>}
     */
    private function summarize(iterable $logs): array
    {
        $summary = ['real_requests' => 0, 'simulated_requests' => 0, 'unknown_amounts' => 0, 'pending_requests' => 0, 'currencies' => []];
        $currencies = [];
        foreach ($logs as $log) {
            if ($log->mode === 'fake') {
                $summary['simulated_requests']++;

                continue;
            }
            $summary['real_requests']++;
            $summary['pending_requests'] += $log->status === 'pending' ? 1 : 0;
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
