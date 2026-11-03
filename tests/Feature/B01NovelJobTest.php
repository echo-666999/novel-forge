<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Jobs\RunNovelPipelineJob;
use App\Models\Chapter;
use App\Models\CostLog;
use App\Models\Novel;
use App\Services\Pipeline\StageExecutorInterface;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** 验证异步启动的固定模拟边界、额度及重复消费保护。 */
class B01NovelJobTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移 SQLite 兼容的应用表。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 模拟启动不得访问模型网关。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** 所有队列场景均不能生成正文或供应商调用费用。 */
    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->assertSame(0, Chapter::query()->count());
        $this->assertSame(0, CostLog::query()->count());
        parent::tearDown();
    }

    /** 默认额度五在导出前暂停，重复消息不自动恢复或消耗新次数。 */
    public function test_default_job_pauses_at_limit_and_duplicate_delivery_is_harmless(): void
    {
        $novel = Novel::factory()->create();
        $job = new RunNovelPipelineJob($novel->id, true);
        $job->handle();
        $this->assertSame(NovelStatus::Paused, $novel->fresh()->status);
        $this->assertSame(5, $novel->fresh()->execution_count);
        $before = $novel->stages()->orderBy('id')->get()->map->getRawOriginal()->all();
        $job->handle();
        $this->assertSame($before, $novel->stages()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    /** 全局供应商绑定和细纲设置变化不能把旧消息转成真实调用。 */
    public function test_job_uses_frozen_option_and_explicit_fake_executor(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldNotReceive('execute');
        $this->app->instance(StageExecutorInterface::class, $executor);
        app(GeneralSettings::class)->enable_fine_outline = true;
        $novel = Novel::factory()->create();
        $job = unserialize(serialize(new RunNovelPipelineJob($novel->id, false)));
        $job->handle();
        $this->assertSame(NovelStatus::Completed, $novel->fresh()->status);
        $this->assertSame(['setting', 'outline', 'content', 'polish', 'export'], $novel->stages()->orderBy('id')->get()->map(fn ($stage): string => $stage->stage->value)->all());
        $before = $novel->fresh()->getRawOriginal();
        $job->handle();
        $this->assertSame($before, $novel->fresh()->getRawOriginal());
    }

    /** 不存在的小说不建替代记录，失败回调仅标记尚未领取的小说。 */
    public function test_missing_novel_and_failed_job_preserve_active_history(): void
    {
        (new RunNovelPipelineJob(999999, true))->handle();
        $this->assertSame(0, Novel::query()->count());
        $novel = Novel::factory()->create();
        $job = new RunNovelPipelineJob($novel->id, true);
        $job->failed(new RuntimeException('敏感异常不得回显'));
        $this->assertSame(NovelStatus::Error, $novel->fresh()->status);
        $other = Novel::factory()->create();
        $active = new RunNovelPipelineJob($other->id, true);
        $active->handle();
        $before = $other->fresh()->getRawOriginal();
        $active->failed(new RuntimeException('迟到失败通知'));
        $this->assertSame($before, $other->fresh()->getRawOriginal());
    }
}
