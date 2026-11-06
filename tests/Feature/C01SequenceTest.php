<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\CostLog;
use App\Models\FirstChapterRun;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\CanonicalChapterContext;
use App\Services\Pipeline\FirstChapterService;
use App\Services\Pipeline\LegacyContentSources;
use App\Services\Pipeline\OutlineGenerationService;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\StoryStateReplay;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** 连续十章通过真实服务编排，隔离供应商响应不代表文学质量验收。 */
class C01SequenceTest extends TestCase
{
    use DatabaseMigrations;

    /** 在第五章模拟独立评审拒绝，验证提交边界。 */
    private bool $rejectFifthReview = false;

    /** 第九章首次提取制造硬校验失败，验证恢复保持既有总额度。 */
    private bool $rejectNinthExtraction = false;

    /** 模拟旧60秒规划请求失败，验证新尝试等待时间独立冻结。 */
    private bool $rejectFirstOutline = false;

    /** 仅首次规划模拟结果未知，后续请求使用正常隔离响应。 */
    private bool $unknownFirstOutline = false;

    /** 仅迁移项目业务表。 */
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
            $this->assertSame('gpt-6.1-terra', $request['model']);
            $this->assertArrayNotHasKey('temperature', $request->data());
            $this->assertSame(['effort' => 'low'], $request['reasoning']);
            $this->assertSame(['effort' => 'low'], CostLog::latest('id')->firstOrFail()->request_parameters['reasoning']);
            $log = CostLog::latest('id')->firstOrFail();
            $version = $log->request_parameters['schema_version'];
            if ($version === 'b02-setting-v1') {
                $data = B02SettingTest::setting();
            } elseif ($version === 'b10-setting-decision-v1') {
                $data = ['setting' => [...B02SettingTest::setting(), 'open_questions' => []], 'answers' => ['来信不能改变已经发生的过去。']];
            } elseif ($version === 'b03-outline-v1') {
                if ($this->unknownFirstOutline) {
                    $this->unknownFirstOutline = false;
                    throw new ConnectionException('隔离规划结果未知');
                }
                if ($this->rejectFirstOutline) {
                    $this->rejectFirstOutline = false;

                    return Http::response(['error' => ['message' => '隔离规划失败']], 400);
                }
                $data = B03OutlineTest::batch();
            } elseif (isset($log->request_parameters['purpose'])) {
                if ($log->request_parameters['purpose'] === 'review') {
                    $submission = ChapterSubmissionRun::findOrFail($log->request_parameters['submission_run_id']);
                    $data = ['review' => ['verdict' => $this->rejectFifthReview && $submission->chapter->number === 5 ? 'needs_revision' : 'pass', 'summary' => '隔离评审通过', 'evidence_ids' => ['p1'], 'findings' => []]];
                } else {
                    $submission = ChapterSubmissionRun::findOrFail($log->request_parameters['submission_run_id']);
                    $data = ['events' => [['key' => 'event-1', 'event_type' => 'observation', 'narrative_order' => 1,
                        'actor_ref' => array_key_first($submission->snapshot['entity_catalog']), 'target_refs' => [], 'time_scope' => 'present',
                        'fact_category' => 'objective_confirmed', 'summary' => '隔离观察', 'evidence_ids' => ['p1'], 'state_changes' => []]]];
                    if ($this->rejectNinthExtraction && $submission->chapter->number === 9) {
                        $data['events'][0]['event_type'] = 'statement';
                        $this->rejectNinthExtraction = false;
                    }
                }
            } else {
                $payload = json_decode(explode('冻结输入：', $request['input'][1]['content'][0]['text'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(B02SettingTest::input()['premise_raw'], $payload['author_idea']);
                // 检查供应商实际收到的输入，不能仅凭数据库有摘要就宣称已注入。
                $number = $payload['chapter_number'];
                if ($number > 1) {
                    $this->assertCount($number - 1, $payload['prior_context']['chapters']);
                    $this->assertSame($number - 1, $payload['prior_context']['prior_state_version']);
                }
                $response = B04ContentTest::validResponse($request);
                if (($log->request_parameters['step'] ?? null) === 'fine') {
                    $data = B04ContentTest::fine();
                    $ids = array_column($payload['progression_points'], 'id');
                    foreach ($data['scenes'] as &$scene) {
                        $scene['point_ids'] = $ids;
                    }
                    unset($scene);
                    $data['hook_transition']['point_ids'] = $ids;
                    $response['output'][0]['content'][0]['text'] = PlanningJson::encode($data);
                }
                if (($log->request_parameters['step'] ?? null) === 'draft') {
                    $data = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
                    foreach ($data['paragraphs'] as &$paragraph) {
                        $paragraph = mb_substr($paragraph, 0, -1).'。';
                    }
                    unset($paragraph);
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

    /** 十章顺序提交，每次后文只冻结此前正式事件；重复消息不重复请求。 */
    public function test_ten_chapters_commit_in_order_with_frozen_prior_context(): void
    {
        $service = app(FirstChapterService::class);
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], User::factory()->create(), true, false, chapterLimit: 10);
        for ($i = 0; $i < 100 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame(10, Novel::findOrFail($run->novel_id)->chapters_done);
        $this->assertSame(range(1, 10), Chapter::orderBy('number')->pluck('number')->all());
        $this->assertSame(range(1, 10), CanonicalCommit::orderBy('state_version')->pluck('state_version')->all());
        $this->assertSame(10, CanonicalCommit::distinct()->count('idempotency_key'));
        $this->assertCount(10, $run->artifacts['chapters']);
        foreach (ChapterGenerationRun::orderBy('id')->get() as $body) {
            $number = $body->chapter->number;
            if ($number > 1) {
                $context = $body->snapshot['prior_context'];
                $this->assertCount($number - 1, $context['chapters']);
                $this->assertSame(range(1, $number - 1), array_column($context['chapters'], 'number'));
                $this->assertSame(PlanningJson::hash($context), PlanningJson::hash(app(CanonicalChapterContext::class)->build(Novel::findOrFail($run->novel_id), $number)));
                $this->assertSame('objective_confirmed', $context['chapters'][0]['events'][0]['fact_category']);
            }
        }
        $this->assertSame(53, $service->requestCount($run));
        $service->advance($run->id);
        $this->assertSame(53, $service->requestCount($run));
        $this->assertSame(10, CanonicalCommit::count());
        // C02从真实提交服务产物回放第五章，不能包含后五章事件。
        $replay = app(StoryStateReplay::class)->atChapter(Novel::findOrFail($run->novel_id), 5);
        $this->assertSame(5, $replay['state_version']);
        $this->assertSame(5, max(array_column($replay['events'], 'chapter')));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::findOrFail($run->author_id));
        $page = Livewire::test(ViewChapterContent::class, ['record' => $run->novel_id]);
        $page->callAction('selectChapter', data: ['number' => 5])->assertSet('chapterNumber', 5);
        $this->assertSame(5, $page->instance()->run()->chapter->number);
        $this->assertSame(5, $page->instance()->submission()->chapter->number);
        $page->callAction('selectChapter', data: ['number' => 10])->assertSet('chapterNumber', 10);
        $this->assertSame(10, $page->instance()->submission()->chapter->number);

        // 仅修改内存副本模拟伪造前文，正式历史保持不变且来源校验必须拒绝。
        $body = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('number', 5))->firstOrFail();
        $snapshot = $body->snapshot;
        $snapshot['prior_context']['chapters'][0]['events'][0]['summary'] = '伪造前文';
        $body->snapshot = $snapshot;
        $this->expectException(\InvalidArgumentException::class);
        app(LegacyContentSources::class)->assertPlanningSources($body);

    }

    /** 旧首章授权不能派生第二章提交密钥。 */
    public function test_single_chapter_authorization_cannot_expand(): void
    {
        $run = app(FirstChapterService::class)->start(B02SettingTest::input(), User::factory()->create(), true, false);
        $this->expectException(\LogicException::class);
        $run->chapterCommitKey(2);
    }

    /** 第五章拒绝不能污染前四章，也不能跳过失败生成第六章。 */
    public function test_middle_chapter_failure_preserves_prior_commits(): void
    {
        $this->rejectFifthReview = true;
        $service = app(FirstChapterService::class);
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], User::factory()->create(), true, false, chapterLimit: 10);
        for ($i = 0; $i < 80 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('failed', $run->status);
        $this->assertSame('SUBMISSION_STOPPED:REVIEW_NOT_PASSED', $run->error);
        $this->assertSame(range(1, 4), CanonicalCommit::orderBy('state_version')->pluck('state_version')->all());
        $this->assertSame(5, Chapter::max('number'));
        $this->assertNull(Chapter::where('number', 5)->sole()->canonical_commit_id);
        $before = $service->requestCount($run);
        $service->advance($run->id);
        $this->assertSame($before, $service->requestCount($run));
    }

    /** 创建入口冻结十章范围，错误篇幅计划不能创建小说或调用模型。 */
    public function test_creation_ui_validates_and_freezes_ten_chapter_scope(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Livewire::test(CreateNovel::class)->fillForm([...B02SettingTest::input(), 'target_chapters' => 5, 'automatic_first_ten' => true])
            ->call('create')->assertHasFormErrors(['target_chapters']);
        $this->assertSame(0, Novel::count());
        Livewire::test(CreateNovel::class)->fillForm([...B02SettingTest::input(), 'automatic_first_ten' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(10, FirstChapterRun::sole()->chapter_limit);
        $this->assertSame('c01-v1', FirstChapterRun::sole()->policy['version']);
        $this->assertSame('gpt-6.1-terra', Novel::sole()->generation_config['model']);
        Http::assertNothingSent();
    }

    /** 十章总额度高于当前次数加二十时，恢复仍须沿用既有额度且保留失败提取。 */
    public function test_ninth_chapter_extraction_recovers_without_lowering_authorized_limit(): void
    {
        $this->rejectNinthExtraction = true;
        $service = app(FirstChapterService::class);
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], User::factory()->create(), true, false, chapterLimit: 10);
        $limit = Novel::findOrFail($run->novel_id)->max_execution_count;
        for ($i = 0; $i < 100 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame($limit, Novel::findOrFail($run->novel_id)->max_execution_count);
        $this->assertSame(10, CanonicalCommit::count());
        $this->assertSame(1, ChapterSubmissionRun::where('status', 'failed')->count());
        $this->assertSame(55, $service->requestCount($run));
        $this->assertTrue(collect($run->history)->contains('action', 'automatic_recovery'));
    }

    /** 恢复旧规划不覆盖60秒历史，新尝试冻结180秒且继续通过正常门禁。 */
    public function test_outline_recovery_freezes_longer_timeout_without_overwriting_old_plan(): void
    {
        $service = app(FirstChapterService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        $run = $service->advance($run->id);
        $run = $service->advance($run->id);
        $plan = app(OutlineGenerationService::class)->start(Novel::findOrFail($run->novel_id), $run->artifacts['confirmation_id'], $author, 'gpt-6.1-terra');
        $config = [...$plan->config, 'timeout_seconds' => 60];
        // 仅在内存测试库模拟升级前已冻结的计划，生产历史禁止这样改写。
        DB::table('novel_outline_plans')->where('id', $plan->id)->update(['config' => json_encode($config)]);
        $this->rejectFirstOutline = true;
        $run = $service->advance($run->id);
        $this->assertSame('pending', $run->status, $run->error ?? '');
        $this->assertSame($config, $plan->refresh()->config);
        $this->assertSame(60, CostLog::latest('id')->firstOrFail()->request_parameters['timeout_seconds']);
        $run = $service->advance($run->id);
        $this->assertSame('content', $run->step, $run->error ?? '');
        $this->assertSame(180, CostLog::latest('id')->firstOrFail()->request_parameters['timeout_seconds']);
        $this->assertSame($config, $plan->refresh()->config);
    }

    /** 确认旧未知费用后可以跨阶段执行，原记录仍未知且新增未知请求仍需再次确认。 */
    public function test_confirmed_unknown_request_does_not_block_later_chapters_or_approve_future_unknowns(): void
    {
        $service = app(FirstChapterService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        $this->unknownFirstOutline = true;
        for ($i = 0; $i < 10 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('failed', $run->status);
        $unknownId = CostLog::where('status', 'unknown')->sole()->id;
        $run = $service->retry($run->id, $author, true);
        $this->assertSame([$unknownId], $run->acknowledgedUnknownLogIds());
        for ($i = 0; $i < 100 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame('unknown', CostLog::findOrFail($unknownId)->status);
        $this->assertSame(10, CanonicalCommit::count());
        CostLog::factory()->create(['novel_id' => $run->novel_id, 'mode' => 'real', 'status' => 'unknown']);
        $run->update(['status' => 'failed']);
        $this->expectException(\InvalidArgumentException::class);
        $service->retry($run->id, $author);
    }
}
