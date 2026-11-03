<?php

namespace App\Services\Ai;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Throwable;

/** 沿用 SDK 原生 OpenAI 接口，仅禁止其 Retry-After 隐式重试。 */
final class SingleAttemptOpenAiGateway extends OpenAiGateway
{
    /** 即使供应商返回短 Retry-After，也只保留第一次请求结果。 */
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        return null;
    }
}
