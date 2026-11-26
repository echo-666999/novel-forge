<?php

namespace Tests\Feature;

use App\Models\CostLog;
use App\Models\User;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Search\EmbeddingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/** 检索嵌入只写索引：在途或结果未知时不阻塞正文；会改写故事的请求仍然阻塞。 */
class EmbeddingRequestGuardTest extends TestCase
{
    use DatabaseMigrations;

    /** 内存测试库执行全部业务迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 隔离网络、队列及后台账号。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::factory()->create());
    }

    /** 不可变历史只在内存测试库清理。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 上一章提交触发的嵌入仍在途、或曾中断为未知时，下一章照常启动。 */
    public function test_pending_or_unknown_embedding_does_not_block_new_content(): void
    {
        $novel = B04ContentTest::readyNovel(false, 20);
        foreach (['pending', 'unknown'] as $status) {
            CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'route_version' => EmbeddingService::PROFILE, 'status' => $status]);
        }
        $this->assertSame(0, CostLog::where('novel_id', $novel->id)->storyRequests()->count());

        $run = app(ContentGenerationService::class)->start($novel, auth()->user());
        $this->assertSame('pending', $run->status);
    }

    /** 会改写故事的请求在途时仍然阻塞。 */
    public function test_pending_story_request_still_blocks(): void
    {
        $novel = B04ContentTest::readyNovel(false, 20);
        CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'route_version' => 'b04-draft-prompt-v17', 'status' => 'pending']);
        $this->assertSame(1, CostLog::where('novel_id', $novel->id)->storyRequests()->count());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('存在活动步骤或尚未确认的未知调用');
        app(ContentGenerationService::class)->start($novel, auth()->user());
    }
}
