<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\RunChapterStepJob;
use App\Models\CanonicalCommit;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\NovelSettingConfirmation;
use App\Models\StoryEntity;
use App\Models\StoryEvent;
use App\Models\StoryOutboxEvent;
use App\Models\StoryStateProjection;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\CanonicalCommitService;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\ProgressionChecker;
use App\Services\Pipeline\SubmissionContract;
use App\Services\Pipeline\SubmissionValidator;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 真实程序路径使用SDK HTTP夹具，不以合成正文冒充文学验收。 */
class ChapterSubmissionTest extends TestCase
{
    use DatabaseMigrations;

    /** 当前隔离供应商响应场景，不发送真实模型请求。 */
    private string $scenario = 'pass';

    /** 显式限制项目迁移路径，避免加载供应商PostgreSQL扩展迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 每例独立迁移，供应商只使用受控响应。 */
    protected function setUp(): void
    {
        parent::setUp();
        self::fakeProvider(fn (): string => $this->scenario);
    }

    /** 清理仅限已核实的内存测试库。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** PostgreSQL专项也复用同一真实SDK请求夹具，不访问外网。 */
    public static function fakeProvider(?callable $scenario = null): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.providers.openai.key' => 'sk-isolated-submission-test', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Http::fake(['*' => function (Request $request) use ($scenario) {
            $log = CostLog::latest('id')->firstOrFail();
            $purpose = $log->request_parameters['purpose'] ?? null;
            if ($purpose === null) {
                $response = B04ContentTest::validResponse($request);

                return Http::response($response);
            }
            if (DB::transactionLevel() !== 0) {
                throw new \RuntimeException('外部请求不应持有事务');
            }
            $mode = $scenario === null ? 'pass' : $scenario();
            if ($mode === 'unknown') {
                throw new ConnectionException('隔离响应未知');
            }
            $run = ChapterSubmissionRun::findOrFail($log->request_parameters['submission_run_id']);
            $ref = array_key_first($run->snapshot['entity_catalog']);
            if ($purpose === 'review') {
                $data = ['review' => ['verdict' => $mode === 'review_reject' ? 'needs_revision' : 'pass', 'summary' => '隔离基本评审结论', 'evidence_ids' => ['p1'], 'findings' => []]];
            } else {
                $data = self::extraction($ref);
                if ($mode === 'proposal') {
                    $data['proposed_entities'] = [['key' => 'new-1', 'type' => 'character', 'name' => '隔离访客', 'aliases' => ['隔离来客'], 'description' => '本章出现的隔离实体', 'core' => true, 'evidence_ids' => ['p1']]];
                    $data['events'][0]['target_refs'] = ['new-1'];
                }
                if ($mode === 'bad_fact') {
                    $data['events'][0]['event_type'] = 'statement';
                }
                // 未定义引用必须保留提取证据并由本地验证阻塞，不能隐式创建实体。
                if ($mode === 'unknown_entity') {
                    $data['events'][0]['target_refs'] = ['entity-999999'];
                }
                if ($mode === 'invalid_category') {
                    $data['events'][0]['fact_category'] = [];
                }
                // 夹具走与当前供应商相同的内联定义协议，旧结构仅供历史验证测试使用。
                if ($mode === 'proposal') {
                    $proposal = $data['proposed_entities'][0];
                    unset($proposal['key']);
                    $data['events'][0]['target_refs'] = [$proposal];
                }
                unset($data['proposed_entities']);
                // 当前协议将变化放在来源事件内部，避免独立数组的来源编号错配。
                foreach ($data['events'] as &$event) {
                    $event['state_changes'] = [];
                    foreach ($data['patch'] as $patch) {
                        if ($patch['source_event_key'] === $event['key']) {
                            unset($patch['source_event_key'], $patch['before_ref']);
                            $event['state_changes'][] = $patch;
                        }
                    }
                }
                unset($event, $data['patch']);
            }
            $response = B02SettingTest::response(PlanningJson::encode($data));
            $response['model'] = $request['model'].'-2025-04-14';

