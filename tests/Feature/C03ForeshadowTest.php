<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewForeshadows;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\Foreshadow;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\ForeshadowLedger;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** 伏笔证据、期限注入与后台操作的隔离验收，不发送真实模型请求。 */
class C03ForeshadowTest extends TestCase
{
    use DatabaseMigrations;

    /** 业务及设置迁移仅在内存测试库执行。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 后台登录与外网保护。 */
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    /** 清理隔离数据以满足不可回滚历史保护。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 构造指定小说的正式来源关系，不伪称生成验收。
     * @return array{0: Novel, 1: ChapterVersion}
     */
    private function formal(?Novel $novel = null, int $number = 1): array
    {
        $novel ??= Novel::factory()->create(['target_chapters' => 10]);
        $chapter = Chapter::factory()->create(['novel_id' => $novel->id, 'number' => $number]);
        $run = ChapterGenerationRun::factory()->create(['chapter_id' => $chapter->id]);
        $body = "桌上放着一封旧信。\n信中写着旧店的地址。";
        $version = ChapterVersion::factory()->create(['run_id' => $run->id, 'body' => $body, 'body_hash' => hash('sha256', $body)]);
        $submission = ChapterSubmissionRun::factory()->create(['version_id' => $version->id]);
        $validation = ChapterSubmissionValidation::factory()->create(['submission_run_id' => $submission->id]);
        $commit = CanonicalCommit::factory()->create(['validation_id' => $validation->id, 'previous_state_version' => $number - 1, 'state_version' => $number, 'result' => ['body_hash' => $version->body_hash]]);
        $chapter->update(['formal_version_id' => $version->id, 'canonical_commit_id' => $commit->id]);

        return [$novel, $version];
    }

    /** 三条伏笔在前五章登记，强化与回收不污染早期视图，逾期持续提醒。 */
    public function test_three_plans_flow_with_formal_evidence_and_overdue_context(): void
    {
        [$novel, $first] = $this->formal();
        [, $fifth] = $this->formal($novel, 5);
        $service = app(ForeshadowLedger::class);
        $before = DB::table('canonical_commits')->get()->toJson();
        foreach (range(1, 3) as $i) {
            $plan = $service->plan($novel, auth()->user(), '旧信'.$i, '查明旧信来源', 1, 4, (string) Str::uuid7());
            $service->record($plan, auth()->user(), 0, 'planted', $first->id, 1, '一封旧信', '原文出现信件', (string) Str::uuid7());
        }
        $this->assertCount(3, $service->context($novel, 2)['items']);
        $due = $service->context($novel, 4)['items'];
        $this->assertSame([true, true, true], array_column($due, 'must_address'));
        $this->assertSame(['resolve', 'resolve', 'resolve'], array_column($due, 'obligation'));
        $service->record($plan, auth()->user(), 1, 'reinforced', $fifth->id, 2, '旧店的地址', '补充线索', (string) Str::uuid7());
        $service->record($plan, auth()->user(), 2, 'resolved', $fifth->id, 2, '旧店的地址', '隔离测试回收依据', (string) Str::uuid7());
        $this->assertCount(3, $service->context($novel, 5)['items']);
        $this->assertCount(2, $service->context($novel, 6)['items']);
        $this->assertTrue($service->context($novel, 9)['items'][0]['must_address']);
        $this->assertSame($before, DB::table('canonical_commits')->get()->toJson());
        $this->assertDatabaseCount('cost_logs', 0);
        Http::assertNothingSent();
    }

    /** 同一请求可重放，重复键不同输入、非法跳转与过期修订均拒绝。 */
    public function test_idempotency_and_invalid_transitions(): void
    {
        [$novel, $version] = $this->formal();
        $service = app(ForeshadowLedger::class);
        $key = (string) Str::uuid7();
        $plan = $service->plan($novel, auth()->user(), '旧信', '回收信件来源', 1, 4, $key);
        $this->assertSame($plan->id, $service->plan($novel, auth()->user(), '旧信', '回收信件来源', 1, 4, $key)->id);
        $recordKey = (string) Str::uuid7();
        $entry = $service->record($plan, auth()->user(), 0, 'planted', $version->id, 1, '旧信', '出现', $recordKey);
        $this->assertSame($entry->id, $service->record($plan, auth()->user(), 0, 'planted', $version->id, 1, '旧信', '出现', $recordKey)->id);
        foreach ([
            fn () => $service->plan($novel, auth()->user(), '另一个标题', '回收信件来源', 1, 4, $key),
            fn () => $service->record($plan, auth()->user(), 0, 'resolved', $version->id, 1, '旧信', '过期修订', (string) Str::uuid7()),
            fn () => $service->record($plan, auth()->user(), 1, 'planted', $version->id, 1, '旧信', '重复埋设', (string) Str::uuid7()),
            fn () => $service->record($plan, auth()->user(), 1, 'resolved', $version->id, 1, '伪造内容', '无来源', (string) Str::uuid7()),
        ] as $operation) {
            try {
                $operation();
                $this->fail('无效请求未拒绝');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('foreshadow_evidence', 1);
            }
        }
        $this->expectException(\LogicException::class);
        $entry->update(['reason' => '覆盖']);
    }

