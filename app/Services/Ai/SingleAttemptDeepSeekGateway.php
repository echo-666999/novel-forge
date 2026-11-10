<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Throwable;

/** DeepSeek官方Responses兼容协议；JSON模式约束语法，业务契约负责结构，禁止隐式重试。 */
final class SingleAttemptDeepSeekGateway extends OpenAiGateway
{
    /** 思考强度由本次请求显式冻结；默认关闭，修复可选择低强度且不隐式增加请求。 */
    public function __construct(Dispatcher $events, private string $reasoningEffort = 'none')
    {
        if (! in_array($reasoningEffort, ['none', 'low'], true)) {
            throw new InvalidArgumentException('DeepSeek思考强度无效');
        }
        parent::__construct($events);
    }

    /** 发送明确的思考策略；服务地址仍由DeepSeek供应商配置提供。
     * @param  array<string, mixed>  $body
     * @param  array<mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    protected function mergeSharedResponsesRequestOptions(array $body, array $tools, ?array $schema, ?TextGenerationOptions $options, Provider $provider): array
    {
        $body = parent::mergeSharedResponsesRequestOptions($body, $tools, $schema, $options, $provider);
        $body['reasoning'] = ['effort' => $this->reasoningEffort];
        $body['store'] = false;
        if ($schema !== null && $schema !== []) {
            // 实测json_schema模式仍返回非法JSON；改用官方JSON模式，并显式传入完整结构契约。
            $contract = $body['text']['format']['schema'];
            $body['text'] = ['format' => ['type' => 'json_object']];
            $body['instructions'] = '只输出有效JSON对象，严格遵守以下JSON Schema，不输出代码围栏或说明：'
                .json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $body;
    }

    /** 实际协议与参数入审计，不会把DeepSeek请求记作OpenAI请求。
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function modelParameters(array $parameters, string $reasoningEffort = 'none'): array
    {
        return [...$parameters, 'strict' => false, 'output_format' => isset($parameters['schema_version']) ? 'json_object' : 'text',
            'schema_enforcement' => 'application', 'protocol' => 'responses', 'reasoning' => ['effort' => $reasoningEffort], 'store' => false];
    }

    /** 收到Retry-After也不重发，恢复必须留下新的独立请求记录。 */
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        return null;
    }
}
