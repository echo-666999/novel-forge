<?php

namespace Tests\Feature;

use App\Enums\ProductionFailure;
use App\Enums\Stage;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\FormalRevisions;
use App\Filament\Resources\Novels\Pages\ManageBaselines;
use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Filament\Resources\Novels\Pages\ViewForeshadows;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewOutlinePlan;
use App\Jobs\AdvanceProductionRunJob;
use App\Jobs\FormalRevisionStepJob;
use App\Models\CanonicalBaseline;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\ContextManifest;
use App\Models\CostLog;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Models\FormalRevision;
use App\Models\ModelPrice;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\OutlineChapterRevision;
use App\Models\ProductionRun;
use App\Models\StoryEvent;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\CanonicalBaselines;
use App\Services\Pipeline\CanonicalChapterContext;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContextBuilder;
use App\Services\Pipeline\ForeshadowLedger;
use App\Services\Pipeline\FormalChapterExport;
use App\Services\Pipeline\FormalRevisionContract;
use App\Services\Pipeline\FormalRevisionService;
use App\Services\Pipeline\LegacyContentSources;
use App\Services\Pipeline\NovelBudget;
use App\Services\Pipeline\NovelProgressQuery;
use App\Services\Pipeline\OutlineGenerationService;
use App\Services\Pipeline\OutlineRevisions;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\ProductionRunService;
use App\Services\Pipeline\StoryStateReplay;
use App\Services\Pipeline\StyleSignals;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 连续十章通过真实服务编排，隔离供应商响应不代表文学质量验收。 */
class C01SequenceTest extends TestCase
{
    use DatabaseMigrations;

    /** 专项验证供应商实际收到的到期伏笔，默认不改变其他场景。 */
    private bool $assertForeshadows = false;

    /** 第三章事件提取持续返回供应商错误，验证提交失败后的整章重写入口。 */
    private bool $failThirdExtraction = false;

    /** 在第五章模拟独立评审拒绝，验证提交边界。 */
    private bool $rejectFifthReview = false;

    /** 第九章首次提取制造硬校验失败，验证恢复保持既有总额度。 */
    private bool $rejectNinthExtraction = false;

    /** D01 伏笔故障注入：omit_payoff 不报告回收，early_payoff 在窗口前一章报告回收。 */
    private ?string $foreshadowFault = null;

    /** 模拟旧60秒规划请求失败，验证新尝试等待时间独立冻结。 */
    private bool $rejectFirstOutline = false;

    /** 仅首次规划模拟结果未知，后续请求使用正常隔离响应。 */
    private bool $unknownFirstOutline = false;

