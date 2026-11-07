<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewNovelCosts;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\CostLedgerExport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** 筛选账单不泄漏跨书数据或原始请求，并保留未知费用。 */
class C09CostExportTest extends TestCase
{
    use DatabaseMigrations;

    /** 隔离测试库同时包含应用设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 字段白名单排除正文、请求与错误原文，不把null变成零。 */
    public function test_export_is_scoped_redacted_and_preserves_unknown(): void
    {
        $novel = Novel::factory()->create();
        $log = CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'billing_status' => 'unknown', 'response_text' => '私有正文', 'error_message' => '私有错误', 'request_parameters' => ['private' => '不得导出']]);
        CostLog::factory()->create(['novel_id' => Novel::factory()->create()->id]);
        $payload = app(CostLedgerExport::class)->payload($novel->id, CostLog::query());
        $this->assertCount(1, $payload['records']);
        $this->assertSame($log->id, $payload['records'][0]['id']);
        $this->assertNull($payload['records'][0]['actual_cost']);
        $this->assertSame('unknown', $payload['records'][0]['billing_status']);
        $this->assertStringNotContainsString('私有', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('request_parameters', $payload['records'][0]);
    }

    /** 后台真实动作下载当前筛选，不发模型请求、不修改账单。 */
    public function test_filtered_download_action(): void
    {
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $novel = Novel::factory()->create();
        $unknown = CostLog::factory()->create(['novel_id' => $novel->id, 'billing_status' => 'unknown']);
        $simulated = CostLog::factory()->create(['novel_id' => $novel->id]);
        Livewire::test(ViewNovelCosts::class, ['record' => $novel->id])->filterTable('billing_status', 'unknown')
            ->assertCanSeeTableRecords([$unknown])->assertCanNotSeeTableRecords([$simulated])
            ->callAction('exportCosts')->assertHasNoActionErrors()->assertFileDownloaded('novel-'.$novel->id.'-costs.json');
        $this->assertSame(2, CostLog::count());
        Http::assertNothingSent();
    }
}
