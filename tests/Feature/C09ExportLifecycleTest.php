<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ExportCenter;
use App\Jobs\BuildNovelExport;
use App\Models\ChapterVersion;
use App\Models\NovelExport;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Pipeline\FormalChapterExport;
use App\Services\Pipeline\NovelExportLifecycle;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 异步导出文件有冻结来源、私有下载、完整校验及可追溯生命周期。 */
class C09ExportLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    /** 所有迁移仅在内存隔离库执行。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 隔离队列和私有磁盘，禁止任何真实模型调用。 */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /** 清理内存夹具，不删除开发库任务或真实文件。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('novel_exports')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 消费者输出与原同步导出逐字一致，重复消息不重复构建。 */
    public function test_job_builds_frozen_private_file_and_downloads_exact_bytes(): void
    {
        $novel = C08FormalExportTest::history();
        $actor = User::factory()->create();
        $service = app(NovelExportLifecycle::class);
        $expected = app(FormalChapterExport::class)->build($novel->id, 10, 'md');
        $record = $service->request($novel->id, $actor, 10, 'md');
        Queue::assertPushed(BuildNovelExport::class, fn ($job): bool => $job->exportId === $record->id && $job->queue === 'novel-exports' && $job->connection === 'database');
        $this->assertSame('queued', $record->status);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $novel->update(['title' => '后来修改的书名不进入旧导出']);
        (new BuildNovelExport($record->id))->handle($service);
        $history = $record->fresh()->history;
        $service->build($record->id);
        $this->assertSame($history, $record->fresh()->history);
        $this->assertSame('ready', $record->fresh()->status);
        $this->assertSame($expected['content'], $service->download($novel->id, $record->id, $actor)['content']);
        $this->assertSame(10, $novel->fresh()->chapters_done);
        Http::assertNothingSent();
    }

    /** 移除和过期只删派生文件，再生成使用原版本及书名。 */
    public function test_remove_regenerate_and_expiry_preserve_audit(): void
    {
        $novel = C08FormalExportTest::history();
        $actor = User::factory()->create();
        $service = app(NovelExportLifecycle::class);
        $first = $service->request($novel->id, $actor, 10, 'txt');
        $service->build($first->id);
        $original = $service->download($novel->id, $first->id, $actor)['content'];
        $service->remove($novel->id, $first->id, $actor);
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $novel->update(['title' => '新书名']);
        $second = $service->regenerate($novel->id, $first->id, $actor);
        $this->assertSame($first->snapshot_hash, $second->snapshot_hash);
        $this->assertSame($first->id, $second->parent_id);
        $service->build($second->id);
        $this->assertSame($original, $service->download($novel->id, $second->id, $actor)['content']);
        $this->travel(8)->days();
        $this->assertSame(1, $service->prune());
        $this->assertSame(0, $service->prune());
        $this->assertSame(2, NovelExport::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->expectException(ValidationException::class);
        $service->download($novel->id, $second->id, $actor);
    }

    /** 正式来源篡改导致任务失败且不发布半文件。 */
    public function test_damaged_source_fails_without_partial_output(): void
    {
        $novel = C08FormalExportTest::history();
        $service = app(NovelExportLifecycle::class);
        $record = $service->request($novel->id, User::factory()->create(), 10, 'txt');
        DB::table('chapter_versions')->where('id', $record->snapshot['manifest'][0]['version_id'])->update(['body' => '损坏正文']);
        $service->build($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    /** 其他账号不能通过任务编号下载私有文件。 */
    public function test_download_rejects_other_account(): void
    {
        $novel = C08FormalExportTest::history();
        $service = app(NovelExportLifecycle::class);
        $record = $service->request($novel->id, User::factory()->create(), 10, 'txt');
        $service->build($record->id);
        $this->expectException(ModelNotFoundException::class);
        $service->download($novel->id, $record->id, User::factory()->create());
    }

    /** 文件被改写时明确失败，不继续展示可下载的成功状态。 */
    public function test_corrupt_file_is_rejected_and_audited(): void
    {
        $novel = C08FormalExportTest::history();
        $actor = User::factory()->create();
        $service = app(NovelExportLifecycle::class);
        $record = $service->request($novel->id, $actor, 10, 'txt');
        $service->build($record->id);
        Storage::disk('local')->put('novel-exports/'.$record->uuid.'.txt', '损坏文件');
        try {
            $service->download($novel->id, $record->id, $actor);
            $this->fail('损坏文件不应下载');
        } catch (ValidationException) {
            $this->assertSame('failed', $record->fresh()->status);
            $this->assertSame('download_rejected', $record->fresh()->history[array_key_last($record->fresh()->history)]['action']);
        }
    }

    /** 硬中断遗留任务隔离后，迟到消息不能重新发布文件。 */
    public function test_stalled_worker_is_failed_and_late_message_is_ignored(): void
    {
        $novel = C08FormalExportTest::history();
        $service = app(NovelExportLifecycle::class);
        $record = $service->request($novel->id, User::factory()->create(), 10, 'txt');
        $record->update(['status' => 'building', 'started_at' => now()->subMinutes(11)]);
        Storage::disk('local')->put('novel-exports/'.$record->uuid.'.txt.part', '半文件');
        $service->prune();
        $this->assertSame('failed', $record->fresh()->status);
        $service->build($record->id);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame('failed', $record->fresh()->status);
    }

    /** 已下载或失败的审计记录不能通过模型更新悄悄删除。 */
    public function test_export_history_cannot_be_replaced(): void
    {
        $novel = C08FormalExportTest::history();
        $record = app(NovelExportLifecycle::class)->request($novel->id, User::factory()->create(), 10, 'txt');
        $this->expectException(\LogicException::class);
        $record->update(['history' => []]);
    }

    /** 标准开发入口包含导出专用消费者，不需要借用付费正文队列。 */
    public function test_local_development_registers_export_consumer(): void
    {
        $previous = app()['env'];
        app()->instance('env', 'local');
        try {
            (new AppServiceProvider(app()))->boot();
            $command = collect(DevCommands::commands())->firstWhere('name', 'exports');
            $this->assertNotNull($command);
            $this->assertStringContainsString('queue:listen database --queue=novel-exports', $command['command']);
            Http::assertNothingSent();
        } finally {
            app()->instance('env', $previous);
        }
    }

    /** 后台真实动作创建任务，文件由消费者生成后才能下载。 */
    public function test_page_actions_use_queue_and_download_service(): void
    {
        $novel = C08FormalExportTest::history();
        $actor = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);
        $page = Livewire::test(ExportCenter::class, ['record' => $novel->id])->callAction('createExport', ['end' => 10, 'format' => 'md'])->assertHasNoActionErrors();
        $record = NovelExport::firstOrFail();
        app(NovelExportLifecycle::class)->build($record->id);
        $page->callTableAction('download', $record)->assertHasNoTableActionErrors()->assertFileDownloaded('novel-'.$novel->id.'-chapters-1-10.md');
        $this->assertSame(20, ChapterVersion::count());
        Http::assertNothingSent();
    }
}
