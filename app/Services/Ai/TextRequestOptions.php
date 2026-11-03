<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/** 受限请求参数，不允许调用方绕过单次请求和阶段超时边界。 */
final readonly class TextRequestOptions
{
    /** 仅通过固定用途工厂创建，参数随后同时用于请求与日志。
     * @param  array{start: int, end: int, total: int}|null  $outlineRange
     */
    private function __construct(public int $maxOutputTokens, public int $timeoutSeconds, public ?float $temperature, public bool $structuredSetting, public ?array $outlineRange = null) {}

    /** 保持 A03 短文本连通验证的原有行为。 */
    public static function probe(): self
    {
        return new self(128, 30, null, false);
    }

    /** 冻结 B02 输出上限，温度只接受模型支持的有限数值。 */
    public static function setting(float $temperature): self
    {
        if (! is_finite($temperature) || $temperature < 0 || $temperature > 2) {
            throw new InvalidArgumentException('设定生成温度必须在0至2之间');
        }

        return new self(4096, 45, $temperature, true);
    }

    /** 冻结大纲单批范围和调用边界，不允许任意扩展 Schema 或批次大小。 */
    public static function outline(float $temperature, int $start, int $end, int $total): self
    {
        self::setting($temperature);
        if ($start < 1 || $end < $start || $end > $total || $end - $start >= 10) {
            throw new InvalidArgumentException('大纲批次范围不合法');
        }

        return new self(8192, 60, $temperature, false, ['start' => $start, 'end' => $end, 'total' => $total]);
    }

    /** 区分结构化文本与原有短文本，不改变 B02 的布尔兼容属性。 */
    public function isStructured(): bool
    {
        return $this->structuredSetting || $this->outlineRange !== null;
    }

    /** 保存实际非敏感参数，默认请求保持原有三字段结构。
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $parameters = ['max_output_tokens' => $this->maxOutputTokens, 'timeout_seconds' => $this->timeoutSeconds, 'attempt_limit' => 1];

        if ($this->outlineRange !== null) {
            return [...$parameters, 'temperature' => $this->temperature, 'schema_version' => 'b03-outline-v1', 'strict' => true, 'range' => $this->outlineRange];
        }

        return $this->structuredSetting ? [...$parameters, 'temperature' => $this->temperature, 'schema_version' => 'b02-setting-v1', 'strict' => true] : $parameters;
    }
}
