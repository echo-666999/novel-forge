<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\RunChapterStepJob;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\NovelStage;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentPromptAudit;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\ProgressionChecker;
use App\Services\Pipeline\RunControlService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 真实应用入口与SDK HTTP夹具覆盖v14边界，合成正文不冒充文学或真实模型验收。 */
class B04ExecutionTest extends TestCase
{
    use DatabaseMigrations;

    protected int $consumed = 0;

    protected int $checks = 0;

    protected string $scenario = 'pass';

    /** 仅迁移隔离库及设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 禁止意外网络请求，使用SDK实际载荷核对请求前费用及审计。 */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.providers.openai.key' => 'sk-b04-offline', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->fakeFlow();
    }

    /** 只清理已确认的内存库。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 请求数据直接来自已保存快照，模型返回由测试明确控制。 */
    protected function fakeFlow(): void
    {
        Http::fake(['*' => function (Request $request) {
            $log = CostLog::latest('id')->firstOrFail();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('pending', $log->status);
            $audit = $log->request_parameters['prompt_audit'];
            $this->assertSame($audit['user_prompt'], $request['input'][1]['content'][0]['text']);
            $this->assertSame($audit['system_prompt'], $request['input'][0]['content']);
            $this->assertSame($audit['schema_hash'], PlanningJson::hash($request['text']['format']['schema']));
            $this->assertSame(ContentExecutionContract::VERSION, $log->request_parameters['schema_version']);
            $payload = json_decode(explode('冻结输入：', $audit['user_prompt'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
            $step = $log->request_parameters['step'];
            $data = [];
            if ($this->scenario === 'unknown') {
                throw new ConnectionException('隔离超时');
            }
            if ($this->scenario === 'provider') {
                return Http::response(['error' => ['message' => 'offline rejected', 'type' => 'invalid_request_error']], 400);
            }
            if ($step === 'fine') {
                $data = B04ContentTest::fine();
            } elseif ($step === 'draft') {
                $this->assertArrayNotHasKey('available_anchors', $payload);
                $this->assertArrayNotHasKey('last_attempt', $payload);
                $paragraphs = [];
                foreach ($payload['budget']['slots'] as $i => $slot) {
                    $text = str_repeat(mb_chr(0x4E00 + $i + 16 * NovelStage::findOrFail($log->novel_stage_id)->input['run_id']), intdiv($payload['budget']['min'] + $payload['budget']['max'], 2));
                    if ($this->scenario === 'meta' && $i === 0) {
                        $text = '此段约350字。'.mb_substr($text, 8);
                    }
                    if ($this->scenario === 'complete') {
                        $text = mb_substr($text, 0, -1).'。';
                    }
                    $paragraphs[$slot] = $text;
                }
                $data = ['title' => '隔离首章候选', 'paragraphs' => array_reverse($paragraphs, true)];
                if ($this->scenario === 'schema') {
                    $data['paragraphs'][array_key_first($paragraphs)] = '短文';
                }
            } elseif ($step === 'revise') {
                $id = array_key_first($payload['available_anchors']);
                $text = $payload['available_anchors'][$id]['text'];
                $text = str_replace('此段约350字。', '核对原档完成调查。', $text).'修订后取得新证据。';
                $data = ['ranges' => [['start_id' => $id, 'end_id' => $id, 'text' => $text, 'reason' => '隔离修复待解决问题', 'addresses' => array_keys($payload['findings'])]]];
            } else {
                $this->checks++;
                $id = array_key_first($payload['evidence_catalog']);
                $met = ! ($this->scenario === 'insufficient' || ($this->scenario === 'once' && $this->checks === 1));
                $data = ['points' => [['id' => 'chapter-1-point-1', 'met' => $met, 'reason' => '隔离推进判断', 'evidence_ids' => [$id]]],
                    'hook' => ['met' => $met, 'reason' => '隔离钩子判断', 'evidence_ids' => [$id]], 'setting_violations' => [], 'continuity_issues' => [], 'story_tensions' => [],
                    'finding_reviews' => array_map(fn ($finding): array => ['id' => $finding, 'disposition' => $met ? 'resolved' : 'unresolved', 'reason' => '隔离逐项复核', 'evidence_ids' => [$id]], array_keys($payload['findings']))];
                if ($this->scenario === 'blocked') {
                    $data['setting_violations'] = [['rule_id' => array_key_first($payload['rule_catalog']), 'reason' => '隔离核心冲突', 'evidence_ids' => [$id]]];
                }
                if ($this->scenario === 'evidence') {
                    $data['points'][0]['evidence_ids'] = ['p999'];
                }
                if ($this->scenario === 'missing_review') {
                    $data['finding_reviews'] = [];
                }
            }
            $response = B02SettingTest::response(PlanningJson::encode($data));
            $response['model'] = $this->scenario === 'model' ? 'wrong-model' : $request['model'].'-2025-04-14';

            return Http::response($response);
        }]);
    }

    /** 固定测试消费上限只防止实现意外循环，不给生产增加预算。 */
    protected function drain(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $job = Queue::pushed(RunChapterStepJob::class)->get($this->consumed);
            if ($job === null) {
                return;
            }
            $this->consumed++;
            $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        }
        $this->fail('有限流程出现意外循环');
    }

    /** 创建独立源链及正常中文篇幅政策。 */
    protected function start(bool $fine = true, ?int $requests = null): ChapterGenerationRun
    {
        $novel = B04ContentTest::readyNovel($fine, 20);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500]]);

        return app(ContentGenerationService::class)->start($novel, User::firstOrFail(), requestLimit: $requests);
    }

    /** 首次入口直接使用新协议且关闭细纲不暗中新增请求。 */
    #[DataProvider('firstPassCases')]
    public function test_first_pass_uses_unified_contract(bool $fine, int $calls, int $cap): void
    {
        $run = $this->start($fine);
        $this->assertSame(ContentExecutionContract::VERSION, $run->config['version']);
        $this->assertSame($cap, $run->request_limit);
        $this->drain();
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $version = ChapterVersion::sole();
        $this->assertGreaterThanOrEqual(2500, $version->character_count);
        $this->assertLessThanOrEqual(3500, $version->character_count);
        $this->assertCount($fine ? 6 : 8, explode("\n", $version->body));
        $this->assertSame('pass', ChapterProgressionCheck::sole()->verdict);
        $this->assertSame(2 + $calls, $run->chapter->novel->fresh()->execution_count);
        $this->assertSame(0, $run->chapter->novel->fresh()->state_version);
        $this->assertSame($fine ? 1 : 0, NovelStage::where('input->run_id', $run->id)->where('stage', 'fine_outline')->count());
        foreach (CostLog::all() as $log) {
            $this->assertSame('调用前完整快照，摘要通过', app(ContentPromptAudit::class)->read($log)['status']);
        }
        Http::assertSentCount($calls);
    }

    /** 开关对应正常通过次数及最大授权次数。 */
    public static function firstPassCases(): array
    {
        return [[true, 3, 5], [false, 2, 4]];
    }

    /** 无进展只允许一次修订，不能自动建子运行重置额度。 */
    #[DataProvider('retryCases')]
    public function test_one_revision_and_terminal_pause(string $scenario, string $reason): void
    {
        $this->scenario = $scenario;
        $run = $this->start();
        $this->drain();
        $this->assertSame($reason, $run->fresh()->stop_reason);
        $this->assertSame(5, $run->fresh()->requests_used);
        $this->assertSame(1, $run->fresh()->retry_count);
        $this->assertSame(1, ChapterGenerationRun::count());
        $this->assertSame(2, ChapterVersion::count());
        $versions = ChapterVersion::orderBy('id')->get();
        $this->assertSame($versions[0]->id, $versions[1]->parent_version_id);
        $this->assertSame($versions[0]->body_hash, hash('sha256', $versions[0]->fresh()->body));
        $this->assertSame(2, ChapterProgressionCheck::count());
        Http::assertSentCount(5);
    }

    /** 已解决与仍不足均在固定预算结束。 */
    public static function retryCases(): array
    {
        return [['once', 'awaiting_review'], ['insufficient', 'retry_limit']];
    }

    /** 设置为零和较小授权预算都不能被默认的一次修订覆盖。 */
    #[DataProvider('limitedCases')]
    public function test_explicit_limits_are_honored(int $configured, int $requested, int $expectedCap): void
    {
        $settings = app(GeneralSettings::class);
        $settings->max_retries_per_chapter = $configured;
        $settings->save();
        $this->scenario = 'insufficient';
        $run = $this->start(requests: $requested);
        $this->drain();
        $this->assertSame('retry_limit', $run->fresh()->stop_reason);
        $this->assertSame($expectedCap, $run->request_limit);
        $this->assertSame(0, $run->fresh()->retry_count);
        $this->assertSame($configured, app(GeneralSettings::class)->max_retries_per_chapter);
        Http::assertSentCount(3);
    }

    /** 原设置不改写，较小预算主动放弃修订。 */
    public static function limitedCases(): array
    {
        return [[0, 3, 3], [3, 3, 3]];
    }

    /** 写作说明在首次默认路径即进入质量门禁，不必先走旧恢复链。 */
    public function test_meta_text_is_repaired_before_independent_check(): void
    {
        $this->scenario = 'meta';
        $run = $this->start();
        $this->drain();
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $this->assertSame(1, $run->fresh()->retry_count);
        $this->assertStringContainsString('此段约350字。', ChapterVersion::oldest('id')->firstOrFail()->body);
        $this->assertStringNotContainsString('此段约350字。', ChapterVersion::latest('id')->firstOrFail()->body);
        Http::assertSentCount(4);
    }

    /** 技术错误、非法响应、核心冲突及未知结果不得自动探测或重试。 */
    #[DataProvider('failureCases')]
    public function test_failures_stop_without_hidden_successor(string $scenario, string $reason, int $calls): void
    {
        $this->scenario = $scenario;
        $run = $this->start(false);
        $this->drain();
        $this->assertSame($reason, $run->fresh()->stop_reason);
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame(1, ChapterGenerationRun::count());
        $this->assertSame(0, $run->fresh()->retry_count);
        Http::assertSentCount($scenario === 'unknown' ? 0 : $calls);
    }

    /** 各类失败均以原始请求证据保留，不自动放宽合同。 */
    public static function failureCases(): array
    {
        return [['schema', 'invalid_schema', 1], ['unknown', 'unknown', 1], ['provider', 'provider_failed', 1], ['model', 'invalid_schema', 1], ['blocked', 'blocked', 2], ['evidence', 'invalid_schema', 2], ['missing_review', 'invalid_schema', 2]];
    }

    /** 显式恢复字数失败时冻结原始输出与实测差额，并保留失败日志。 */
    public function test_draft_recovery_carries_failed_paragraphs_and_measurements(): void
    {
        $this->scenario = 'schema';
        $parent = $this->start(false);
        $this->drain();
        $failed = CostLog::sole();
        $original = $failed->response_text;
        $this->scenario = 'pass';
        $child = app(ContentGenerationService::class)->recover($parent->id, User::firstOrFail(), '按原段实测字数恢复');
        $this->assertSame($failed->id, $child->config['recovery_draft']['cost_log_id']);
        $this->assertSame($failed->structured_output, $child->config['recovery_draft']['response']);
        $this->assertStringContainsString('paragraph_1实际2字', $child->config['recovery_validation_error']);
        $this->drain();
        $next = CostLog::where('id', '>', $failed->id)->oldest('id')->firstOrFail();
        $prompt = $next->request_parameters['prompt_audit']['user_prompt'];
        $payload = json_decode(explode('冻结输入：', $prompt, 2)[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($failed->id, $payload['previous_failed_draft']['cost_log_id']);
        $this->assertSame($original, $failed->fresh()->response_text);
        $this->assertSame('invalid_schema', $parent->fresh()->stop_reason);
        $this->assertSame('awaiting_review', $child->fresh()->stop_reason);
        Http::assertSentCount(3);
    }

    /** 全文失败只保存意见，显式继续仍使用原授权剩余两次。 */
    public function test_reading_failure_is_not_automatic_paid_continuation(): void
    {
        $run = $this->start();
        $this->drain();
        $service = app(ContentGenerationService::class);
        $service->recordReading($run->id, User::firstOrFail(), false, ['隔离全文意见：首段尚缺推进']);
        $this->assertSame('reading_failed', $run->fresh()->stop_reason);
        $this->assertSame(3, Queue::pushed(RunChapterStepJob::class)->count());
        $service->continue($run->id, reason: '在已确认的原预算内修订');
        $this->drain();
        $this->assertSame(5, $run->fresh()->requests_used);
        $accepted = $service->recordReading($run->id, User::firstOrFail(), true, ['隔离全文核对通过']);
        $this->assertSame('b04_accepted', $accepted->stop_reason);
        $this->assertFalse($accepted->control_history[array_key_last($accepted->control_history)]['author_story_approval']);
        $this->expectException(InvalidArgumentException::class);
        $service->recover($run->id, User::firstOrFail(), '已验收不能继续');
    }

    /** 耗尽修订次数后全文不通过也不能通过继续命令重置。 */
    public function test_reading_cannot_reset_exhausted_revision_budget(): void
    {
        $this->scenario = 'once';
        $run = $this->start();
        $this->drain();
        app(ContentGenerationService::class)->recordReading($run->id, User::firstOrFail(), false, ['隔离新意见']);
        $this->expectException(InvalidArgumentException::class);
        app(ContentGenerationService::class)->continue($run->id, reason: '仍无修订额度');
    }

    /** 重复消息和迟到失败回调不产生额外请求或覆盖已成功结果。 */
    public function test_duplicate_and_late_jobs_are_idempotent(): void
    {
        $run = $this->start();
        $job = Queue::pushed(RunChapterStepJob::class)->first();
        $this->drain();
        $before = $run->fresh()->getRawOriginal();
        $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        $job->failed(null);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        Http::assertSentCount(3);
    }

    /** 来源被篡改必须在HTTP前拒绝，费用及领用计数不增加。 */
    #[DataProvider('tamperCases')]
    public function test_pre_http_tampering_is_rejected(string $field): void
    {
        $run = $this->start();
        if ($field === 'limit') {
            DB::table('chapter_generation_runs')->where('id', $run->id)->update(['request_limit' => 99]);
        } elseif ($field === 'config') {
            DB::table('chapter_generation_runs')->where('id', $run->id)->update(['config' => PlanningJson::encode([...$run->config, 'temperature' => 0.99])]);
        } else {
            DB::table('novel_outline_chapters')->where('id', $run->outline_chapter_id)->update(['title' => '被篡改']);
        }
        $this->drain();
        $this->assertSame('invalid_contract', $run->fresh()->stop_reason);
        $this->assertSame(0, $run->fresh()->requests_used);
        $this->assertSame(0, CostLog::count());
        Http::assertNothingSent();
    }

    /** 配置、数据库额度和规划正文都属于请求前冻结边界。 */
    public static function tamperCases(): array
    {
        return [['limit'], ['config'], ['outline']];
    }

    /** 未知结果独立授权后才可恢复，旧请求、费用和模型设置不改。 */
    public function test_unknown_recovery_requires_confirmation_and_preserves_cost(): void
    {
        $this->scenario = 'unknown';
        $run = $this->start(false);
        $this->drain();
        $before = $run->fresh()->getRawOriginal();
        $unknown = CostLog::sole();
        try {
            app(ContentGenerationService::class)->recover($run->id, User::firstOrFail(), '未确认未知结果');
            $this->fail('未知结果没有确认也恢复');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, ChapterGenerationRun::count());
        }
        $settings = DB::table('settings')->get()->toJson();
        $next = app(ContentGenerationService::class)->recover($run->id, User::firstOrFail(), '已核查未知结果，本次独立授权', confirmUnknown: true, model: 'gpt-4.1');
        $this->scenario = 'pass';
        $this->drain();
        $this->assertSame('awaiting_review', $next->fresh()->stop_reason);
        $this->assertSame('gpt-4.1', $next->config['model']);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame('unknown', $unknown->fresh()->status);
        $this->assertSame($settings, DB::table('settings')->get()->toJson());
        $this->assertSame(2, $next->fresh()->requests_used);
    }

    /** 普通额度不够时发送前拒绝，只有明确给出新总上限才允许启动。 */
    public function test_execution_increase_is_explicit_and_audited(): void
    {
        $novel = B04ContentTest::readyNovel(limit: 5);
        try {
            app(ContentGenerationService::class)->start($novel, User::firstOrFail());
            $this->fail('剩余额度不足却启动');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $novel->chapters()->count());
        }
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail(), executionLimit: 7);
        $this->assertSame(7, $novel->fresh()->max_execution_count);
        $this->assertSame('execution_limit', $run->control_history[1]['action']);
        Http::assertNothingSent();
    }

    /** 旧不限次数开关、过大请求预算及未说明原因均不能发送HTTP。 */
    public function test_cli_and_retired_interfaces_cannot_expand_authorization(): void
    {
        $run = $this->start(false);
        $this->drain();
        $this->artisan('app:continue-content', ['run' => $run->id, '--continuous' => true])->expectsOutputToContain('已停用')->assertFailed();
        $this->assertFalse(method_exists(ContentGenerationService::class, 'recoverLength'));
        $this->assertFalse(method_exists(ContentGenerationService::class, 'recoverWithParagraphBudget'));
        Http::assertSentCount(2);
        $this->expectException(InvalidArgumentException::class);
        app(ContentGenerationService::class)->recover($run->id, User::firstOrFail(), '范围修订不能给五次预算', 5);
    }

    /** 模型、段落、来源和已保存检查均不可原地覆盖。 */
    public function test_saved_artifacts_are_immutable(): void
    {
        $run = $this->start();
        $this->drain();
        foreach ([[$run->fresh(), ['config' => []]], [ChapterVersion::sole(), ['body' => '覆盖']], [ChapterProgressionCheck::sole(), ['verdict' => 'blocked']]] as [$model, $data]) {
            try {
                $model->update($data);
                $this->fail('历史产物被覆盖');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    /** 后台首次入口和全文核对都调用统一服务；旧策略按钮不再暴露。 */
    public function test_backend_uses_unified_flow_and_bounded_controls(): void
    {
        $novel = B04ContentTest::readyNovel();
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('generateFirstChapter', [
            'tokens' => 8192, 'request_limit' => 5, 'execution_limit' => 20, 'reason' => '隔离后台启动', 'accept_request' => true])->assertHasNoActionErrors();
        $this->drain();
        $run = ChapterGenerationRun::sole();
        Livewire::test(ViewChapterContent::class, ['record' => $novel->id])->assertSee('记录B04全文核对')
            ->assertDontSee('持续推进B04')->assertDontSee('按段落预算恢复')->assertDontSee('修复锚点协议并继续')
            ->callAction('fullReading', ['accepted' => true, 'notes' => '隔离实现核对通过'])->assertHasNoActionErrors();
        $this->assertSame('b04_accepted', $run->fresh()->stop_reason);
    }

    /** 费用意图保存失败不能发送HTTP，更不能报作已发生的未知付费。 */
    public function test_audit_write_failure_has_no_http_or_cost(): void
    {
        $run = $this->start(false);
        CostLog::creating(fn () => throw new \RuntimeException('隔离审计写入故障'));
        try {
            $this->drain();
            $this->assertSame('audit_failed', $run->fresh()->stop_reason);
            $this->assertSame(0, CostLog::count());
            Http::assertNothingSent();
        } finally {
            CostLog::flushEventListeners();
        }
    }

    /** 真实历史配置仅作为冻结夹具恢复到隔离库，不重新执行旧生成策略。 */
    protected function legacyCandidate(): ChapterGenerationRun
    {
        $run = $this->start();
        $this->drain();
        $config = json_decode(file_get_contents(base_path('tests/Fixtures/b04-legacy-v2-config.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::table('chapter_generation_runs')->where('id', $run->id)->update(['config' => PlanningJson::encode($config), 'input_hash' => PlanningJson::hash([$run->snapshot, $config])]);

        return $run->fresh();
    }

    /** 旧协议仍可校验和展示，新消息或迟到失败回调不能再执行它。 */
    public function test_legacy_artifacts_are_readable_but_not_executable(): void
    {
        $run = $this->legacyCandidate();
        app(ContentGenerationService::class)->assertFrozenSources($run);
        $before = $run->getRawOriginal();
        $job = new RunChapterStepJob($run->id, $run->invocation_id);
        $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        $job->failed(null);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame('awaiting_review', $run->stop_reason);
        Http::assertSentCount(3);
    }

    /** 旧候选通过唯一恢复入口转为新协议，重复操作不重复投递或改写父运行。 */
    public function test_legacy_recovery_reuses_fine_and_preserves_all_artifacts(): void
    {
        $parent = $this->legacyCandidate();
        $before = $parent->getRawOriginal();
        $version = ChapterVersion::sole()->getRawOriginal();
        $check = ChapterProgressionCheck::sole()->getRawOriginal();
        $service = app(ContentGenerationService::class);
        $next = $service->recover($parent->id, User::firstOrFail(), '明确授权旧候选的有限修订');
        $this->assertSame($next->id, $service->recover($parent->id, User::firstOrFail(), '重复点击')->id);
        $this->assertSame(4, Queue::pushed(RunChapterStepJob::class)->count());
        $this->assertSame(ContentExecutionContract::VERSION, $next->config['version']);
        $this->assertSame(2, $next->request_limit);
        $this->assertSame($parent->fine_stage_id, $next->fine_stage_id);
        $this->drain();
        $this->assertSame('awaiting_review', $next->fresh()->stop_reason);
        $this->assertSame(1, $next->fresh()->retry_count);
        $this->assertSame($before, $parent->fresh()->getRawOriginal());
        $this->assertSame($version, ChapterVersion::findOrFail($version['id'])->getRawOriginal());
        $this->assertSame($check, ChapterProgressionCheck::findOrFail($check['id'])->getRawOriginal());
        $this->assertSame(1, NovelStage::where('stage', 'fine_outline')->count());
        Http::assertSentCount(5);
    }

    /** CLI恢复和后台恢复共用服务，模型覆盖不改变全局设置。 */
    public function test_cli_recovery_and_rewrite_use_explicit_bounded_authorization(): void
    {
        $parent = $this->legacyCandidate();
        $settings = DB::table('settings')->get()->toJson();
        $this->artisan('app:continue-content', ['run' => $parent->id, '--recover' => true, '--rewrite' => true,
            '--model' => 'gpt-4.1', '--max-requests' => 4, '--reason' => '明确授权独立整章候选'])->assertSuccessful();
        $next = ChapterGenerationRun::latest('id')->firstOrFail();
        $this->assertSame('draft', $next->next_step);
        $this->assertSame(4, $next->request_limit);
        $this->drain();
        $this->assertSame('awaiting_review', $next->fresh()->stop_reason);
        $this->assertSame(2, $next->fresh()->requests_used);
        $this->assertSame($settings, DB::table('settings')->get()->toJson());
        $this->assertSame('gpt-4.1', $next->config['model']);
        Http::assertSentCount(5);
    }

    /** 合法恢复之后篡改祖先正文，必须在付费前停止。 */
    public function test_recovery_source_tampering_is_rejected_before_http(): void
    {
        $parent = $this->legacyCandidate();
        $next = app(ContentGenerationService::class)->recover($parent->id, User::firstOrFail(), '隔离来源保护');
        DB::table('chapter_versions')->where('id', $next->source_version_id)->update(['body' => '篡改来源']);
        $this->drain();
        $this->assertSame('invalid_contract', $next->fresh()->stop_reason);
        $this->assertSame(0, $next->fresh()->requests_used);
        Http::assertSentCount(3);
    }

    /** 绑定检查或受检版本被改动时，全文核对不能签出验收回执。 */
    public function test_reading_rejects_mismatched_check(): void
    {
        $run = $this->start();
        $this->drain();
        DB::table('chapter_progression_checks')->update(['body_hash' => str_repeat('0', 64)]);
        $this->expectException(InvalidArgumentException::class);
        app(ContentGenerationService::class)->recordReading($run->id, User::firstOrFail(), true, ['不能接受错误摘要']);
    }

    /** 新长文任务只消费专用连接，普通队列的原超时边界保持。 */
    public function test_queue_timeout_order_is_explicit(): void
    {
        $this->start();
        $job = Queue::pushed(RunChapterStepJob::class)->first();
        $this->assertSame('redis_content_acceptance', $job->connection);
        $this->assertSame('content-acceptance', $job->queue);
        $this->assertSame(210, $job->timeout);
        $this->assertSame(240, config('queue.connections.redis_content_acceptance.retry_after'));
        $this->assertSame(75, (new RunChapterStepJob(1, 'old'))->timeout);
    }

    /** 投递失败保持零调用，可在原授权内明确继续，不伪造未知费用。 */
    public function test_dispatch_failure_is_not_a_paid_unknown_request(): void
    {
        $run = $this->start(false);
        Queue::pushed(RunChapterStepJob::class)->first()->failed(null);
        $this->assertSame('dispatch_failed', $run->fresh()->stop_reason);
        $this->assertSame(0, CostLog::count());
        $this->assertSame(0, $run->fresh()->requests_used);
        app(ContentGenerationService::class)->continue($run->id, reason: '恢复未发送的投递');
        $this->drain();
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        Http::assertSentCount(2);
    }

    /** 额度暂停后明确加额，已完成细纲不得重新请求。 */
    public function test_resume_reuses_completed_fine_without_resetting_budget(): void
    {
        $run = $this->start();
        Queue::pushed(RunChapterStepJob::class)->first()->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        $this->consumed = 1;
        $fine = $run->fresh()->fine_stage_id;
        $novel = $run->chapter->novel;
        $novel->update(['max_execution_count' => $novel->fresh()->execution_count]);
        $this->drain();
        $this->assertSame('execution_limit', $run->fresh()->stop_reason);
        app(ContentGenerationService::class)->continue($run->id, executionLimit: 7, reason: '明确补足原授权剩余总额度');
        $this->drain();
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $this->assertSame($fine, $run->fresh()->fine_stage_id);
        $this->assertSame(3, $run->fresh()->requests_used);
        $this->assertSame(5, $run->fresh()->request_limit);
        Http::assertSentCount(3);
    }

    /** 全局设置变化不影响已启动运行冻结的模型和温度。 */
    public function test_started_model_and_temperature_are_frozen(): void
    {
        $run = $this->start();
        $settings = app(GeneralSettings::class);
        $settings->default_model = 'different-model';
        $settings->temperature = 0.1;
        $settings->save();
        $this->drain();
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $this->assertSame([$run->config['model']], CostLog::distinct()->pluck('requested_model')->all());
        $this->assertSame('different-model', app(GeneralSettings::class)->default_model);
        Http::assertSentCount(3);
    }

    /** 仅注册专用消费者，正常开发启动即可发现新队列，不额外发送HTTP。 */
    public function test_local_development_registers_content_consumer(): void
    {
        $previous = app()['env'];
        app()->instance('env', 'local');
        try {
            (new AppServiceProvider(app()))->boot();
            $commands = collect(DevCommands::commands());
            $content = $commands->firstWhere('name', 'content');
            $this->assertNotNull($content);
            $this->assertStringContainsString('queue:listen redis_content_acceptance --queue=content-acceptance', $content['command']);
            $this->assertStringContainsString('--timeout=225', $content['command']);
            Http::assertNothingSent();
        } finally {
            app()->instance('env', $previous);
        }
    }

    /** 通用指令不能携带验收样本的角色、地点和事件假设。 */
    public function test_v14_instructions_have_no_acceptance_story_assumptions(): void
    {
        foreach (['fine', 'draft', 'revise', 'check'] as $step) {
            foreach (['守灯人', '海鸥', '灯塔', '访客'] as $word) {
                $this->assertStringNotContainsString($word, ContentExecutionContract::instructions($step));
            }
        }
        $this->assertFalse(method_exists(TextRequestOptions::class, 'continuationChapter'));
        $this->assertFalse(method_exists(TextRequestOptions::class, 'chapter'));
    }

    /** 业务拒绝有明确页面通知，不创建章节或发送请求。 */
    public function test_backend_source_rejection_has_clear_notification(): void
    {
        $novel = B04ContentTest::readyNovel();
        DB::table('novel_outline_chapters')->where('chapter_number', 1)->update(['title' => '隔离篡改来源']);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('generateFirstChapter', [
            'tokens' => 8192, 'request_limit' => 5, 'execution_limit' => 20, 'reason' => '来源无效应拒绝', 'accept_request' => true])->assertNotified('首章操作未执行');
        $this->assertSame(0, $novel->chapters()->count());
        Http::assertNothingSent();
    }

    /** B08在正文HTTP返回前请求暂停，保留该步结果并在原额度内继续。 */
    public function test_b08_pause_after_fine_result_and_resume_remaining_steps(): void
    {
        $run = $this->start();
        Http::fake(['*' => function (Request $request) use ($run) {
            app(RunControlService::class)->pause($run->chapter->novel_id, auth()->user(), 'B08在途暂停');

            return Http::response(B04ContentTest::validResponse($request));
        }]);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $run->refresh();
        $this->assertSame('paused', $run->status);
        $this->assertSame('user_paused', $run->stop_reason);
        $this->assertSame(1, $run->requests_used);
        $this->assertNotNull($run->plan_data);
        $this->assertSame('completed', NovelStage::findOrFail($run->fine_stage_id)->status->value);
        app(ContentGenerationService::class)->continue($run->id, reason: 'B08继续', authorId: auth()->id());
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertFalse($run->chapter->novel->fresh()->pause_requested);
        $this->assertSame(1, $run->fresh()->requests_used);
    }
}
