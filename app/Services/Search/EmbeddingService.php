<?php

namespace App\Services\Search;

use App\Models\CostLog;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** 嵌入专用单次请求入口，先保存调用意图，未知费用绝不记为零。 */
final class EmbeddingService
{
    /** 代次固定，变更模型或维度必须另建索引，不能混用已有向量。 */
    public const PROFILE = 'c05-small-1536-cosine-zhparser-chunk600-v1';

    /** 正文模型设置不影响嵌入模型。 */
    public const MODEL = 'text-embedding-3-small';

    /** 一个批次最多16块、每块最多1800字节，保守控制接口输入。
     * @param  list<string>  $texts
     * @return array{vectors: list<list<float>>, log_id: int, cached: bool}
     */
    public function embed(int $novelId, array $texts, string $purpose): array
    {
        if ($texts === [] || count($texts) > 16 || collect($texts)->contains(fn ($text): bool => ! is_string($text) || trim($text) === '' || strlen($text) > 2400)) {
            throw new RuntimeException('嵌入批次为空或超出分块上限');
        }
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('真实嵌入请求禁止在数据库事务中执行');
        }
        $hash = PlanningJson::hash($texts);
        $cached = CostLog::where('novel_id', $novelId)->where('route_version', self::PROFILE)->where('prompt_hash', $hash)->where('status', 'succeeded')->latest('id')->first();
        if ($cached !== null) {
            return ['vectors' => $cached->structured_output['vectors'], 'log_id' => $cached->id, 'cached' => true];
        }
        $key = trim((string) config('ai.providers.openai.key'));
        if ($key === '' || rtrim((string) config('ai.providers.openai.url'), '/') !== 'https://api.openai.com/v1') {
            throw new RuntimeException('缺少OpenAI密钥或地址不是已授权官方接口，尚未发送');
        }
        $log = CostLog::create(['request_uuid' => (string) Str::uuid7(), 'novel_id' => $novelId, 'mode' => 'real', 'requested_provider' => 'openai', 'requested_model' => self::MODEL,
            'route_version' => self::PROFILE, 'prompt_hash' => $hash, 'request_parameters' => ['task' => 'C05', 'purpose' => $purpose, 'dimensions' => 1536, 'input' => $texts], 'status' => 'pending', 'billing_status' => 'unknown', 'started_at' => now()]);
        $started = hrtime(true);
        try {
            // Laravel HTTP默认不重试；每条流水严格对应一次HTTP尝试，保留未知结果。
            $response = Http::withToken($key)->acceptJson()->connectTimeout(15)->timeout(90)->post('https://api.openai.com/v1/embeddings', ['model' => self::MODEL, 'input' => $texts, 'dimensions' => 1536, 'encoding_format' => 'float']);
            $log->provider_request_id = $response->header('x-request-id') ?: null;
            // 失败响应也保留脱敏证据；禁止将可能回显的认证密钥落库。
            $raw = $response->json();
            $log->raw_response = is_array($raw) ? $this->sanitize($raw, $key) : ['body' => '[非JSON响应已省略]'];
            if (! $response->successful()) {
                $log->status = $response->status() >= 500 || $response->status() === 408 ? 'unknown' : 'failed';
                $log->error_category = 'http_'.$response->status();
                throw new RuntimeException('嵌入接口返回HTTP '.$response->status().'，未自动重试');
            }
            $data = $response->json();
            $vectors = [];
            foreach ($data['data'] ?? [] as $item) {
                $vector = $item['embedding'] ?? [];
                if (! is_int($item['index'] ?? null) || isset($vectors[$item['index']]) || count($vector) !== 1536 || collect($vector)->contains(fn ($value): bool => ! is_numeric($value) || ! is_finite((float) $value)) || array_sum(array_map(fn ($value): float => (float) $value ** 2, $vector)) <= 0) {
                    throw new RuntimeException('嵌入响应序号或维度无效');
                }
                $vectors[$item['index']] = $vector;
            }
            ksort($vectors);
            if (array_keys($vectors) !== range(0, count($texts) - 1) || ($data['model'] ?? '') !== self::MODEL) {
                throw new RuntimeException('嵌入响应模型或数量不匹配');
            }
            $tokens = data_get($data, 'usage.prompt_tokens');
            if (! is_int($tokens) || $tokens < 0) {
                throw new RuntimeException('嵌入响应缺少有效用量');
            }
            $log->fill(['status' => 'succeeded', 'provider' => 'openai', 'model' => self::MODEL, 'input_tokens' => $tokens, 'output_tokens' => 0, 'structured_output' => ['vectors' => $vectors]]);
        } catch (Throwable $exception) {
            $log->status = $log->status === 'pending' ? 'unknown' : $log->status;
            $log->error_category ??= 'embedding_response_unknown';
            // 不保存异常原文，避免HTTP异常链携带认证信息。
            $log->error_message = '嵌入调用未成功确认，保留流水，请检查接口及响应契约';
        }
        $log->fill(['duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'finished_at' => now()])->saveOrFail();
        if ($log->status !== 'succeeded') {
            throw new RuntimeException('嵌入未完成，调用流水 #'.$log->id.'（'.$log->error_category.'）；词法检索仍可用');
        }

        return ['vectors' => $vectors, 'log_id' => $log->id, 'cached' => false];
    }

    /** 递归脱敏原始响应，包括供应商错误回显。
     * @param  array<string|int,mixed>  $data
     * @return array<string|int,mixed>
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
