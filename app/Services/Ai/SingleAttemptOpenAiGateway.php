<?php

namespace App\Services\Ai;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Throwable;

/** 沿用 SDK 原生接口，适配指定模型参数并禁止隐式重试。 */
final class SingleAttemptOpenAiGateway extends OpenAiGateway
{
    /** SDK完成请求组装后应用模型兼容参数，审计记录使用相同规则。
     * @param  array<string, mixed>  $body
     * @param  array<mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    protected function mergeSharedResponsesRequestOptions(array $body, array $tools, ?array $schema, ?TextGenerationOptions $options, Provider $provider): array
    {
        return self::modelParameters(parent::mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider), $body['model']);
    }

    /** 仅适配已核实参数要求的Sol与Terra型号；低推理替代旧非推理调用，预留短探测的推理输出预算。
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function modelParameters(array $parameters, string $model): array
    {
        if (! in_array($model, ['gpt-6.1-sol', 'gpt-6.1-terra'], true)) {
            return $parameters;
        }
        unset($parameters['temperature'], $parameters['top_p'], $parameters['top_logprobs']);
        $parameters['reasoning'] = ['effort' => 'low'];
        $parameters['max_output_tokens'] = max(1024, $parameters['max_output_tokens'] ?? 1024);

        return $parameters;
    }

    /** 即使供应商返回短 Retry-After，也只保留第一次请求结果。 */
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        return null;
    }
}