    /** 候选章节存在时冻结账本，避免输入创建后被另一次编辑改变。 */
    public function test_active_candidate_blocks_ledger_changes(): void
    {
        $novel = Novel::factory()->create();
        Chapter::factory()->create(['novel_id' => $novel->id]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('存在未正式提交章节');
        app(ForeshadowLedger::class)->plan($novel, auth()->user(), '旧信', '来源', 1, 4, (string) Str::uuid7());
    }

    /** 后台可创建计划、查看正式原文及章节链接，其他小说不可见。 */
    public function test_page_creates_plan_and_exposes_scoped_formal_links(): void
    {
        [$novel, $version] = $this->formal();
        Livewire::test(ViewForeshadows::class, ['record' => $novel->id])->callAction('createPlan', data: ['key' => (string) Str::uuid7(), 'title' => '信封上的地址', 'description' => '查明地址', 'plant' => 1, 'target' => 4])->assertRedirect();
        $plan = Foreshadow::firstOrFail();
        // 实际后台动作负责提交证据，不能只验证服务后展示已有数据。
        Livewire::test(ViewForeshadows::class, ['record' => $novel->id])->callTableAction('recordEvidence', $plan, data: ['revision' => 0, 'key' => (string) Str::uuid7(), 'status' => 'planted', 'version' => $version->id, 'paragraph' => 1, 'quote' => '一封旧信', 'reason' => '正式正文证据'])->assertHasNoTableActionErrors()->assertRedirect();
        $this->assertSame('planted', $plan->fresh()->status);
        $page = Livewire::test(ViewForeshadows::class, ['record' => $novel->id])->assertSee('信封上的地址')->mountTableAction('inspectEvidence', $plan);
        $modal = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('一封旧信', $modal);
        $this->assertStringContainsString('正式正文证据', $modal);
        $this->assertStringContainsString(NovelResource::getUrl('state', ['record' => $novel, 'chapter' => 1]), $modal);
        $other = Novel::factory()->create();
        Livewire::test(ViewForeshadows::class, ['record' => $other->id])->assertDontSee('信封上的地址');
        auth()->logout();
        $this->get(NovelResource::getUrl('foreshadows', ['record' => $novel]))->assertRedirect();
    }

    /** 即使来源是其他书的正式正文，也不能登记到当前小说。 */
    public function test_cross_novel_formal_version_is_rejected(): void
    {
        [$novel] = $this->formal();
        [, $foreign] = $this->formal();
        $service = app(ForeshadowLedger::class);
        $plan = $service->plan($novel, auth()->user(), '旧信', '来源', 1, 4, (string) Str::uuid7());
        $this->expectException(ModelNotFoundException::class);
        $service->record($plan, auth()->user(), 0, 'planted', $foreign->id, 1, '旧信', '跨书', (string) Str::uuid7());
    }

    /** 账本证据被底层破坏时停止注入，不能将假原文交给后续生成。 */
    public function test_corrupt_evidence_is_rejected_before_context_injection(): void
    {
        [$novel, $version] = $this->formal();
        $service = app(ForeshadowLedger::class);
        $plan = $service->plan($novel, auth()->user(), '旧信', '来源', 1, 4, (string) Str::uuid7());
        $entry = $service->record($plan, auth()->user(), 0, 'planted', $version->id, 1, '旧信', '出现', (string) Str::uuid7());
        DB::table('foreshadow_evidence')->where('id', $entry->id)->update(['evidence' => json_encode(['paragraph' => 1, 'start' => 0, 'end' => 2, 'quote' => '伪造'])]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('伏笔证据已损坏');
        $service->context($novel, 2);
    }
}
