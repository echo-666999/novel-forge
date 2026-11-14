<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewNovelCosts;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\NovelCostSummary;
use App\Services\Pipeline\NovelPipelineService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** 隔离验证成本精度、未知值、币种边界与后台只读；HTTP全部为受控夹具。 */
class B09CostDisplayTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移项目表，避免访问开发库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 使用后台工厂账号，拒绝任何未声明的外网请求。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local', 'ai.providers.openai.key' => 'sk-b09-offline', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    /** 实际费用优先、估算不重复计入；失败照计，币种隔离，微小金额保持精确。 */
    public function test_currency_totals_preserve_precision_and_do_not_double_count(): void
    {
        $novel = Novel::factory()->create();
        $base = ['novel_id' => $novel->id, 'mode' => 'real', 'currency' => 'USD', 'status' => 'succeeded', 'billing_status' => 'estimated'];
        CostLog::factory()->create([...$base, 'actual_cost' => '0.10000001', 'estimated_cost' => '99.00000000']);
        CostLog::factory()->create([...$base, 'status' => 'failed', 'estimated_cost' => '0.20000002']);
        CostLog::factory()->create([...$base, 'estimated_cost' => '0.00000001']);
        CostLog::factory()->create([...$base, 'currency' => 'CNY', 'estimated_cost' => '2.30000000']);
        CostLog::factory()->create([...$base, 'currency' => null, 'billing_status' => 'unknown', 'status' => 'unknown']);
        CostLog::factory()->create(['novel_id' => $novel->id, 'actual_cost' => '100.00000000']);
        CostLog::factory()->create([...$base, 'novel_id' => Novel::factory()->create()->id, 'estimated_cost' => '500.00000000']);
        $summary = app(NovelCostSummary::class)->forNovel($novel->id);
        $this->assertSame(5, $summary['real_requests']);
        $this->assertSame(1, $summary['simulated_requests']);
        $this->assertSame(1, $summary['unknown_amounts']);
        $this->assertSame([
            ['currency' => 'CNY', 'actual' => '0.00000000', 'estimated' => '2.30000000', 'actual_count' => 0, 'estimated_count' => 1],
            ['currency' => 'USD', 'actual' => '0.10000001', 'estimated' => '0.20000003', 'actual_count' => 1, 'estimated_count' => 2],
        ], $summary['currencies']);
        Http::assertNothingSent();
    }

    /** 全未知、无调用和已知零费用明确区分，刷新不能写账或发送请求。 */
    public function test_unknown_and_zero_amounts_have_distinct_read_only_display(): void
    {
        $novel = Novel::factory()->create();
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSee('暂无已知金额')->assertSee('真实请求 0 次');
        $log = CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'billing_status' => 'unknown']);
        $before = $log->refresh()->getRawOriginal();
        Livewire::test(ViewNovelCosts::class, ['record' => $novel->id])->assertSee('金额未知 1 次')->assertSee('待返回 1 次')->callAction('refreshCosts')->assertSuccessful();
        $this->assertSame($before, $log->fresh()->getRawOriginal());
        CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'billing_status' => 'estimated', 'currency' => 'USD', 'estimated_cost' => '0.00000000']);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSee('未结算估算 0.00000000')->assertSee('金额未知 1 次');
        Http::assertNothingSent();
    }

    /** 分页筛选只改变明细，不改变全小说汇总，也不泄漏其他小说记录。 */
    public function test_cost_page_is_scoped_and_filter_does_not_change_total(): void
    {
        $novel = Novel::factory()->create();
        $mine = CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'status' => 'failed']);
        $foreign = CostLog::factory()->create(['novel_id' => Novel::factory()->create()->id, 'requested_model' => 'foreign-private-model']);
        Livewire::test(ViewNovelCosts::class, ['record' => $novel->id])->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$foreign])
            ->assertDontSee('foreign-private-model')->filterTable('status', 'succeeded')->assertCanNotSeeTableRecords([$mine])->assertSee('真实请求 1 次');
        Http::assertNothingSent();
    }

    /** 实际SDK路径连续记录多阶段调用；价格升高或未知都不阻断后续生成。 */
    public function test_cost_growth_and_unknown_prices_do_not_block_generation(): void
    {
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        Http::fake(['*' => Http::response(B02SettingTest::response('隔离成本响应'))]);
        $service = app(AiTextGenerationService::class);
        config(['ai.costs.prices' => ['gpt-4.1-mini-2025-04-14' => ['version' => 'b09-test', 'currency' => 'USD', 'input_per_million' => '10000000', 'output_per_million' => '10000000']]]);
        foreach (range(1, 2) as $i) {
            $stage = NovelStage::factory()->for($novel)->create();
            $log = $service->generate('隔离计费验收'.$i, 'real', 'gpt-4.1-mini', novelStageId: $stage->id);
            $this->assertSame('succeeded', $log->status);
            $this->assertSame('5800.00000000', $log->estimated_cost);
            $this->assertSame(bcmul('5800', (string) $i, 8), app(NovelCostSummary::class)->forNovel($novel->id)['currencies'][0]['estimated']);
        }
        config(['ai.costs.prices' => []]);
        $this->assertSame('succeeded', $service->generate('未知价格仍继续', 'real', 'gpt-4.1-mini', novelId: $novel->id)->status);
        $summary = app(NovelCostSummary::class)->forNovel($novel->id);
        $this->assertSame(3, $summary['real_requests']);
        $this->assertSame(1, $summary['unknown_amounts']);
        Http::assertSentCount(3);
        // 巨额已有成本不会影响独立模拟流水线的状态机判断。
        $another = Novel::factory()->create(['max_execution_count' => 6]);
        CostLog::factory()->create(['novel_id' => $another->id, 'mode' => 'real', 'actual_cost' => '999999999999.00000000', 'currency' => 'USD']);
        $this->assertSame('completed', app(NovelPipelineService::class)->run($another, true)->reason);
    }

    /** 汇总为一行标签；明细默认只显示时间、模型、结果、计费状态与合并后的费用，编号与模式列默认隐藏。 */
    public function test_cost_page_shows_summary_badges_and_compact_columns(): void
    {
        $novel = Novel::factory()->create();
        CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'status' => 'succeeded', 'billing_status' => 'estimated', 'currency' => 'CNY', 'estimated_cost' => '0.12000000']);
        Livewire::test(ViewNovelCosts::class, ['record' => $novel->id])->assertSee('真实请求 1 次')->assertSee('CNY 未结算估算 0.12000000')
            ->assertSee('约 0.12000000 CNY')->assertCanRenderTableColumn('cost')->assertCanNotRenderTableColumn('id')->assertCanNotRenderTableColumn('mode')->assertCanNotRenderTableColumn('novel_stage_id');
        Http::assertNothingSent();
    }
}
