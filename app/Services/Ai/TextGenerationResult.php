<?php

namespace App\Services\Ai;

/** 与具体 SDK 解耦的文本结果；缺失计量和供应商标识保持未知。 */
final readonly class TextGenerationResult
{
    /**
     * 保存正文、结构化输出和已脱敏的原始响应，不包含 HTTP 请求头。
     *
     * @param  array<string, mixed>|null  $structured
     * @param  array{input_tokens: ?int, output_tokens: ?int, cache_read_tokens: ?int, cache_write_tokens: ?int, reasoning_tokens: ?int}  $usage
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public string $text,
        public ?array $structured,
        public ?string $provider,
        public ?string $model,
        public array $usage,
        public ?string $providerRequestId = null,
        public ?array $rawResponse = null,
    ) {}
}
