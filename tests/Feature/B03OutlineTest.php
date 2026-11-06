<?php

namespace Tests\Feature;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewOutlinePlan;
use App\Jobs\RunOutlineBatchJob;
use App\Jobs\RunSettingJob;
use App\Models\Chapter;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelOutlinePlan;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Pipeline\ConfirmSettingService;
use App\Services\Pipeline\OutlineExecutor;
use App\Services\Pipeline\OutlineGenerationService;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\RunControlService;
use App\Services\Pipeline\SettingExecutor;
use Database\Factories\NovelSettingConfirmationFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** 在隔离内存库通过真实 SDK 协议验证长篇分批、作者确认和重试边界。 */
class B03OutlineTest extends TestCase
{
    use DatabaseMigrations;

    /** SQLite 只执行应用迁移。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 工厂用户、假队列和禁止外网，任何调用必须命中测试响应。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'ai.providers.openai.key' => 'sk-b03-test-key', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** 仅清理内存夹具后允许迁移回滚，不触碰实际作者确认或数据库。 */
    protected function tearDown(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame(0, Chapter::query()->count());
        foreach (['novel_outline_chapters', 'novel_outline_plans', 'novel_setting_confirmations'] as $table) {
            DB::table($table)->delete();
        }
        DB::table('novels')->update(['generation_mode' => 'simulation']);
        parent::tearDown();
    }

    /** 一次最多十章的独立候选样本；前后差异可由后续正文检查定位。
     * @return array<string, mixed>
     */
    public static function batch(int $start = 1, int $end = 10, int $total = 10): array
    {
        $chapters = [];
        foreach (range($start, $end) as $number) {
            $chapters[] = ['chapter_number' => $number, 'title' => '档案室的第'.$number.'份证据', 'summary' => '主角核查档案并找出新的航线信息。', 'objective' => '确认第'.$number.'份线索', 'conflict' => '记录被人刻意涂改', 'hook' => '签名指向下一处调查地点',
                'progression_points' => [['change_type' => 'information', 'subject' => '林澈', 'before' => '缺少第'.$number.'份航线证据', 'action' => '比对原档与来信', 'after' => '取得第'.$number.'份可验证航线证据', 'verification' => '正文呈现比对过程和新证据，改变下一步调查目的地']]];
        }
        $result = ['chapters' => $chapters, 'handoff' => ['continuity_summary' => '候选规划推进到第'.$end.'章，下一章承接已取得的证据。', 'open_threads' => ['幕后篡改者仍待揭示']]];
        if ($start === 1) {
            $result['roadmap'] = ['whole_story_summary' => '主角沿证据链寻找失踪师父并揭开篡改旧案的动机。', 'ending_direction' => '师徒重逢并公开证据，人物选择承担代价。', 'major_turning_points' => [['chapter_number' => $total, 'change' => '查明篡改者并完成主要调查']]];
        }

        return $result;
    }

