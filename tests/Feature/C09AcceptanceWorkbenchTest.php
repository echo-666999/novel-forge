<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\AcceptanceWorkbench as WorkbenchPage;
use App\Models\AcceptanceBatch;
use App\Models\AcceptanceEvidence;
use App\Models\CanonicalCommit;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\User;
use App\Services\Pipeline\AcceptanceWorkbench;
use App\Services\Pipeline\PlanningJson;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 验收记录与正式状态隔离，机器通过绝不自动认可。 */
class C09AcceptanceWorkbenchTest extends TestCase
{
    use DatabaseMigrations;

    /** 测试使用隔离内存库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 仅清理内存验收夹具，正式历史迁移保护保持有效。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('acceptance_evidence')->delete();
        DB::table('acceptance_batches')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 构造一章可验证来源，数据库修改仅用于内存夹具。 */
    private function batch(): array
    {
        Http::preventStrayRequests();
        [$novel] = C02StoryStateTest::history();
        $novel->update(['chapters_done' => 6, 'state_version' => 6]);
        $version = ChapterVersion::whereHas('chapter', fn ($q) => $q->where('novel_id', $novel->id)->where('number', 1))->firstOrFail();
        $run = ChapterGenerationRun::findOrFail($version->run_id);
        $snapshot = $run->snapshot;
        $snapshot['novel']['chapter_length_policy'] = ['min_characters' => 1, 'max_characters' => 1000];
        DB::table('chapter_generation_runs')->where('id', $run->id)->update(['snapshot' => json_encode($snapshot), 'input_hash' => PlanningJson::hash([$snapshot, $run->config])]);
        $commit = CanonicalCommit::where('version_id', $version->id)->firstOrFail();
        $validation = ChapterSubmissionValidation::findOrFail($commit->validation_id);
        DB::table('chapter_submission_validations')->where('id', $validation->id)->update(['verdict' => 'pass']);
        DB::table('chapter_reviews')->where('id', $validation->review_id)->update(['verdict' => 'pass']);
        $actor = User::factory()->create();
        $batch = app(AcceptanceWorkbench::class)->create($novel->id, $actor, 'recovery', 1, '隔离测试，不代表真实作品通过');

        return [$novel, $actor, $batch, $version];
    }

    /** 机器通过仍等待人工，缺少证据拒绝关闭。 */
    public function test_machine_pass_cannot_accept_batch(): void
    {
        [$novel, $actor, $batch] = $this->batch();
        $service = app(AcceptanceWorkbench::class);
        $result = $service->machine($novel->id, $batch->id, $actor);
        $this->assertTrue($result->payload['pass']);
        $this->assertSame('human_pending', $batch->fresh()->status);
        $this->assertCount(6, $service->gaps($batch));
        $this->expectException(ValidationException::class);
        $service->close($novel->id, $batch->id, $actor, '不应绕过证据', true);
    }

    /** 来源摘录和逐项低分说明必填，非法输入不能留下认可记录。 */
    public function test_invalid_quote_is_rejected(): void
    {
        [$novel, $actor, $batch] = $this->batch();
        $this->expectException(ValidationException::class);
        app(AcceptanceWorkbench::class)->rate($novel->id, $batch->id, $actor, $this->rating('不存在的原文'));
    }

    /** 低于最低分时必须说明修复期待，不会自动舍弃失败输入。 */
    public function test_low_score_requires_repair_expectation(): void
    {
        [$novel, $actor, $batch, $version] = $this->batch();
        $data = $this->rating($version->body);
        $data['scores']['plot'] = 2;
        $this->expectException(ValidationException::class);
        app(AcceptanceWorkbench::class)->rate($novel->id, $batch->id, $actor, $data);
    }

    /** 模型更新入口不能修改批次契约，防止事后降低阈值。 */
    public function test_frozen_policy_is_immutable(): void
    {
        [$novel, $actor, $batch] = $this->batch();
        $snapshot = $batch->snapshot;
        $snapshot['rating_policy']['minimum'] = 1;
        $this->expectException(\LogicException::class);
        $batch->update(['snapshot' => $snapshot]);
    }

