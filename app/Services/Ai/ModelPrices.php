<?php

namespace App\Services\Ai;

use App\Models\ModelPrice;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Validator;

/** 已核实单价的唯一读取入口：后台登记的单价与 config/ai.php 中的单价合并，按最长前缀匹配模型。 */
final class ModelPrices
{
    /** 请求前冻结的完整单价集合：每个模型取已生效的最新登记；配置文件中的同名单价优先。
     * @return array<string, array<string, mixed>>
     */
    public function snapshot(): array
    {
        $prices = [];
        foreach (ModelPrice::where('effective_at', '<=', now())->orderBy('effective_at')->orderBy('id')->get() as $row) {
            $prices[$row->model] = $row->toPriceSnapshot();
        }

        return [...$prices, ...array_filter((array) config('ai.costs.prices', []), 'is_array')];
    }

    /** 按模型标识匹配单价：先精确匹配，再匹配最长的“登记标识-”前缀（如 gpt-4.1-mini 匹配 gpt-4.1-mini-2025-04-14）。
     * @param  array<string, array<string, mixed>>  $snapshot
     * @return array<string, mixed>|null
     */
    public static function resolve(array $snapshot, ?string $model): ?array
    {
        if ($model === null || $model === '') {
            return null;
        }
        if (isset($snapshot[$model])) {
            return $snapshot[$model];
        }
        $best = null;
        foreach (array_keys($snapshot) as $key) {
            if (str_starts_with($model, $key.'-') && ($best === null || strlen((string) $key) > strlen($best))) {
                $best = (string) $key;
            }
        }

        return $best === null ? null : $snapshot[$best];
    }

    /** 当前对某模型生效的单价。
     * @return array<string, mixed>|null
     */
    public function current(string $model): ?array
    {
        return self::resolve($this->snapshot(), $model);
    }

    /** 预算预留与费用预估使用的单价上限：输入超过长上下文阈值时取长上下文单价，不扣闲时折扣。
     * @param  array<string, mixed>  $price
     * @return array{input: string, output: string}
     */
    public static function ceilingRates(array $price, int $inputTokens): array
    {
        $threshold = $price['long_context_threshold'] ?? null;
        $long = is_int($threshold) && $threshold > 0 && $inputTokens > $threshold && isset($price['long_input_per_million'], $price['long_output_per_million']);

        return ['input' => (string) $price[$long ? 'long_input_per_million' : 'input_per_million'], 'output' => (string) $price[$long ? 'long_output_per_million' : 'output_per_million']];
    }

    /** 登记一条新单价；要求填写来源与核实日期，金额为非负十进制，最多8位小数。
     * 闲时倍率与高峰时段须同时填写；长上下文阈值与长上下文输入、输出单价须同时填写。
     *
     * @param  array<string, mixed>  $data
     */
    public function register(User $actor, array $data): ModelPrice
    {
        $decimal = ['regex:/\A\d{1,8}(?:\.\d{1,8})?\z/'];
        if (is_string($data['peak_hours_utc'] ?? null)) {
            $data['peak_hours_utc'] = array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $data['peak_hours_utc']) ?: []), fn (string $window): bool => $window !== ''));
        }
        $data = array_map(fn ($value) => $value === '' || $value === [] ? null : $value, $data);
        $valid = Validator::make($data, [
            'model' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'], 'currency' => ['required', 'in:CNY,USD'],
            'input_per_million' => ['required', ...$decimal], 'output_per_million' => ['required', ...$decimal],
            'cache_read_per_million' => ['nullable', ...$decimal], 'cache_write_per_million' => ['nullable', ...$decimal],
            'off_peak_ratio' => ['nullable', 'required_with:peak_hours_utc', ...$decimal, 'numeric', 'gt:0', 'lte:1'],
            'peak_hours_utc' => ['nullable', 'required_with:off_peak_ratio', 'array', 'max:12'],
            'peak_hours_utc.*' => ['string', 'regex:/\A([01]\d|2[0-3]):[0-5]\d-(([01]\d|2[0-3]):[0-5]\d|24:00)\z/', function (string $attribute, string $value, Closure $fail): void {
                [$start, $end] = explode('-', $value);
                if ($start >= $end) {
                    $fail('高峰时段的结束时间须晚于开始时间。');
                }
            }],
            'long_context_threshold' => ['nullable', 'required_with:long_input_per_million,long_output_per_million', 'integer', 'min:1'],
            'long_input_per_million' => ['nullable', 'required_with:long_context_threshold', ...$decimal],
            'long_cache_read_per_million' => ['nullable', ...$decimal], 'long_cache_write_per_million' => ['nullable', ...$decimal],
            'long_output_per_million' => ['nullable', 'required_with:long_context_threshold', ...$decimal],
            'source' => ['required', 'string', 'max:2000'], 'verified_on' => ['required', 'date', 'before_or_equal:today'],
        ], ['regex' => ':attribute格式不正确。金额最多8位小数；时段写成01:00-04:00。', 'required' => '请填写:attribute。', 'required_with' => '填写:values时须同时填写:attribute。',
            'gt' => ':attribute须大于0。', 'lte' => ':attribute不能大于1。'], [
                'model' => '模型标识', 'currency' => '币种', 'input_per_million' => '输入（缓存未命中）单价', 'output_per_million' => '输出单价',
                'cache_read_per_million' => '缓存命中单价', 'cache_write_per_million' => '缓存写入单价', 'off_peak_ratio' => '闲时倍率', 'peak_hours_utc' => '高峰时段',
                'peak_hours_utc.*' => '高峰时段', 'long_context_threshold' => '长上下文阈值', 'long_input_per_million' => '长上下文输入单价',
                'long_cache_read_per_million' => '长上下文缓存命中单价', 'long_cache_write_per_million' => '长上下文缓存写入单价', 'long_output_per_million' => '长上下文输出单价', 'source' => '来源', 'verified_on' => '核实日期',
            ])->validate();
        $optional = ['cache_read_per_million', 'cache_write_per_million', 'off_peak_ratio', 'peak_hours_utc', 'long_context_threshold', 'long_input_per_million', 'long_cache_read_per_million', 'long_cache_write_per_million', 'long_output_per_million'];

        return ModelPrice::create([...$valid, ...array_combine($optional, array_map(fn (string $key) => $valid[$key] ?? null, $optional)),
            'effective_at' => now(), 'created_by' => $actor->id, 'created_at' => now()]);
    }
}
