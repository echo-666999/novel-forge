<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Throwable;

/** 使用官方 Laravel AI SDK 调用 DeepSeek；不自行拼装或重发模型请求。 */
final class DeepSeekTextProvider implements TextProvider
{
    /** 显式注入 SDK 管理器与事件总线，使离线测试可使用同一条程序路径。 */
    public function __construct(private AiManager $manager, private Dispatcher $events) {}

    /** 校验固定路由并执行一次请求，SDK 默认的缺失用量零值不得当作真实计量。 */
    public function generate(string $prompt, string $model, ?TextRequestOptions $options = null): TextGenerationResult
    {
        $options ??= TextRequestOptions::probe();
        $key = trim((string) config('ai.providers.deepseek.key'));
        if ($key === '' || in_array($key, ['sk-...', 'your-api-key', 'your_deepseek_api_key'], true)) {
            throw new AiGenerationException('请先在本机配置有效 DEEPSEEK_API_KEY；尚未发送请求。', 'missing_credentials');
        }
        if (! in_array(rtrim((string) config('ai.providers.deepseek.url'), '/'), ['https://api.deepseek.com', 'https://api.deepseek.com/v1'], true)) {
            throw new AiGenerationException('A03 仅允许已批准的 DeepSeek 官方地址。', 'invalid_endpoint');
        }
        if (config('ai.providers.deepseek.driver') !== 'deepseek') {
            throw new AiGenerationException('A03 要求使用 SDK 的 DeepSeek 驱动。', 'invalid_driver');
        }

        try {
            $this->manager->textProvider('deepseek')->useTextGateway(new SingleAttemptDeepSeekGateway($this->events, $options->executionContext['deepseek_reasoning_effort'] ?? $options->submissionContext['deepseek_reasoning_effort'] ?? 'none'));
            $agent = match (true) {
                $options->settingDecision => new SettingDecisionAgent($options),
                $options->submissionPurpose !== null => new SubmissionAgent($options),
                $options->chapterStep !== null => new ChapterGenerationAgent($options),
                $options->outlineRange !== null => new OutlineGenerationAgent($options),
                $options->structuredSetting => new SettingGenerationAgent($options),
                default => new TextGenerationAgent,
            };
            $response = $agent->prompt($prompt, provider: 'deepseek', model: $model, timeout: $options->timeoutSeconds);
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
        // 官方缓存未命中计数不等于缓存写入计量，缺失值保持未知。
        $usage = ['cache_write_tokens' => null];
        foreach (['input_tokens' => 'input_tokens', 'output_tokens' => 'output_tokens', 'cache_read_tokens' => 'input_tokens_details.cached_tokens', 'reasoning_tokens' => 'output_tokens_details.reasoning_tokens'] as $name => $path) {
            $value = data_get($rawUsage, $path);
            $usage[$name] = is_int($value) ? $value : null;
        }
        $text = $this->sanitize(['text' => $response->text], $key)['text'];
        // 仅完整响应允许处理尾逗号；原始响应保持原样，另附规范化证据。
        if ($options->isStructured() && ($raw['status'] ?? null) === 'completed') {
            $normalized = DeepSeekJsonOutput::normalize($text);
            if ($normalized['removed_comma_offsets'] !== [] || $normalized['removed_trailing_offsets'] !== []) {
                // 只有尾逗号时沿用原版本号与字段，历史审计格式保持不变。
                $raw['_application_json_normalization'] = [
                    'version' => $normalized['removed_trailing_offsets'] === [] ? 'trailing-comma-v1' : 'trailing-comma-bracket-v2', 'original_text_sha256' => hash('sha256', $text),
                    'normalized_text_sha256' => hash('sha256', $normalized['text']),
                    'removed_comma_offsets' => $normalized['removed_comma_offsets'],
                    ...($normalized['removed_trailing_offsets'] === [] ? [] : ['removed_trailing_bracket_offsets' => $normalized['removed_trailing_offsets']]),
                ];
                $text = $normalized['text'];
            }
        }
        $decoded = $options->isStructured() ? json_decode($text, true) : null;
        $result = new TextGenerationResult(
            text: $text,
            structured: is_array($decoded) ? $decoded : null,
            provider: filled($response->meta->provider) ? $response->meta->provider : null,
            model: filled($response->meta->model) ? $response->meta->model : null,
            usage: $usage,
            providerRequestId: $response->raw?->header('x-request-id') ?: null,
            rawResponse: $raw,
        );
        if (trim($result->text) === '' || ($raw !== null && ($raw['status'] ?? null) !== 'completed')) {
            throw new AiGenerationException('供应商未返回完整有效文本，已保留响应。', 'invalid_response', 'failed', $result);
        }

        // 不使用SDK宽松解码结果；只接受原文或留痕规范化后的有效JSON对象。
        if ($options->isStructured()) {
            $decoded = json_decode($result->text, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || array_is_list($decoded)) {
                throw new AiGenerationException('DeepSeek未返回有效JSON对象，已保留原始响应。', 'invalid_response', 'failed', $result);
            }
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