    /** 正式稿修订核对的假结果：equivalent、not_equivalent，或 new_facts（声称等价但列出新增事实）。 */
    private string $formalVerdict = 'equivalent';

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
            } elseif ($version === FormalRevisionContract::VERSION) {
                $data = $this->formalRevisionResponse($request, $log);
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
                    if ($this->failThirdExtraction && $submission->chapter->number === 3) {
                        return Http::response(['error' => ['message' => '隔离提取失败']], 400);
                    }
                    $data = ['events' => [['key' => 'event-1', 'event_type' => 'observation', 'narrative_order' => 1,
                        'actor_ref' => array_key_first($submission->snapshot['entity_catalog']), 'target_refs' => [], 'time_scope' => 'present',
                        'fact_category' => 'objective_confirmed', 'summary' => '隔离观察', 'evidence_ids' => ['p1'], 'state_changes' => []]]];
                    if ($this->rejectNinthExtraction && $submission->chapter->number === 9) {
                        $data['events'][0]['event_type'] = 'statement';
                        $this->rejectNinthExtraction = false;
                    }
                    // 长程伏笔：按冻结上下文的义务报告埋设、强化与回收，可注入故障。
                    $submitted = json_decode(explode('冻结提交输入：', $request['input'][1]['content'][0]['text'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
                    $longRange = array_filter($submitted['foreshadow_context']['items'] ?? [], fn (array $item): bool => isset($item['payoff_to']));
                    if ($longRange !== []) {
                        $number = $submission->chapter->number;
                        $data['foreshadows'] = [];
                        foreach ($longRange as $item) {
                            $action = match ($item['obligation']) {
                                'plant' => 'plant', 'resolve' => 'payoff', 'prepare' => $number % 5 === 0 ? 'reinforce' : null,
                                'preserve' => $number % 25 === 0 ? 'reinforce' : null, default => null,
                            };
                            // 故障只注入到必回收的铜钥匙，其他伏笔照常流转。
                            $faulty = $item['title'] === '缺口铜钥匙';
                            if ($faulty && $this->foreshadowFault === 'omit_payoff' && $action === 'payoff') {
                                $action = null;
                            }
                            if ($faulty && $this->foreshadowFault === 'early_payoff' && $number === $item['payoff_from'] - 1) {
                                $action = 'payoff';
                            }
                            if ($action !== null) {
                                $data['foreshadows'][] = ['foreshadow_id' => $item['id'], 'action' => $action, 'evidence_ids' => ['p1'], 'explanation' => '隔离测试：'.$action];
                            }
                        }
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
                    // 前文状态序号来自活动基线上一章提交；基线切换后不再等于章号减一。
                    $this->assertSame(app(CanonicalBaselines::class)->activeStateBefore(Novel::findOrFail($log->novel_id), $number), $payload['prior_context']['prior_state_version']);
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
        // 预算与单价只在内存库清理，迁移回滚保护保持开启。
        DB::table('cost_logs')->update(['reserved_cost' => null, 'reserved_currency' => null]);
        DB::table('novels')->update(['budget_amount' => null, 'budget_currency' => null, 'creative_brief' => null]);
        DB::table('model_prices')->delete();
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

    /** O4：续写运行遇到预算不足时，第11章在发送请求前停下，停止原因可读，补足预算后恢复继续。 */
    public function test_extension_stops_before_request_when_budget_is_insufficient(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $first = $this->drain($service->start([...B02SettingTest::input(), 'target_chapters' => 20, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10));
        ModelPrice::factory()->create(['model' => 'gpt-6.1-terra', 'currency' => 'CNY']);
        app(NovelBudget::class)->set($first->novel_id, $author, '0.000001', 'CNY', '隔离验证预算拦截');
        $logs = CostLog::count();
        $run = $this->drain($service->extend($first->novel_id, $author, 2, null, '预算不足的续写', false));
        $this->assertSame(['failed', 'CONTENT_STOPPED:budget_exhausted'], [$run->status, $run->error]);
        $this->assertSame($logs, CostLog::count());
        $this->assertStringContainsString('预算不足', ProductionFailure::describe($run->error, $run->step)['label']);
        // 提高预算后显式恢复，从同一断点继续并完成两章。
        app(NovelBudget::class)->set($first->novel_id, $author, '1000', 'CNY', '补足预算');
        $run = $this->drain($service->retry($run->id, $author));
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame(12, Novel::findOrFail($first->novel_id)->chapters_done);
        $this->assertSame(0, CostLog::where('id', '>', $logs)->whereNull('reserved_cost')->count());
    }

    /** 推进生产运行；运行完成且未达目标时按十章一段续写，直到目标章数或运行停止。 */
    private function produceAll(ProductionRun $run, User $author): ProductionRun
    {
        $service = app(ProductionRunService::class);
        $run = $this->drain($run);
        while ($run->status === 'completed' && ($novel = Novel::findOrFail($run->novel_id))->chapters_done < $novel->target_chapters) {
            $run = $this->drain($service->extend($novel->id, $author, min(10, $novel->target_chapters - $novel->chapters_done), null, '隔离续写', false));
        }

        return $run;
    }

    /** 登记 PRD 11.3 样例的三条长程伏笔：必回收的铜钥匙（第3章埋设、第100章回收）及两条中期伏笔。
     * @return array{0: Foreshadow, 1: Foreshadow, 2: Foreshadow}
     */
    private function longRangePlans(ProductionRun $run, User $author, int $target): array
    {
        $ledger = app(ForeshadowLedger::class);
        $novel = Novel::findOrFail($run->novel_id);
        $key = $ledger->plan($novel, $author, '缺口铜钥匙', '前3章出现缺口铜钥匙，第'.$target.'章揭示其证明旧案证据被替换', 3, $target, (string) Str::uuid7(),
            ['surface_clue' => '一枚有异常缺口的铜钥匙', 'hidden_meaning' => '缺口与原始档案封记对应，证明证据被替换', 'payoff_effect' => '主角据此识破替换并改变调查方向',
                'knowledge_constraint' => '回收前任何人物都不知道缺口的用途', 'payoff_from' => $target, 'payoff_to' => $target, 'must_payoff' => true]);
        $letter = $ledger->plan($novel, $author, '褪色来信', '第2章出现褪色来信，中期揭示寄信人', 2, (int) ceil($target * 0.4), (string) Str::uuid7(),
            ['surface_clue' => '一封褪色的来信', 'hidden_meaning' => '寄信人是失踪的师父', 'payoff_from' => (int) ceil($target * 0.3), 'payoff_to' => (int) ceil($target * 0.4), 'must_payoff' => true]);
        $lamp = $ledger->plan($novel, $author, '熄灭的灯', '第5章灯塔异常熄灭，中期解释原因', 5, (int) ceil($target * 0.6), (string) Str::uuid7(),
            ['surface_clue' => '灯塔无故熄灭', 'hidden_meaning' => '有人借熄灯掩护走私', 'payoff_from' => (int) ceil($target * 0.6), 'payoff_to' => (int) ceil($target * 0.6)]);

        return [$key, $letter, $lamp];
    }

    /** D01：生成前冻结三条长程伏笔，逐段续写到第100章；必回收伏笔在第3章埋设、中途强化、第100章由正式正文自动回收，证据链可从回收反查埋设。 */
    public function test_hundred_chapter_foreshadow_payoff_through_real_pipeline(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'target_chapters' => 100, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        [$key, $letter, $lamp] = $this->longRangePlans($run, $author, 100);
        $this->assertTrue($key->planned_before_generation);
        $novel = Novel::findOrFail($run->novel_id);
        // 第95章前确定性取得待回收伏笔及来源，不依赖检索召回。
        $run = $this->drain($run);
        $run = $this->produceAll($run, $author);
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $novel->refresh();
        $this->assertSame([100, 100], [$novel->chapters_done, $novel->state_version]);
        $key->refresh();
        $this->assertSame('resolved', $key->status);
        $chain = $key->evidence;
        $this->assertSame(['planted', 3], [$chain->first()->to_status, $chain->first()->chapter_number]);
        $this->assertSame(['resolved', 100, 'commit_extraction'], [$chain->last()->to_status, $chain->last()->chapter_number, $chain->last()->source]);
        $this->assertGreaterThan(0, $chain->where('to_status', 'reinforced')->whereBetween('chapter_number', [80, 99])->count());
        $finalCommit = CanonicalCommit::where('novel_id', $novel->id)->where('state_version', 100)->sole();
        $this->assertSame([$finalCommit->id, $finalCommit->version_id], [$chain->last()->commit_id, $chain->last()->version_id]);
        $this->assertSame('resolved', $letter->fresh()->status);
        // 中期伏笔在回收窗口首章（第30章）回收。
        $this->assertSame(30, $letter->fresh()->evidence->last()->chapter_number);
        $this->assertSame('resolved', $lamp->fresh()->status);
        // 第95章的冻结上下文必须带有铜钥匙及其埋设来源，义务为回收准备。
        $ninetyFive = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', 95))->firstOrFail();
        $item = collect($ninetyFive->snapshot['foreshadow_context']['items'])->firstWhere('id', $key->id);
        $this->assertSame(['prepare', 3], [$item['obligation'], $item['sources'][0]['chapter_number']]);
        $hundred = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', 100))->firstOrFail();
        $this->assertSame(['resolve', true], [collect($hundred->snapshot['foreshadow_context']['items'])->firstWhere('id', $key->id)['obligation'], collect($hundred->snapshot['foreshadow_context']['items'])->firstWhere('id', $key->id)['must_address']]);
        // 已回收后不再出现在后续上下文中；准备检查显示无提醒。
        $this->assertSame([], collect(app(ForeshadowLedger::class)->readiness($novel))->firstWhere('id', $key->id)['warnings']);
    }

    /** D01 逆向测试：必回收伏笔在窗口末章没有回收证据时阻塞提交，停在第12章前。 */
    public function test_missing_payoff_blocks_the_target_chapter(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'target_chapters' => 12, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        [$key] = $this->longRangePlans($run, $author, 12);
        $this->foreshadowFault = 'omit_payoff';
        $run = $this->produceAll($run, $author);
        $this->assertSame(['failed', 'SUBMISSION_STOPPED:EVENT_VALIDATION_BLOCKED'], [$run->status, $run->error]);
        $this->assertSame(11, Novel::findOrFail($run->novel_id)->chapters_done);
        $validation = ChapterSubmissionValidation::latest('id')->firstOrFail();
        $this->assertContains('FORESHADOW_PAYOFF_MISSING', array_column($validation->result['errors'], 'code'));
        $this->assertNotSame('resolved', $key->fresh()->status);
        // 必回收伏笔不能通过手工登记绕过正文回收。
        $version = ChapterVersion::findOrFail(Chapter::where('novel_id', $run->novel_id)->where('number', 11)->sole()->formal_version_id);
        $this->expectExceptionMessage('必回收伏笔只能由正式提交的正文自动回收');
        app(ForeshadowLedger::class)->record($key->fresh(), $author, $key->fresh()->revision, 'resolved', $version->id, 1, mb_substr(explode("\n", $version->body)[0], 0, 2), '手工回收', (string) Str::uuid7());
    }

    /** D01：伏笔页登记长程契约，展示回收窗口、必回收与准备检查；必回收项不提供手工“已回收”。 */
    public function test_foreshadow_page_registers_long_range_contract(): void
    {
        $author = User::factory()->create();
        $run = app(ProductionRunService::class)->start([...B02SettingTest::input(), 'target_chapters' => 100], $author, true, false, chapterLimit: 10);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        Livewire::test(ViewForeshadows::class, ['record' => $run->novel_id])
            ->callAction('createPlan', ['title' => '缺口铜钥匙', 'description' => '第100章回收', 'plant' => 3, 'target' => 100, 'surface_clue' => '缺口铜钥匙', 'hidden_meaning' => '证明证据被替换',
                'payoff_from' => 100, 'payoff_to' => 100, 'must_payoff' => true])->assertHasNoActionErrors();
        $ledger = Foreshadow::sole();
        $this->assertSame([100, 100, true, true], [$ledger->payoff_from, $ledger->payoff_to, $ledger->must_payoff, $ledger->planned_before_generation]);
        Livewire::test(ViewForeshadows::class, ['record' => $run->novel_id])->assertSee('第100～100章')->assertSee('回收准备检查');
        // 窗口末章与最迟回收章不一致时拒绝登记。
        Livewire::test(ViewForeshadows::class, ['record' => $run->novel_id])
            ->callAction('createPlan', ['title' => '错误窗口', 'description' => '窗口不一致', 'plant' => 3, 'target' => 90, 'payoff_from' => 99, 'payoff_to' => 100])
            ->assertNotified('回收窗口须晚于埋设章，且窗口末章等于最迟回收章');
        $this->assertSame(1, Foreshadow::count());
    }

    /** D01：用验收样例文件启动时，伏笔契约与预算在投递任何任务前写入，契约标记为生成前登记。 */
    public function test_cli_registers_sample_contract_before_dispatch(): void
    {
        $author = User::factory()->create();
        $this->artisan('app:run-first-chapter', ['--chapters' => 10, '--input' => storage_path('app/private/d01-acceptance/input.json'), '--author' => $author->id,
            '--authorize-automatic-commit' => true, '--model' => 'deepseek-flash', '--foreshadows' => storage_path('app/private/d01-acceptance/foreshadows.json'), '--budget' => '50'])
            ->expectsOutputToContain('已登记伏笔')->assertSuccessful();
        $novel = Novel::sole();
        $this->assertSame([100, 800, 1200, '50.00000000', 'CNY', 'deepseek-flash'], [$novel->target_chapters, $novel->chapter_length_policy['min_characters'], $novel->chapter_length_policy['max_characters'], $novel->budget_amount, $novel->budget_currency, $novel->generation_config['model']]);
        $this->assertSame('不写穿越、重生、超自然能力；不写恋爱主线；不写血腥暴力', $novel->creative_brief['forbidden_content']);
        $plans = Foreshadow::orderBy('id')->get();
        $this->assertSame([[3, 100, 100, true], [2, 30, 40, true], [5, 60, 60, false]], $plans->map(fn ($plan): array => [$plan->plant_chapter, $plan->payoff_from, $plan->payoff_to, $plan->must_payoff])->all());
        $this->assertTrue($plans->every(fn ($plan): bool => $plan->planned_before_generation));
        Queue::assertPushed(AdvanceProductionRunJob::class, 1);
        $this->assertSame(0, CostLog::count());
    }

    /** D01 逆向测试：把回收提前到窗口前一章，提交被阻塞，不会提前变为已回收。 */
    public function test_early_payoff_outside_window_is_blocked(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'target_chapters' => 12, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        [$key] = $this->longRangePlans($run, $author, 12);
        $this->foreshadowFault = 'early_payoff';
        $run = $this->produceAll($run, $author);
        $this->assertSame('failed', $run->status);
        $validation = ChapterSubmissionValidation::latest('id')->firstOrFail();
        $this->assertContains('FORESHADOW_WINDOW_VIOLATION', array_column($validation->result['errors'], 'code'));
        $this->assertNotSame('resolved', $key->fresh()->status);
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

    /** 章纲与前文冲突时：作者修订未提交章节的章纲，整章重写按修订冻结并继续提交；原章纲、旧快照和已提交章节不变。 */
    public function test_outline_revision_unblocks_rewrite_of_failed_chapter(): void
    {
        $this->rejectFifthReview = true;
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        for ($i = 0; $i < 80 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('SUBMISSION_STOPPED:REVIEW_NOT_PASSED', $run->error);
        $novel = Novel::findOrFail($run->novel_id);
        $plan = $novel->outlinePlans()->where('status', 'completed')->latest('id')->firstOrFail();
        $fifth = $plan->chapters()->where('chapter_number', 5)->sole();
        $original = $fifth->attributesToArray();
        $oldBody = ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('number', 5))->latest('id')->firstOrFail();
        $revisions = app(OutlineRevisions::class);
        $points = $fifth->progression_points;
        $points[0]['after'] = '修订后的变化结果';
        $data = ['summary' => $fifth->summary, 'objective' => $fifth->objective, 'conflict' => $fifth->conflict, 'hook' => '修订后的钩子：只保留前文已建立的线索', 'progression_points' => $points];

        // 已提交章节、编号被改动和无变化的修订都被拒绝，且不发送请求。
        $committed = $plan->chapters()->where('chapter_number', 4)->sole();
        $this->assertThrows(fn () => $revisions->revise($novel->id, $committed->id, $author, [...$data, 'progression_points' => $committed->progression_points], '冲突', null), ValidationException::class);
        $this->assertThrows(fn () => $revisions->revise($novel->id, $fifth->id, $author, [...$data, 'progression_points' => [[...$points[0], 'id' => 'chapter-5-point-9']]], '冲突', null), ValidationException::class);
        $this->assertThrows(fn () => $revisions->revise($novel->id, $fifth->id, $author, [...$data, 'hook' => $fifth->hook, 'progression_points' => $fifth->progression_points], '冲突', null), ValidationException::class);
        $before = $service->requestCount($run);
        $revision = $revisions->revise($novel->id, $fifth->id, $author, $data, '章纲要求与前文正式正文冲突', null);
        $this->assertThrows(fn () => $revisions->revise($novel->id, $fifth->id, $author, [...$data, 'hook' => '另一个钩子'], '并发修订', null), ValidationException::class);
        $this->assertSame($original, $fifth->refresh()->attributesToArray());
        $this->assertSame($before, $service->requestCount($run));

        $this->rejectFifthReview = false;
        $resumed = $service->retry($run->id, $author, rewrite: true);
        $rewrite = ChapterGenerationRun::findOrFail($resumed->artifacts['body_run_id']);
        $this->assertSame('fine', $rewrite->next_step);
        $this->assertNull($rewrite->plan_data);
        $this->assertSame('修订后的钩子：只保留前文已建立的线索', $rewrite->snapshot['source_chapter']['hook']);
        $this->assertSame('修订后的变化结果', $rewrite->snapshot['source_chapter']['progression_points'][0]['after']);
        $this->assertSame(['id' => $revision->id, 'content_hash' => $revision->content_hash], $rewrite->snapshot['outline_revision']);
        $this->assertNotNull($rewrite->config['submission_review']);
        // 旧运行快照仍是原章纲，并继续通过来源核对。
        $this->assertSame($original['hook'], $oldBody->refresh()->snapshot['source_chapter']['hook']);
        app(LegacyContentSources::class)->assertPlanningSources($oldBody);
        app(LegacyContentSources::class)->assertPlanningSources($rewrite);

        $run = $resumed;
        for ($i = 0; $i < 80 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame(10, CanonicalCommit::count());
        // 快照中的修订身份被篡改时来源核对拒绝。
        $tampered = new ChapterGenerationRun;
        $tampered->setRawAttributes($rewrite->refresh()->getAttributes());
        $tampered->snapshot = [...$rewrite->snapshot, 'outline_revision' => ['id' => $revision->id, 'content_hash' => str_repeat('0', 64)]];
        $this->assertThrows(fn () => app(LegacyContentSources::class)->assertPlanningSources($tampered), \InvalidArgumentException::class);
        // 之后整章重写的章节不能再修订。
        $this->assertThrows(fn () => $revisions->revise($novel->id, $fifth->id, $author, [...$data, 'hook' => '提交后修订'], '冲突', $revision->id), ValidationException::class);
    }

    /** 提交准备失败后作者授权整章重写：已通过检查的候选另起新正文运行，按当前前文协议重新冻结，旧证据保留。 */
    public function test_rewrite_after_submission_failure_refreezes_prior_context(): void
    {
        $this->failThirdExtraction = true;
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        for ($i = 0; $i < 80 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('SUBMISSION_STOPPED:PROVIDER_FAILED', $run->error);
        $accepted = ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('number', 3))->latest('id')->firstOrFail();
        $this->assertSame('b04_accepted', $accepted->stop_reason);
        $this->failThirdExtraction = false;
        $resumed = $service->retry($run->id, $author, rewrite: true);
        $this->assertSame('content', $resumed->step);
        $rewrite = ChapterGenerationRun::findOrFail($resumed->artifacts['body_run_id']);
        $this->assertSame($accepted->id, $rewrite->parent_run_id);
        $this->assertSame('draft', $rewrite->next_step);
        $this->assertSame(CanonicalChapterContext::VERSION, $rewrite->snapshot['prior_context']['version']);
        $this->assertSame(app(ContextBuilder::class)->policy(), $rewrite->snapshot['context_policy']);
        $run = $resumed;
        for ($i = 0; $i < 80 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame(10, CanonicalCommit::count());
        $this->assertGreaterThanOrEqual(1, ChapterSubmissionRun::where('stop_reason', 'PROVIDER_FAILED')->count());
    }

    /** 滑动窗口只省略窗口外章节的原文引用，事件与段落位置完整保留；旧协议快照仍按原版本核对。 */
    public function test_prior_context_window_keeps_events_and_legacy_snapshots_verify(): void
    {
        $service = app(ProductionRunService::class);
        $author = User::factory()->create();
        $run = $service->start([...B02SettingTest::input(), 'target_chapters' => 20, 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 10);
        for ($i = 0; $i < 100 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $run = $service->extend($run->novel_id, $author, 2, null, '验证滑动窗口', false);
        for ($i = 0; $i < 40 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $novel = Novel::findOrFail($run->novel_id);
        $context = app(CanonicalChapterContext::class);
        $windowed = $context->build($novel, 13);
        $legacy = $context->build($novel, 13, CanonicalChapterContext::LEGACY_VERSION);
        $this->assertSame(3, $windowed['evidence_window']['full_evidence_from_chapter']);
        $this->assertArrayNotHasKey('evidence_window', $legacy);
        foreach ($windowed['chapters'] as $index => $chapter) {
            $old = $legacy['chapters'][$index];
            $this->assertSame(array_column($old['events'], 'event_id'), array_column($chapter['events'], 'event_id'));
            $this->assertSame(array_column($old['events'], 'summary'), array_column($chapter['events'], 'summary'));
            foreach ($chapter['events'] as $e => $event) {
                foreach ($event['evidence'] as $k => $evidence) {
                    $source = $old['events'][$e]['evidence'][$k];
                    $this->assertSame(array_diff_key($source, ['quote' => true]), array_diff_key($evidence, ['quote' => true]));
                    $chapter['number'] < 3 ? $this->assertArrayNotHasKey('quote', $evidence) : $this->assertSame($source['quote'], $evidence['quote']);
                }
            }
        }
        // 新运行冻结窗口版本；旧协议快照按其版本重建后仍通过来源核对。
        $twelfth = ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('number', 12))->latest('id')->firstOrFail();
        $this->assertSame(CanonicalChapterContext::VERSION, $twelfth->snapshot['prior_context']['version']);
        $legacyRun = new ChapterGenerationRun;
        $legacyRun->setRawAttributes($twelfth->getAttributes());
        $snapshot = [...$twelfth->snapshot, 'prior_context' => $context->build($novel, 12, CanonicalChapterContext::LEGACY_VERSION)];
        $legacyRun->snapshot = $snapshot;
        $legacyRun->input_hash = PlanningJson::hash([$snapshot, $twelfth->config]);
        app(LegacyContentSources::class)->assertPlanningSources($legacyRun);
        // 声称窗口版本却携带完整引文的快照与按版本重建结果不符，拒绝。
        $legacyRun->snapshot = [...$snapshot, 'prior_context' => [...$snapshot['prior_context'], 'version' => CanonicalChapterContext::VERSION]];
        $legacyRun->input_hash = PlanningJson::hash([$legacyRun->snapshot, $twelfth->config]);
        $this->assertThrows(fn () => app(LegacyContentSources::class)->assertPlanningSources($legacyRun), \InvalidArgumentException::class);

        // 跨章文风信号按近章正式正文冻结，只进入写作请求，篡改后来源核对拒绝。
        $this->assertSame(PlanningJson::hash(app(StyleSignals::class)->context($novel, 12)), PlanningJson::hash($twelfth->snapshot['style_signals']));
        $this->assertSame(['from' => 2, 'to' => 11], $twelfth->snapshot['style_signals']['window']);
        $first = ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('number', 1))->firstOrFail();
        $this->assertArrayNotHasKey('style_signals', $first->snapshot);
        $prompts = CostLog::where('novel_id', $novel->id)->get()->groupBy(fn (CostLog $log): string => (string) ($log->request_parameters['step'] ?? 'other'))
            ->map(fn ($logs): bool => $logs->contains(fn (CostLog $log): bool => str_contains($log->request_parameters['prompt_audit']['user_prompt'] ?? '', '"style_signals"')));
        $this->assertTrue($prompts['draft']);
        $this->assertFalse($prompts['check'] ?? false);
        $tampered = new ChapterGenerationRun;
        $tampered->setRawAttributes($twelfth->getAttributes());
        $forged = $twelfth->snapshot;
        $forged['style_signals']['recent_endings'][0]['text'] = '伪造结尾';
        $tampered->snapshot = $forged;
        $tampered->input_hash = PlanningJson::hash([$forged, $twelfth->config]);
        $this->assertThrows(fn () => app(LegacyContentSources::class)->assertPlanningSources($tampered), \InvalidArgumentException::class, '文风信号与正式来源');
    }

    /** 章纲页面为未提交章节提供修订入口，已提交章节不显示。 */
    public function test_outline_page_revises_unwritten_chapter(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $author = User::factory()->create();
        $this->actingAs($author);
        $service = app(ProductionRunService::class);
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 1);
        for ($i = 0; $i < 40 && $run->status === 'pending'; $i++) {
            $run = $service->advance($run->id);
        }
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $plan = Novel::findOrFail($run->novel_id)->outlinePlans()->latest('id')->firstOrFail();
        $first = $plan->chapters()->where('chapter_number', 1)->sole();
        $second = $plan->chapters()->where('chapter_number', 2)->sole();
        Livewire::test(ViewOutlinePlan::class, ['record' => $run->novel_id])
            ->assertTableActionHidden('reviseOutline', $first)
            ->assertTableActionVisible('reviseOutline', $second)
            ->callTableAction('reviseOutline', $second, ['hook' => '作者修订的第二章钩子', 'reason' => '与前文冲突'])
            ->assertHasNoTableActionErrors()
            ->assertSee('修订 #');
        $this->assertSame('作者修订的第二章钩子', OutlineChapterRevision::sole()->hook);
        $this->assertSame($second->progression_points, OutlineChapterRevision::sole()->progression_points);
        // 下一章新运行按修订冻结。
        $body = app(ContentGenerationService::class)->start(Novel::findOrFail($run->novel_id), $author, chapterNumber: 2);
        $this->assertSame('作者修订的第二章钩子', $body->snapshot['source_chapter']['hook']);
        $this->assertSame(OutlineChapterRevision::sole()->id, $body->snapshot['outline_revision']['id']);
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

    /** 生成并正式提交前四章，供基线测试使用。 */
    private function committedFour(User $author): Novel
    {
        $service = app(ProductionRunService::class);
        $run = $service->start([...B02SettingTest::input(), 'chapter_length_policy' => ['min_characters' => 20, 'max_characters' => 300]], $author, true, false, chapterLimit: 1);
        app(ForeshadowLedger::class)->plan(Novel::findOrFail($run->novel_id), $author, '基线伏笔', '后续回收线索', 1, 5, (string) Str::uuid7());
        $run = $this->drain($run);
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $run = $this->drain($service->extend($run->novel_id, $author, 3, null, '基线测试续写', false));
        $this->assertSame('completed', $run->status, $run->error ?? '');

        return Novel::findOrFail($run->novel_id);
    }

    /** 在候选基线修订一章：在末段开头插入文字，事件与伏笔证据按偏移重新定位。 */
    private function reviseCommitted(CanonicalBaseline $candidate, int $number, string $insert, User $author): CanonicalCommit
    {
        $baselines = app(CanonicalBaselines::class);
        $source = $baselines->commitFor(CanonicalBaseline::findOrFail($candidate->parent_id), $number);
        $old = ChapterVersion::findOrFail($source->version_id);
        $paragraphs = explode("\n", $old->body);
        $last = count($paragraphs);
        $offset = $last > 1 ? mb_strlen(implode("\n", array_slice($paragraphs, 0, $last - 1))) + 1 : 0;
        $paragraphs[$last - 1] = $insert.$paragraphs[$last - 1];
        $body = implode("\n", $paragraphs);
        $version = ChapterVersion::create(['chapter_id' => $old->chapter_id, 'run_id' => $old->run_id, 'source_stage_id' => NovelStage::factory()->create(['novel_id' => Chapter::findOrFail($old->chapter_id)->novel_id, 'stage' => Stage::Content])->id, 'parent_version_id' => $old->id,
            'version' => (int) ChapterVersion::where('chapter_id', $old->chapter_id)->max('version') + 1, 'title' => $old->title, 'body' => $body, 'body_hash' => hash('sha256', $body),
            'character_count' => mb_strlen($body), 'count_version' => $old->count_version, 'created_at' => now()]);
        $shift = fn (array $evidence): array => $evidence['start'] >= $offset ? [...$evidence, 'start' => $evidence['start'] + mb_strlen($insert), 'end' => $evidence['end'] + mb_strlen($insert)] : $evidence;
        $events = StoryEvent::where('commit_id', $source->id)->get()->mapWithKeys(fn (StoryEvent $event): array => [$event->event_key => array_map($shift, $event->evidence)])->all();
        $foreshadows = ForeshadowEvidence::where('commit_id', $source->id)->where('baseline_id', $candidate->parent_id)->get()->mapWithKeys(fn (ForeshadowEvidence $entry): array => [$entry->id => $shift($entry->evidence)])->all();

        return $baselines->commitRevision($candidate, $number, $version, $events, $foreshadows, $author, '测试润色');
    }

    /** 候选基线修订第2章并重挂下游：验证后切换，新旧基线都能回放和导出，旧运行快照仍可核对，之后续写接在新基线上。 */
    public function test_candidate_baseline_revises_rebases_and_switches(): void
    {
        $author = User::factory()->create();
        $novel = $this->committedFour($author);
        $baselines = app(CanonicalBaselines::class);
        $initial = $baselines->current($novel);
        $this->assertSame([1, 2, 3, 4], array_map(fn (CanonicalCommit $commit): int => $commit->state_version, $baselines->chain($initial)));
        $before = app(FormalChapterExport::class)->build($novel->id, 4, 'txt');
        $evidenceBefore = ForeshadowEvidence::where('novel_id', $novel->id)->count();

        $candidate = $baselines->createCandidate($novel, 2, 'formal_revision', '测试正式稿修订', $author);
        $this->assertFalse($baselines->writable($novel->refresh(), 5));
        $revised = $this->reviseCommitted($candidate, 2, '雨停了。', $author);
        $this->assertSame('revision', $revised->origin);
        $this->assertSame(2, $baselines->rebase($candidate, $author));
        // 修订期间活动基线、导出与上下文都不变。
        $this->assertSame($before['content'], app(FormalChapterExport::class)->build($novel->id, 4, 'txt')['content']);
        $verification = $baselines->verify($candidate);
        $this->assertSame([2], $verification['changed_chapters']);
        $this->assertSame(4, $verification['chapters']);

        // 通过“提交与基线”页面切换：只对已验证候选开放，并要求原因与确认。
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        Livewire::test(ManageBaselines::class, ['record' => $novel->id])->assertSuccessful()->assertSee('正式稿修订')
            ->assertTableActionHidden('switch', $initial)->assertTableActionVisible('switch', $candidate->refresh())
            ->callTableAction('switch', $candidate, ['reason' => '确认润色', 'confirm' => true])->assertHasNoTableActionErrors()->assertNotified('已切换正式基线');
        $novel->refresh();
        $this->assertSame($candidate->id, $novel->active_baseline_id);
        $this->assertSame('superseded', $initial->refresh()->status);
        $this->assertSame(4, $novel->chapters_done);
        $this->assertSame(7, $novel->state_version);
        $chapterTwo = Chapter::where('novel_id', $novel->id)->where('number', 2)->firstOrFail();
        $this->assertSame($revised->version_id, $chapterTwo->formal_version_id);
        $after = app(FormalChapterExport::class)->build($novel->id, 4, 'txt');
        $this->assertStringContainsString('雨停了。', $after['content']);
        $this->assertSame($before['content'], app(FormalChapterExport::class)->build($novel->id, 4, 'txt', $initial->id)['content']);
        $old = app(StoryStateReplay::class)->atChapter($novel, 4, $initial->refresh());
        $new = app(StoryStateReplay::class)->atChapter($novel, 4);
        $this->assertSame(array_column($old['events'], 'summary'), array_column($new['events'], 'summary'));
        $this->assertSame(array_column($old['entities'], 'name'), array_column($new['entities'], 'name'));
        $this->assertNotSame(array_column($old['events'], 'id'), array_column($new['events'], 'id'));
        $context = app(CanonicalChapterContext::class)->build($novel, 5);
        $this->assertSame($revised->id, $context['chapters'][1]['commit_id']);
        $this->assertSame(7, $context['prior_state_version']);
        app(ForeshadowLedger::class)->context($novel, 5);
        $this->assertSame($evidenceBefore * 2, ForeshadowEvidence::where('novel_id', $novel->id)->count());
        // 旧基线冻结的运行快照仍按原基线核对。
        $fourth = ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('novel_id', $novel->id)->where('number', 4))->latest('id')->firstOrFail();
        app(LegacyContentSources::class)->assertPlanningSources($fourth);

        // 续写第5章接在新基线上，状态序号继续单调递增。
        $this->assertTrue($baselines->writable($novel, 5));
        $run = app(ProductionRunService::class)->extend($novel->id, $author, 1, null, '基线切换后续写', false);
        $run = $this->drain($run);
        $this->assertSame('completed', $run->status, $run->error ?? '');
        $fifth = $baselines->commitFor($candidate->refresh(), 5);
        $this->assertSame($candidate->id, $fifth->baseline_id);
        $this->assertSame(7, $fifth->previous_state_version);
        $this->assertSame(8, $fifth->state_version);
        $this->assertSame(5, count($baselines->chain($candidate)));
    }

    /** 引文对不上、篡改复制事件都不能通过；放弃候选后可以继续写作。 */
    public function test_candidate_baseline_rejects_bad_evidence_and_can_be_abandoned(): void
    {
        $author = User::factory()->create();
        $novel = $this->committedFour($author);
        $baselines = app(CanonicalBaselines::class);
        $candidate = $baselines->createCandidate($novel, 3, 'formal_revision', '测试', $author);
        $this->assertThrows(fn () => $baselines->createCandidate($novel->refresh(), 2, 'formal_revision', '第二个候选', $author), \InvalidArgumentException::class);
        $source = $baselines->commitFor($baselines->current($novel), 3);
        $old = ChapterVersion::findOrFail($source->version_id);
        $body = '换了开头。'.$old->body;
        $version = ChapterVersion::create(['chapter_id' => $old->chapter_id, 'run_id' => $old->run_id, 'source_stage_id' => NovelStage::factory()->create(['novel_id' => Chapter::findOrFail($old->chapter_id)->novel_id, 'stage' => Stage::Content])->id, 'parent_version_id' => $old->id,
            'version' => (int) ChapterVersion::where('chapter_id', $old->chapter_id)->max('version') + 1, 'title' => $old->title, 'body' => $body, 'body_hash' => hash('sha256', $body),
            'character_count' => mb_strlen($body), 'count_version' => $old->count_version, 'created_at' => now()]);
        // 证据未随正文偏移，引文与新位置不符，拒绝。
        $events = StoryEvent::where('commit_id', $source->id)->get()->mapWithKeys(fn (StoryEvent $event): array => [$event->event_key => $event->evidence])->all();
        $foreshadows = ForeshadowEvidence::where('commit_id', $source->id)->where('baseline_id', $candidate->parent_id)->get()->mapWithKeys(fn (ForeshadowEvidence $entry): array => [$entry->id => $entry->evidence])->all();
        $this->assertThrows(fn () => $baselines->commitRevision($candidate, 3, $version, $events, $foreshadows, $author, '错误定位'), \InvalidArgumentException::class, '证据引文与修订正文位置不一致');

        $this->reviseCommitted($candidate, 3, '夜深了。', $author);
        $baselines->rebase($candidate, $author);
        // 篡改复制事件的证据位置后，回放核对拒绝验证与切换。
        $copy = StoryEvent::whereIn('commit_id', CanonicalCommit::where('baseline_id', $candidate->id)->pluck('id'))->orderBy('id')->firstOrFail();
        $evidence = $copy->evidence;
        $evidence[0]['quote'] = '伪造引文';
        DB::table('story_events')->where('id', $copy->id)->update(['evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE)]);
        $this->assertThrows(fn () => $baselines->switchTo($candidate, $author, '尝试切换'), \InvalidArgumentException::class);
        $this->assertSame('candidate', $candidate->refresh()->status);

        $baselines->abandon($candidate, '放弃测试');
        $this->assertTrue($baselines->writable($novel->refresh(), 5));
        $this->assertSame(4, count($baselines->chain($baselines->current($novel))));
    }

    /** 正式稿修订：建议逐条校验，只替换接受项；事实核对不通过不能提交；通过后进入候选基线，切换后正文更新、旧基线可读，请求上限生效。 */
    /** 事件全部成立时，核对列出的新增事实或约束变化可由作者确认为有意修改；没有可确认的变化或理由为空不能确认。 */
    public function test_author_confirms_intended_new_facts_in_formal_revision(): void
    {
        $author = User::factory()->create();
        $novel = $this->committedFour($author);
        $service = app(FormalRevisionService::class);
        $ai = app(AiTextGenerationService::class);
        $revision = $service->start($novel, $author, 2, 2, '补一处衔接动作', 4);
        $this->formalVerdict = 'equivalent';
        $two = $revision->chapters()->sole();
        $two = $service->suggest($two->id, $ai);
        $two = $service->decide($two->id, $author, ['s1' => 'accept']);

        // 不等价的核对结果不能由作者确认放行。
        $this->formalVerdict = 'not_equivalent';
        $two = $service->verify($two->id, $ai);
        $this->assertThrows(fn () => $service->confirmFactChanges($two->id, $author, '有意修改'), \InvalidArgumentException::class);

        // 只有新增事实时保留平移后的证据，作者确认后视为通过，确认记录留档并可提交、切换。
        $this->formalVerdict = 'new_facts';
        $two = $service->verify($two->id, $ai);
        $this->assertSame('failed', $two->status);
        $this->assertIsArray($two->equivalence['event_evidence']);
        $this->assertThrows(fn () => $service->confirmFactChanges($two->id, $author, ' '), \InvalidArgumentException::class);
        // 改变的约束同样可确认；早于本功能、未保留证据的核对在证据可确定性平移时由程序补算。
        $this->formalVerdict = 'lost';
        $two = $service->verify($two->id, $ai);
        $this->assertSame(['原有的阴雨背景被改变'], $two->equivalence['lost_constraints']);
        $two->update(['equivalence' => [...$two->equivalence, 'event_evidence' => null, 'foreshadow_evidence' => null]]);
        $two = $service->confirmFactChanges($two->id, $author, '天气是有意补充的衔接');
        $this->assertSame(['原有的阴雨背景被改变'], $two->equivalence['author_confirmed_changes']['lost_constraints']);
        $this->assertIsArray($two->equivalence['event_evidence']);
        $this->assertSame('verified', $two->status);
        $this->assertSame($author->id, $two->equivalence['author_confirmed_changes']['actor_id']);
        $this->assertSame('verified', $service->commitCandidate($revision, $author)->status);
        $service->switchTo($revision->refresh(), $author, '确认修订');
        $this->assertStringContainsString('雨停了，', app(FormalChapterExport::class)->build($novel->id, 4, 'txt')['content']);
    }

    public function test_formal_revision_polishes_committed_chapter_through_candidate_baseline(): void
    {
        $author = User::factory()->create();
        $novel = $this->committedFour($author);
        $before = app(FormalChapterExport::class)->build($novel->id, 4, 'txt');
        $initial = app(CanonicalBaselines::class)->current($novel);
        $service = app(FormalRevisionService::class);
        $ai = app(AiTextGenerationService::class);
        $this->assertThrows(fn () => $service->start($novel, $author, 2, 5, '越界', 8), \InvalidArgumentException::class);
        $revision = $service->start($novel, $author, 2, 3, '去掉重复开头', 8);
        $this->assertFalse(app(CanonicalBaselines::class)->writable($novel->refresh(), 5));
        [$two, $three] = $revision->chapters()->get()->all();
        $this->assertSame([2, 3], [$two->chapter_number, $three->chapter_number]);
        $this->assertArrayHasKey('overused_phrases', $two->signals);

        $two = $service->suggest($two->id, $ai);
        $three = $service->suggest($three->id, $ai);
        $this->assertSame('suggested', $two->status);
        $this->assertSame([true, false], array_column($two->suggestions, 'valid'));
        $this->assertSame('原句不在该段或在该段出现多次', $two->suggestions[1]['invalid_reason']);
        $this->assertThrows(fn () => $service->decide($two->id, $author, ['s2' => 'accept']), \InvalidArgumentException::class);
        $two = $service->decide($two->id, $author, ['s1' => 'accept', 's2' => 'reject']);
        $three = $service->decide($three->id, $author, ['s1' => 'exception']);
        $this->assertSame('decided', $two->status);
        $this->assertSame('unchanged', $three->status);
        $source = ChapterVersion::findOrFail($two->source_version_id)->body;
        $candidate = ChapterVersion::findOrFail($two->candidate_version_id)->body;
        $this->assertSame('雨停了，'.$source, $candidate);

        // 核对不等价、或声称等价却列出新增事实，都不能通过，也不能提交候选基线。
        $this->formalVerdict = 'not_equivalent';
        $this->assertSame('failed', $service->verify($two->id, $ai)->status);
        $this->formalVerdict = 'new_facts';
        $two = $service->verify($two->id, $ai);
        $this->assertSame('failed', $two->status);
        $this->assertSame(['新增了雨停的天气事实'], $two->equivalence['new_facts']);
        $this->assertThrows(fn () => $service->commitCandidate($revision, $author), \InvalidArgumentException::class);
        $this->formalVerdict = 'equivalent';
        $two = $service->verify($two->id, $ai);
        $this->assertSame('verified', $two->status, (string) $two->error);
        $this->assertSame(5, $revision->refresh()->requests_used);

        $baseline = $service->commitCandidate($revision, $author);
        $this->assertSame('verified', $baseline->status);
        $this->assertSame([2], $baseline->verification['changed_chapters']);
        $this->assertSame($before['content'], app(FormalChapterExport::class)->build($novel->id, 4, 'txt')['content']);
        $service->switchTo($revision->refresh(), $author, '确认润色');
        $this->assertSame('switched', $revision->refresh()->status);
        $after = app(FormalChapterExport::class)->build($novel->id, 4, 'txt');
        $this->assertStringContainsString('雨停了，', $after['content']);
        $this->assertSame($before['content'], app(FormalChapterExport::class)->build($novel->id, 4, 'txt', $initial->id)['content']);
        app(StoryStateReplay::class)->atChapter($novel->refresh(), 4);
        app(ForeshadowLedger::class)->context($novel, 5);
        $this->assertTrue(app(CanonicalBaselines::class)->writable($novel, 5));

        // 第二次修订只授权一次请求：第二章建议之后再请求在发送前拒绝。
        $second = $service->start($novel, $author, 1, 2, '再润色一次', 1);
        [$one, $twoAgain] = $second->chapters()->get()->all();
        $this->assertSame('suggested', $service->suggest($one->id, $ai)->status);
        $requests = CostLog::count();
        $this->assertThrows(fn () => $service->suggest($twoAgain->id, $ai), \InvalidArgumentException::class, '已用完本次修订授权的1次请求');
        $this->assertSame($requests, CostLog::count());
        $service->abandon($second, '测试放弃');
        $this->assertTrue(app(CanonicalBaselines::class)->writable($novel->refresh(), 5));
    }

    /** 正式稿修订页面：发起、排队生成建议、逐条取舍并查看改动；修订进行中不能再发起新的修订。 */
    public function test_formal_revision_page_drives_suggest_and_decide(): void
    {
        $author = User::factory()->create();
        $novel = $this->committedFour($author);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        $page = Livewire::test(FormalRevisions::class, ['record' => $novel->id])->assertSuccessful()->assertSee('尚未发起')
            ->callAction('start', ['from' => 1, 'to' => 2, 'intent' => '去掉重复开头', 'request_limit' => 4, 'accept' => true])->assertHasNoActionErrors()->assertNotified('已发起正式稿修订');
        $revision = FormalRevision::sole();
        $first = $revision->chapters()->firstOrFail();
        $page = Livewire::test(FormalRevisions::class, ['record' => $novel->id])->assertActionHidden('start')
            ->callTableAction('suggest', $first)->assertNotified('已排队生成建议');
        Queue::assertPushed(FormalRevisionStepJob::class, fn (FormalRevisionStepJob $job): bool => $job->chapterId === $first->id && $job->step === 'suggest');
        (new FormalRevisionStepJob($first->id, 'suggest'))->handle(app(FormalRevisionService::class), app(AiTextGenerationService::class));
        Livewire::test(FormalRevisions::class, ['record' => $novel->id])
            ->callTableAction('decide', $first->refresh(), ['s1' => 'accept', 's2' => 'reject'])->assertHasNoTableActionErrors()->assertNotified('已保存取舍')
            ->assertTableActionVisible('diff', $first->refresh())->assertTableActionVisible('verify', $first);
        $this->assertSame('decided', $first->status);
    }

    /** 正式稿修订的假供应商：建议把第1段整段加上开头语，并附一条引文不存在的建议；核对按设定给出结论并重新定位待定位证据。
     * @return array<string, mixed>
     */
    private function formalRevisionResponse(Request $request, CostLog $log): array
    {
        $payload = json_decode(explode('冻结输入：', $request['input'][1]['content'][0]['text'], 2)[1], true, flags: JSON_THROW_ON_ERROR);
        if ($log->request_parameters['revision_purpose'] === 'suggest') {
            $first = $payload['paragraphs'][0]['text'];

            return ['suggestions' => [['paragraph' => 1, 'quote' => $first, 'replacement' => '雨停了，'.$first, 'problem' => '开头与近章雷同'],
                ['paragraph' => 1, 'quote' => '不存在的原句', 'replacement' => '改法', 'problem' => '无效建议']]];
        }
        $changed = $payload['changed_paragraphs'][0] ?? ['paragraph' => 1, 'after' => ''];

        return ['verdict' => $this->formalVerdict === 'not_equivalent' ? 'not_equivalent' : 'equivalent', 'summary' => '隔离核对',
            'event_checks' => array_map(fn (array $event): array => ['event_key' => $event['event_key'], 'holds' => $this->formalVerdict !== 'not_equivalent', 'reason' => '隔离核对'], $payload['events']),
            'new_facts' => $this->formalVerdict === 'new_facts' ? ['新增了雨停的天气事实'] : [], 'lost_constraints' => $this->formalVerdict === 'lost' ? ['原有的阴雨背景被改变'] : [], 'literary_issues' => [],
            'relocations' => array_map(fn (array $item): array => ['ref' => $item['ref'], 'paragraph' => $changed['paragraph'], 'quote' => mb_substr($changed['after'], 0, 6)], $payload['unresolved'])];
    }
}
