<?php

namespace Tests\Feature;

use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use App\Services\Pipeline\ProductionRunService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 通过SDK真实HTTP组装路径离线验证DeepSeek路由、协议及失败证据。 */
class DeepSeekAiGenerationTest extends TestCase
{
    use DatabaseMigrations;

    /** 测试只迁移应用表，禁止触碰真实专库。
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
        config(['ai.providers.deepseek.key' => 'sk-deepseek-test-secret', 'ai.providers.deepseek.url' => 'https://api.deepseek.com/v1']);
        Http::preventStrayRequests();
    }

    /** 清理内存数据库授权样例，避免回滚保护误阻塞测试迁移。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('production_runs')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 返回官方Responses协议样本，缺失用量不得被SDK默认零值掩盖。
     * @return array<string, mixed>
     */
    private function response(string $content = '{"ok":true}', string $finish = 'stop'): array
    {
        return ['id' => 'resp-test', 'object' => 'response', 'model' => 'deepseek-flash',
            'status' => $finish === 'stop' ? 'completed' : 'incomplete',
            'output' => [['id' => 'msg-test', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $content, 'annotations' => []]]]],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 20]]];
    }

    /** 模型明确路由、真实参数与审计对应，结构化请求通过Responses发送Schema。 */
    public function test_structured_request_routes_to_deepseek_and_records_usage(): void
    {
        Http::fake(['https://api.deepseek.com/v1/responses' => function (Request $request) {
            $this->assertSame('deepseek', CostLog::sole()->requested_provider);
            $this->assertSame('pending', CostLog::sole()->status);
            $this->assertSame('deepseek-flash', $request['model']);
            $this->assertSame('json_object', $request['text']['format']['type']);
            $this->assertStringContainsString('JSON Schema', $request['instructions']);
            $this->assertSame(['effort' => 'none'], $request['reasoning']);
            $this->assertSame(4096, $request['max_output_tokens']);
            $this->assertFalse($request['store']);
            $this->assertSame('system', $request['input'][0]['role']);
            $this->assertStringContainsString('"properties"', $request['instructions']);

            return Http::response($this->response(), 200, ['x-request-id' => 'deepseek-request']);
        }]);
        $log = app(AiTextGenerationService::class)->generate('测试JSON设定', 'real', 'deepseek-flash', options: TextRequestOptions::setting(0.5));
        $this->assertSame('deepseek', $log->provider);
        $this->assertSame('succeeded', $log->status);
        $this->assertSame(50, $log->input_tokens);
        $this->assertSame(20, $log->cache_read_tokens);
        $this->assertNull($log->cache_write_tokens);
        $this->assertNull($log->actual_cost);
        $this->assertFalse($log->request_parameters['strict']);
        $this->assertSame('json_object', $log->request_parameters['output_format']);
        $this->assertSame('responses', $log->request_parameters['protocol']);
        $this->assertSame('b02-deepseek-setting-v1', $log->route_version);
        $this->assertSame('deepseek-request', $log->provider_request_id);
        Http::assertSentCount(1);
    }

    /** 截断、空白、伪JSON都必须保留为失败，不能进入后续正文处理。
     * @return array<string, array{string, string}>
     */
    public static function invalidResponses(): array
    {
        return ['truncated' => ['{"ok":true}', 'length'], 'empty' => ['   ', 'stop'], 'invalid_json' => ['不是JSON', 'stop'],
            'unclosed' => ['{"title":"正文', 'stop'], 'missing_value' => ['{"title":,}', 'stop'],
            'empty_array_comma' => ['{"items":[,]}', 'stop'], 'incomplete_with_comma' => ['{"ok":true,}', 'length']];
    }

    /** 尾逗号可留痕规范化，但正文字符串中的逗号、括号及转义必须逐字不变。 */
    public function test_trailing_commas_are_normalized_with_original_evidence(): void
    {
        $text = '{"title":"题目","paragraphs":{"p1":"她说：\\"保留,}和,]\\"。","p2":"结束。",},"items":["甲",],}';
        Http::fake(['api.deepseek.com/*' => Http::response($this->response($text))]);
        $log = app(AiTextGenerationService::class)->generate('JSON', 'real', 'deepseek-flash', options: TextRequestOptions::setting(0.5));
        $this->assertSame('succeeded', $log->status);
        $this->assertSame('她说："保留,}和,]"。', $log->structured_output['paragraphs']['p1']);
        $this->assertSame($text, $log->raw_response['output'][0]['content'][0]['text']);
        $audit = $log->raw_response['_application_json_normalization'];
        $this->assertSame(hash('sha256', $text), $audit['original_text_sha256']);
        $this->assertSame(hash('sha256', $log->response_text), $audit['normalized_text_sha256']);
        $this->assertCount(3, $audit['removed_comma_offsets']);
        $this->assertSame(json_decode($log->response_text, true), $log->structured_output);
        Http::assertSentCount(1);
    }

    /** 错误响应原文仍可追溯，且不自动重试。 */
    #[DataProvider('invalidResponses')]
    public function test_invalid_output_is_preserved(string $content, string $finish): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response($this->response($content, $finish))]);
        try {
            app(AiTextGenerationService::class)->generate('JSON', 'real', 'deepseek-flash', options: TextRequestOptions::setting(0.5));
            $this->fail('应当拒绝无效输出');
        } catch (AiGenerationException $error) {
            $this->assertSame('invalid_response', $error->category);
        }
        $this->assertSame('failed', CostLog::sole()->status);
        $this->assertSame($content, CostLog::sole()->response_text);
        Http::assertSentCount(1);
    }

    /** 明确拒绝和连接不确定分开记录，Retry-After不能造成隐式重发。 */
    public function test_rate_limit_has_one_attempt_and_sanitized_evidence(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'Bearer sk-deepseek-test-secret']], 429, ['Retry-After' => '1'])]);
        try {
            app(AiTextGenerationService::class)->generate('测试', 'real', 'deepseek-flash');
            $this->fail('应当拒绝请求');
        } catch (AiGenerationException $error) {
            $this->assertSame('http_429', $error->category);
        }
        $log = CostLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString('sk-deepseek-test-secret', json_encode($log->toArray()));
        Http::assertSentCount(1);
    }

    /** 非官方地址在HTTP之前拒绝，防止泄露小说和凭据。 */
    public function test_non_official_endpoint_is_rejected_before_http(): void
    {
        config(['ai.providers.deepseek.url' => 'https://other.invalid/v1']);
        try {
            app(AiTextGenerationService::class)->generate('测试', 'real', 'deepseek-flash');
            $this->fail('应拒绝非官方地址');
        } catch (AiGenerationException $error) {
            $this->assertSame('invalid_endpoint', $error->category);
        }
        Http::assertNothingSent();
    }

    /** 缺失用量为未知，不从SDK的默认值伪造零费用。 */
    public function test_absent_usage_stays_unknown(): void
    {
        $response = $this->response('连接成功');
        unset($response['usage']);
        Http::fake(['api.deepseek.com/*' => Http::response($response)]);
        $log = app(AiTextGenerationService::class)->generate('测试', 'real', 'deepseek-flash');
        $this->assertNull($log->input_tokens);
        $this->assertNull($log->output_tokens);
        $this->assertSame('unknown', $log->billing_status);
        Http::assertSentCount(1);
    }

    /** 自动十章采用通用默认模型并冻结，随后修改默认模型不能改变旧运行。 */
    public function test_ten_chapter_run_freezes_deepseek_default(): void
    {
        Queue::fake();
        $settings = app(GeneralSettings::class);
        $settings->default_model = 'deepseek-flash';
        $settings->save();
        $run = app(ProductionRunService::class)->start(B02SettingTest::input(), User::factory()->create(), true, false, chapterLimit: 10);
        $settings->default_model = 'gpt-4.1-mini';
        $settings->save();
        $this->assertSame('deepseek-flash', Novel::findOrFail($run->novel_id)->generation_config['model']);
        Http::assertNothingSent();
    }

    /** 未配置密钥时只保存本地失败，不把请求送到另一供应商。 */
    public function test_missing_credentials_does_not_send_http(): void
    {
        config(['ai.providers.deepseek.key' => null]);
        try {
            app(AiTextGenerationService::class)->generate('测试', 'real', 'deepseek-flash');
            $this->fail('必须拒绝缺失凭据');
        } catch (AiGenerationException $error) {
            $this->assertSame('missing_credentials', $error->category);
        }
        Http::assertNothingSent();
    }

    /** 超时保留未知状态与未知费用，不自动重试或降级供应商。 */
    public function test_connection_failure_remains_unknown(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::failedConnection()]);
        try {
            app(AiTextGenerationService::class)->generate('测试', 'real', 'deepseek-flash');
            $this->fail('必须报告连接异常');
        } catch (AiGenerationException $error) {
            $this->assertSame('unknown', $error->status);
        }
        $this->assertSame('unknown', CostLog::sole()->status);
        $this->assertNull(CostLog::sole()->actual_cost);
    }

    /** 提交评审的低推理容量真实发送并记账，不污染普通请求配置。 */
    public function test_submission_review_sends_explicit_reasoning_budget(): void
    {
        Http::fake(['api.deepseek.com/*' => function (Request $request) {
            $this->assertSame(['effort' => 'low'], $request['reasoning']);
            $this->assertSame(16384, $request['max_output_tokens']);

            return Http::response($this->response('{"review":{"verdict":"pass","summary":"隔离评审","evidence_ids":["p1"],"findings":[]}}'));
        }]);
        $options = TextRequestOptions::submission('review', ['run_id' => 1, 'version_id' => 1, 'body_hash' => hash('sha256', '正文。'), 'anchors' => ['p1' => ['paragraph' => 1, 'text' => '正文。']], 'deepseek_reasoning_effort' => 'low']);
        $log = app(AiTextGenerationService::class)->generate('测试独立评审', 'real', 'deepseek-flash', options: $options);
        $this->assertSame(['effort' => 'low'], $log->request_parameters['reasoning']);
        Http::assertSentCount(1);
    }

    /** 正文Schema按段落列表声明场景长度、段落类型和单段格式，不只依赖供应商对复杂正则的支持。 */
    public function test_draft_schema_carries_explicit_length_limits(): void
    {
        Http::fake(['api.deepseek.com/*' => function (Request $request) {
            $schema = json_decode(explode('说明：', $request['instructions'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
            $field = $schema['properties']['paragraphs']['properties']['scene_1'];
            $this->assertSame('array', $field['type']);
            $this->assertSame(['narration', 'dialogue'], $field['items']['properties']['kind']['enum']);
            $pattern = $field['items']['properties']['text']['pattern'];
            $this->assertSame(1, preg_match('/'.$pattern.'/u', '“走吧。”'));
            $this->assertSame(0, preg_match('/'.$pattern.'/u', "她来了。\n“走吧。”"));
            $this->assertStringContainsString('344至406', $field['description']);
            $this->assertStringContainsString('建议约390字', json_encode($request->data(), JSON_UNESCAPED_UNICODE));
            $this->assertSame(['effort' => 'low'], $request['reasoning']);

            return Http::response($this->response('{"title":"样本","paragraphs":{"scene_1":[{"kind":"narration","text":"正文"}]}}'));
        }]);
        $options = TextRequestOptions::executionChapter('draft', 0.5, ['anchors' => [], 'rules' => [], 'findings' => [], 'deepseek_reasoning_effort' => 'low', 'budget' => ['slots' => ['scene_1'], 'min' => 344, 'max' => 406]]);
        $log = app(AiTextGenerationService::class)->generate('测试JSON正文', 'real', 'deepseek-flash', options: $options);
        $this->assertSame(['effort' => 'low'], $log->request_parameters['reasoning']);
        Http::assertSentCount(1);
    }
}
