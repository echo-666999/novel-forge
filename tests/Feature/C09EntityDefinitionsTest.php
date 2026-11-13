<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\EntityLibrary;
use App\Models\EntityDefinitionVersion;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\User;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentQualityContract;
use App\Services\Pipeline\EntityDefinitions;
use App\Services\Pipeline\PlanningJson;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 实体定义批准、跨书隔离和稳定身份约束的离线验收。 */
class C09EntityDefinitionsTest extends TestCase
{
    use DatabaseMigrations;

    /** 迁移使用内存测试数据库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 只清理隔离夹具，允许保护历史的迁移安全回滚。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('entity_definition_versions')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 候选不影响目录；批准产生稳定实体，修订保留原定义且仅对未来章位生效。 */
    public function test_approval_and_alias_revision_keep_stable_identity(): void
    {
        $novel = Novel::factory()->create();
        $actor = User::factory()->create();
        $service = app(EntityDefinitions::class);
        $draft = $service->propose($novel->id, $actor, $this->definition(), '初稿');
        $this->assertSame([], app(ChapterSubmissionService::class)->catalog($novel->id));
        $approved = $service->approve($novel->id, $draft->id, $actor, '明确批准', true);
        $entity = StoryEntity::findOrFail($approved->entity_id);
        $original = $entity->definition;
        $this->assertCount(1, app(ChapterSubmissionService::class)->catalog($novel->id));
        $novel->update(['chapters_done' => 2]);
        $new = $this->definition();
        $new['aliases'] = ['修理师傅'];
        $candidate = $service->propose($novel->id, $actor, $new, '补充别名', $entity->id, $approved->id);
        $service->approve($novel->id, $candidate->id, $actor, '作者批准别名', true);
        $this->assertSame($original, $entity->fresh()->definition);
        $this->assertSame([], $service->effective($entity, 2)['aliases']);
        $this->assertSame(['修理师傅'], $service->effective($entity, 3)['aliases']);
        $this->assertSame('candidate', $draft->fresh()->status);
        $this->assertCount(4, EntityDefinitionVersion::all());
    }

    /** 同名或别名冲突不能被批准成为另一个实体。 */
    public function test_alias_conflict_is_blocked(): void
    {
        $novel = Novel::factory()->create();
        $actor = User::factory()->create();
        $service = app(EntityDefinitions::class);
        $draft = $service->propose($novel->id, $actor, $this->definition(), '初稿');
        $service->approve($novel->id, $draft->id, $actor, '批准', true);
        $other = $this->definition();
        $other['name'] = '另一人';
        $other['aliases'] = ['苏禾'];
        $draft = $service->propose($novel->id, $actor, $other, '冲突候选');
        $this->expectException(ValidationException::class);
        $service->approve($novel->id, $draft->id, $actor, '禁止混同', true);
    }

    /** 已批准身份不能通过新候选改名。 */
    public function test_approved_name_cannot_be_rewritten(): void
    {
        $novel = Novel::factory()->create();
        $actor = User::factory()->create();
        $service = app(EntityDefinitions::class);
        $draft = $service->propose($novel->id, $actor, $this->definition(), '初稿');
        $approved = $service->approve($novel->id, $draft->id, $actor, '批准', true);
        $changed = $this->definition();
        $changed['name'] = '另一个人';
        $this->expectException(ValidationException::class);
        $service->propose($novel->id, $actor, $changed, '试图改名', $approved->entity_id);
    }

    /** 不同书的实体不能作为关系目标。 */
    public function test_relationship_target_cannot_cross_novels(): void
    {
        $novel = Novel::factory()->create();
        $actor = User::factory()->create();
        $other = StoryEntity::factory()->create();
        $definition = $this->definition();
        $definition['relationships'] = [['target_id' => $other->id, 'description' => '不能跨书']];
        $this->expectException(ModelNotFoundException::class);
        app(EntityDefinitions::class)->propose($novel->id, $actor, $definition, '跨书关系');
    }

    /** 实际表单创建候选，不默认作者批准。 */
    public function test_form_creates_only_candidate(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $novel = Novel::factory()->create();
        Livewire::test(EntityLibrary::class, ['record' => $novel->id])->callAction('create', ['definition' => $this->definition(), 'reason' => '后台候选'])->assertHasNoActionErrors()->assertNotified('已保存实体候选');
        $this->assertSame(0, StoryEntity::count());
        $this->assertSame('candidate', EntityDefinitionVersion::firstOrFail()->status);
        Livewire::test(EntityLibrary::class, ['record' => $novel->id])->searchTable('苏禾')->assertCanSeeTableRecords(EntityDefinitionVersion::all());
    }

