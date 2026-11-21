<?php

namespace App\Console\Commands;

use App\Models\CostLog;
use App\Services\Ai\CostEstimator;
use App\Services\Ai\ModelPrices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** 单价登记有误时，按当前生效的单价重算已有真实请求的估算金额；默认只预览，加 --apply 才写入，并在每条明细中追加重算记录。 */
#[Signature('ai:reprice-cost-logs {--model=* : 只重算这些响应模型（可多次传入），不传时全部} {--novel= : 只重算该小说的请求} {--from= : 只重算此时间之后开始的请求} {--apply : 写入重算结果} {--reason= : 写入时必填，说明为何重算}')]
#[Description('按当前生效单价重算真实请求的估算金额；已有实际账单、模拟或缺少用量的请求不变')]
class RepriceCostLogs extends Command
{
    /** 逐条按请求开始时间重算，打印差额；写入时与重算记录在同一事务中保存。 */
    public function handle(CostEstimator $estimator, ModelPrices $prices): int
    {
        $apply = (bool) $this->option('apply');
        $reason = trim((string) $this->option('reason'));
        if ($apply && $reason === '') {
            $this->error('写入重算结果时须用 --reason 说明原因。');

            return self::FAILURE;
        }
        $snapshot = $prices->snapshot();
        $query = CostLog::query()->where('mode', 'real')->whereNull('actual_cost')->whereNotNull('input_tokens')->whereNotNull('output_tokens')->orderBy('id');
        if ($this->option('model') !== []) {
            $query->whereIn('model', (array) $this->option('model'));
        }
        if (filled($this->option('novel'))) {
            $query->where('novel_id', (int) $this->option('novel'));
        }
        if (filled($this->option('from'))) {
            $query->where('started_at', '>=', $this->option('from'));
        }
        $changed = 0;
        $totals = [];
        foreach ($query->cursor() as $log) {
            $price = ModelPrices::resolve($snapshot, $log->model) ?? ModelPrices::resolve($snapshot, $log->requested_model);
            $usage = ['input_tokens' => $log->input_tokens, 'output_tokens' => $log->output_tokens, 'cache_read_tokens' => $log->cache_read_tokens, 'cache_write_tokens' => $log->cache_write_tokens, 'reasoning_tokens' => $log->reasoning_tokens];
            $result = $estimator->estimateUsage($usage, $log->raw_response, 'real', $price, $log->started_at);
            if ($result['billing_status'] !== 'estimated' || ($result['estimated_cost'] === $log->estimated_cost && $result['price_version'] === $log->price_version)) {
                continue;
            }
            $changed++;
            $currency = $result['currency'];
            $totals[$currency] ??= ['before' => '0', 'after' => '0'];
            if ($log->estimated_cost !== null && $log->currency === $currency) {
                $totals[$currency]['before'] = bcadd($totals[$currency]['before'], $log->estimated_cost, 8);
            }
            $totals[$currency]['after'] = bcadd($totals[$currency]['after'], $result['estimated_cost'], 8);
            $this->line('#'.$log->id.' '.$log->model.' '.$log->status.'：'.($log->estimated_cost === null ? '未知' : $log->currency.' '.$log->estimated_cost).' 改为 '.$currency.' '.$result['estimated_cost'].'（'.$result['price_snapshot']['applied']['period'].'）');
            if ($apply) {
                DB::transaction(function () use ($log, $result, $reason): void {
                    $log->forceFill([...$result, 'repricing_history' => [...($log->repricing_history ?? []), [
                        'from' => ['price_version' => $log->price_version, 'currency' => $log->currency, 'estimated_cost' => $log->estimated_cost, 'billing_status' => $log->billing_status],
                        'to' => ['price_version' => $result['price_version'], 'currency' => $result['currency'], 'estimated_cost' => $result['estimated_cost']],
                        'reason' => $reason, 'at' => now()->toIso8601String(),
                    ]]])->saveOrFail();
                });
            }
        }
        foreach ($totals as $currency => $total) {
            $this->info($currency.' 合计：'.$total['before'].' 改为 '.$total['after']);
        }
        $this->info(($apply ? '已重算 ' : '预览：将重算 ').$changed.' 条请求'.($apply ? '。' : '；确认后加 --apply --reason="…" 写入。'));

        return self::SUCCESS;
    }
}
