<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 单次阶段结果，成功与失败均可保留可序列化的输出证据。 */
final readonly class StageResult
{
    /** 阶段费用快照；金额使用定点字符串，不把未知计量冒充零费用。
     * @var array{billing_status: string, currency: ?string, estimated_cost: ?string, actual_cost: ?string}
     */
    public array $cost;

    /** 结果只能通过明确的成功或失败工厂创建。
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $cost
     */
    private function __construct(public bool $succeeded, public array $output, public ?string $error, ?array $cost)
    {
        json_encode($output, JSON_THROW_ON_ERROR);
        $this->cost = self::normalizeCost($cost);
    }

    /** 成功结果不包含错误，不允许执行器自行指定下一阶段。
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $cost
     */
    public static function completed(array $output, ?array $cost = null): self
    {
        return new self(true, $output, null, $cost);
    }

    /** 失败保留已有输出，但不会自动重试或修改历史记录。
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $cost
     */
    public static function failed(string $error, array $output = [], ?array $cost = null): self
    {
        return new self(false, $output, trim($error) !== '' ? $error : '模拟阶段执行失败', $cost);
    }

    /** 复用 A03 的计费语义，拒绝浮点金额、额外字段及相互矛盾的可信度。
     * @param  array<string, mixed>|null  $cost
     * @return array{billing_status: string, currency: ?string, estimated_cost: ?string, actual_cost: ?string}
     */
    private static function normalizeCost(?array $cost): array
    {
        $unknown = ['billing_status' => 'unknown', 'currency' => null, 'estimated_cost' => null, 'actual_cost' => null];
        if ($cost === null) {
            return $unknown;
        }
        if (array_diff(array_keys($cost), array_keys($unknown)) !== []) {
            throw new InvalidArgumentException('阶段费用包含未约定的字段');
        }
        $cost = array_replace($unknown, $cost);
        if (! in_array($cost['billing_status'], ['simulated', 'estimated', 'unknown'], true)
            || ($cost['currency'] !== null && (! is_string($cost['currency']) || preg_match('/^[A-Z]{3}$/D', $cost['currency']) !== 1))) {
            throw new InvalidArgumentException('阶段计费状态或币种不合法');
        }
        foreach (['estimated_cost', 'actual_cost'] as $field) {
            if ($cost[$field] !== null && (! is_string($cost[$field]) || preg_match('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{8}$/D', $cost[$field]) !== 1)) {
                throw new InvalidArgumentException('阶段费用必须为非负的八位小数字符串');
            }
        }
        $valid = match ($cost['billing_status']) {
            'simulated' => $cost['currency'] === null && $cost['estimated_cost'] === '0.00000000' && $cost['actual_cost'] === '0.00000000',
            'estimated' => $cost['currency'] !== null && $cost['estimated_cost'] !== null && $cost['actual_cost'] === null,
            'unknown' => $cost['estimated_cost'] === null && $cost['actual_cost'] === null,
        };
        if (! $valid) {
            throw new InvalidArgumentException('阶段费用与计费可信度不一致');
        }

        return $cost;
    }
}