            return Http::response($response);
        }]);
    }

    /** 构造一次真实B04入口的已验收候选，作者来源仍来自已确认设定。
     * @return array{ChapterVersion, User}
     */
    public static function candidate(): array
    {
        $novel = B04ContentTest::readyNovel();
        $confirmation = NovelSettingConfirmation::where('novel_id', $novel->id)->sole();
        $operator = User::findOrFail($confirmation->confirmed_by);
        $run = app(ContentGenerationService::class)->start($novel, $operator);
        $consumed = 0;
        while ($job = Queue::pushed(RunChapterStepJob::class)->get($consumed++)) {
            $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        }
        app(ContentGenerationService::class)->recordReading($run->id, $operator, true, ['隔离实现全文核对，不代表作者正式批准']);

        return [ChapterVersion::findOrFail($run->chapter->fresh()->current_candidate_version_id), $operator];
    }

    /** 单事件夹具不含新实体或客观状态修改。
     * @return array<string, mixed>
     */
    public static function extraction(string $ref = 'entity-1'): array
    {
        return ['proposed_entities' => [], 'events' => [['key' => 'event-1', 'event_type' => 'observation', 'narrative_order' => 1, 'actor_ref' => $ref,
            'target_refs' => [], 'time_scope' => 'present', 'fact_category' => 'objective_confirmed', 'summary' => '隔离观察事件', 'evidence_ids' => ['p1']]], 'patch' => []];
    }

    /** 两个用途通过同一准备运行，不使用手工拼接的已通过产物。
     * @return array{ChapterSubmissionRun, User}
     */
    public static function prepared(): array
    {
        [$version, $operator] = self::candidate();
        $service = app(ChapterSubmissionService::class);
        $run = $service->prepare($version->id, $operator, 0, '隔离有限两次提交准备', dispatch: false);
        for ($i = 0; $i < 2 && $run->refresh()->status === 'pending'; $i++) {
            $service->execute($run->id, $run->invocation_id, false);
        }

        return [$run->refresh(), $operator];
    }

    /** 正式提交的产物编号全部显式绑定同一运行。 */
    public static function commit(ChapterSubmissionRun $run, User $operator, array $approved = [], ?string $key = null): CanonicalCommit
    {
        return app(CanonicalCommitService::class)->commit($run->version_id, ChapterReview::where('submission_run_id', $run->id)->sole()->id,
            ChapterEventExtraction::where('submission_run_id', $run->id)->sole()->id, ChapterSubmissionValidation::where('submission_run_id', $run->id)->oldest('id')->firstOrFail()->id,
            $run->expected_state_version, $key ?? (string) Str::uuid7(), $operator, '隔离作者正式批准', $approved, true);
    }

    /** 成功只增加一份正式状态，重复同幂等键不重复事件，也不启动下一章。 */
    public function test_full_path_and_idempotency_preserve_original_body_and_pause(): void
    {
        [$run, $operator] = self::prepared();
        $this->assertSame('ready', $run->status, $run->stop_reason ?? '');
        // 实际供应商输入必须携带正文确定性字数，评审与提取均不能依靠模型估数。
        foreach (CostLog::where('novel_id', $run->novel_id)->where('prompt_version', 'like', 'submission-%')->get() as $request) {
            $prompt = $request->request_parameters['prompt_audit']['user_prompt'];
            $this->assertStringContainsString('"visible_non_whitespace_characters":'.ContentSchema::count($run->version->body), $prompt);
        }
        $before = $run->version->getRawOriginal();
        $novel = $run->novel->getRawOriginal();
        $key = (string) Str::uuid7();
        $commit = self::commit($run, $operator, key: $key);
        $this->assertSame($commit->id, self::commit($run, $operator, key: $key)->id);
        $this->assertSame($before, $run->version->fresh()->getRawOriginal());
        $this->assertSame(1, $run->novel->fresh()->state_version);
        $this->assertSame(1, $run->novel->fresh()->chapters_done);
        $this->assertSame($novel['chapters_total'], $run->novel->fresh()->getRawOriginal('chapters_total'));
        $this->assertSame($novel['status'], $run->novel->fresh()->getRawOriginal('status'));
        $this->assertSame($run->version_id, $run->chapter->fresh()->formal_version_id);
        $this->assertSame('committed', $run->chapter->fresh()->status->value);
        $this->assertSame(1, StoryEvent::count());
        $this->assertSame(1, StoryStateProjection::count());
        $this->assertSame(1, StoryOutboxEvent::count());
        Http::assertSentCount(5);
    }

    /** 有核心新实体时停在批准门禁，明确批准后才能原子注册实体。 */
    public function test_proposed_entity_is_not_implicitly_approved(): void
    {
        $this->scenario = 'proposal';
        [$run, $operator] = self::prepared();
        $this->assertSame('awaiting_approval', $run->status, $run->stop_reason ?? '');
        $this->assertSame(1, StoryEntity::count());
        try {
            self::commit($run, $operator);
            $this->fail('未批准核心实体却提交');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('UNAPPROVED_ENTITY', $exception->getMessage());
            $this->assertSame(0, CanonicalCommit::count());
        }
        self::commit($run, $operator, ['new-1']);
        $this->assertSame(2, StoryEntity::count());
        $this->assertTrue(CanonicalCommit::sole()->approvals['author_story_approval']);
        Http::assertSentCount(5);
    }

    /** 供应商未知、评审失败和硬事实失败均不自动重试，也不进入正式状态。 */
    #[DataProvider('stopCases')]
    public function test_failures_stop_without_paid_retries(string $scenario, int $calls): void
    {
        $this->scenario = $scenario;
        [$run, $operator] = self::prepared();
        $this->assertSame('failed', $run->status);
        $before = $run->getRawOriginal();
        app(ChapterSubmissionService::class)->execute($run->id, $run->invocation_id, false);
        $again = app(ChapterSubmissionService::class)->prepare($run->version_id, $operator, 0, '重复命令不重新收费', dispatch: false);
        $this->assertSame($before, $again->getRawOriginal());
        $this->assertSame(0, CanonicalCommit::count());
        $this->assertSame(0, $run->novel->fresh()->state_version);
        $this->assertSame($calls, CostLog::whereNotNull('request_parameters->purpose')->count());
    }

    /** 每种失败的费用意图数有独立断言。 */
    public static function stopCases(): array
    {
        return [['unknown', 1], ['review_reject', 1], ['bad_fact', 2], ['unknown_entity', 2], ['invalid_category', 2]];
    }

    /** 显式后继使用新额度，原失败产物不变；同前驱重复命令不重复请求。 */
    public function test_explicit_successor_preserves_failed_run_and_can_commit(): void
    {
        $this->scenario = 'unknown_entity';
        [$original, $operator] = self::prepared();
        $before = $original->getRawOriginal();
        $extraction = ChapterEventExtraction::sole()->getRawOriginal();
        $limit = max($original->novel->max_execution_count, $original->novel->execution_count + 2);
        $this->scenario = 'pass';
        $options = ['version' => $original->version_id, '--expected-state-version' => '0', '--max-execution-count' => (string) $limit,
            '--retry-of' => (string) $original->id, '--reason' => '明确批准后继两次', '--sync' => true];
        $this->artisan('app:prepare-chapter-submission', $options)->assertSuccessful();
        $successor = ChapterSubmissionRun::where('predecessor_id', $original->id)->sole();
        $this->assertSame(2, $successor->attempt);
        $this->assertSame('ready', $successor->status);
        $this->assertSame($extraction['result_hash'], $successor->config['repair_feedback']['result_hash']);
        $this->assertContains('UNKNOWN_ENTITY', array_column($successor->config['repair_feedback']['errors'], 'code'));
        $request = CostLog::where('request_parameters->submission_run_id', $successor->id)->where('request_parameters->purpose', 'extract')->sole();
        $this->assertStringContainsString('rejected_extraction', $request->request_parameters['prompt_audit']['user_prompt']);
        $this->assertStringContainsString('UNKNOWN_ENTITY', $request->request_parameters['prompt_audit']['user_prompt']);
        $this->assertSame($before, $original->fresh()->getRawOriginal());
        $this->assertSame($extraction, ChapterEventExtraction::findOrFail($extraction['id'])->getRawOriginal());
        $this->artisan('app:prepare-chapter-submission', $options)->assertSuccessful();
        $this->assertSame(2, ChapterSubmissionRun::count());
        self::commit($successor, $operator);
        $this->assertSame(1, CanonicalCommit::count());
        Http::assertSentCount(7);
    }

    /** 自动重试仍被禁止，成功运行不能充当失败前驱。 */
    public function test_successor_rejects_nonfailed_predecessor(): void
    {
        [$run, $operator] = self::prepared();
        $this->expectExceptionMessage('RETRY_SOURCE_INVALID');
        app(ChapterSubmissionService::class)->prepare($run->version_id, $operator, 0, '不合法后继', $run->novel->execution_count + 2, false, $run->id);
    }

    /** 后继授权不能用于换正文，且必须显式给出总调用上限。 */
    public function test_successor_requires_explicit_limit(): void
    {
        $this->scenario = 'unknown_entity';
        [$run, $operator] = self::prepared();
        $this->expectExceptionMessage('EXECUTION_LIMIT_CONFIRMATION_REQUIRED');
        app(ChapterSubmissionService::class)->prepare($run->version_id, $operator, 0, '缺少追加总额', dispatch: false, retryOf: $run->id);
    }

    /** 中间一轮评审失败时，后续提取仍收到最近的真实提取错误。 */
    public function test_successor_keeps_extraction_feedback_across_review_failure(): void
    {
        $this->scenario = 'unknown_entity';
        [$original, $operator] = self::prepared();
        $service = app(ChapterSubmissionService::class);
        $this->scenario = 'review_reject';
        $middle = $service->prepare($original->version_id, $operator, 0, '隔离中间评审失败', 20, false, $original->id);
        $service->execute($middle->id, $middle->invocation_id, false);
        $this->assertSame('failed', $middle->fresh()->status);
        $this->scenario = 'pass';
        $next = $service->prepare($original->version_id, $operator, 0, '隔离追溯提取反馈', 20, false, $middle->id);
        $this->assertSame(ChapterEventExtraction::sole()->id, $next->config['repair_feedback']['extraction_id']);
        $this->assertContains('UNKNOWN_ENTITY', array_column($next->config['repair_feedback']['errors'], 'code'));
        Http::assertSentCount(6);
    }

    /** 原文核对拒绝后禁止正式提交，后继收到原核对意见且旧提取不变。 */
    public function test_event_reading_rejection_preserves_artifact_and_reaches_successor(): void
    {
        [$run, $operator] = self::prepared();
        $extraction = ChapterEventExtraction::sole();
        $before = $extraction->getRawOriginal();
        $this->artisan('app:review-chapter-events', ['run' => $run->id, '--extraction' => $extraction->id, '--result-hash' => $extraction->result_hash,
            '--reject' => true, '--finding' => ['事件主体与原文不符']])->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame($before, $extraction->fresh()->getRawOriginal());
        $next = app(ChapterSubmissionService::class)->prepare($run->version_id, $operator, 0, '阅读失败后继', 20, false, $run->id);
        $this->assertSame(['事件主体与原文不符'], $next->config['repair_feedback']['reading_findings']);
        Http::assertSentCount(5);
        $this->expectExceptionMessage('SUBMISSION_NOT_READY');
        self::commit($run, $operator);
    }

    /** 原文核对回执不能绑定其他摘要，接受阅读本身不创建正式事件。 */
    public function test_event_reading_receipt_binds_exact_extraction(): void
    {
        [$run, $operator] = self::prepared();
        $extraction = ChapterEventExtraction::sole();
        $service = app(ChapterSubmissionService::class);
        $service->recordEventReading($run->id, $extraction->id, $extraction->result_hash, $operator, true, ['逐项原文对应通过']);
        $this->assertSame('ready', $run->fresh()->status);
        $this->assertSame(0, CanonicalCommit::count());
        $this->expectExceptionMessage('EVENT_READING_SOURCE_INVALID');
        $service->recordEventReading($run->id, $extraction->id, str_repeat('0', 64), $operator, true, ['错误摘要']);
    }

    /** B05违规案例通过真实准备入口落下提取与阻塞回执，正文及正式状态保持原样。 */
    public function test_unknown_entity_preserves_extraction_and_blocks_without_formal_events(): void
    {
        $this->scenario = 'unknown_entity';
        [$version, $operator] = self::candidate();
        $before = $version->getRawOriginal();
        $service = app(ChapterSubmissionService::class);
        $run = $service->prepare($version->id, $operator, 0, 'B05未定义实体阻塞验收', dispatch: false);
        $service->execute($run->id, $run->invocation_id, false);
        $run->refresh();
        $service->execute($run->id, $run->invocation_id, false);
        $this->assertSame('EVENT_VALIDATION_BLOCKED', $run->fresh()->stop_reason);
        $extraction = ChapterEventExtraction::sole();
        $validation = ChapterSubmissionValidation::sole();
        $this->assertSame($version->body_hash, $extraction->body_hash);
        $this->assertSame($extraction->id, $validation->extraction_id);
        $this->assertSame('blocked', $validation->verdict);
        $this->assertContains('UNKNOWN_ENTITY', array_column($validation->result['errors'], 'code'));
        foreach ($extraction->result['events'][0]['evidence'] as $evidence) {
            $this->assertSame($evidence['quote'], mb_substr($version->body, $evidence['start'], $evidence['end'] - $evidence['start']));
        }
        $this->assertSame($before, $version->fresh()->getRawOriginal());
        $this->assertSame(1, StoryEntity::count());
        $this->assertSame(0, StoryEvent::count());
        $this->assertSame(0, CanonicalCommit::count());
        $this->assertSame(0, $run->novel->fresh()->state_version);
        Http::assertSentCount(5);
    }

    /** 事务最后一笔失败时，章节、实体、事件、投影、计数和提交必须全部回滚。 */
    public function test_outbox_failure_rolls_back_entire_commit(): void
    {
        [$run, $operator] = self::prepared();
        StoryOutboxEvent::creating(fn () => throw new \RuntimeException('隔离事务末端故障'));
        try {
            self::commit($run, $operator);
            $this->fail('故障未回滚');
        } catch (\RuntimeException $exception) {
            $this->assertSame('隔离事务末端故障', $exception->getMessage());
        } finally {
            StoryOutboxEvent::flushEventListeners();
        }
        foreach ([CanonicalCommit::class, StoryEvent::class, StoryStateProjection::class, StoryOutboxEvent::class] as $model) {
            $this->assertSame(0, $model::count());
        }
        $this->assertNull($run->chapter->fresh()->formal_version_id);
        $this->assertSame(0, $run->novel->fresh()->state_version);
        $this->assertSame('ready', $run->fresh()->status);
    }

    /** 同键改参数、已提交后新键和跨版本产物均不能产生第二份提交。 */
    public function test_conflicting_idempotency_key_is_rejected(): void
    {
        [$run, $operator] = self::prepared();
        $key = (string) Str::uuid7();
        self::commit($run, $operator, key: $key);
        $this->expectExceptionMessage('IDEMPOTENCY_CONFLICT');
        self::commit($run, $operator, ['new-99'], $key);
    }

    /** 原文修改后确定性重验失败，即便评审显示PASS也不能提交。 */
    public function test_source_tampering_is_rejected_before_commit(): void
    {
        [$run, $operator] = self::prepared();
        DB::table('chapter_versions')->where('id', $run->version_id)->update(['body' => '篡改正文。']);
        $this->expectException(InvalidArgumentException::class);
        self::commit($run, $operator);
    }

    /** 来源变化或结构失败在评审HTTP之前停止，不能扣成一次真实请求。 */
    public function test_stale_source_before_preparation_http_uses_no_request(): void
    {
        [$version, $operator] = self::candidate();
        $service = app(ChapterSubmissionService::class);
        $run = $service->prepare($version->id, $operator, 0, '隔离请求前来源保护', dispatch: false);
        DB::table('chapter_versions')->where('id', $version->id)->update(['body' => '被修改的正文。']);
        $service->execute($run->id, $run->invocation_id, false);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->requests_used);
        Http::assertSentCount(3);
    }

    /** 评审费用和原始响应也必须属于本次冻结输入，不能借用或篡改已保存PASS。 */
    public function test_tampered_artifact_or_request_cannot_commit(): void
    {
        [$run, $operator] = self::prepared();
        $review = ChapterReview::where('submission_run_id', $run->id)->sole();
        DB::table('cost_logs')->where('id', $review->cost_log_id)->update(['response_text' => '{"verdict":"reject"}']);
        $this->expectException(InvalidArgumentException::class);
        self::commit($run, $operator);
    }

    /** 重复及迟到任务不再消费请求，也不能将已完成准备改成失败。 */
    public function test_duplicate_jobs_and_late_failures_preserve_artifacts(): void
    {
        [$run] = self::prepared();
        $before = $run->getRawOriginal();
        $service = app(ChapterSubmissionService::class);
        $service->execute($run->id, $run->invocation_id, false);
        $service->interrupted($run->id, $run->invocation_id);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        Http::assertSentCount(5);
    }

    /** 失效故事版本和其他作者都不能凭已有评审提交。 */
    public function test_stale_state_is_rejected_without_formal_writes(): void
    {
        [$run, $operator] = self::prepared();
        // 状态序号全书单调，失效以活动基线为准：已提交章数前进后，本章不再是下一章。
        DB::table('novels')->where('id', $run->novel_id)->update(['state_version' => 1, 'chapters_done' => 1]);
        try {
            self::commit($run, $operator);
            $this->fail('失效版本仍提交');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('STALE_STATE', $exception->getMessage());
        }
        $this->assertSame(0, CanonicalCommit::count());
        $this->assertNull($run->chapter->fresh()->formal_version_id);
    }

    /** 后台展示全部提议证据，显式逐项批准后复用统一事务服务。 */
    public function test_backend_shows_proposals_and_commits_only_explicit_approvals(): void
    {
        $this->scenario = 'proposal';
        [$run, $operator] = self::prepared();
        $this->actingAs($operator);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $extraction = ChapterEventExtraction::where('submission_run_id', $run->id)->sole();
        Livewire::test(ViewChapterContent::class, ['record' => $run->novel_id])
            ->assertSee('隔离访客')->assertSee('等待作者批准新实体')
            ->callAction('commitChapter', ['submission_id' => $run->id, 'proposal_hash' => PlanningJson::hash($extraction->result['proposed_entities']),
                'idempotency_key' => (string) Str::uuid7(), 'approved_entities' => ['new-1'], 'reason' => '隔离后台逐项批准', 'acknowledge' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(1, CanonicalCommit::count());
        $this->assertSame(2, StoryEntity::count());
        Http::assertSentCount(5);
    }

    /** 换账号后在后台接管本书，再在正文工作台依次完成全文核对、准备提交与正式提交，全程只用后台按钮。 */
    public function test_new_author_finishes_submission_in_backend_after_takeover(): void
    {
        $novel = B04ContentTest::readyNovel();
        $owner = User::findOrFail(NovelSettingConfirmation::where('novel_id', $novel->id)->sole()->confirmed_by);
        $body = app(ContentGenerationService::class)->start($novel, $owner);
        for ($consumed = 0; $job = Queue::pushed(RunChapterStepJob::class)->get($consumed); $consumed++) {
            $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        }
        $this->assertSame('awaiting_review', $body->fresh()->stop_reason);
        $other = User::factory()->create();
        $this->actingAs($other);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // 接管前：页面给出需要的账号和接管入口。
        Livewire::test(ViewChapterContent::class, ['record' => $novel->id])->callAction('fullReading', ['accepted' => true, 'notes' => '接管前尝试'])
            ->assertNotified();
        $this->assertSame('awaiting_review', $body->fresh()->stop_reason);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('takeOverBook', ['reason' => '换用日常账号', 'confirm' => true])->assertNotified('已接管本书');

        $page = Livewire::test(ViewChapterContent::class, ['record' => $novel->id]);
        $page->callAction('fullReading', ['accepted' => true, 'notes' => '接管人已阅读全文'])->assertHasNoActionErrors();
        $this->assertSame('b04_accepted', $body->fresh()->stop_reason);
        $page = Livewire::test(ViewChapterContent::class, ['record' => $novel->id])->assertActionVisible('prepareSubmission')
            ->callAction('prepareSubmission', ['reason' => '接管后准备提交', 'accept_request' => true])->assertHasNoActionErrors();
        $submission = ChapterSubmissionRun::sole();
        $this->assertSame($other->id, $submission->config['operator_id']);
        for ($i = 0; $i < 2 && $submission->refresh()->status === 'pending'; $i++) {
            app(ChapterSubmissionService::class)->execute($submission->id, $submission->invocation_id, false);
        }
        Livewire::test(ViewChapterContent::class, ['record' => $novel->id])->assertActionVisible('commitChapter')
            ->callAction('commitChapter', ['reason' => '接管人正式提交', 'acknowledge' => true])->assertHasNoActionErrors();
        $this->assertSame($body->chapter->fresh()->formal_version_id, CanonicalCommit::sole()->version_id);
    }

    /** 一键提交：评审通过且无新实体时自动正式提交；有新实体待批准或评审未通过时停下，不自动提交。 */
    #[DataProvider('oneClickCases')]
    public function test_one_click_submission_commits_only_when_nothing_needs_approval(string $scenario, string $status, int $commits): void
    {
        $this->scenario = $scenario;
        [$version, $operator] = self::candidate();
        $this->actingAs($operator);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewChapterContent::class, ['record' => $version->chapter->novel_id])->assertActionVisible('submitInOneStep')
            ->callAction('submitInOneStep', ['reason' => '一键提交', 'acknowledge' => true])->assertHasNoActionErrors();
        $run = ChapterSubmissionRun::sole();
        $this->assertSame($operator->id, $run->config['auto_commit']['operator_id']);
        for ($i = 0; $i < 2 && $run->refresh()->status === 'pending'; $i++) {
            app(ChapterSubmissionService::class)->execute($run->id, $run->invocation_id, false);
        }
        $this->assertSame($status, $run->refresh()->status);
        $this->assertSame($commits, CanonicalCommit::count());
        if ($commits === 1) {
            $this->assertSame($version->id, $version->chapter->fresh()->formal_version_id);
            $this->assertSame($run->config['auto_commit']['idempotency_key'], CanonicalCommit::sole()->idempotency_key);
        }
    }

    /** 无新实体、有新实体待批准、评审未通过三种结果。
     * @return array<string, array{string, string, int}>
     */
    public static function oneClickCases(): array
    {
        return ['无新实体' => ['pass', 'committed', 1], '新实体待批准' => ['proposal', 'awaiting_approval', 0], '评审未通过' => ['review_reject', 'failed', 0]];
    }

    /** 命令不接受扩额为第三次请求，提交命令缺少作者确认时不落正式状态。 */
    public function test_cli_entrypoints_enforce_budget_and_author_confirmation(): void
    {
        [$version] = self::candidate();
        $this->artisan('app:prepare-chapter-submission', ['version' => $version->id, '--expected-state-version' => '0', '--max-requests' => '3', '--reason' => '非法第三次'])
            ->assertFailed();
        $this->assertSame(0, ChapterSubmissionRun::count());
        $this->artisan('app:prepare-chapter-submission', ['version' => $version->id, '--expected-state-version' => '0', '--sync' => true, '--reason' => '隔离同步两次'])
            ->assertSuccessful();
        $run = ChapterSubmissionRun::sole();
        $this->assertSame('ready', $run->status);
        $this->artisan('app:commit-chapter', ['version' => $version->id, '--review' => ChapterReview::sole()->id, '--extraction' => ChapterEventExtraction::sole()->id,
            '--validation' => ChapterSubmissionValidation::sole()->id, '--expected-state-version' => '0', '--idempotency-key' => (string) Str::uuid7(), '--reason' => '未勾选作者确认'])
            ->assertFailed();
        $this->assertSame(0, CanonicalCommit::count());
        Http::assertSentCount(5);
    }

    /** 工厂只创建带来源关系的隔离约束夹具，不冒充可提交的真实产物。 */
    public function test_factories_produce_relationally_consistent_fixtures(): void
    {
        $event = StoryEvent::factory()->create();
        $projection = StoryStateProjection::factory()->create();
        $outbox = StoryOutboxEvent::factory()->create();
        $this->assertSame(StoryEntity::findOrFail($event->actor_entity_id)->novel_id, $event->novel_id);
        $this->assertSame(CanonicalCommit::findOrFail($projection->commit_id)->novel_id, $projection->novel_id);
        $this->assertNotNull($outbox->commit_id);
    }

    /** 人物传闻不得升级为客观生死；死者当前行动及别名冲突有确定性硬门禁。 */
    public function test_semantic_gate_catches_supported_hard_conflicts(): void
    {
        $validator = app(SubmissionValidator::class);
        $data = $validator->normalize(self::extraction(), '他听说旧人仍然活着。');
        $data['events'][0]['event_type'] = 'statement';
        $data['events'][0]['fact_category'] = 'rumor';
        $data['patch'] = [['entity_ref' => 'entity-1', 'property' => 'life_status', 'value_ref' => '', 'value_text' => 'alive', 'before_ref' => '', 'fact_category' => 'objective_confirmed', 'source_event_key' => 'event-1']];
        $catalog = ['entity-1' => ['name' => '隔离主角', 'type' => 'character', 'aliases' => []]];
        $this->assertSame('blocked', $validator->validate($data, $catalog, ['entities' => []])['verdict']);
        $data['patch'] = [];
        $data['events'][0]['event_type'] = 'action';
        $this->assertContains('DEAD_ACTOR', array_column($validator->validate($data, $catalog, ['entities' => ['entity-1' => ['life_status' => ['value_text' => 'dead']]]])['errors'], 'code'));
    }

    /** 证据区间由Unicode原文计算，正文占位与截断不得进入付费评审。 */
    public function test_evidence_and_structural_checks(): void
    {
        $body = "他抵达港口。\n\n她打开信。";
        foreach (SubmissionContract::evidence(['p1', 'p3'], $body) as $item) {
            $this->assertSame($item['quote'], mb_substr($body, $item['start'], $item['end'] - $item['start']));
        }
        $this->assertContains('placeholder', SubmissionContract::structuralIssues('正文待补充。', ['min_characters' => 1, 'max_characters' => 100]));
        $this->assertContains('possible_truncation', SubmissionContract::structuralIssues('他的话说到这里', ['min_characters' => 1, 'max_characters' => 100]));
    }

    /** B08后台显式重跑失败准备，保留旧证据且仅入队新后继。 */
    public function test_b08_backend_retries_failed_submission_without_overwriting_history(): void
    {
        $this->scenario = 'review_reject';
        [$run, $operator] = self::prepared();
        $before = $run->getRawOriginal();
        $this->actingAs($operator);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewChapterContent::class, ['record' => $run->novel_id])
            ->callAction('retrySubmission', ['predecessor_id' => $run->id, 'execution_limit' => max($run->novel->max_execution_count, $run->novel->execution_count + 2),
                'reason' => 'B08显式重跑', 'accept_request' => true])->assertHasNoActionErrors();
        $successor = ChapterSubmissionRun::where('predecessor_id', $run->id)->sole();
        $this->assertSame('pending', $successor->status);
        $this->assertSame(0, $successor->requests_used);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        Http::assertSentCount(4);
    }
}
