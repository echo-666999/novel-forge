<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\StoryStructurePlan;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\StoryStructureVersion;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\StoryStructure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 卷弧冻结与范围保护，不把未来规划当成已发生事实。 */
class C09StoryStructureTest extends TestCase
{
    use DatabaseMigrations;

    /** 隔离迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 仅清除内存夹具以允许保护历史的迁移回滚。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('story_structure_versions')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 草稿不进入生成，冻结后按章选择对应弧且保留版本。 */
    public function test_only_frozen_structure_is_used_in_new_context(): void
    {
        $novel = Novel::factory()->create(['target_chapters' => 10]);
        $actor = User::factory()->create();
        $service = app(StoryStructure::class);
        $draft = $service->save($novel->id, $actor, $this->tree(), false, '草稿', null);
        $this->assertNull($service->context($novel, 1));
        $frozen = $service->save($novel->id, $actor, $this->tree(), true, '作者冻结', $draft->id);
        $context = $service->context($novel, 6);
        $this->assertSame($frozen->id, $context['version_id']);
        $this->assertSame(6, $context['arc']['start']);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertStringContainsString('不代表角色已知', $context['visibility']);
    }

    /** 重叠或漏章的结构不能冻结。 */
    public function test_incomplete_range_is_rejected(): void
    {
        $novel = Novel::factory()->create(['target_chapters' => 10]);
        $tree = $this->tree();
        $tree[0]['arcs'][1]['start'] = 7;
        $this->expectException(ValidationException::class);
        app(StoryStructure::class)->save($novel->id, User::factory()->create(), $tree, true, '冻结', null);
    }

    /** 已提交章位禁止改动，但未开始的弧可新增冻结版本。 */
    public function test_future_arc_can_change_but_committed_arc_cannot(): void
    {
        $novel = Novel::factory()->create(['target_chapters' => 10]);
        $actor = User::factory()->create();
        $service = app(StoryStructure::class);
        $first = $service->save($novel->id, $actor, $this->tree(), true, '初版', null);
        $novel->update(['chapters_done' => 2]);
        $tree = $this->tree();
        $tree[0]['arcs'][1]['goal'] = '修改未来目标';
        $second = $service->save($novel->id, $actor, $tree, true, '未来修订', $first->id);
        $this->assertSame(2, StoryStructureVersion::count());
        $tree[0]['arcs'][0]['goal'] = '覆盖过去目标';
        $this->expectException(ValidationException::class);
        $service->save($novel->id, $actor, $tree, true, '修改过去', $second->id);
    }

    /** 结构版本更新后旧表单不得静默覆盖。 */
    public function test_stale_edit_is_rejected(): void
    {
        $novel = Novel::factory()->create(['target_chapters' => 10]);
        $actor = User::factory()->create();
        $service = app(StoryStructure::class);
        $service->save($novel->id, $actor, $this->tree(), false, '初稿', null);
        $this->expectException(ValidationException::class);
        $service->save($novel->id, $actor, $this->tree(), false, '过期表单', null);
    }

    /** 真正的章节启动与请求审计使用冻结结构，不读取后续修订。 */
    public function test_real_program_path_freezes_structure_into_request_payload(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => ['message' => 'offline rejection']], 400)]);
        config(['ai.providers.openai.key' => 'offline-c09', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        $novel = B04ContentTest::readyNovel();
        $actor = User::factory()->create();
        $structure = app(StoryStructure::class);
        $first = $structure->save($novel->id, $actor, $this->tree(), true, '冻结初版', null);
        $run = app(ContentGenerationService::class)->start($novel, $actor, 8192, 5, '隔离验收');
        $this->assertSame($first->id, $run->snapshot['author_structure']['version_id']);
        $tree = $this->tree();
        $tree[0]['arcs'][0]['goal'] = '后续变更不能进入旧运行';
        $structure->save($novel->id, $actor, $tree, true, '新版本', $first->id);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $prompt = CostLog::latest('id')->firstOrFail()->request_parameters['prompt_audit']['user_prompt'];
        $this->assertStringContainsString('author_structure', $prompt);
        $this->assertStringContainsString('完成搬迁', $prompt);
        $this->assertStringNotContainsString('后续变更不能进入旧运行', $prompt);
        Http::assertSentCount(1);
    }

    /** 后台表单实际保存草稿，默认不会启用新规划或调用模型。 */
    public function test_editor_action_saves_draft_without_freezing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $novel = Novel::factory()->create(['target_chapters' => 10]);
        Livewire::test(StoryStructurePlan::class, ['record' => $novel->id])
            ->callAction('edit', ['expected_id' => null, 'volumes' => $this->tree(), 'reason' => '表单验收', 'freeze' => false, 'acknowledged' => true])
            ->assertHasNoActionErrors()->assertNotified('卷弧新版本已保存');
        $this->assertSame('draft', StoryStructureVersion::firstOrFail()->status);
        $this->assertNull(app(StoryStructure::class)->context($novel, 1));
    }

    /** 连续覆盖十章的测试结构。 */
    private function tree(): array
    {
        $node = ['title' => '修补旧物', 'start' => 1, 'end' => 10, 'goal' => '完成搬迁', 'turning_point' => '找到新店', 'climax' => '如期搬走', 'ending_state' => '新店开张'];

        return [[...$node, 'arcs' => [[...$node, 'end' => 5], [...$node, 'start' => 6]]]];
    }
}
