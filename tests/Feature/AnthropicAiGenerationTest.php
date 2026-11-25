<?php

namespace Tests\Feature;

use App\Models\CostLog;
use App\Models\ModelPrice;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\ModelProfiles;
use App\Services\Ai\TextRequestOptions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 通过SDK真实HTTP组装路径离线验证Claude路由、Messages协议、用量换算与失败证据；全部使用假响应。 */
class AnthropicAiGenerationTest extends TestCase
{
    use DatabaseMigrations;

    /** 测试只迁移应用表。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 假凭据与禁止外网策略确保任何测试都不产生模型费用。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.anthropic.key' => 'sk-ant-test-secret', 'ai.providers.anthropic.url' => 'https://api.anthropic.com/v1']);
        Http::preventStrayRequests();
    }

    /** 清理内存库中的单价，避免迁移回滚保护拦截。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('model_prices')->delete();
        parent::tearDown();
    }

    /** Messages 协议响应样本：input_tokens 不含缓存命中与写入。
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function response(?string $text = null, string $stop = 'end_turn', array $usage = ['input_tokens' => 30, 'output_tokens' => 10, 'cache_read_input_tokens' => 15, 'cache_creation_input_tokens' => 5]): array
    {
        $text ??= json_encode(B02SettingTest::setting(), JSON_UNESCAPED_UNICODE);

        return ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_reason' => $stop, 'stop_details' => null,
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => $text]], 'usage' => $usage];
    }

    /** 结构化请求走原生 output_config，带思考强度、无温度、无回退；用量换算为“输入含缓存”并按单价估算。 */
    public function test_structured_request_routes_to_claude_and_estimates_cost(): void
    {
        ModelPrice::factory()->create(['model' => 'claude-sonnet-5-5', 'currency' => 'USD', 'input_per_million' => '2', 'cache_read_per_million' => '0.10',
            'cache_write_per_million' => '2.50', 'output_per_million' => '10', 'effective_at' => now()->subDay()]);
        Http::fake(['https://api.anthropic.com/v1/messages' => function (Request $request) {
            $this->assertSame('anthropic', CostLog::sole()->requested_provider);
            $this->assertSame('pending', CostLog::sole()->status);
            $this->assertSame('sk-ant-test-secret', $request->header('x-api-key')[0]);
            $this->assertSame('claude-sonnet-5-5', $request['model']);
            $this->assertSame(4096, $request['max_tokens']);
            $this->assertSame('json_schema', $request['output_config']['format']['type']);
            $this->assertSame('low', $request['output_config']['effort']);
            $this->assertArrayNotHasKey('temperature', $request->data());
            $this->assertArrayNotHasKey('fallbacks', $request->data());
            $this->assertArrayNotHasKey('tool_choice', $request->data());

            return Http::response($this->response(), 200, ['request-id' => 'req_claude']);
        }]);
        $log = app(AiTextGenerationService::class)->generate('测试JSON设定', 'real', 'claude-sonnet-5-5', options: TextRequestOptions::setting(0.5));
        $this->assertSame(['succeeded', 'anthropic', 'req_claude', B02SettingTest::setting()], [$log->status, $log->provider, $log->provider_request_id, $log->structured_output]);
        $this->assertSame([50, 10, 15, 5, null], [$log->input_tokens, $log->output_tokens, $log->cache_read_tokens, $log->cache_write_tokens, $log->reasoning_tokens]);
        // 30 未缓存 × $2 + 15 命中 × $0.10 + 5 写入 × $2.50 + 10 输出 × $10 = 0.000174
        $this->assertSame(['estimated', '0.00017400'], [$log->billing_status, $log->estimated_cost]);
        $this->assertSame(['messages', 'json_schema', 'low', false], [$log->request_parameters['protocol'], $log->request_parameters['output_format'], $log->request_parameters['effort'], $log->request_parameters['refusal_fallback']]);
        $this->assertArrayNotHasKey('temperature', $log->request_parameters);
        $this->assertSame('b02-anthropic-setting-v1', $log->route_version);
        Http::assertSentCount(1);
    }

    /** 统一结构校验对 Claude 同样生效但只留痕：不符合约定的输出照常返回成功记录，是否接纳由业务契约决定。 */
    public function test_structure_problems_are_recorded_without_failing_the_call(): void
    {
        $setting = B02SettingTest::setting();
        $setting['tone']['pov'] = 3;
        $setting['invented'] = '未约定';
        Http::fake(['api.anthropic.com/*' => Http::response($this->response(json_encode($setting, JSON_UNESCAPED_UNICODE)))]);
        $log = app(AiTextGenerationService::class)->generate('JSON', 'real', 'claude-sonnet-5-5', options: TextRequestOptions::setting(0.5));
        $this->assertSame(['succeeded', 50], [$log->status, $log->input_tokens]);
        $this->assertEqualsCanonicalizing(['/tone/pov：类型应为 string，实际为 integer', '/：出现未约定的字段 invented'], $log->raw_response['_schema_validation']['shape']);
        Http::assertSentCount(1);
    }

