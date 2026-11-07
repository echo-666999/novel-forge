<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\NovelArchiveService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 书架管理不触碰运行授权和正式状态，不产生真实模型请求。 */
class C09NovelManagementTest extends TestCase
{
    use DatabaseMigrations;

    /** 在隔离数据库创建业务表和设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 归档和恢复保留冻结配置，重复请求不重复追加审计。 */
    public function test_archive_is_idempotent_and_does_not_change_execution_state(): void
    {
        $actor = User::factory()->create();
        $novel = Novel::factory()->create(['status' => NovelStatus::Running, 'state_version' => 7, 'generation_config' => ['frozen' => true]]);
        $service = app(NovelArchiveService::class);
        $service->setArchived($novel->id, $actor, true, '暂时收起');
        $service->setArchived($novel->id, $actor, true, '重复点击');
        $novel->refresh();
        $this->assertNotNull($novel->archived_at);
        $this->assertCount(1, $novel->archive_history);
        $this->assertSame($actor->id, $novel->archive_history[0]['actor_id']);
        $this->assertSame(NovelStatus::Running, $novel->status);
        $this->assertSame(7, $novel->state_version);
        $this->assertSame(['frozen' => true], $novel->generation_config);
        $service->setArchived($novel->id, $actor, false, '恢复查看');
        $novel->refresh();
        $this->assertNull($novel->archived_at);
        $this->assertCount(2, $novel->archive_history);
        $this->assertSame('restore', $novel->archive_history[1]['action']);
        // 清理测试专库审计以允许防丢失迁移正常回滚。
        Novel::query()->delete();
    }

    /** 无操作原因时不得写入归档状态。 */
    public function test_archive_requires_a_reason(): void
    {
        $novel = Novel::factory()->create();
        $this->expectException(ValidationException::class);
        app(NovelArchiveService::class)->setArchived($novel->id, User::factory()->create(), true, ' ');
    }

    /** 复制只打开预填表单，不建书、不调用模型、不继承授权。 */
    public function test_copy_prefills_inputs_without_dispatching_or_inheriting_authority(): void
    {
        Http::preventStrayRequests();
        Bus::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $novel = Novel::factory()->create(['generation_mode' => 'setting_real', 'title' => '原书', 'generation_config' => ['model' => 'frozen-model']]);
        Livewire::withQueryParams(['copy_from' => $novel->id])->test(CreateNovel::class)
            ->assertSet('data.title', '原书（副本）')
            ->assertSet('data.premise_raw', $novel->premise_raw)
            ->assertSet('data.generation_mode', 'simulation')
            ->assertSet('data.automatic_first_ten', false)
            ->assertSet('data.automatic_first_chapter', false);
        $this->assertSame(1, Novel::count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        $this->get('/admin/novels/create?copy_from=999999')->assertNotFound();
        $novel->delete();
    }
}
