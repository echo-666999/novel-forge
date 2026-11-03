<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/** 受限请求参数，不允许调用方绕过单次请求和阶段超时边界。 */
final readonly class TextRequestOptions
{
    /** 仅通过固定用途工厂创建，参数随后同时用于请求与日志。 */
    private function __construct(public int $maxOutputTokens, public int $timeoutSeconds, public ?float $temperature, public bool $structuredSetting) {}

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

    /** 保存实际非敏感参数，默认请求保持原有三字段结构。
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $parameters = ['max_output_tokens' => $this->maxOutputTokens, 'timeout_seconds' => $this->timeoutSeconds, 'attempt_limit' => 1];

        return $this->structuredSetting ? [...$parameters, 'temperature' => $this->temperature, 'schema_version' => 'b02-setting-v1', 'strict' => true] : $parameters;
    }
}