    /** 未登记的 claude- 前缀模型同样路由到 Claude；可选模型列表包含新增的四个型号。 */
    public function test_models_are_registered_and_prefix_routes_to_claude(): void
    {
        $this->assertSame('anthropic', ModelProfiles::provider('claude-opus-5-5'));
        $this->assertSame('anthropic', ModelProfiles::provider('claude-unlisted-9'));
        $this->assertSame(32768, ModelProfiles::extendedOutputTokens('claude-haiku-5-5'));
        $this->assertSame([], array_diff(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-2'], array_keys(ModelProfiles::options())));
    }

    /** 截断与安全拒答都按失败保留响应并计费，不自动重发、不换模型。 */
    public function test_truncation_and_refusal_are_failures_with_usage(): void
    {
        $refusal = [...$this->response('', 'refusal'), 'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null]];
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($this->response('{"ok":', 'max_tokens'))->push($refusal)]);
        foreach (['output_truncated', 'refusal'] as $expected) {
            try {
                app(AiTextGenerationService::class)->generate('JSON', 'real', 'claude-opus-5-5', options: TextRequestOptions::setting(0.5));
                $this->fail('应当按失败处理');
            } catch (AiGenerationException $error) {
                $this->assertSame($expected, $error->category);
            }
        }
        $logs = CostLog::orderBy('id')->get();
        $this->assertSame([['failed', 'output_truncated', 50], ['failed', 'refusal', 50]], $logs->map(fn (CostLog $log): array => [$log->status, $log->error_category, $log->input_tokens])->all());
        $this->assertStringContainsString('cyber', $logs[1]->error_message);
        Http::assertSentCount(2);
    }

    /** 1小时缓存写入单价不同，金额保持未知而不按5分钟价低估。 */
    public function test_one_hour_cache_write_keeps_cost_unknown(): void
    {
        ModelPrice::factory()->create(['model' => 'claude-opus-5-5', 'currency' => 'USD', 'input_per_million' => '4', 'cache_read_per_million' => '0.20',
            'cache_write_per_million' => '5', 'output_per_million' => '20', 'effective_at' => now()->subDay()]);
        $usage = ['input_tokens' => 30, 'output_tokens' => 10, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 5, 'cache_creation' => ['ephemeral_5m_input_tokens' => 0, 'ephemeral_1h_input_tokens' => 5]];
        Http::fake(['api.anthropic.com/*' => Http::response($this->response('连接成功', 'end_turn', $usage))]);
        $log = app(AiTextGenerationService::class)->generate('测试', 'real', 'claude-opus-5-5');
        $this->assertSame(['succeeded', 'unknown', null], [$log->status, $log->billing_status, $log->estimated_cost]);
    }

    /** 过载（529）为结果未知，Retry-After 不造成隐式重发，证据脱敏。 */
    public function test_overload_is_unknown_single_attempt_and_sanitized(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'key sk-ant-test-secret']], 529, ['retry-after' => '1'])]);
        try {
            app(AiTextGenerationService::class)->generate('测试', 'real', 'claude-haiku-5-5');
            $this->fail('应当报告失败');
        } catch (AiGenerationException $error) {
            $this->assertSame(['unknown', 'http_529'], [$error->status, $error->category]);
        }
        $this->assertStringNotContainsString('sk-ant-test-secret', json_encode(CostLog::sole()->toArray()));
        Http::assertSentCount(1);
    }

    /** 缺少密钥或非官方地址在HTTP之前拒绝。 */
    public function test_missing_key_and_unofficial_endpoint_send_nothing(): void
    {
        foreach ([['ai.providers.anthropic.key' => null], ['ai.providers.anthropic.key' => 'sk-ant-test-secret', 'ai.providers.anthropic.url' => 'https://proxy.invalid/v1']] as $config) {
            config($config);
            try {
                app(AiTextGenerationService::class)->generate('测试', 'real', 'claude-opus-5-5');
                $this->fail('应当在发送前拒绝');
            } catch (AiGenerationException $error) {
                $this->assertContains($error->category, ['missing_credentials', 'invalid_endpoint']);
            }
        }
        Http::assertNothingSent();
    }
}
