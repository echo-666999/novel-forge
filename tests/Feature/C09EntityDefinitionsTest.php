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
