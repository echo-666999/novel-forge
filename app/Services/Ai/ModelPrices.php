<?php

namespace App\Services\Ai;

use App\Models\ModelPrice;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/** 已核实单价的唯一读取入口：后台登记的单价与 config/ai.php 中的单价合并，按最长前缀匹配模型。 */
final class ModelPrices
{
    /** 请求前冻结的完整单价集合：每个模型取已生效的最新登记；配置文件中的同名单价优先。
     * @return array<string, array<string, string>>
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
     * @param  array<string, array<string, string>>  $snapshot
     * @return array<string, string>|null
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
     * @return array<string, string>|null
     */
    public function current(string $model): ?array
    {
        return self::resolve($this->snapshot(), $model);
    }

    /** 登记一条新单价；要求填写来源与核实日期，金额为非负十进制，最多8位小数。
     * @param  array<string, mixed>  $data
     */
    public function register(User $actor, array $data): ModelPrice
    {
        $decimal = ['regex:/\A\d{1,8}(?:\.\d{1,8})?\z/'];
        $valid = Validator::make($data, [
            'model' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'], 'currency' => ['required', 'in:CNY,USD'],
            'input_per_million' => ['required', ...$decimal], 'output_per_million' => ['required', ...$decimal],
            'cache_read_per_million' => ['nullable', ...$decimal], 'cache_write_per_million' => ['nullable', ...$decimal],
            'source' => ['required', 'string', 'max:2000'], 'verified_on' => ['required', 'date', 'before_or_equal:today'],
        ], ['regex' => ':attribute格式不正确，金额最多8位小数。', 'required' => '请填写:attribute。'], [
            'model' => '模型标识', 'currency' => '币种', 'input_per_million' => '输入单价', 'output_per_million' => '输出单价',
            'cache_read_per_million' => '缓存读取单价', 'cache_write_per_million' => '缓存写入单价', 'source' => '来源', 'verified_on' => '核实日期',
        ])->validate();

        return ModelPrice::create([...$valid, 'cache_read_per_million' => filled($valid['cache_read_per_million'] ?? null) ? $valid['cache_read_per_million'] : null,
            'cache_write_per_million' => filled($valid['cache_write_per_million'] ?? null) ? $valid['cache_write_per_million'] : null,
            'effective_at' => now(), 'created_by' => $actor->id, 'created_at' => now()]);
    }
}
