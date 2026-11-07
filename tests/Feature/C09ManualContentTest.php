<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\Pages\EditChapterDraft;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\NovelSettingConfirmation;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\ManualContentDrafts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 人工工作草稿的隔离、并发保护及独立检查回归。 */
class C09ManualContentTest extends TestCase
{
    use DatabaseMigrations;

    /** 使用隔离内存数据库及完整设置迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 仅清理内存夹具，生产草稿受迁移回滚保护。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('manual_content_drafts')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 构造合法冻结来源，禁止真实模型调用。 */
    private function source(): array
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.providers.openai.key' => 'offline-c09']);
        $novel = B04ContentTest::readyNovel(false);
        $actor = User::findOrFail(NovelSettingConfirmation::where('novel_id', $novel->id)->firstOrFail()->confirmed_by);
        $run = app(ContentGenerationService::class)->start($novel, $actor, 8192, 2, '隔离编辑验收');
        $run->update(['status' => 'paused', 'stop_reason' => 'user_paused']);
        $novel->refresh()->update(['status' => NovelStatus::Paused]);
        $body = B04ContentTest::body();
        $version = ChapterVersion::factory()->create(['run_id' => $run->id, 'body' => $body, 'body_hash' => hash('sha256', $body), 'character_count' => ContentSchema::count($body)]);
        $version->chapter->update(['current_candidate_version_id' => $version->id]);

        return [$novel, $actor, $version];
    }

    /** 自动保存不改候选、不产生费用，旧标签不能覆盖新修订。 */
    public function test_autosave_is_isolated_and_rejects_stale_revision(): void
    {
        [$novel, $actor, $version] = $this->source();
        $drafts = app(ManualContentDrafts::class);
        $this->assertSame(1, $drafts->save($novel->id, $version->id, $actor, '', '', 0));
        $this->assertSame(1, ChapterVersion::count());
        $this->assertSame(0, CostLog::count());
        $this->assertSame($version->body, $version->fresh()->body);
        $this->expectException(ValidationException::class);
        $drafts->save($novel->id, $version->id, $actor, '过期', '过期正文', 0);
    }

    /** 其他账号不能读取或覆盖作者工作草稿。 */
    public function test_other_actor_cannot_open_draft(): void
    {
        [$novel, $actor, $version] = $this->source();
        $this->expectException(ValidationException::class);
        app(ManualContentDrafts::class)->open($novel->id, $version->id, User::factory()->create());
    }

    /** 后台表单可保存工作正文，并通过明确动作另建候选。 */
    public function test_editor_saves_and_publishes_without_ai(): void
    {
        [$novel, $actor, $source] = $this->source();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);
        Livewire::test(EditChapterDraft::class, ['record' => $novel->id])
            ->assertSet('versionId', $source->id)
            ->set('data.body', $source->body.'她记下日期。')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->callAction('publish', ['reason' => '隔离表单验收'])
            ->assertHasNoActionErrors();
        $this->assertSame(2, ChapterVersion::count());
        $this->assertSame(0, CostLog::count());
        Http::assertNothingSent();
    }

    /** 正式版本不可进入编辑，工作草稿不会绕开提交边界。 */
    public function test_formal_chapter_cannot_be_edited(): void
    {
        [$novel, $actor, $source] = $this->source();
        $source->chapter->update(['formal_version_id' => $source->id]);
        $this->expectException(ValidationException::class);
        app(ManualContentDrafts::class)->save($novel->id, $source->id, $actor, '覆盖', $source->body, 0);
    }

    /** 发布另建版本且只在明确授权后执行一次独立检查。 */
    public function test_new_candidate_requires_its_own_check(): void
    {
        [$novel, $actor, $source] = $this->source();
        $body = $source->body.'她合上档案，记下日期。';
        app(ManualContentDrafts::class)->save($novel->id, $source->id, $actor, '人工修改标题', $body, 0);
        $service = app(ContentGenerationService::class);
        $candidate = $service->saveManualCandidate($novel->id, $source->id, $actor, 1, '补充收尾动作');
        $this->assertSame($source->id, $candidate->parent_version_id);
        $this->assertSame($body, $candidate->body);
        $this->assertSame(B04ContentTest::body(), $source->fresh()->body);
        $this->assertNull($candidate->chapter->progression_result);
        $this->assertSame(0, CostLog::count());
        $run = ChapterGenerationRun::findOrFail($candidate->run_id);
        $this->assertSame('manual_draft_saved', $run->stop_reason);
        $this->assertSame(1, $run->request_limit);
        Http::fake(['*' => fn ($request) => Http::response(B04ContentTest::validResponse($request))]);
        $run = $service->continue($run->id, executionLimit: 20, reason: '独立检查', authorId: $actor->id);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        Http::assertSentCount(1);
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $this->assertSame($candidate->id, $candidate->chapter->fresh()->progression_result['version_id']);
        $this->assertNull($candidate->chapter->fresh()->formal_version_id);
        // 隔离夹具模拟作者阅读后，只创建提交准备；不跳过评审提取与正式批准。
        $service->recordReading($run->id, $actor, true, ['隔离阅读确认，不代表真实作品认可']);
        $submission = app(ChapterSubmissionService::class)->prepare($candidate->id, $actor, 0, '隔离提交入口验证', 20, false);
        $this->assertSame($candidate->id, $submission->version_id);
        $this->assertSame(0, $submission->requests_used);
        $this->assertNull($candidate->chapter->fresh()->formal_version_id);
        Http::assertSentCount(1);
    }
}
