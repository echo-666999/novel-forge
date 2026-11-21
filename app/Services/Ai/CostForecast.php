<?php

namespace App\Services\Ai;

use App\Models\CostLog;
use App\Services\Pipeline\NovelBudget;

/** 启动前的费用区间估算：单价取已登记单价，Token 用量取该模型历史成功请求的平均值，上限按输出上限计算。只用于提示，不作为账单。 */
final class CostForecast
{
    /** 没有历史请求时使用的保守输入、输出 Token 估计。 */
    private const DEFAULT_INPUT = 8000;

    private const DEFAULT_OUTPUT = 3000;

    /** 估算一段请求的费用区间。
     * @return array{known: bool, low: ?string, high: ?string, currency: ?string, text: string}
     */
    public function range(string $model, int $minRequests, int $maxRequests, int $maxOutputTokens): array
    {
        $price = app(ModelPrices::class)->current($model);
        if ($price === null) {
            return ['known' => false, 'low' => null, 'high' => null, 'currency' => null,
                'text' => '预计'.$minRequests.'～'.$maxRequests.'次请求；模型'.$model.'尚未登记单价，金额无法估算（不代表免费），可在“模型单价”登记。'];
        }
        [$input, $output] = $this->averageUsage($model);
        $per = function (int $in, int $out) use ($price): string {
            $rates = ModelPrices::ceilingRates($price, $in);

            return bcdiv(bcadd(bcmul((string) $in, $rates['input'], 8), bcmul((string) $out, $rates['output'], 8), 8), '1000000', 8);
        };
        // 登记了闲时折扣时，下限按闲时价计，上限按标准价计。
        $low = bcmul(bcmul($per($input, $output), (string) $minRequests, 8), is_string($price['off_peak_ratio'] ?? null) ? $price['off_peak_ratio'] : '1', 8);
        $high = bcmul($per((int) ceil($input * 1.5), max($output, $maxOutputTokens)), (string) $maxRequests, 8);

        return ['known' => true, 'low' => $low, 'high' => $high, 'currency' => $price['currency'],
            'text' => '预计'.$minRequests.'～'.$maxRequests.'次请求，约'.NovelBudget::money($low, $price['currency']).'～'.NovelBudget::money($high, $price['currency'])
                .'（按已登记单价和该模型历史平均用量估算，上限按输出上限计；实际以账单为准）。'];
    }

    /** 该模型历史成功真实请求的平均输入、输出 Token；没有历史时用保守默认值。
     * @return array{0: int, 1: int}
     */
    private function averageUsage(string $model): array
    {
        $row = CostLog::where('mode', 'real')->where('status', 'succeeded')->where('requested_model', $model)->whereNotNull('input_tokens')->whereNotNull('output_tokens')
            ->selectRaw('avg(input_tokens) as input, avg(output_tokens) as output')->first();

        return [(int) ceil((float) ($row?->input ?? self::DEFAULT_INPUT)) ?: self::DEFAULT_INPUT, (int) ceil((float) ($row?->output ?? self::DEFAULT_OUTPUT)) ?: self::DEFAULT_OUTPUT];
    }
}