    /** 实体以卡片展示名称、类型、简介与来源，顶部显示数量与待批准候选，版本表类型显示中文。 */
    public function test_page_shows_entity_cards_and_candidate_overview(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $novel = Novel::factory()->create();
        $structured = ['name' => '沈禾', 'type' => 'character', 'aliases' => [], 'description' => ['role' => '档案修复师，故事主角', 'goal' => '查清旧案']];
        StoryEntity::factory()->create(['novel_id' => $novel->id, 'name' => '沈禾', 'normalized_name' => '沈禾', 'definition' => $structured, 'definition_hash' => PlanningJson::hash($structured), 'origin' => ['kind' => 'confirmed_setting']]);
        $place = ['name' => '修复室', 'type' => 'location', 'aliases' => [], 'description' => '沈禾工作的修复室'];
        StoryEntity::factory()->create(['novel_id' => $novel->id, 'name' => '修复室', 'normalized_name' => '修复室', 'type' => 'location', 'definition' => $place, 'definition_hash' => PlanningJson::hash($place), 'origin' => ['kind' => 'canonical_commit']]);
        app(EntityDefinitions::class)->propose($novel->id, $actor, $this->definition(), '新增候选');

        Livewire::test(EntityLibrary::class, ['record' => $novel->id])
            ->assertSee('正式实体 2 个')->assertSee('人物 1')->assertSee('地点 1')->assertSee('待批准候选 1 条')
            ->assertSee('档案修复师，故事主角')->assertSee('来自作者确认设定')->assertSee('正文提交时引入')
            ->assertSee('人物 · 新增实体')->assertSee('第1章起')->assertDontSee('查清旧案');
    }

    /** 定义弹窗不再显示 JSON：修订候选先列出与来源版本的字段差异，再按分组显示完整定义；批准弹窗同样展示差异。 */
    public function test_definition_modal_shows_fields_and_diff_against_source(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $novel = Novel::factory()->create();
        $service = app(EntityDefinitions::class);
        $draft = $service->propose($novel->id, $actor, $this->definition(), '初稿');
        $approved = $service->approve($novel->id, $draft->id, $actor, '明确批准', true);
        $revised = $this->definition();
        $revised['aliases'] = ['修理师傅'];
        $revised['description'] = '修订后的描述：她在修复站负责最后一道质检。';
        $candidate = $service->propose($novel->id, $actor, $revised, '补充别名', $approved->entity_id, $approved->id);

        $page = Livewire::test(EntityLibrary::class, ['record' => $novel->id])->mountTableAction('inspect', $candidate);
        $modal = $page->instance()->getMountedAction()->getModalContent()->render();
        foreach (['与来源版本对照', '#'.$approved->id.'（已批准）', '#'.$candidate->id.'（候选）', '修改 1', '新增 1', '修订后的描述：她在修复站负责最后一道质检。', '修理师傅', '本版本完整定义', '基本信息'] as $text) {
            $this->assertStringContainsString($text, $modal);
        }
        $this->assertStringNotContainsString('"description"', $modal);

        // 批准会以候选为来源另存版本，只有最初的候选没有来源版本。
        $first = Livewire::test(EntityLibrary::class, ['record' => $novel->id])->mountTableAction('inspect', $draft);
        $this->assertStringContainsString('完整定义（首次建立，没有来源版本）', $first->instance()->getMountedAction()->getModalContent()->render());

        $approval = Livewire::test(EntityLibrary::class, ['record' => $novel->id])->mountTableAction('approve', $candidate);
        $this->assertStringContainsString('修订后的描述：她在修复站负责最后一道质检。', $approval->instance()->getSchema($approval->instance()->getMountedActionSchemaName())->toHtml());
    }

    /** 批准规则进入真实新运行与质量检查契约，旧运行不重新读取数据库。 */
    public function test_new_run_freezes_approved_rules(): void
    {
        Queue::fake();
        $novel = B04ContentTest::readyNovel();
        $actor = User::factory()->create();
        $service = app(EntityDefinitions::class);
        $definition = $this->definition();
        $definition['rules'] = '维修工具不能凭空出现';
        $candidate = $service->propose($novel->id, $actor, $definition, '候选规则');
        $approved = $service->approve($novel->id, $candidate->id, $actor, '批准规则', true);
        $run = app(ContentGenerationService::class)->start($novel, $actor, 8192, 5, '隔离验证');
        $this->assertSame($approved->id, $run->snapshot['author_entity_definitions'][0]['version_id']);
        $this->assertSame('苏禾：维修工具不能凭空出现', ContentQualityContract::rules($run->snapshot)['entity-definition-'.$approved->id]);
        $new = $service->propose($novel->id, $actor, $definition, '允许保存待决候选', $approved->entity_id, $approved->id);
        $this->expectException(ValidationException::class);
        $service->approve($novel->id, $new->id, $actor, '不能中途批准', true);
    }

    /** 独立测试的作者设定，不调用模型。 */
    private function definition(): array
    {
        return ['name' => '苏禾', 'type' => 'character', 'aliases' => [], 'description' => '维修店店主', 'traits' => '', 'rules' => '', 'scope' => '现实生活，无超自然能力', 'relationships' => []];
    }
}
