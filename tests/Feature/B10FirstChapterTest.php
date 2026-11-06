<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\ViewNovelRun;
use App\Jobs\AdvanceFirstChapterJob;
use App\Models\CanonicalCommit;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\CostLog;
use App\Models\FirstChapterRun;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\FirstChapterService;
use App\Services\Pipeline\PlanningJson;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** 通过真实SDK适配器的隔离响应验证从新创意到正式提交，不冒充文学验收。 */
class B10FirstChapterTest extends TestCase
{
    use DatabaseMigrations;

    /** 供应商拒绝开关，用于验证恢复不会覆盖失败证据。 */
    private bool $reject = false;

    /** 独立评审拒绝场景，验证自动策略不豁免内容门禁。 */
    private bool $rejectReview = false;

    /** 模拟模型在段落预算边界截断句子的真实缺陷。 */
    private bool $truncateDraft = false;

    /** 首次提取返回事实类别错误，后继响应恢复正确类别。 */
    private bool $rejectFirstExtraction = false;

    /** 项目迁移包含自动授权表，不加载供应商扩展。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 每个测试禁止外网；所有请求依然经过统一记账入口。 */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.providers.openai.key' => 'sk-b10-isolated', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Http::fake(['*' => function (Request $request) {
            $this->assertSame(0, DB::transactionLevel());
            if ($this->reject) {
                return Http::response(['error' => ['message' => '隔离拒绝']], 400);
            }
            $log = CostLog::latest('id')->firstOrFail();
            $version = $log->request_parameters['schema_version'];
            if ($version === 'b02-setting-v1') {
                $data = B02SettingTest::setting();
            } elseif ($version === 'b10-setting-decision-v1') {
                $data = ['setting' => [...B02SettingTest::setting(), 'open_questions' => []], 'answers' => ['来信不能改变已经发生的过去。']];
            } elseif ($version === 'b03-outline-v1') {
                $data = B03OutlineTest::batch();
            } elseif (isset($log->request_parameters['purpose'])) {
                if ($log->request_parameters['purpose'] === 'review') {
                    $data = ['review' => ['verdict' => $this->rejectReview ? 'needs_revision' : 'pass', 'summary' => '隔离评审通过', 'evidence_ids' => ['p1'], 'findings' => []]];
                } else {
                    $submission = ChapterSubmissionRun::findOrFail($log->request_parameters['submission_run_id']);
                    $data = ['events' => [['key' => 'event-1', 'event_type' => 'observation', 'narrative_order' => 1,
                        'actor_ref' => array_key_first($submission->snapshot['entity_catalog']), 'target_refs' => [], 'time_scope' => 'present',
                        'fact_category' => 'objective_confirmed', 'summary' => '隔离观察', 'evidence_ids' => ['p1'], 'state_changes' => []]]];
                    if ($this->rejectFirstExtraction) {
                        $data['events'][0]['event_type'] = 'statement';
                        $this->rejectFirstExtraction = false;
                    }
                }
            } else {
                $payload = json_decode(explode('冻结输入：', $request['input'][1]['content'][0]['text'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(B02SettingTest::input()['premise_raw'], $payload['author_idea']);
                $response = B04ContentTest::validResponse($request);
                if (($log->request_parameters['step'] ?? null) === 'draft') {
                    $data = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
                    foreach ($data['paragraphs'] as &$paragraph) {
                        $paragraph = mb_substr($paragraph, 0, -1).'。';
                    }
                    unset($paragraph);
                    if ($this->truncateDraft) {
                        $first = array_key_first($data['paragraphs']);
                        $data['paragraphs'][$first] = mb_substr($data['paragraphs'][$first], 0, -3).'为后续';
                    }
                    $response['output'][0]['content'][0]['text'] = PlanningJson::encode($data);
                }

                return Http::response($response);
            }

            $response = B02SettingTest::response(PlanningJson::encode($data));
            $response['model'] = $request['model'].'-2025-04-14';

            return Http::response($response);
        }]);
    }

    /** 仅在已确认的内存库清理不可变夹具。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('first_chapter_runs')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 从真实创建入口构造授权，正文夹具使用最小篇幅。 */
    private function start(?string $model = null): FirstChapterRun
    {
        return app(FirstChapterService::class)->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], User::factory()->create(), true, false, $model);
    }

    /** 单次授权贯穿所有门禁，重复完成消息不能增加费用或正式章数。 */
    public function test_new_idea_automatically_commits_with_truthful_delegation_and_idempotency(): void
    {
        $service = app(FirstChapterService::class);
        $run = $this->start();
        for ($i = 0; $i < 20 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame(1, Novel::findOrFail($run->novel_id)->chapters_done);
        $this->assertSame(8, $service->requestCount($run));
        $commit = CanonicalCommit::findOrFail($run->artifacts['commit_id']);
        $this->assertFalse($commit->approvals['author_story_approval']);
        $this->assertSame('delegated_policy', $commit->approvals['approval_mode']);
        $this->assertSame($run->policy_hash, $commit->approvals['policy_hash']);
        $body = ChapterGenerationRun::findOrFail($run->artifacts['body_run_id']);
        $this->assertSame('delegated_policy', collect($body->control_history)->last()['acceptance_mode']);
        $service->advance($run->id);
        $this->assertSame(8, $service->requestCount($run));
        $this->assertSame(1, CanonicalCommit::count());
        Queue::assertNothingPushed();
    }

    /** 一次模型选择贯穿全流程，不污染全局配置；每个阶段仍记录实际请求。 */
    public function test_selected_model_is_frozen_across_all_steps(): void
    {
        $default = app(GeneralSettings::class)->default_model;
        $run = $this->start('gpt-4.1');
        $service = app(FirstChapterService::class);
        for ($i = 0; $i < 20 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame(['gpt-4.1'], CostLog::where('novel_id', $run->novel_id)->distinct()->pluck('requested_model')->all());
        $this->assertSame($default, app(GeneralSettings::class)->default_model);
    }

    /** 模型自报通过也不能让句中截断正文进入正式状态。 */
    public function test_incomplete_paragraph_is_rejected_before_submission(): void
    {
        $this->truncateDraft = true;
        $service = app(FirstChapterService::class);
        $run = $this->start();
        for ($i = 0; $i < 20 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('failed', $run->status);
        $this->assertSame('CONTENT_STOPPED:invalid_schema', $run->error);
        $this->assertSame(0, CanonicalCommit::count());
        $this->assertSame(0, ChapterSubmissionRun::count());
    }

    /** 事件硬校验失败自动带反馈创建后继，原失败及成本均保留。 */
    public function test_event_validation_failure_recovers_automatically_without_weakening_gate(): void
    {
        $this->rejectFirstExtraction = true;
        $service = app(FirstChapterService::class);
        $run = $this->start();
        for ($i = 0; $i < 20 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame(10, $service->requestCount($run));
        $this->assertSame(1, ChapterSubmissionRun::where('status', 'failed')->count());
        $this->assertSame(1, ChapterSubmissionRun::where('status', 'committed')->count());
        $this->assertTrue(collect($run->history)->contains('action', 'automatic_recovery'));
    }

    /** 供应商结果未知即停，不因自动恢复授权重发可能已计费请求。 */
    public function test_unknown_provider_response_never_automatically_recovers(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('隔离网络中断');
        });
        $run = $this->start();
        $run = app(FirstChapterService::class)->advance($run->id);
        $this->assertSame('failed', $run->status);
        $this->assertSame('unknown', CostLog::sole()->status);
        $this->assertFalse(collect($run->history)->contains('action', 'automatic_recovery'));
        $this->assertSame(1, $attempts);
    }

    /** 评审未通过必须停止，自动授权不能强行正式提交。 */
    public function test_independent_review_rejection_blocks_commit(): void
    {
        $this->rejectReview = true;
        $service = app(FirstChapterService::class);
        $run = $this->start();
        for ($i = 0; $i < 20 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('failed', $run->status);
        $this->assertSame('SUBMISSION_STOPPED:REVIEW_NOT_PASSED', $run->error);
        $this->assertSame(0, CanonicalCommit::count());
        $this->assertSame(0, Novel::findOrFail($run->novel_id)->chapters_done);
    }

    /** 后台创建与运行台共用服务，并展示请求次数及自动授权来源。 */
    public function test_background_creation_and_audit_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Livewire::test(CreateNovel::class)
            ->fillForm([...B02SettingTest::input(), 'automatic_first_chapter' => true])->call('create')->assertHasNoFormErrors();
        $run = FirstChapterRun::sole();
        Queue::assertPushed(AdvanceFirstChapterJob::class);
        Http::assertNothingSent();
        Livewire::test(ViewNovelRun::class, ['record' => $run->novel_id])
            ->assertSee('自动首章授权与进度')->assertSee('真实 AI 请求次数')->assertSee('非人工阅读');
    }

    /** 未知请求不能未经确认续费，操作者不符也不能恢复。 */
    public function test_unknown_request_and_different_author_cannot_resume(): void
    {
        $run = $this->start();
        $run->update(['status' => 'failed']);
        CostLog::factory()->create(['novel_id' => $run->novel_id, 'mode' => 'real', 'status' => 'unknown']);
        try {
            app(FirstChapterService::class)->retry($run->id, User::findOrFail($run->author_id));
            $this->fail('未知费用应阻止未经确认恢复');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('未知响应', $error->getMessage());
        }
        try {
            app(FirstChapterService::class)->retry($run->id, User::factory()->create(), true);
            $this->fail('其他用户不能恢复');
        } catch (\LogicException $error) {
            $this->assertSame('自动授权来源不匹配', $error->getMessage());
        }
        $this->assertSame('failed', $run->refresh()->status);
        Http::assertNothingSent();
    }

    /** 缺少明确授权不能创建小说或发请求。 */
    public function test_no_authorization_creates_nothing(): void
    {
        try {
            app(FirstChapterService::class)->start(B02SettingTest::input(), User::factory()->create(), false);
            $this->fail('缺少授权应拒绝');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Novel::count());
            Http::assertNothingSent();
        }
    }

    /** 失败设定保留成本与尝试，从同一自动流程创建后继尝试。 */
    public function test_failed_setting_can_resume_without_losing_request_history(): void
    {
        $run = $this->start();
        $service = app(FirstChapterService::class);
        $this->reject = true;
        $run = $service->advance($run->id);
        $this->assertSame('pending', $run->status);
        $this->assertTrue(collect($run->history)->contains('action', 'automatic_recovery'));
        $this->assertSame(1, $service->requestCount($run));
        $this->reject = false;
        $run = $service->advance($run->id);
        $this->assertSame('decision', $run->step);
        $this->assertSame(2, $service->requestCount($run));
        $this->assertSame(1, CostLog::where('status', 'failed')->count());
    }

    /** 领取过期后显式核对只关闭未知结果；恢复前不得自动调用供应商。 */
    public function test_expired_claim_is_reconciled_without_reissuing(): void
    {
        $run = $this->start();
        $run->update(['status' => 'running', 'step' => 'decision', 'lease_token' => (string) Str::uuid7(), 'lease_expires_at' => now()->subMinute()]);
        CostLog::factory()->create(['novel_id' => $run->novel_id, 'mode' => 'real', 'status' => 'pending']);
        app(FirstChapterService::class)->reconcile($run->id, User::findOrFail($run->author_id));
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame('unknown', CostLog::sole()->status);
        $this->assertNull($run->lease_token);
        Http::assertNothingSent();
    }

    /** 已领取消息不能被重复领取，队列超时不会偷换成新请求。 */
    public function test_duplicate_running_message_is_noop(): void
    {
        $run = $this->start();
        $run->update(['status' => 'running']);
        (new AdvanceFirstChapterJob($run->id))->handle(app(FirstChapterService::class));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