    /** 按 SDK 真实 HTTP 协议返回批次，回调可检查请求前已提交的意图。 */
    private function fakeBatches(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => function (Request $request) {
            $log = CostLog::query()->latest('id')->firstOrFail();
            $range = $log->request_parameters['range'];
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('pending', $log->status);
            $this->assertSame(8192, $request['max_output_tokens']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertSame($range['start'], $request['text']['format']['schema']['properties']['chapters']['items']['properties']['chapter_number']['minimum']);

            return Http::response(B02SettingTest::response(json_encode(self::batch($range['start'], $range['end'], $range['total']), JSON_UNESCAPED_UNICODE)));
        }]);
    }

    /** 确定性消费已捕获的固定批次消息，不能无限循环或意外调用外网。 */
    private function drain(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $job = Queue::pushed(RunOutlineBatchJob::class)->get($i);
            if ($job === null) {
                return;
            }
            $job->handle(app(OutlineGenerationService::class), app(OutlineExecutor::class));
        }
        $this->fail('测试批次数超过固定保护上限');
    }

    /** 每批十章，整批完成后才推进，并保持模拟任务与旧设定回调隔离。 */
    public function test_ten_chapter_outline_and_read_only_page_use_real_protocol_once(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create(['confirmed_by' => auth()->id()]);
        $novel = $confirmation->novel;
        $this->assertTrue($confirmation->hasValidHash());
        $plan = app(OutlineGenerationService::class)->start($novel, $confirmation->id, auth()->user());
        $oldJob = new RunSettingJob($novel->id, $novel->generation_config['invocation_id']);
        $oldJob->handle(app(SettingExecutor::class));
        $oldJob->failed(new RuntimeException('迟到设定回调'));
        $this->assertSame(NovelStatus::Pending, $novel->fresh()->status);
        $this->fakeBatches();
        $job = Queue::pushed(RunOutlineBatchJob::class)->sole();
        $job->handle(app(OutlineGenerationService::class), app(OutlineExecutor::class));
        $before = $plan->fresh()->getRawOriginal();
        $job->handle(app(OutlineGenerationService::class), app(OutlineExecutor::class));
        $job->failed(new RuntimeException('迟到失败'));
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame(11, $plan->fresh()->next_chapter);
        $this->assertSame(10, $plan->chapters()->count());
        $this->assertSame('chapter-1-point-1', $plan->chapters()->first()->progression_points[0]['id']);
        $this->assertSame(Stage::FineOutline, $novel->fresh()->current_stage);
        $this->assertSame(2, $novel->fresh()->execution_count);
        $this->assertSame('33.33', $novel->fresh()->progress);
        $this->assertSame('unknown', CostLog::query()->sole()->billing_status);
        $page = Livewire::test(ViewOutlinePlan::class, ['record' => $novel->id])->assertSee('大纲候选已完成')->assertCanSeeTableRecords($plan->chapters()->get())->mountTableAction('readChapter', $plan->chapters()->first())->assertActionMounted(TestAction::make('readChapter')->table($plan->chapters()->first()));
        $modalHtml = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('正文完成条件', $modalHtml);
        $this->assertStringContainsString('取得第1份可验证航线证据', $modalHtml);
        Http::assertSentCount(1);
    }

    /** 百章在一次授权范围内分十批，后续批次复用走向与上一批输入。 */
    public function test_hundred_chapters_are_generated_in_ten_ordered_batches(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $novel = $confirmation->novel;
        $novel->update(['target_chapters' => 100, 'max_execution_count' => 11]);
        $plan = app(OutlineGenerationService::class)->start($novel, $confirmation->id, auth()->user());
        $this->fakeBatches();
        $this->drain();
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame(range(1, 100), $plan->chapters()->orderBy('chapter_number')->pluck('chapter_number')->all());
        $stages = $novel->stages()->where('stage', Stage::Outline->value)->orderBy('id')->get();
        $this->assertCount(10, $stages);
        $this->assertCount(10, $stages[1]->input['previous_chapters']);
        $this->assertSame(PlanningJson::hash($plan->fresh()->roadmap), PlanningJson::hash($stages[9]->input['roadmap']));
        $this->assertSame(11, $novel->fresh()->execution_count);
        $this->assertSame(0, $novel->fresh()->chapters_done);
        Http::assertSentCount(10);
    }

    /** 默认五次包含设定一次，四批四十章后暂停；显式加额度后只生成剩余章节。 */
    public function test_allowance_pause_and_continuation_preserve_successful_chapters(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $novel = $confirmation->novel;
        $novel->update(['target_chapters' => 100]);
        $plan = app(OutlineGenerationService::class)->start($novel, $confirmation->id, auth()->user());
        $this->fakeBatches();
        $this->drain();
        $this->assertSame('paused', $plan->fresh()->status);
        $this->assertSame(41, $plan->fresh()->next_chapter);
        $before = $plan->chapters()->orderBy('chapter_number')->get()->map->getRawOriginal()->all();
        Queue::fake();
        $this->artisan('app:continue-outline', ['plan' => $plan->id])->assertExitCode(1);
        $this->artisan('app:continue-outline', ['plan' => $plan->id, '--max-execution-count' => '11'])->assertExitCode(1);
        $this->artisan('app:continue-outline', ['plan' => $plan->id, '--max-execution-count' => '11', '--reason' => '离线验证百章续跑'])->assertExitCode(0);
        $this->drain();
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame($before, $plan->chapters()->where('chapter_number', '<=', 40)->orderBy('chapter_number')->get()->map->getRawOriginal()->all());
        $this->assertSame(11, $plan->fresh()->control_history[1]['new_limit']);
        $this->assertNull($plan->fresh()->control_history[1]['author_id']);
        Http::assertSentCount(10);
    }

    /** 无法通过删掉问题、伪造摘要或不勾选确认来批准来源候选。 */
    public function test_confirmation_requires_source_decisions_and_explicit_acknowledgment(): void
    {
        $novel = Novel::factory()->create(['generation_mode' => GenerationMode::SettingReal, 'status' => NovelStatus::Paused, 'current_stage' => Stage::Outline, 'execution_count' => 1]);
        $setting = NovelSettingConfirmationFactory::setting();
        $setting['open_questions'] = [['question' => '师父是否存活？', 'impact' => '核心人物生死', 'blocking' => true]];
        $stage = NovelStage::factory()->create(['novel_id' => $novel->id, 'stage' => Stage::Setting, 'status' => StageStatus::Completed, 'output' => ['simulation' => false, 'data' => ['setting' => $setting]]]);
        $before = $stage->fresh()->getRawOriginal();
        $service = app(ConfirmSettingService::class);
        $draft = $service->draft($novel);
        foreach ([[], ['acknowledged' => true], ['acknowledged' => true, 'source_hash' => 'forged']] as $changes) {
            try {
                $service->confirm($novel, auth()->user(), array_replace($draft, $changes));
                $this->fail('不完整确认应被拒绝');
            } catch (ValidationException) {
                $this->assertSame(0, NovelSettingConfirmation::query()->count());
            }
        }
        $draft['answers'][0]['answer'] = '师父存活，终局与主角重逢。';
        $draft['acknowledged'] = true;
        $confirmation = $service->confirm($novel, auth()->user(), $draft);
        $this->assertSame([], $confirmation->setting['open_questions']);
        $this->assertSame($draft['answers'][0]['answer'], $confirmation->decisions[0]['answer']);
        $this->assertTrue($confirmation->hasValidHash());
        $this->assertSame($confirmation->id, $service->confirm($novel, auth()->user(), $draft)->id);
        $this->assertSame($before, $stage->fresh()->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /** 章序、推进点和文本的不合格样本整批拒绝。
     * @return array<string, array{string}>
     */
    public static function invalidBatches(): array
    {
        $missing = self::batch();
        array_pop($missing['chapters']);
        $duplicate = self::batch();
        $duplicate['chapters'][1]['chapter_number'] = 1;
        $empty = self::batch();
        $empty['chapters'][0]['progression_points'] = [];
        $four = self::batch();
        $four['chapters'][0]['progression_points'] = array_fill(0, 4, $four['chapters'][0]['progression_points'][0]);
        $same = self::batch();
        $same['chapters'][0]['progression_points'][0]['after'] = $same['chapters'][0]['progression_points'][0]['before'].'　';
        $title = self::batch();
        $title['chapters'][0]['title'] = '第1章';
        $blank = self::batch();
        $blank['chapters'][0]['hook'] = '　';
        $mood = self::batch();
        $mood['chapters'][0]['progression_points'][0]['change_type'] = 'mood';

        return ['missing chapter' => [json_encode($missing)], 'duplicate' => [json_encode($duplicate)], 'no points' => [json_encode($empty)], 'four points' => [json_encode($four)], 'same state' => [json_encode($same)], 'placeholder title' => [json_encode($title)], 'blank hook' => [json_encode($blank)], 'mood type' => [json_encode($mood)], 'broken json' => ['{"chapters":'], 'markdown wrapper' => ['```json'.json_encode(self::batch()).'```']];
    }

    /** 费用响应保存成功并不意味着大纲结构成功；失败不能写入任何候选章。 */
    #[DataProvider('invalidBatches')]
    public function test_invalid_batch_preserves_cost_and_accepts_no_chapters(string $text): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $plan = app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(B02SettingTest::response($text))]);
        $this->drain();
        $this->assertSame('error', $plan->fresh()->status);
        $this->assertSame(1, $plan->fresh()->next_chapter);
        $this->assertSame(0, $plan->chapters()->count());
        $this->assertSame($text, CostLog::query()->sole()->response_text);
        $this->assertSame('succeeded', CostLog::query()->sole()->status);
        $this->assertSame(StageStatus::Failed, $confirmation->novel->stages()->where('stage', 'outline')->sole()->status);
        Http::assertSentCount(1);
    }

    /** 一批成功后第二批未知，确认续跑只重试失败范围，不覆盖成功批次。 */
    public function test_unknown_second_batch_requires_confirmation_before_retry(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $novel = $confirmation->novel;
        $novel->update(['target_chapters' => 20]);
        $plan = app(OutlineGenerationService::class)->start($novel, $confirmation->id, auth()->user());
        Http::fakeSequence('https://api.openai.com/v1/responses')->push(B02SettingTest::response(json_encode(self::batch(1, 10, 20))))->push(['error' => ['message' => '测试服务中断']], 503)->push(B02SettingTest::response(json_encode(self::batch(11, 20, 20))));
        $this->drain();
        $this->assertSame('error', $plan->fresh()->status);
        $this->assertSame(11, $plan->fresh()->next_chapter);
        $before = $plan->chapters()->get()->map->getRawOriginal()->all();
        Queue::fake();
        $this->artisan('app:continue-outline', ['plan' => $plan->id])->assertExitCode(1)->expectsOutputToContain('--confirm-unknown');
        $this->artisan('app:continue-outline', ['plan' => $plan->id, '--confirm-unknown' => true])->assertExitCode(0);
        $this->drain();
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame(3, CostLog::query()->count());
        $this->assertSame($before, $plan->chapters()->where('chapter_number', '<=', 10)->get()->map->getRawOriginal()->all());
        $this->assertSame(4, $novel->fresh()->execution_count);
        Http::assertSentCount(3);
    }

    /** 错误关联、外部篡改和重复启动在请求前失败。 */
    public function test_cross_novel_tamper_and_duplicate_start_are_rejected(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $other = Novel::factory()->create();
        try {
            app(OutlineGenerationService::class)->start($other, $confirmation->id, auth()->user());
            $this->fail('跨书应拒绝');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $plan = app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
        try {
            app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
            $this->fail('重复应拒绝');
        } catch (ValidationException) {
            $this->assertSame(1, NovelOutlinePlan::query()->count());
        }
        $confirmation->setting = [];
        $this->expectException(LogicException::class);
        $confirmation->save();
    }

    /** 投递回执未知时，已经运行的计划不能被标记成未启动失败。 */
    public function test_dispatch_failure_does_not_overwrite_running_plan(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (RunOutlineBatchJob $job): void {
            $this->assertSame(0, DB::transactionLevel());
            NovelOutlinePlan::findOrFail($job->planId)->update(['status' => 'running']);
            throw new RuntimeException('测试回执丢失');
        });
        $plan = app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
        $this->assertSame('running', $plan->status);
        Http::assertNothingSent();
    }

    /** 后台确认和付费启动分开提交，未同意请求时绝不投递。 */
    public function test_filament_confirmation_then_explicit_paid_start(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create(['confirmed_by' => auth()->id()]);
        $novel = $confirmation->novel;
        $draft = app(ConfirmSettingService::class)->draft($novel);
        $draft['setting']['world']['background'] = '作者修订后的灯塔背景';
        $draft['acknowledged'] = true;
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('confirmSetting', ['setting.world.background' => '作者修订后的灯塔背景', 'acknowledged' => true])->assertHasNoActionErrors()->assertNotified('设定版本已确认');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $latest = $novel->settingConfirmations()->latest('id')->firstOrFail();
        $this->assertSame('作者修订后的灯塔背景', $latest->setting['world']['background']);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSee('作者修订后的灯塔背景')->callAction('generateOutline', ['confirmation_id' => $latest->id, 'accept_request' => false])->assertHasActionErrors(['accept_request' => 'accepted']);
        Queue::assertNothingPushed();
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('generateOutline', ['confirmation_id' => $latest->id, 'accept_request' => true])->assertHasNoActionErrors();
        Queue::assertPushed(RunOutlineBatchJob::class, 1);
        $this->assertSame(1, NovelOutlinePlan::query()->count());
    }

    /** 未确认来源不能通过伪造版本编号启动模型。 */
    public function test_unconfirmed_novel_is_rejected_before_any_dispatch(): void
    {
        $novel = Novel::factory()->create(['generation_mode' => GenerationMode::SettingReal, 'status' => NovelStatus::Paused, 'current_stage' => Stage::Outline]);
        try {
            app(OutlineGenerationService::class)->start($novel, 999999, auth()->user());
            $this->fail('未确认来源应拒绝');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, NovelOutlinePlan::query()->count());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** 同一请求尚未返回时另一消费者必须看到 running 并退出。 */
    public function test_overlapping_delivery_cannot_create_second_attempt(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $plan = app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
        Http::fake(['https://api.openai.com/v1/responses' => function () use ($plan) {
            app(OutlineGenerationService::class)->runBatch($plan->id, $plan->invocation_id, app(OutlineExecutor::class));
            $this->assertSame(1, CostLog::query()->count());

            return Http::response(B02SettingTest::response(json_encode(self::batch())));
        }]);
        $this->drain();
        $this->assertSame(1, $confirmation->novel->stages()->where('stage', 'outline')->count());
        Http::assertSentCount(1);
    }

    /** 接纳过程写到第五章发生数据库故障时，已写的本批章节必须全部回滚。 */
    public function test_settlement_failure_rolls_back_entire_batch_but_keeps_cost_evidence(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $plan = app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
        $this->fakeBatches();
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with($query->sql, 'insert into "novel_outline_chapters"') && ++$inserts === 5) {
                throw new RuntimeException('测试整批接纳中断');
            }
        });
        $job = Queue::pushed(RunOutlineBatchJob::class)->sole();
        try {
            $job->handle(app(OutlineGenerationService::class), app(OutlineExecutor::class));
            $this->fail('应触发本地落库故障');
        } catch (RuntimeException) {
            $job->failed(new RuntimeException('任务结算中断'));
        }
        $this->assertSame(0, $plan->chapters()->count());
        $this->assertSame(1, $plan->fresh()->next_chapter);
        $this->assertSame('error', $plan->fresh()->status);
        $this->assertSame('succeeded', CostLog::query()->sole()->status);
        $this->assertNotNull(CostLog::query()->sole()->response_text);
        $this->assertSame('unknown', $confirmation->novel->stages()->where('stage', 'outline')->sole()->output['data']['outcome']);
        Http::assertSentCount(1);
    }

    /** 初始投递故障不丢失计划，显式继续可换新令牌，旧消息不会领取新批次。 */
    public function test_unclaimed_dispatch_failure_is_resumable_and_stale_job_is_ignored(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $stale = null;
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (RunOutlineBatchJob $job) use (&$stale): void {
            $stale = $job;
            throw new RuntimeException('测试 Redis 回执失败');
        });
        $plan = app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
        $this->assertSame('error', $plan->status);
        $this->assertSame('dispatch_unconfirmed', $plan->stop_reason);
        Bus::shouldReceive('dispatch')->once()->andReturn(null);
        $resumed = app(OutlineGenerationService::class)->continue($plan->id);
        $this->assertNotSame($stale->invocationId, $resumed->invocation_id);
        $stale->handle(app(OutlineGenerationService::class), app(OutlineExecutor::class));
        $stale->failed(new RuntimeException('迟到回调'));
        $this->assertSame('pending', $plan->fresh()->status);
        $this->assertSame(0, CostLog::query()->count());
        Http::assertNothingSent();
    }

    /** 外部写入改变确认快照或来源输出，不能静默作为后续批次的新输入。 */
    public function test_tampered_confirmation_is_rejected_before_request(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        DB::table('novel_setting_confirmations')->where('id', $confirmation->id)->update(['content_hash' => str_repeat('0', 64)]);
        try {
            app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, auth()->user());
            $this->fail('篡改摘要应拒绝');
        } catch (ValidationException) {
            $this->assertSame(0, NovelOutlinePlan::query()->count());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** 实际后台重复列表可提交问题答案，不能通过省略问题身份绕过阻塞校验。 */
    public function test_filament_blocking_question_answer_is_saved_with_source_identity(): void
    {
        $novel = Novel::factory()->create(['generation_mode' => GenerationMode::SettingReal, 'status' => NovelStatus::Paused, 'current_stage' => Stage::Outline]);
        $setting = NovelSettingConfirmationFactory::setting();
        $setting['open_questions'] = [['question' => '师父是否存活？', 'impact' => '影响结局', 'blocking' => true]];
        NovelStage::factory()->create(['novel_id' => $novel->id, 'stage' => Stage::Setting, 'status' => StageStatus::Completed, 'output' => ['simulation' => false, 'data' => ['setting' => $setting]]]);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('confirmSetting', ['answers' => [['answer' => '师父存活并在结局重逢。']], 'acknowledged' => true])->assertHasNoActionErrors()->assertNotified('设定版本已确认');
        $confirmation = $novel->settingConfirmations()->sole();
        $this->assertSame('师父是否存活？', $confirmation->decisions[0]['question']);
        $this->assertSame('师父存活并在结局重逢。', $confirmation->decisions[0]['answer']);
        $this->assertSame([], $confirmation->setting['open_questions']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** 已保存作者决定时迁移回滚必须拒绝，不能删除确认历史。 */
    public function test_migration_rollback_rejects_saved_confirmation_history(): void
    {
        NovelSettingConfirmation::factory()->create();
        $migration = require database_path('migrations/2026_11_03_190822_create_b03_planning_tables.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('拒绝删除 B03 历史');
        $migration->down();
    }

    /** B08批次在途暂停保留十章，继续时只生成剩余章并解除暂停标志。 */
    public function test_b08_inflight_pause_preserves_batch_and_continues(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create(['confirmed_by' => auth()->id()]);
        $novel = $confirmation->novel;
        $novel->update(['target_chapters' => 11, 'max_execution_count' => 10]);
        $service = app(OutlineGenerationService::class);
        $plan = $service->start($novel, $confirmation->id, auth()->user());
        Http::fake(['*' => function (Request $request) use ($novel) {
            app(RunControlService::class)->pause($novel->id, auth()->user(), 'B08在途大纲暂停');

            return Http::response(B02SettingTest::response(json_encode(self::batch(1, 10, 11))));
        }]);
        $service->runBatch($plan->id, $plan->invocation_id, app(OutlineExecutor::class));
        $this->assertSame('user_paused', $plan->fresh()->stop_reason);
        $this->assertCount(10, $plan->chapters()->get());
        $before = $plan->chapters()->get()->map->getRawOriginal()->all();
        $service->continue($plan->id, authorId: auth()->id());
        $this->assertFalse($novel->fresh()->pause_requested);
        $this->assertSame($before, $plan->chapters()->get()->map->getRawOriginal()->all());
        $this->assertSame(11, $plan->fresh()->next_chapter);
        Http::assertSentCount(1);
    }
}
