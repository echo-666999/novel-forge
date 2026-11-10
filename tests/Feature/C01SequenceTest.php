<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\ContextManifest;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\CanonicalChapterContext;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ForeshadowLedger;
use App\Services\Pipeline\LegacyContentSources;
use App\Services\Pipeline\NovelProgressQuery;
use App\Services\Pipeline\OutlineGenerationService;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\ProductionRunService;
use App\Services\Pipeline\StoryStateReplay;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** 连续十章通过真实服务编排，隔离供应商响应不代表文学质量验收。 */
class C01SequenceTest extends TestCase
{
    use DatabaseMigrations;

    /** 专项验证供应商实际收到的到期伏笔，默认不改变其他场景。 */
    private bool $assertForeshadows = false;

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
        // 此组验证Terra协议，显式设置测试默认模型，不依赖生产十章硬编码。
        $settings = app(GeneralSettings::class);
        $settings->default_model = 'gpt-6.1-terra';
        $settings->save();
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
            // C04清单与供应商实际载荷逐字一致，生成和提交准备均走统一入口。
            if (isset($log->request_parameters['context_manifest_id'])) {
                $manifest = ContextManifest::findOrFail($log->request_parameters['context_manifest_id']);
                $this->assertSame($manifest->document['user_prompt'], $request['input'][1]['content'][0]['text']);
                $this->assertSame($manifest->document_hash, PlanningJson::hash($manifest->document));
                $this->assertSame($manifest->document['system_prompt'], $request['input'][0]['content']);
                $this->assertSame(PlanningJson::hash($manifest->document['response_schema']), PlanningJson::hash($request['text']['format']['schema']));
            }
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
                // 按请求范围返回批次，支持超过十章的目标；原十章场景仍是1至10章。
                $range = $log->request_parameters['range'];
                $data = B03OutlineTest::batch($range['start'], $range['end'], $range['total']);
            } elseif (isset($log->request_parameters['purpose'])) {
                if ($this->assertForeshadows) {
                    $payload = json_decode(explode('冻结提交输入：', $request['input'][1]['content'][0]['text'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
                    $this->assertCount(3, $payload['foreshadow_context']['items']);
                    $this->assertSame([true, true, true], array_column($payload['foreshadow_context']['items'], 'must_address'));
                }
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
                if ($this->assertForeshadows) {
                    $this->assertCount(3, $payload['foreshadow_context']['items']);
                    $this->assertSame([true, true, true], array_column($payload['foreshadow_context']['items'], 'must_address'));
                }
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
        // 续写运行引用前序运行，先解除引用再清理内存夹具。
        DB::table('production_runs')->update(['predecessor_id' => null]);
        DB::table('production_runs')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 十章顺序提交，每次后文只冻结此前正式事件；重复消息不重复请求。 */
    public function test_ten_chapters_commit_in_order_with_frozen_prior_context(): void
    {
        $this->assertForeshadows = true;
        $service = app(ProductionRunService::class);
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], User::factory()->create(), true, false, chapterLimit: 10);
        // 在生成首章前登记三条计划，验证所有十章的真实请求路径持续携带未完成义务。
        foreach (range(1, 3) as $index) {
            app(ForeshadowLedger::class)->plan(Novel::findOrFail($run->novel_id), User::firstOrFail(), '隔离伏笔'.$index, '后续回收线索', 1, 5, (string) Str::uuid7());
        }
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

    /** 推进自动运行直至停止，返回最终状态。 */
    private function drain(ProductionRun $run): ProductionRun
    {
        $service = app(ProductionRunService::class);
        for ($i = 0; $i < 200 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }

        return $run;
    }

    /** O2：前十章完成后授权第11～20章，同一本书两次运行顺序提交，旧提交键与回放不受影响。 */
    public function test_completed_ten_chapter_run_extends_to_chapters_eleven_to_twenty(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $first = $this->drain($service->start([...B02SettingTest::input(), 'target_chapters' => 20, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10));
        $this->assertSame('completed', $first->status, $first->error ?? '');
        $keys = CanonicalCommit::orderBy('state_version')->pluck('idempotency_key')->all();
        $replayTen = PlanningJson::hash(app(StoryStateReplay::class)->atChapter(Novel::findOrFail($first->novel_id), 10)['state']);
        $baseline = CostLog::count();

        $second = $service->extend($first->novel_id, $author, 10, null, '隔离续写第11至20章', false);
        $this->assertSame([11, 20, $first->id, 'content'], [$second->start_chapter, $second->chapter_limit, $second->predecessor_id, $second->step]);
        $this->assertSame(11, $second->artifacts['chapter_number']);
        $second = $this->drain($second);
        $this->assertSame('completed', $second->status, $second->error ?? '');
        $novel = Novel::findOrFail($first->novel_id);
        $this->assertSame([20, 20], [$novel->chapters_done, $novel->state_version]);
        $this->assertSame(range(1, 20), CanonicalCommit::orderBy('state_version')->pluck('state_version')->all());
        $this->assertSame($keys, CanonicalCommit::orderBy('state_version')->limit(10)->pluck('idempotency_key')->all());
        $this->assertSame($replayTen, PlanningJson::hash(app(StoryStateReplay::class)->atChapter($novel, 10)['state']));
        $this->assertSame(CostLog::count() - $baseline, $service->requestCount($second));
        // 第11章起的正文冻结了全部正式前文，提交键只来自第二次授权。
        $eleven = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('number', 11))->firstOrFail();
        $this->assertCount(10, $eleven->snapshot['prior_context']['chapters']);
        $this->assertSame($second->chapterCommitKey(11), CanonicalCommit::where('state_version', 11)->value('idempotency_key'));
        $this->assertSame(2, ProductionRun::where('novel_id', $novel->id)->count());
    }

    /** O2：续写授权的范围、目标章数、在途运行与单次上限均被拒绝；授权冻结后范围外章号不能派生提交键。 */
    public function test_extension_guards(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $this->drain($service->start([...B02SettingTest::input(), 'target_chapters' => 12, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10));
        $this->assertSame('completed', $run->status, $run->error ?? '');
        foreach ([[11, null], [3, null], [0, null], [2, 'not-a-model']] as [$count, $model]) {
            try {
                $service->extend($run->novel_id, $author, $count, $model, '越界测试', false);
                $this->fail('续写范围'.$count.'应被拒绝');
            } catch (\InvalidArgumentException) {
                $this->assertSame(1, ProductionRun::where('novel_id', $run->novel_id)->count());
            }
        }
        $next = $service->extend($run->novel_id, $author, 2, 'gpt-6.1-terra', '续写最后两章', false);
        $this->assertSame('gpt-6.1-terra', $next->frozenModel());
        $this->expectException(\InvalidArgumentException::class);
        $service->extend($run->novel_id, $author, 1, null, '在途运行不能重复授权', false);
    }

    /** O2：续写授权冻结起止章，范围外章号不能派生提交键，篡改范围会使授权校验失败。 */
    public function test_range_policy_is_frozen(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $this->drain($service->start([...B02SettingTest::input(), 'target_chapters' => 20, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10));
        $next = $service->extend($run->novel_id, $author, 3, null, '冻结范围', false);
        $next->assertDelegation($run->novel_id, $author->id);
        foreach ([10, 14] as $number) {
            try {
                $next->chapterCommitKey($number);
                $this->fail('第'.$number.'章不在授权范围');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
        DB::table('production_runs')->where('id', $next->id)->update(['chapter_limit' => 20]);
        $this->expectException(\LogicException::class);
        $next->fresh()->assertDelegation($run->novel_id, $author->id);
    }

    /** O2：自动首章完成后，作者无需新授权即可手动逐章写到第3章，每章经过检查、评审、提取并由作者批准提交。 */
    public function test_manual_chapters_continue_after_completed_run(): void
    {
        $author = User::factory()->create();
        $run = $this->drain(app(ProductionRunService::class)->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false));
        $this->assertSame('completed', $run->status, $run->error ?? '');
        foreach ([2, 3] as $number) {
            $novel = Novel::findOrFail($run->novel_id);
            $body = app(ContentGenerationService::class)->start($novel, $author, reason: '手动第'.$number.'章', chapterNumber: $number);
            for ($i = 0; $i < 10 && $body->status === 'pending'; $i++) {
                app(ContentExecutionService::class)->runStep($body->id, $body->invocation_id, app(AiTextGenerationService::class));
                $body->refresh();
            }
            $this->assertSame('awaiting_review', $body->stop_reason);
            $body = app(ContentExecutionService::class)->recordReading($body->id, $author, true, ['作者全文核对通过']);
            $submission = app(ChapterSubmissionService::class)->prepare($body->chapter->current_candidate_version_id, $author, $number - 1, '手动提交准备', dispatch: false);
            for ($i = 0; $i < 5 && $submission->status === 'pending'; $i++) {
                app(ChapterSubmissionService::class)->execute($submission->id, $submission->invocation_id, false);
                $submission->refresh();
            }
            $this->assertContains($submission->status, ['ready', 'awaiting_approval']);
            ChapterSubmissionTest::commit($submission, $author, array_column(ChapterEventExtraction::where('submission_run_id', $submission->id)->sole()->result['proposed_entities'], 'key'));
        }
        $novel = Novel::findOrFail($run->novel_id);
        $this->assertSame([3, 3], [$novel->chapters_done, $novel->state_version]);
        $this->assertSame(1, ProductionRun::where('novel_id', $novel->id)->count());
        $this->assertNull(ProductionRun::coveringChapter($novel->id, 2));
        $this->assertSame([1, 2, 3], CanonicalCommit::orderBy('state_version')->pluck('state_version')->all());
    }

    /** O2：工作台在运行完成后显示“生成下一章”和“继续自动生成”，续写动作建立冻结范围的新运行；运行在途时两者隐藏。 */
    public function test_novel_page_offers_next_chapter_and_extension(): void
    {
        $author = User::factory()->create();
        $run = $this->drain(app(ProductionRunService::class)->start([...B02SettingTest::input(), 'target_chapters' => 20, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        $page = Livewire::test(ViewNovel::class, ['record' => $run->novel_id])
            ->assertActionVisible('generateNextChapter')->assertActionVisible('extendProduction')
            ->callAction('extendProduction', ['count' => 3, 'model' => null, 'reason' => '后台续写验收', 'accept_request' => true])
            ->assertHasNoActionErrors();
        $next = ProductionRun::latestFor($run->novel_id);
        $this->assertSame([11, 13, 'pending'], [$next->start_chapter, $next->chapter_limit, $next->status]);
        Livewire::test(ViewNovel::class, ['record' => $run->novel_id])->assertActionDoesNotExist('generateNextChapter')->assertActionDoesNotExist('extendProduction');
    }

    /** 已执行过的假队列任务，避免重复处理同一消息。
     * @var list<object>
     */
    private array $handledJobs = [];

    /** 依次执行假队列中尚未处理的任务，任务内新投递的消息继续执行，直到队列为空。 */
    private function runQueuedJobs(): void
    {
        for ($round = 0; $round < 60; $round++) {
            $ran = false;
            foreach (Queue::pushedJobs() as $entries) {
                foreach ($entries as $entry) {
                    if (in_array($entry['job'], $this->handledJobs, true)) {
                        continue;
                    }
                    $this->handledJobs[] = $entry['job'];
                    app()->call([$entry['job'], 'handle']);
                    $ran = true;
                }
            }
            if (! $ran) {
                return;
            }
        }
    }

    /** 工作台进度卡片的当前标题。 */
    private function progressHeadline(Novel $novel): string
    {
        return app(NovelProgressQuery::class)->for($novel->fresh())['headline'];
    }

    /** O3 退出标准：新用户只按工作台提示和页面按钮，就能从建书走到第1章正式提交。 */
    public function test_new_user_reaches_first_formal_chapter_through_workbench(): void
    {
        $author = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        Livewire::test(CreateNovel::class)->fillForm([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300], 'production_mode' => 'manual'])
            ->call('create')->assertHasNoFormErrors();
        $novel = Novel::sole();
        $this->runQueuedJobs();
        $this->assertSame('等你确认设定', $this->progressHeadline($novel));
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSee('● 设定确认')
            ->callAction('confirmSetting', ['answers' => [['answer' => '来信不能改变已经发生的过去。']], 'acknowledged' => true])->assertHasNoActionErrors();
        $this->assertSame('可以生成大纲', $this->progressHeadline($novel));
        Livewire::test(ViewNovel::class, ['record' => $novel->id])
            ->callAction('generateOutline', ['confirmation_id' => $novel->settingConfirmations()->max('id'), 'accept_request' => true])->assertHasNoActionErrors();
        $this->runQueuedJobs();
        $this->assertSame('可以开始写作', $this->progressHeadline($novel));
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('generateFirstChapter', ['accept_request' => true])->assertHasNoActionErrors();
        $this->runQueuedJobs();
        $this->assertSame('第1章：等你核对全文', $this->progressHeadline($novel));
        Livewire::test(ViewChapterContent::class, ['record' => $novel->id])->assertSee('第1章：等你核对全文')
            ->callAction('fullReading', ['accepted' => true, 'notes' => '全文核对通过'])->assertHasNoActionErrors();
        $this->assertSame('第1章：可以准备正式提交', $this->progressHeadline($novel));
        Livewire::test(ViewChapterContent::class, ['record' => $novel->id])->callAction('prepareSubmission', ['reason' => '准备提交第1章', 'accept_request' => true])->assertHasNoActionErrors();
        $this->runQueuedJobs();
        $this->assertContains($this->progressHeadline($novel), ['第1章：可以正式提交', '第1章：等你批准新实体并正式提交']);
        Livewire::test(ViewChapterContent::class, ['record' => $novel->id])->callAction('commitChapter', ['approved_entities' => [], 'reason' => '作者批准第1章', 'acknowledge' => true])->assertHasNoActionErrors();
        $this->assertSame(1, $novel->fresh()->chapters_done);
        $this->assertSame('已正式提交1/10章，可以继续写', $this->progressHeadline($novel));
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertActionVisible('generateNextChapter')->assertActionVisible('extendProduction')
            ->assertSee('原始要求与覆盖')->assertSee('成品验收')->assertDontSee('B04');
    }

    /** 旧首章授权不能派生第二章提交密钥。 */
    public function test_single_chapter_authorization_cannot_expand(): void
    {
        $run = app(ProductionRunService::class)->start(B02SettingTest::input(), User::factory()->create(), true, false);
        $this->expectException(\LogicException::class);
        $run->chapterCommitKey(2);
    }

    /** 第五章拒绝不能污染前四章，也不能跳过失败生成第六章。 */
    public function test_middle_chapter_failure_preserves_prior_commits(): void
    {
        $this->rejectFifthReview = true;
        $service = app(ProductionRunService::class);
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
        // 显式恢复必须先修正文，不能只重复评审直到碰巧通过。
        $resumed = $service->retry($run->id, User::findOrFail($run->author_id));
        $this->assertSame('content', $resumed->step);
        $repair = ChapterGenerationRun::findOrFail($resumed->artifacts['body_run_id']);
        $this->assertSame('revise', $repair->next_step);
        $this->assertSame(Chapter::where('number', 5)->sole()->current_candidate_version_id, $repair->config['submission_review']['version_id']);
        $this->assertSame($before, $service->requestCount($resumed));
    }

    /** 创建入口冻结十章范围，错误篇幅计划不能创建小说或调用模型。 */
    public function test_creation_ui_validates_and_freezes_ten_chapter_scope(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Livewire::test(CreateNovel::class)->fillForm([...B02SettingTest::input(), 'target_chapters' => 5, 'production_mode' => 'auto_ten'])
            ->call('create')->assertHasFormErrors(['target_chapters']);
        $this->assertSame(0, Novel::count());
        Livewire::test(CreateNovel::class)->fillForm([...B02SettingTest::input(), 'production_mode' => 'auto_ten'])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(10, ProductionRun::sole()->chapter_limit);
        $this->assertSame('c01-v1', ProductionRun::sole()->policy['version']);
        $this->assertSame('gpt-6.1-terra', Novel::sole()->generation_config['model']);
        Http::assertNothingSent();
    }

    /** 十章总额度高于当前次数加二十时，恢复仍须沿用既有额度且保留失败提取。 */
    public function test_ninth_chapter_extraction_recovers_without_lowering_authorized_limit(): void
    {
        $this->rejectNinthExtraction = true;
        $service = app(ProductionRunService::class);
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
        $service = app(ProductionRunService::class);
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
        $service = app(ProductionRunService::class);
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
