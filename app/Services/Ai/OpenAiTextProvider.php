<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Throwable;

/** 使用官方 Laravel AI SDK 调用 OpenAI；不自行拼装或重发模型请求。 */
final class OpenAiTextProvider implements TextProvider
{
    /** 显式注入 SDK 管理器与事件总线，使离线测试可使用同一条程序路径。 */
    public function __construct(private AiManager $manager, private Dispatcher $events) {}

    /** 校验固定路由并执行一次请求，SDK 默认的缺失用量零值不得当作真实计量。 */
    public function generate(string $prompt, string $model): TextGenerationResult
    {
        $key = trim((string) config('ai.providers.openai.key'));
        if ($key === '' || in_array($key, ['sk-...', 'your-api-key', 'your_openai_api_key'], true)) {
            throw new AiGenerationException('请先在本机配置有效 OPENAI_API_KEY；尚未发送请求。', 'missing_credentials');
        }
        if (rtrim((string) config('ai.providers.openai.url'), '/') !== 'https://api.openai.com/v1') {
            throw new AiGenerationException('A03 仅允许已批准的 OpenAI 官方地址。', 'invalid_endpoint');
        }
        if (config('ai.providers.openai.driver') !== 'openai') {
            throw new AiGenerationException('A03 要求使用 SDK 的 OpenAI 驱动。', 'invalid_driver');
        }

        try {
            $this->manager->textProvider('openai')->useTextGateway(new SingleAttemptOpenAiGateway($this->events));
            $response = (new TextGenerationAgent)->prompt($prompt, provider: 'openai', model: $model, timeout: 30);
        } catch (Throwable $exception) {
            // 不透传供应商消息或请求异常链，其中可能包含密钥和认证请求头。
            $requestException = $this->requestException($exception);
            $http = $requestException?->response;
            $status = $http?->status();
            $unknown = $exception instanceof ProviderConnectionException || $exception instanceof ConnectionException || $status === null || $status === 408 || $status >= 500;
            $data = $http?->json();
            $evidence = new TextGenerationResult('', null, null, null,
                ['input_tokens' => null, 'output_tokens' => null, 'cache_read_tokens' => null, 'cache_write_tokens' => null, 'reasoning_tokens' => null],
                $http?->header('x-request-id') ?: null,
                is_array($data) ? $this->sanitize($data, $key) : null);
            throw new AiGenerationException(
                $unknown ? '供应商请求结果无法确认，已停止且不会自动重发。' : '供应商明确拒绝请求，HTTP '.$status.'；已停止且不会自动重试。',
                $status !== null ? 'http_'.$status : 'provider_connection',
                $unknown ? 'unknown' : 'failed',
                $evidence,
            );
        }

        $raw = $response->raw?->json();
        $raw = is_array($raw) ? $this->sanitize($raw, $key) : null;
        $rawUsage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];
        $usage = [];
        foreach (['input_tokens' => 'input_tokens', 'output_tokens' => 'output_tokens', 'cache_read_tokens' => 'input_tokens_details.cached_tokens', 'cache_write_tokens' => 'input_tokens_details.cache_write_tokens', 'reasoning_tokens' => 'output_tokens_details.reasoning_tokens'] as $name => $path) {
            $value = data_get($rawUsage, $path);
            $usage[$name] = is_int($value) ? $value : null;
        }
        $result = new TextGenerationResult(
            text: $this->sanitize(['text' => $response->text], $key)['text'],
            structured: null,
            provider: filled($response->meta->provider) ? $response->meta->provider : null,
            model: filled($response->meta->model) ? $response->meta->model : null,
            usage: $usage,
            providerRequestId: $response->raw?->header('x-request-id') ?: null,
            rawResponse: $raw,
        );
        if (trim($result->text) === '' || ($raw !== null && ($raw['status'] ?? null) !== 'completed')) {
            throw new AiGenerationException('供应商未返回完整有效文本，已保留响应。', 'invalid_response', 'failed', $result);
        }

        return $result;
    }

    /** 从 SDK 包装异常中定位 HTTP 结果，仅提取状态与安全证据。 */
    private function requestException(Throwable $exception): ?RequestException
    {
        do {
            if ($exception instanceof RequestException) {
                return $exception;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return null;
    }

    /** 递归移除响应中可能回显的密钥，不保存任何请求或响应认证头。
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data, string $key): array
    {
        foreach ($data as $name => $value) {
            if (is_array($value)) {
                $data[$name] = $this->sanitize($value, $key);
            } elseif (is_string($value)) {
                $data[$name] = preg_replace('/Bearer\s+\S+|sk-[A-Za-z0-9_.-]+/i', '[已脱敏]', str_replace($key, '[已脱敏]', $value));
            }
        }

        return $data;
    }
}
