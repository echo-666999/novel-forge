<?php

namespace Tests\Feature;

use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextGenerationAgent;
use App\Settings\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/** 离线覆盖 SDK 真实 HTTP 协议路径、成本落库与失败边界，禁止意外外网调用。 */
class A03AiGenerationTest extends TestCase
{
    use DatabaseMigrations;

    /** SQLite 只迁移应用表，PostgreSQL 约束由独立集成测试负责。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 使用明确的测试凭据；SDK 调用只能命中测试定义的响应。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.openai.key' => 'sk-a03-test-secret', 'ai.providers.openai.url' => 'https://api.openai.com/v1', 'ai.providers.openai.driver' => 'openai']);
        Http::preventStrayRequests();
    }

    /** 构造供应商协议样本，调用真实 SDK 解析器而非镜像应用实现。
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>
     */
    private function response(?array $usage = null, string $text = 'A03连接成功。'): array
    {
        $data = [
            'id' => 'resp_a03', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4-0613',
            'output' => [['type' => 'message', 'id' => 'msg_a03', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]],
        ];
        if ($usage !== null) {
            $data['usage'] = $usage;
        }

        return $data;
    }

    /** 默认命令只产生固定 JSON，且无密钥时仍可完整记账。 */
    public function test_default_command_is_deterministic_offline_and_persists_simulated_cost(): void
    {
        config(['ai.providers.openai.key' => null]);
        $this->artisan('app:verify-ai', ['--json' => true])->assertExitCode(0)->expectsOutputToContain('A03 Fake Provider');
        $log = CostLog::query()->sole();
        $this->assertSame('fake', $log->mode);
        $this->assertSame('succeeded', $log->status);
        $this->assertSame(['ok' => true, 'message' => 'A03 Fake Provider'], $log->structured_output);
        $this->assertSame('simulated', $log->billing_status);
        $this->assertSame('0.00000000', $log->actual_cost);
        $this->assertNull($log->input_tokens);
        Http::assertNothingSent();
    }

