<?php

namespace App\Services\Pipeline;

use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\ModelPrices;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 按书金额预算：请求前按单价上限预留，已花＋在途预留＋本次预留超过预算时在发送前拒绝。全程使用十进制字符串。 */
final class NovelBudget
{
    /** 达到预算的这一比例时显示预警。 */
    public const WARNING_RATIO = '0.8';

    /** 预算摘要：已花（实际优先，其次估算）、在途与结果未知请求的预留、剩余和状态。
     * @return array{budget: ?string, currency: ?string, spent: string, reserved: string, remaining: ?string, unknown: int, state: string}
     */
    public function summary(Novel $novel): array
    {
        $currency = $novel->budget_currency;
        $spent = '0.00000000';
        $reserved = '0.00000000';
        $unknown = 0;
        foreach (CostLog::where('novel_id', $novel->id)->where('mode', 'real')->get(['status', 'currency', 'billing_status', 'actual_cost', 'estimated_cost', 'reserved_cost', 'reserved_currency']) as $log) {
            $billed = $log->actual_cost ?? ($log->billing_status === 'estimated' ? $log->estimated_cost : null);
            if ($billed !== null && ($currency === null || $log->currency === $currency)) {
                $spent = bcadd($spent, (string) $billed, 8);
            } elseif ($billed === null && in_array($log->status, ['pending', 'unknown', 'succeeded'], true) && $log->reserved_cost !== null && ($currency === null || $log->reserved_currency === $currency)) {
                // 结果未知或尚未结算的请求按预留金额计入，宁可多算，不把未知费用当作零。
                $reserved = bcadd($reserved, (string) $log->reserved_cost, 8);
            } elseif ($billed === null && $log->status !== 'failed') {
                $unknown++;
            }
        }
        $budget = $novel->budget_amount === null ? null : (string) $novel->budget_amount;
        $remaining = $budget === null ? null : bcsub($budget, bcadd($spent, $reserved, 8), 8);
        $state = match (true) {
            $budget === null => 'none',
            bccomp($remaining, '0', 8) <= 0 => 'exhausted',
            bccomp(bcadd($spent, $reserved, 8), bcmul($budget, self::WARNING_RATIO, 8), 8) >= 0 => 'warning',
            default => 'normal',
        };

        return ['budget' => $budget, 'currency' => $currency, 'spent' => $spent, 'reserved' => $reserved, 'remaining' => $remaining, 'unknown' => $unknown, 'state' => $state];
    }

    /** 作者可读的一段说明。 */
    public function describe(Novel $novel): string
    {
        $s = $this->summary($novel);
        if ($s['budget'] === null) {
            return '未设预算：请求不受金额限制，只受执行次数上限约束。'.($s['unknown'] > 0 ? '有'.$s['unknown'].'次请求金额未知（未登记单价），不代表免费。' : '');
        }
        $label = ['normal' => '正常', 'warning' => '已用超过80%', 'exhausted' => '预算已用完，新请求会在发送前被拒绝'][$s['state']];

        return '预算 '.self::money($s['budget'], $s['currency']).'，已花 '.self::money($s['spent'], $s['currency']).'，在途预留 '.self::money($s['reserved'], $s['currency'])
            .'，剩余 '.self::money($s['remaining'], $s['currency']).'（'.$label.'）。'.($s['unknown'] > 0 ? '另有'.$s['unknown'].'次请求金额未知，未计入。' : '');
    }

    /** 设置或取消本书预算；记录原因，历史只追加。空金额表示取消预算。 */
    public function set(int $novelId, User $actor, ?string $amount, ?string $currency, string $reason): Novel
    {
        $amount = filled($amount) ? trim((string) $amount) : null;
        if (($amount !== null && (! preg_match('/\A\d{1,12}(?:\.\d{1,8})?\z/', $amount) || ! in_array($currency, ['CNY', 'USD'], true))) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['budget' => '预算金额须为非负数（最多8位小数），币种为CNY或USD，并填写原因。']);
        }

        return DB::transaction(function () use ($novelId, $actor, $amount, $currency, $reason): Novel {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $novel->update(['budget_amount' => $amount, 'budget_currency' => $amount === null ? null : $currency,
                'budget_history' => [...($novel->budget_history ?? []), ['from' => $novel->budget_amount === null ? null : (string) $novel->budget_amount, 'from_currency' => $novel->budget_currency,
                    'to' => $amount, 'to_currency' => $amount === null ? null : $currency, 'reason' => $reason, 'operator_id' => $actor->id, 'at' => now()->toIso8601String()]]]);

            return $novel;
        });
    }

    /** 在写入待返回记录的同一事务中调用：锁定小说，按单价上限计算本次预留，超出剩余预算时拒绝，尚未发送任何请求。
     * @return array{amount: string, currency: string}|null 未设预算时返回空
     *
     * @throws AiGenerationException
     */
    public function reserve(int $novelId, string $model, int $inputTokens, int $maxOutputTokens): ?array
    {
        $novel = Novel::lockForUpdate()->findOrFail($novelId);
        if ($novel->budget_amount === null) {
            return null;
        }
        $price = app(ModelPrices::class)->current($model);
        if ($price === null || ($price['currency'] ?? null) !== $novel->budget_currency) {
            throw new AiGenerationException('本书设了'.$novel->budget_currency.'预算，但模型'.$model.'没有登记同币种单价，无法按预算控制，尚未发送请求。', 'budget_price_unknown', 'failed');
        }
        $amount = bcadd(bcdiv(bcadd(bcmul((string) $inputTokens, $price['input_per_million'], 8), bcmul((string) $maxOutputTokens, $price['output_per_million'], 8), 8), '1000000', 10), '0.000000005', 8);
        $summary = $this->summary($novel);
        if (bccomp($amount, $summary['remaining'], 8) > 0) {
            throw new AiGenerationException('预算不足：本次请求最多需要'.self::money($amount, $novel->budget_currency).'，剩余'.self::money($summary['remaining'], $novel->budget_currency).'，尚未发送请求。', 'budget_exhausted', 'failed');
        }

        return ['amount' => $amount, 'currency' => $novel->budget_currency];
    }

    /** 金额显示：保留两位小数，过小金额保留四位。 */
    public static function money(?string $amount, ?string $currency): string
    {
        if ($amount === null) {
            return '未知';
        }
        $symbol = ['CNY' => '¥', 'USD' => '$'][$currency] ?? ($currency.' ');
        $absolute = ltrim($amount, '-');
        $places = bccomp($absolute, '0', 8) > 0 && bccomp($absolute, '0.01', 8) < 0 ? 4 : 2;

        return (str_starts_with($amount, '-') ? '-' : '').$symbol.number_format((float) $absolute, $places, '.', ',');
    }
}
