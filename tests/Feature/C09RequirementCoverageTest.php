<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\Requirements;
use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\RequirementVersion;
use App\Models\User;
use App\Services\Pipeline\RequirementCoverage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 要求版本、作者决定及计划证据必须保持隔离且可复核。 */
class C09RequirementCoverageTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移隔离内存库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 仅清除隔离夹具，迁移保护不绕过真实数据。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('requirement_versions')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 确认是必填，硬要求不允许静默豁免。 */
    public function test_waiver_requires_explicit_author_acknowledgement(): void
    {
        $novel = Novel::factory()->create();
        $this->expectException(ValidationException::class);
        app(RequirementCoverage::class)->save($novel->id, User::factory()->create(), $this->input($novel, ['status' => 'waived', 'acknowledged' => false]));
    }

    /** 修改只新增版本，旧版原样保留，过期编辑拒绝。 */
    public function test_revision_preserves_history_and_rejects_stale_edit(): void
    {
        $novel = Novel::factory()->create();
        $actor = User::factory()->create();
        $service = app(RequirementCoverage::class);
        $first = $service->save($novel->id, $actor, $this->input($novel));
        $next = $service->save($novel->id, $actor, $this->input($novel, ['description' => '新提炼', 'status' => 'waived']), $first->id);
        $this->assertSame(2, $next->revision);
        $this->assertSame($first->requirement_key, $next->requirement_key);
        $this->assertSame('unresolved', $first->fresh()->status);
        $this->assertSame(2, RequirementVersion::count());
        $this->expectException(ValidationException::class);
        $service->save($novel->id, $actor, $this->input($novel), $first->id);
    }

    /** 覆盖只接受同书计划原句，并冻结计划摘要。 */
    public function test_coverage_requires_same_novel_and_exact_plan_evidence(): void
    {
        $plan = NovelOutlineChapter::factory()->create();
        $novel = $plan->plan->novel;
        $actor = User::factory()->create();
        $service = app(RequirementCoverage::class);
        $input = $this->input($novel, ['status' => 'covered', 'outline_chapter_id' => $plan->id, 'evidence_quote' => $plan->objective]);
        $version = $service->save($novel->id, $actor, $input);
        $this->assertSame($plan->id, $version->outline_chapter_id);
        $this->assertSame($plan->objective, $version->evidence_quote);
        $this->assertSame(64, strlen($version->evidence_hash));
        $other = Novel::factory()->create();
        $this->expectException(ValidationException::class);
        $service->save($other->id, $actor, [...$input, 'source_quote' => $other->premise_raw]);
    }

    /** 来源原句不能由模型或作者凭空补写。 */
    public function test_fabricated_source_is_rejected(): void
    {
        $novel = Novel::factory()->create();
        $this->expectException(ValidationException::class);
        app(RequirementCoverage::class)->save($novel->id, User::factory()->create(), $this->input($novel, ['source_quote' => '不在创意里的内容']));
    }

    /** 全部历史按要求归集，筛选到当前版仍保留真实版本号。 */
    public function test_history_keeps_requirement_identity_and_revision_order(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $novel = Novel::factory()->create();
        $service = app(RequirementCoverage::class);
        $first = $service->save($novel->id, $actor, $this->input($novel));
        $other = $service->save($novel->id, $actor, $this->input($novel, ['description' => '另一项要求']));
        $next = $service->save($novel->id, $actor, $this->input($novel, ['description' => '修订要求']), $first->id);
        $expected = $first->requirement_key < $other->requirement_key ? [$next, $first, $other] : [$other, $next, $first];
        $page = Livewire::test(Requirements::class, ['record' => $novel->id])
            ->assertSee('要求标识')->assertSee('记录编号')->assertSee('V2')
            ->assertCanNotSeeTableRecords([$first])
            ->filterTable('versions', 'all')->assertCanSeeTableRecords($expected, inOrder: true);
        $page->filterTable('versions', 'latest')->assertCanSeeTableRecords([$next, $other])->assertCanNotSeeTableRecords([$first])->assertSee('V2');
    }

    /** 构造明确作者确认的测试输入。 */
    private function input(Novel $novel, array $changes = []): array
    {
        return array_replace(['source_quote' => $novel->premise_raw, 'description' => '保留要求', 'category' => '剧情', 'strength' => 'hard', 'status' => 'unresolved', 'reason' => '作者复核', 'acknowledged' => true], $changes);
    }
}
