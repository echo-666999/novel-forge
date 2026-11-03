<?php

namespace App\Services\Pipeline;

/** 单次阶段结果，成功与失败均可保留可序列化的输出证据。 */
final readonly class StageResult
{
    /** 结果只能通过明确的成功或失败工厂创建。
     * @param  array<string, mixed>  $output
     */
    private function __construct(public bool $succeeded, public array $output, public ?string $error)
    {
        json_encode($output, JSON_THROW_ON_ERROR);
    }

    /** 成功结果不包含错误，不允许执行器自行指定下一阶段。
     * @param  array<string, mixed>  $output
     */
    public static function completed(array $output): self
    {
        return new self(true, $output, null);
    }

    /** 失败保留已有输出，但不会自动重试或修改历史记录。
     * @param  array<string, mixed>  $output
     */
    public static function failed(string $error, array $output = []): self
    {
        return new self(false, $output, trim($error) !== '' ? $error : '模拟阶段执行失败');
    }
}
