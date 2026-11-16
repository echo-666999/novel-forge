<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ScenePlans;
use App\Models\CostLog;
use App\Models\NovelOutlineChapter;
use App\Models\ScenePlanVersion;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ScenePlanning;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 场景规划校验、历史隔离及真实程序入口的无网络验收。 */
class C09ScenePlanningTest extends TestCase
{
    use DatabaseMigrations;

    /** 设置迁移与配置迁移使用同一隔离数据库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 清除内存夹具，避免保护性回滚删除真实历史。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('scene_plan_versions')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 场景在实际调用前快照中冻结，后来草稿不会混入请求。 */
    public function test_frozen_scenes_reach_real_request_path(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['message' => 'offline rejection']], 400)]);
        config(['ai.providers.openai.key' => 'offline-c09', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        $novel = B04ContentTest::readyNovel();
        $outline = NovelOutlineChapter::firstOrFail();
        $actor = User::factory()->create();
        $service = app(ScenePlanning::class);
        $draft = $service->save($novel->id, $outline->id, $actor, $this->scenes($outline), false, '草稿', null);
        $this->assertNull($service->context($novel, $outline));
        $frozen = $service->save($novel->id, $outline->id, $actor, $this->scenes($outline), true, '冻结', $draft->id);
        $run = app(ContentGenerationService::class)->start($novel, $actor, 8192, 5, '隔离验收');
        $this->assertSame($frozen->id, $run->snapshot['author_scene_plan']['version_id']);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $prompt = CostLog::latest('id')->firstOrFail()->request_parameters['prompt_audit']['user_prompt'];
        $this->assertStringContainsString('author_scene_plan', $prompt);
        $this->assertStringContainsString('检修电表', $prompt);
        Http::assertSentCount(1);
        $this->expectException(ValidationException::class);
        $service->save($novel->id, $outline->id, $actor, $this->scenes($outline), false, '不能改旧运行', $frozen->id);
    }

    /** 缺覆盖、未知点、重复目标和字数超限均不能冻结；草稿可保留缺口。 */
    public function test_invalid_scenes_report_each_gap(): void
    {
        $novel = B04ContentTest::readyNovel();
        $outline = NovelOutlineChapter::firstOrFail();
        $scenes = $this->scenes($outline);
        $scenes[0]['point_ids'] = ['unknown'];
        $scenes[0]['words'] = 9999;
        $scenes[] = $scenes[0];
        $service = app(ScenePlanning::class);
        $this->assertCount(4, $service->issues($novel, $outline, $scenes));
        $actor = User::factory()->create();
        $draft = $service->save($novel->id, $outline->id, $actor, $scenes, false, '待修正', null);
        $this->expectException(ValidationException::class);
        $service->save($novel->id, $outline->id, $actor, $scenes, true, '错误冻结', $draft->id);
    }

    /** 并发编辑不能覆盖新版本。 */
    public function test_stale_editor_is_rejected(): void
    {
        $novel = B04ContentTest::readyNovel();
        $outline = NovelOutlineChapter::firstOrFail();
        $actor = User::factory()->create();
        app(ScenePlanning::class)->save($novel->id, $outline->id, $actor, $this->scenes($outline), false, '第一版', null);
        $this->expectException(ValidationException::class);
        app(ScenePlanning::class)->save($novel->id, $outline->id, $actor, $this->scenes($outline), false, '过期', null);
    }

    /** 实际表单保存草稿和冻结版本；旧版本不覆盖，生成前可反复编辑。 */
    public function test_page_saves_versioned_scene_list(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $novel = B04ContentTest::readyNovel();
        $outline = NovelOutlineChapter::firstOrFail();
        Livewire::test(ScenePlans::class, ['record' => $novel->id])->callAction('create', ['outline_chapter_id' => $outline->id, 'scenes' => $this->scenes($outline), 'reason' => '新增场景', 'freeze' => true, 'acknowledged' => true])->assertHasNoActionErrors()->assertNotified('场景规划新版本已保存');
        $this->assertSame('frozen', ScenePlanVersion::firstOrFail()->status);
    }

    /** 列表说明每行是一章大纲，状态用中文标明是否进入生成，而不是显示内部状态码。 */
    public function test_page_explains_optional_scene_status(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $novel = B04ContentTest::readyNovel();
        $outline = NovelOutlineChapter::firstOrFail();
        // 没有任何场景规划时默认列表为空，不再重复列出全部章节大纲。
        $page = Livewire::test(ScenePlans::class, ['record' => $novel->id])->assertSee('尚无场景规划')->assertCanNotSeeTableRecords([$outline])->assertActionVisible('create');
        $page->filterTable('scene_plan', null)->assertCanSeeTableRecords([$outline])->assertSee('未规划（可选）');
        $page->filterTable('scene_plan', 'planned')->callAction('create', ['outline_chapter_id' => $outline->id, 'scenes' => $this->scenes($outline), 'reason' => '新增场景', 'freeze' => false, 'acknowledged' => true])->assertHasNoActionErrors();
        $page->assertCanSeeTableRecords([$outline])->assertSee('草稿（未冻结，不进入生成）')->assertDontSee('draft')->assertDontSee($outline->objective)
            ->assertSee(count($this->scenes($outline)).' 个')->assertSee(count($outline->progression_points).' / '.count($outline->progression_points));
        // 已有版本的章节不再出现在新建选项里，继续在列表中编辑。
        $page->callTableAction('edit', $outline, ['expected_id' => ScenePlanVersion::firstOrFail()->id, 'scenes' => $this->scenes($outline), 'reason' => '修改场景', 'freeze' => false, 'acknowledged' => true])->assertHasNoTableActionErrors();
        $this->assertSame(2, ScenePlanVersion::count());
        $page->filterTable('scene_plan', 'none')->assertCanNotSeeTableRecords([$outline]);
    }

    /** 拆分和合并均建立完整新列表，旧场景内容保留。 */
    public function test_split_and_merge_preserve_history_and_budget(): void
    {
        $novel = B04ContentTest::readyNovel();
        $outline = NovelOutlineChapter::firstOrFail();
        $actor = User::factory()->create();
        $service = app(ScenePlanning::class);
        $initial = $service->save($novel->id, $outline->id, $actor, $this->scenes($outline), false, '初稿', null);
        $split = $this->scenes($outline);
        $split[0]['words'] = 50;
        $split[] = [...$split[0], 'title' => '修复验收', 'goal' => '通电验证修复结果'];
        $second = $service->save($novel->id, $outline->id, $actor, $split, true, '拆分检查与验收', $initial->id);
        $this->assertCount(1, $initial->fresh()->scenes);
        $this->assertCount(2, $service->context($novel, $outline)['scenes']);
        $merged = $this->scenes($outline);
        $merged[0]['goal'] = '检修并通电验证结果';
        $third = $service->save($novel->id, $outline->id, $actor, $merged, true, '合并连续行动', $second->id);
        $this->assertCount(2, $second->fresh()->scenes);
        $this->assertSame($third->id, $service->context($novel, $outline)['version_id']);
        $novel->update(['chapter_length_policy' => ['min_characters' => 200, 'max_characters' => 300]]);
        $this->expectException(ValidationException::class);
        $service->context($novel->refresh(), $outline);
    }

    /** 构造与测试大纲对应的场景；不会生成正式正文。 */
    private function scenes(NovelOutlineChapter $outline): array
    {
        return [['title' => '电表故障', 'goal' => '检修电表', 'pov' => '店主', 'time' => '上午', 'place' => '店铺', 'participants' => '店主与顾客', 'conflict' => '时间紧迫', 'change' => '确定故障', 'transition' => '从检查到修复', 'prohibited' => '不能跳过必要动作', 'point_ids' => array_column($outline->progression_points, 'id'), 'words' => 100]];
    }
}
