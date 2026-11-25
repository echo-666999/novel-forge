<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Throwable;

/** Claude官方Messages协议：沿用SDK原生请求组装（原生结构化输出），按请求冻结思考强度，禁止隐式重试。 */
final class SingleAttemptAnthropicGateway extends AnthropicGateway
{
    /** 思考强度：沿用本项目“默认不推理、显式要求时低强度推理”的约定，分别映射为 low 与 medium。 */
    public const EFFORTS = ['none' => 'low', 'low' => 'medium'];

    /** 思考强度在请求前冻结，审计记录使用相同映射。 */
    public function __construct(Dispatcher $events, private string $reasoningEffort = 'none')
    {
        if (! isset(self::EFFORTS[$reasoningEffort])) {
            throw new InvalidArgumentException('Claude思考强度无效');
        }
        parent::__construct($events);
    }

    /** 在SDK组装的请求体上设置思考强度；不发送服务端拒答回退参数，拒答由调用方按失败处理。
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    protected function buildTextRequestBody(Provider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options): array
    {
        $body = parent::buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
        $body['output_config'] = [...($body['output_config'] ?? []), 'effort' => self::EFFORTS[$this->reasoningEffort]];

        return $body;
    }

    /** 实际协议与参数入审计：Claude 5.x 不接受温度，SDK不会发送，审计中同样移除。
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function modelParameters(array $parameters, string $reasoningEffort = 'none'): array
    {
        unset($parameters['temperature'], $parameters['top_p']);

        return [...$parameters, 'protocol' => 'messages', 'output_format' => isset($parameters['schema_version']) ? 'json_schema' : 'text',
            'effort' => self::EFFORTS[$reasoningEffort] ?? self::EFFORTS['none'], 'refusal_fallback' => false];
    }

    /** 收到 Retry-After 也不重发，恢复必须留下新的独立请求记录。 */
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        return null;
    }
}
