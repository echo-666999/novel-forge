<?php

namespace Tests\Feature;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\RunNovelPipelineJob;
use App\Jobs\RunSettingJob;
use App\Models\Chapter;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\SettingExecutor;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** 沿真实 SDK 协议离线验证设定契约、记账及后台路径，绝不访问外网。 */
class B02SettingTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移兼容 SQLite 的应用表。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 使用内存库、工厂账号、假队列和显式测试凭据。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'ai.providers.openai.key' => 'sk-b02-test-secret', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** 内存夹具在断言结束后解除回滚保护，不能作用于持久数据库。 */
    protected function tearDown(): void
    {
        CostLog::flushEventListeners();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(0, Chapter::query()->count());
        DB::table('novels')->update(['generation_mode' => 'simulation']);
        parent::tearDown();
    }

    /** 保留两行原始创意，运行配置不是客户端字段。
     * @return array<string, mixed>
     */
    public static function input(): array
    {
        return ['title' => '灯塔来信', 'genre' => '悬疑', 'premise_raw' => "守灯人每晚收到未来的来信。\n她必须找到十年前失踪的师父。", 'target_chapters' => 10, 'chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], 'generation_mode' => 'setting_real'];
    }

    /** 独立的语义夹具包含关键世界规则歧义，不是应用实现的镜像。
     * @return array<string, mixed>
     */
    public static function setting(): array
    {
        return [
            'world' => ['era' => '架空近代', 'background' => '海雾中的孤岛灯塔与失踪旧案。', 'rules' => ['守灯人每晚收到一封未来的来信，来源尚待确认。']],
            'characters' => [['name' => '林澈', 'role' => '守灯人、主角', 'goal' => '寻找十年前失踪的师父', 'personality' => '谨慎执着', 'speech_style' => '简短克制', 'abilities' => '熟悉航道和灯塔机械', 'limitations' => '来信内容尚无法独立验证']],
            'tone' => ['narrative_style' => '克制悬疑', 'pov' => '第三人称有限视角', 'pacing' => '以验证来信推进调查'],
            'core_conflict' => '依赖来信寻找师父，却必须判断来信是否可信。',
            'preserved_details' => ['每晚收到未来来信', '师父已失踪十年'],
            'open_questions' => [['question' => '来信是否能改变已经发生的过去？', 'impact' => '决定世界因果规则', 'blocking' => true]],
        ];
    }

    /** 构造 OpenAI Responses 协议响应，供真实 SDK 解析。
     * @return array<string, mixed>
     */
    public static function response(string $text, string $status = 'completed'): array
    {
        return ['id' => 'resp_b02', 'object' => 'response', 'status' => $status, 'model' => 'gpt-4.1-mini-2025-04-14', 'usage' => ['input_tokens' => 180, 'output_tokens' => 400], 'output' => [['type' => 'message', 'id' => 'msg_b02', 'role' => 'assistant', 'status' => $status, 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]]];
    }

    /** 冻结设置后经表单入队，真实执行只成功一次并停在大纲边界。 */
    public function test_creation_job_and_view_use_frozen_real_setting_without_simulated_followups(): void
    {
        Livewire::test(CreateNovel::class)->fillForm(self::input())->call('create')->assertHasNoFormErrors()->assertNotified('小说已创建，已提交启动请求');
        $novel = Novel::query()->sole();
        $this->assertSame(GenerationMode::SettingReal, $novel->generation_mode);
        $this->assertSame(NovelStatus::Pending, $novel->status);
        $this->assertSame(self::input()['premise_raw'], $novel->premise_raw);
        $this->assertSame(4096, $novel->generation_config['max_output_tokens']);
        $this->assertArrayNotHasKey('key', $novel->generation_config);
        Queue::assertPushed(RunSettingJob::class, 1);
        Queue::assertNotPushed(RunNovelPipelineJob::class);
        // 模拟任务的迟到失败回调同样不能修改真实小说。
        (new RunNovelPipelineJob($novel->id, true))->failed(new RuntimeException('模拟回调不得接管真实书'));
        $this->assertSame(NovelStatus::Pending, $novel->fresh()->status);
        $job = Queue::pushed(RunSettingJob::class)->sole();
        $settings = app(GeneralSettings::class);
        $settings->default_model = 'gpt-other';
        $settings->temperature = 1.4;
        $settings->enable_fine_outline = false;
        Http::fake(['https://api.openai.com/v1/responses' => function (Request $request) use ($novel, $job) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(NovelStatus::Running, $novel->fresh()->status);
            $this->assertSame(StageStatus::Running, $novel->stages()->sole()->status);
            $this->assertSame('pending', CostLog::query()->sole()->status);
            // 第一请求尚未返回时模拟另一消费者，必须因 running 领用记录退出。
            $job->handle(app(SettingExecutor::class));
            $this->assertSame(1, $novel->stages()->count());
            $this->assertSame('gpt-4.1-mini', $request['model']);
            $this->assertSame(4096, $request['max_output_tokens']);
            $this->assertSame(0.7, $request['temperature']);
            $this->assertSame('json_schema', $request['text']['format']['type']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertFalse($request['text']['format']['schema']['additionalProperties']);
            $this->assertFalse($request['store']);

            return Http::response(self::response(json_encode(self::setting(), JSON_UNESCAPED_UNICODE)), 200, ['x-request-id' => 'req_b02']);
        }]);
        unserialize(serialize($job))->handle(app(SettingExecutor::class));
        $novel->refresh();
        $stage = $novel->stages()->sole();
        $this->assertSame(NovelStatus::Paused, $novel->status);
        $this->assertSame(Stage::Outline, $novel->current_stage);
        $this->assertSame(1, $novel->execution_count);
        $this->assertSame('16.67', $novel->progress);
        $this->assertFalse($stage->input['simulation']);
        $this->assertFalse($stage->output['simulation']);
        $this->assertTrue($stage->input['pipeline']['enable_fine_outline']);
        $this->assertSame(self::setting(), $stage->output['data']['setting']);
        $this->assertSame('candidate', $stage->output['data']['candidate_status']);
        $this->assertSame(CostLog::query()->sole()->id, $stage->output['data']['cost_log_id']);
        $this->assertSame('unknown', $stage->cost['billing_status']);
        $this->assertNull($stage->cost['actual_cost']);
        $this->assertSame(45, CostLog::query()->sole()->request_parameters['timeout_seconds']);
        $before = $stage->getRawOriginal();
        $job->handle(app(SettingExecutor::class));
        (new RunNovelPipelineJob($novel->id, true))->handle();
        $this->assertSame($before, $stage->fresh()->getRawOriginal());
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSee('设定已生成，等待大纲阶段')->assertSee('林澈')->assertSee('世界背景')->assertSee('第 1 次 · ')->assertSee('金额未知')->callAction('refreshStatus')->assertSuccessful();
        Http::assertSentCount(1);
    }

    /** 拒绝 SDK 能宽容修补但不符合本地契约的响应。
     * @return array<string, array{string}>
     */
    public static function invalidOutputs(): array
    {
        $emptyCharacters = self::setting();
        $emptyCharacters['characters'] = [];
        $wrongBoolean = self::setting();
        $wrongBoolean['open_questions'][0]['blocking'] = 1;
        $extra = self::setting();
        $extra['invented'] = '未知字段';
        $blank = self::setting();
        $blank['tone']['pov'] = '　';

        return [
            'broken json' => ['{"world":'], 'markdown wrapper' => ["```json\n".json_encode(self::setting())."\n```"],
            'missing fields' => ['{}'], 'empty characters' => [json_encode($emptyCharacters)],
            'integer boolean' => [json_encode($wrongBoolean)], 'unknown field' => [json_encode($extra)],
            'unicode blank' => [json_encode($blank)],
        ];
    }

    /** 结构错误保留原始响应和已知用量，失败阶段不推进、不自动修复。 */
    #[DataProvider('invalidOutputs')]
    public function test_invalid_schema_preserves_response_and_charge_evidence(string $text): void
    {
        $result = app(CreateNovelService::class)->createAndStart(self::input());
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(self::response($text))]);
        Queue::pushed(RunSettingJob::class)->sole()->handle(app(SettingExecutor::class));
        $stage = $result->novel->stages()->sole();
        $this->assertSame(StageStatus::Failed, $stage->status);
        $this->assertSame('invalid_setting_schema', $stage->output['data']['error_category']);
        $this->assertSame(NovelStatus::Error, $result->novel->fresh()->status);
        $this->assertSame('succeeded', CostLog::query()->sole()->status);
        $this->assertSame($text, CostLog::query()->sole()->response_text);
        $this->assertSame(400, CostLog::query()->sole()->output_tokens);
        $this->assertNull($stage->cost['actual_cost']);
        Http::assertSentCount(1);
    }

    /** 一次 HTTP 失败不得因 Retry-After 变成自动重试。
     * @return array<string, array{int, string}>
     */
    public static function providerFailures(): array
    {
        return ['unauthorized' => [401, 'failed'], 'rate limit' => [429, 'failed'], 'server error' => [503, 'unknown'], 'timeout' => [408, 'unknown']];
    }

    /** 明确失败与结果未知分开记录，错误响应中的凭据不得进入输出。 */
    #[DataProvider('providerFailures')]
    public function test_provider_failures_stop_after_one_request(int $status, string $outcome): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(self::input())->novel;
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Bearer sk-b02-test-secret']], $status, ['Retry-After' => '1'])]);
        Queue::pushed(RunSettingJob::class)->sole()->handle(app(SettingExecutor::class));
        $stage = $novel->stages()->sole();
        $this->assertSame(StageStatus::Failed, $stage->status);
        $this->assertSame($outcome, $stage->output['data']['outcome']);
        $this->assertSame($outcome, CostLog::query()->sole()->status);
        $this->assertStringNotContainsString('sk-b02-test-secret', json_encode($stage->toArray()));
        $this->assertStringNotContainsString('sk-b02-test-secret', json_encode(CostLog::query()->sole()->toArray()));
        Http::assertSentCount(1);
    }

    /** 供应商报告截断或拒答时不能将其标成结构成功。
     * @return array<string, array{string, string}>
     */
    public static function incompleteResponses(): array
    {
        return ['truncated' => [json_encode(self::setting()), 'incomplete'], 'empty' => ['', 'completed']];
    }

    /** 非完整响应保留供应商证据，阶段失败。 */
    #[DataProvider('incompleteResponses')]
    public function test_incomplete_or_empty_response_is_not_accepted(string $text, string $status): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(self::input())->novel;
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(self::response($text, $status))]);
        Queue::pushed(RunSettingJob::class)->sole()->handle(app(SettingExecutor::class));
        $this->assertSame(StageStatus::Failed, $novel->stages()->sole()->status);
        $this->assertSame('invalid_response', CostLog::query()->sole()->error_category);
        $this->assertNotNull(CostLog::query()->sole()->raw_response);
        Http::assertSentCount(1);
    }

    /** 初始记账失败不能产生没有本地意图记录的外部调用。 */
    public function test_cost_intent_failure_stops_before_network(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(self::input())->novel;
        CostLog::creating(fn () => throw new RuntimeException('模拟数据库写入失败'));
        Queue::pushed(RunSettingJob::class)->sole()->handle(app(SettingExecutor::class));
        $this->assertSame(StageStatus::Failed, $novel->stages()->sole()->status);
        $this->assertSame(0, CostLog::query()->count());
        Http::assertNothingSent();
    }

    /** 最终保存失败保留 pending 意图和未知结果，不能冒充有效候选。 */
    public function test_result_persistence_failure_is_unknown_and_does_not_resend(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(self::input())->novel;
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(self::response(json_encode(self::setting())))]);
        CostLog::saving(function (CostLog $log): void {
            if ($log->status === 'succeeded') {
                throw new RuntimeException('模拟结果保存失败');
            }
        });
        $job = Queue::pushed(RunSettingJob::class)->sole();
        $job->handle(app(SettingExecutor::class));
        $this->assertSame('pending', CostLog::query()->sole()->status);
        $this->assertSame('unknown', $novel->stages()->sole()->output['data']['outcome']);
        $job->handle(app(SettingExecutor::class));
        Http::assertSentCount(1);
    }

    /** 详情中的人物文本不得被当作 HTML 执行。 */
    public function test_setting_candidate_is_escaped_in_read_only_view(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(self::input())->novel;
        $setting = self::setting();
        $setting['characters'][0]['name'] = '<script>alert(1)</script>';
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(self::response(json_encode($setting)))]);
        Queue::pushed(RunSettingJob::class)->sole()->handle(app(SettingExecutor::class));
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')->assertDontSeeHtml('<script>alert(1)</script>');
        $this->get('/admin/novels/'.$novel->id.'/edit')->assertNotFound();
    }

    /** 网络连接失败结果未知，不重发并保留关联调用意图。 */
    public function test_connection_failure_is_unknown_without_automatic_retry(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(self::input())->novel;
        Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);
        Queue::pushed(RunSettingJob::class)->sole()->handle(app(SettingExecutor::class));
        $this->assertSame('unknown', CostLog::query()->sole()->status);
        $this->assertSame('unknown', $novel->stages()->sole()->output['data']['outcome']);
        $this->assertSame(1, CostLog::query()->count());
        $this->assertSame(1, $novel->fresh()->execution_count);
    }

    /** 供应商拒答必须保留原始拒答证据，不能误判为设定成功。 */
    public function test_refusal_response_is_preserved_as_failure(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(self::input())->novel;
        $response = self::response('');
        $response['output'][0]['content'] = [['type' => 'refusal', 'refusal' => '测试拒答说明']];
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($response)]);
        Queue::pushed(RunSettingJob::class)->sole()->handle(app(SettingExecutor::class));
        $this->assertSame(StageStatus::Failed, $novel->stages()->sole()->status);
        $this->assertSame('测试拒答说明', CostLog::query()->sole()->raw_response['output'][0]['content'][0]['refusal']);
        Http::assertSentCount(1);
    }
}
