<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Throwable;

/** 使用官方 Laravel AI SDK 的 Anthropic 驱动调用 Claude；不自行拼装或重发模型请求。 */
final class AnthropicTextProvider implements TextProvider
{
    /** 显式注入 SDK 管理器与事件总线，使离线测试可使用同一条程序路径。 */
    public function __construct(private AiManager $manager, private Dispatcher $events) {}

    /** 校验固定路由并执行一次请求；用量按 Claude 计量口径换算为统一字段。 */
    public function generate(string $prompt, string $model, ?TextRequestOptions $options = null): TextGenerationResult
    {
        $options ??= TextRequestOptions::probe();
        $key = trim((string) config('ai.providers.anthropic.key'));
        if ($key === '' || in_array($key, ['sk-ant-...', 'your-api-key'], true)) {
            throw new AiGenerationException('请先在后台“模型供应商”页面填写有效的 Claude 密钥；尚未发送请求。', 'missing_credentials');
        }
        if (! in_array(rtrim((string) config('ai.providers.anthropic.url'), '/'), ProviderCredentials::URLS['anthropic'], true)) {
            throw new AiGenerationException('仅允许已批准的 Claude 官方地址。', 'invalid_endpoint');
        }
        if (config('ai.providers.anthropic.driver') !== 'anthropic') {
            throw new AiGenerationException('Claude 必须使用 SDK 的 Anthropic 驱动。', 'invalid_driver');
        }

        try {
            $effort = $options->executionContext['deepseek_reasoning_effort'] ?? $options->submissionContext['deepseek_reasoning_effort'] ?? 'none';
            $this->manager->textProvider('anthropic')->useTextGateway(new SingleAttemptAnthropicGateway($this->events, $effort));
            $agent = match (true) {
                $options->settingDecision => new SettingDecisionAgent($options),
                $options->formalRevisionPurpose !== null => new FormalRevisionAgent($options),
                $options->submissionPurpose !== null => new SubmissionAgent($options),
                $options->chapterStep !== null => new ChapterGenerationAgent($options),
                $options->outlineRange !== null => new OutlineGenerationAgent($options),
                $options->structuredSetting => new SettingGenerationAgent($options),
                default => new TextGenerationAgent,
            };
            $response = $agent->prompt($prompt, provider: 'anthropic', model: $model, timeout: $options->timeoutSeconds);
        } catch (Throwable $exception) {
            // 不透传供应商消息或请求异常链，其中可能包含密钥和认证请求头。
            $requestException = $this->requestException($exception);
            $http = $requestException?->response;
            $status = $http?->status();
            // 529 为 Claude 过载，与 5xx 一样无法确认是否已处理。
            $unknown = $exception instanceof ProviderConnectionException || $exception instanceof ConnectionException || $status === null || $status === 408 || $status >= 500;
            $data = $http?->json();
            $evidence = new TextGenerationResult('', null, null, null,
                ['input_tokens' => null, 'output_tokens' => null, 'cache_read_tokens' => null, 'cache_write_tokens' => null, 'reasoning_tokens' => null],
                $http?->header('request-id') ?: null,
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
        $result = new TextGenerationResult(
            text: $this->sanitize(['text' => $response->text], $key)['text'],
            structured: $options->isStructured() && is_array($response->structured) ? $response->structured : null,
            provider: filled($response->meta->provider) ? $response->meta->provider : null,
            model: filled($response->meta->model) ? $response->meta->model : null,
            usage: self::usage(is_array($raw['usage'] ?? null) ? $raw['usage'] : []),
            providerRequestId: $response->raw?->header('request-id') ?: null,
            rawResponse: $raw,
        );
        $stop = $raw['stop_reason'] ?? null;
        // 输出达到上限被截断（思考同样占用输出额度）单独归类，调用方可复用截断前已完整写出的内容。
        if ($stop === 'max_tokens') {
            throw new AiGenerationException('输出达到Token上限被截断（思考同样占用输出额度），已保留响应。', 'output_truncated', 'failed', $result);
        }
        // 安全策略拒答：已计费，按失败保留证据；不启用服务端换模型回退，也不自动重发。
        if ($stop === 'refusal') {
            $category = data_get($raw, 'stop_details.category');
            throw new AiGenerationException('Claude 出于安全策略拒绝了本次请求'.(is_string($category) ? '（类别：'.$category.'）' : '').'，已保留响应，不会自动改用其他模型重试。', 'refusal', 'failed', $result);
        }
        if (trim($result->text) === '' || ($raw !== null && ! in_array($stop, ['end_turn', 'stop_sequence'], true))) {
            throw new AiGenerationException('供应商未返回完整有效文本，已保留响应。', 'invalid_response', 'failed', $result);
        }
        if ($options->isStructured() && ($result->structured === null || array_is_list($result->structured))) {
            throw new AiGenerationException('Claude 未返回有效JSON对象，已保留原始响应。', 'invalid_response', 'failed', $result);
        }

        return $result;
    }

    /** Claude 的 input_tokens 不含缓存命中与缓存写入，统一口径为“输入总量含缓存子集”；思考计入输出，不单独报告。
     * @param  array<string, mixed>  $usage
     * @return array{input_tokens: ?int, output_tokens: ?int, cache_read_tokens: ?int, cache_write_tokens: ?int, reasoning_tokens: ?int}
     */
    public static function usage(array $usage): array
    {
        $int = fn (string $name): ?int => is_int($usage[$name] ?? null) ? $usage[$name] : null;
        $input = $int('input_tokens');
        $read = $int('cache_read_input_tokens');
        $write = $int('cache_creation_input_tokens');

        return ['input_tokens' => $input === null ? null : $input + ($read ?? 0) + ($write ?? 0), 'output_tokens' => $int('output_tokens'),
            'cache_read_tokens' => $read, 'cache_write_tokens' => $write, 'reasoning_tokens' => null];
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