    /** 只有完整人工证据和明确关闭才认可，失败评分历史仍可导出。 */
    public function test_complete_evidence_requires_explicit_close_and_preserves_failed_score(): void
    {
        [$novel, $actor, $batch, $version] = $this->batch();
        $service = app(AcceptanceWorkbench::class);
        $service->machine($novel->id, $batch->id, $actor);
        $bad = $this->rating($version->body);
        $bad['scores']['plot'] = 2;
        $bad['repair'] = '补充可见行动';
        $service->rate($novel->id, $batch->id, $actor, $bad);
        $this->assertSame('rejected', $batch->fresh()->status);
        foreach ($batch->snapshot['manual_cases'] as $key => $label) {
            $service->import($novel->id, $batch->id, $actor, ['case_key' => $key, 'result' => 'pass', 'source' => '内存夹具', 'report' => '隔离报告', 'reason' => '协议测试', 'verified' => true]);
        }
        $this->assertNotEmpty($service->gaps($batch));
        $service->rate($novel->id, $batch->id, $actor, $this->rating($version->body));
        $this->assertSame([], $service->gaps($batch));
        $this->assertSame('system_pass', $batch->fresh()->status);
        $service->close($novel->id, $batch->id, $actor, '隔离明确认可', true);
        $this->assertSame('accepted', $batch->fresh()->status);
        $bundle = $service->export($novel->id, $batch->id);
        $this->assertCount(9, $bundle['evidence']);
        $this->assertFalse($bundle['evidence'][1]['payload']['pass']);
        $this->assertStringContainsString($version->body, $bundle['texts']['txt']['content']);
        $this->assertSame(0, CostLog::count());
        Http::assertNothingSent();
        $this->expectException(ValidationException::class);
        $service->rate($novel->id, $batch->id, $actor, $this->rating($version->body));
    }

    /** 跨账号记录与正文篡改都不能获取验收通过。 */
    public function test_other_account_cannot_write(): void
    {
        [$novel, $actor, $batch] = $this->batch();
        $this->expectException(ValidationException::class);
        app(AcceptanceWorkbench::class)->machine($novel->id, $batch->id, User::factory()->create());
    }

    /** 正式来源损坏不再生成证据包。 */
    public function test_source_corruption_blocks_export(): void
    {
        [$novel, $actor, $batch, $version] = $this->batch();
        DB::table('chapter_versions')->where('id', $version->id)->update(['body' => '损坏']);
        $this->expectException(ValidationException::class);
        app(AcceptanceWorkbench::class)->export($novel->id, $batch->id);
    }

    /** 后台动作实际执行机器检查、保持待人工并下载冻结证据。 */
    public function test_page_actions_and_download(): void
    {
        [$novel, $actor, $batch] = $this->batch();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);
        Livewire::test(WorkbenchPage::class, ['record' => $novel->id])
            ->callTableAction('machine', $batch)->assertHasNoTableActionErrors()
            ->callTableAction('export', $batch)->assertHasNoTableActionErrors()->assertFileDownloaded('acceptance-'.$batch->id.'.json');
        $this->assertSame(1, AcceptanceBatch::count());
        $this->assertSame(1, AcceptanceEvidence::count());
    }

    /** 评分弹窗错误可见，保留低分输入，修正后保存未通过评价而不是拒绝低分。 */
    public function test_rating_modal_displays_errors_and_saves_low_scores(): void
    {
        [$novel, $actor, $batch, $version] = $this->batch();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);
        $data = $this->rating('不是该章原文');
        $data['scores']['naturalness'] = 1;
        $data['scores']['appeal'] = 1;
        $data['repair'] = '期待像一篇人工写的网络小说';
        $data['blocking'] = '0';
        $page = Livewire::test(WorkbenchPage::class, ['record' => $novel->id])
            ->callTableAction('rate', $batch, $data);
        $page->assertHasTableActionErrors(['quote'])
            ->assertNotified('人工评分未保存')
            ->assertSet('mountedActions.0.data.scores.naturalness', 1);
        $this->assertSame(0, AcceptanceEvidence::count());
        // 两处原文使用空行分隔，并模拟浏览器粘贴产生的空格和换行。
        $quote = mb_substr($version->body, 0, 5)."\n\n".mb_substr($version->body, -5);
        $page->fillForm(['quote' => " \r\n".$quote."\r\n ", 'repair' => ''], 'mountedActionSchema0')
            ->callMountedAction()->assertHasTableActionErrors(['repair'])->assertNotified('人工评分未保存');
        $page->fillForm(['repair' => $data['repair']], 'mountedActionSchema0')->callMountedAction()
            ->assertHasNoTableActionErrors()->assertNotified('人工评分已追加，未自动认可');
        $evidence = AcceptanceEvidence::sole();
        $this->assertSame(1, $evidence->payload['scores']['naturalness']);
        $this->assertFalse($evidence->payload['pass']);
        $this->assertSame('rejected', $batch->fresh()->status);
        $this->assertSame(0, CostLog::count());
    }

    /** 每项评分均显式填写，不使用界面默认通过分。 */
    private function rating(string $quote): array
    {
        return ['chapter' => 1, 'scores' => array_fill_keys(array_keys(AcceptanceWorkbench::DIMENSIONS), 4), 'quote' => $quote,
            'reason' => '隔离逐章评分', 'repair' => '', 'blocking' => false, 'read_confirmed' => true];
    }
}