    /** SDK 请求前须已提交 pending 明细；响应模型与请求模型分开保留。 */
    public function test_sdk_protocol_records_usage_response_and_actual_model_once(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => function (Request $request) {
            $this->assertSame('pending', CostLog::query()->sole()->status);
            $this->assertSame('gpt-4', $request['model']);
            $this->assertSame(128, $request['max_output_tokens']);
            $this->assertFalse($request['store']);

            return Http::response($this->response(['input_tokens' => 10, 'output_tokens' => 5]), 200, ['x-request-id' => 'req_a03']);
        }]);
        $log = app(AiTextGenerationService::class)->generate('短文本', 'real', 'gpt-4');
        $this->assertSame('succeeded', $log->status);
        $this->assertSame('gpt-4', $log->requested_model);
        $this->assertSame('gpt-4-0613', $log->model);
        $this->assertSame('openai', $log->provider);
        $this->assertSame('req_a03', $log->provider_request_id);
        $this->assertSame(10, $log->input_tokens);
        $this->assertSame(5, $log->output_tokens);
        $this->assertNull($log->cache_read_tokens);
        $this->assertNull($log->actual_cost);
        $this->assertNull($log->estimated_cost);
        $this->assertSame('unknown', $log->billing_status);
        $this->assertNotNull($log->finished_at);
        Http::assertSentCount(1);
    }

    /** SDK 自带 fake 可以覆盖 Agent，而不会误把 SDK 的默认零用量当成真实报告。 */
    public function test_sdk_fake_agent_is_supported_without_inventing_usage(): void
    {
        TextGenerationAgent::fake(['SDK离线文本'])->preventStrayPrompts();
        $log = app(AiTextGenerationService::class)->generate('SDK离线样例', 'real', 'gpt-4');
        $this->assertSame('SDK离线文本', $log->response_text);
        $this->assertNull($log->input_tokens);
        $this->assertNull($log->output_tokens);
        TextGenerationAgent::assertPromptedTimes(1);
        Http::assertNothingSent();
    }

    /** 供应商缺失 usage 时为空，不能因为 SDK 内部默认值而记录零费用。 */
    public function test_missing_usage_remains_unknown(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response())]);
        $log = app(AiTextGenerationService::class)->generate('短文本', 'real', 'gpt-4');
        $this->assertNull($log->input_tokens);
        $this->assertNull($log->output_tokens);
        $this->assertNull($log->actual_cost);
        $this->assertSame('unknown', $log->billing_status);
    }

    /** 估算全程使用十进制，缓存与推理子集不能重复计费。 */
    public function test_decimal_estimate_uses_frozen_exact_model_prices_and_no_duplicate_subsets(): void
    {
        config(['ai.costs.prices' => ['gpt-4-0613' => ['version' => 'test-v1', 'currency' => 'USD', 'input_per_million' => '2.0', 'output_per_million' => '4.0', 'cache_read_per_million' => '0.5', 'cache_write_per_million' => '3.0']]]);
        Http::fake(['https://api.openai.com/v1/responses' => function () {
            config(['ai.costs.prices' => []]);

            return Http::response($this->response(['input_tokens' => 100, 'output_tokens' => 40, 'input_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 10], 'output_tokens_details' => ['reasoning_tokens' => 30]]));
        }]);
        $log = app(AiTextGenerationService::class)->generate('短文本', 'real', 'gpt-4');
        $this->assertSame('0.00034000', $log->estimated_cost);
        $this->assertNull($log->actual_cost);
        $this->assertSame('estimated', $log->billing_status);
        $this->assertSame('test-v1', $log->price_version);
        $this->assertSame('0.5', $log->price_snapshot['cache_read_per_million']);
        $this->assertSame(30, $log->reasoning_tokens);
    }

    /** 占位密钥在请求前失败，但失败日志仍须可查询。 */
    public function test_placeholder_key_is_rejected_and_command_returns_failure_without_network(): void
    {
        config(['ai.providers.openai.key' => 'sk-...']);
        $this->artisan('app:verify-ai', ['--mode' => 'real', '--json' => true])->assertExitCode(1)->expectsOutputToContain('missing_credentials');
        $log = CostLog::query()->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('gpt-4.1-mini', $log->requested_model);
        $this->assertNull($log->actual_cost);
        Http::assertNothingSent();
    }

    /** 新安装及增量迁移后，设置类和持久化默认值须一致。 */
    public function test_persisted_default_model_matches_class_default(): void
    {
        $defaults = (new \ReflectionClass(GeneralSettings::class))->getDefaultProperties();
        $this->assertSame('gpt-4.1-mini', $defaults['default_model']);
        $this->assertSame('gpt-4.1-mini', app(GeneralSettings::class)->refresh()->default_model);
    }

    /** 旧值可迁移、重复执行安全，且升级与回退均保留其他显式模型。 */
    public function test_model_migration_only_changes_approved_values_and_rolls_back_safely(): void
    {
        $migration = require database_path('settings/2026_11_03_131945_update_default_model_for_a03.php');
        $setting = DB::table('settings')->where('group', 'general')->where('name', 'default_model');
        foreach (['gpt-4', 'gpt-6.1'] as $old) {
            $setting->update(['payload' => json_encode($old, JSON_THROW_ON_ERROR)]);
            $migration->up();
            $migration->up();
            $this->assertSame('gpt-4.1-mini', app(GeneralSettings::class)->refresh()->default_model);
            $migration->down();
            $this->assertSame('gpt-4', app(GeneralSettings::class)->refresh()->default_model);
        }
        $setting->update(['payload' => json_encode('my-approved-model', JSON_THROW_ON_ERROR)]);
        $migration->up();
        $migration->down();
        $this->assertSame('my-approved-model', app(GeneralSettings::class)->refresh()->default_model);
        Http::assertNothingSent();
    }

    /** 鉴权失败保存安全证据，但不保存回显密钥或认证头。 */
    public function test_authentication_failure_is_redacted_and_not_retried(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Bad key sk-a03-test-secret', 'code' => 'invalid_api_key']], 401, ['x-request-id' => 'req_failed'])]);
        try {
            app(AiTextGenerationService::class)->generate('短文本', 'real', 'gpt-4');
            $this->fail('鉴权失败不得返回成功');
        } catch (AiGenerationException $exception) {
            $this->assertSame('http_401', $exception->category);
            $this->assertNotNull($exception->costLogId);
        }
        $log = CostLog::query()->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('req_failed', $log->provider_request_id);
        $this->assertStringNotContainsString('sk-a03-test-secret', $log->toJson());
        $this->assertStringNotContainsString('Authorization', $log->toJson());
        Http::assertSentCount(1);
    }

    /** SDK 的短 Retry-After 重试必须被覆盖，429 仍只产生一次请求。 */
    public function test_retry_after_header_cannot_trigger_hidden_sdk_retry(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '0'])]);
        $this->artisan('app:verify-ai', ['--mode' => 'real'])->assertExitCode(1);
        $this->assertSame('failed', CostLog::query()->sole()->status);
        Http::assertSentCount(1);
    }

    /** 服务端错误即使提示立即重试也不能重发，计费结果保持未知。 */
    public function test_server_error_with_retry_after_remains_unknown_and_single_attempt(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'unavailable']], 503, ['Retry-After' => '0'])]);
        $this->artisan('app:verify-ai', ['--mode' => 'real'])->assertExitCode(1);
        $log = CostLog::query()->sole();
        $this->assertSame('unknown', $log->status);
        $this->assertNull($log->actual_cost);
        Http::assertSentCount(1);
    }

    /** 成功正文意外回显认证信息时同样脱敏，Fake 后仍能正确切回真实 SDK 路径。 */
    public function test_mode_switch_does_not_leak_fake_state_or_echoed_credentials(): void
    {
        $service = app(AiTextGenerationService::class);
        $fake = $service->generate('离线文本');
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response(null, '不应回显 sk-a03-test-secret'))]);
        $real = $service->generate('真实路径', 'real', 'gpt-4');
        $this->assertSame('fake', $fake->provider);
        $this->assertSame('openai', $real->provider);
        $this->assertStringNotContainsString('sk-a03-test-secret', $real->toJson());
        $this->assertSame(2, CostLog::query()->count());
        Http::assertSentCount(1);
    }

    /** 超时未知时保留风险状态，不制造零账单或再次调用。 */
    public function test_connection_failure_is_unknown_and_not_retried(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);
        $this->artisan('app:verify-ai', ['--mode' => 'real'])->assertExitCode(1);
        $log = CostLog::query()->sole();
        $this->assertSame('unknown', $log->status);
        $this->assertNull($log->actual_cost);
        $this->assertNull($log->input_tokens);
        Http::assertSentCount(1);
    }

    /** 空正文失败仍保留原始响应和已知 Token 用量，供后续审计。 */
    public function test_empty_response_preserves_evidence_and_usage(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response(['input_tokens' => 10, 'output_tokens' => 0], ''))]);
        $this->artisan('app:verify-ai', ['--mode' => 'real'])->assertExitCode(1);
        $log = CostLog::query()->sole();
        $this->assertSame('invalid_response', $log->error_category);
        $this->assertSame(10, $log->input_tokens);
        $this->assertNotNull($log->raw_response);
    }

    /** 非法计量列保持空值，原始非法数值不丢失。 */
    public function test_invalid_usage_cannot_break_audit_persistence(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->response(['input_tokens' => -1, 'output_tokens' => 5]))]);
        $this->artisan('app:verify-ai', ['--mode' => 'real'])->assertExitCode(1);
        $log = CostLog::query()->sole();
        $this->assertSame('invalid_usage', $log->error_category);
        $this->assertNull($log->input_tokens);
        $this->assertSame(-1, $log->raw_response['usage']['input_tokens']);
    }

    /** 日志表不可用时，不允许先产生外部请求再尝试补账。 */
    public function test_initial_log_failure_prevents_provider_call(): void
    {
        Schema::drop('cost_logs');
        try {
            app(AiTextGenerationService::class)->generate('短文本', 'real', 'gpt-4');
            $this->fail('缺少明细表必须阻止调用');
        } catch (QueryException) {
            Http::assertNothingSent();
        }
    }

    /** 响应后保存失败不能宣称成功，也不能自动重发以弥补数据库错误。 */
    public function test_result_save_failure_is_reported_without_reissuing_request(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => function () {
            Schema::drop('cost_logs');

            return Http::response($this->response(['input_tokens' => 1, 'output_tokens' => 1]));
        }]);
        $this->artisan('app:verify-ai', ['--mode' => 'real'])->assertExitCode(1);
        Http::assertSentCount(1);
    }

    /** 小说和阶段关联不一致时必须在调用前拒绝，避免费用归属错误。 */
    public function test_stage_association_is_validated_and_can_infer_novel(): void
    {
        $stage = NovelStage::factory()->create();
        $log = app(AiTextGenerationService::class)->generate('短文本', novelStageId: $stage->id);
        $this->assertTrue($log->novelStage->is($stage));
        $this->assertTrue($log->novel->is($stage->novel));
        try {
            app(AiTextGenerationService::class)->generate('短文本', novelId: Novel::factory()->create()->id, novelStageId: $stage->id);
            $this->fail('不得错误归属费用');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, CostLog::query()->count());
        }
        Http::assertNothingSent();
    }

    /** 事务内成本日志尚未提交，禁止向真实供应商发送请求。 */
    public function test_real_call_inside_transaction_is_rejected(): void
    {
        DB::beginTransaction();
        try {
            app(AiTextGenerationService::class)->generate('短文本', 'real', 'gpt-4');
            $this->fail('禁止在外层事务内调用');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        } finally {
            DB::rollBack();
        }
    }

    /** 非法模式及未批准地址必须拒绝，而不是悄悄切换供应商。 */
    public function test_invalid_mode_and_unapproved_endpoint_are_rejected(): void
    {
        $this->artisan('app:verify-ai', ['--mode' => 'typo'])->assertExitCode(1);
        $this->assertSame(0, CostLog::query()->count());
        config(['ai.providers.openai.url' => 'https://example.invalid/v1']);
        $this->artisan('app:verify-ai', ['--mode' => 'real'])->assertExitCode(1);
        $this->assertSame('invalid_endpoint', CostLog::query()->sole()->error_category);
        Http::assertNothingSent();
    }
}
