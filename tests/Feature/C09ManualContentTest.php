<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\Pages\EditChapterDraft;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
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
use InvalidArgumentException;
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

    /** 草稿发布为新候选后即删除；之后出现更新候选时，编辑页从当前候选起步而不是旧草稿。 */
    public function test_publish_clears_working_draft(): void
    {
        [$novel, $actor, $source] = $this->source();
        $drafts = app(ManualContentDrafts::class);
        $revision = $drafts->save($novel->id, $source->id, $actor, $source->title, $source->body.'她记下日期。', 0);
        $version = app(ContentGenerationService::class)->saveManualCandidate($novel->id, $source->id, $actor, $revision, '清理草稿验收');

        $this->assertSame(0, DB::table('manual_content_drafts')->count());
        $opened = $drafts->open($novel->id, $version->id, $actor);
        $this->assertFalse($opened['stale']);
        $this->assertSame($version->body, $opened['body']);
        $this->assertSame(0, $opened['revision']);
    }

    /** 基于旧候选的草稿可直接丢弃，编辑区改为当前候选正文。 */
    public function test_stale_draft_can_be_discarded(): void
    {
        [$novel, $actor, $source] = $this->source();
        $older = ChapterVersion::factory()->create(['run_id' => $source->run_id, 'chapter_id' => $source->chapter_id, 'version' => $source->version + 1]);
        app(ManualContentDrafts::class)->save($novel->id, $source->id, $actor, '旧草稿', '旧草稿正文', 0);
        DB::table('manual_content_drafts')->update(['base_version_id' => $older->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);

        Livewire::test(EditChapterDraft::class, ['record' => $novel->id])
            ->assertSet('stale', true)->assertSet('data.body', EditChapterDraft::displayBody('旧草稿正文'))
            ->assertActionVisible('discardDraft')->callAction('discardDraft')->assertHasNoActionErrors();
        $this->assertSame(0, DB::table('manual_content_drafts')->count());
        Livewire::test(EditChapterDraft::class, ['record' => $novel->id])
            ->assertSet('stale', false)->assertSet('data.body', EditChapterDraft::displayBody($source->body));
    }

    /** 已被提交评审驳回的正文不能原样重新保存为候选，草稿保留供继续修改。 */
    public function test_body_rejected_by_review_cannot_be_republished(): void
    {
        [$novel, $actor, $source] = $this->source();
        $body = str_replace('。', '，她停了一下。', $source->body);
        $rejected = ChapterVersion::factory()->create(['run_id' => $source->run_id, 'chapter_id' => $source->chapter_id, 'version' => $source->version + 1,
            'body' => $body, 'body_hash' => hash('sha256', $body), 'character_count' => ContentSchema::count($body)]);
        $submission = ChapterSubmissionRun::factory()->create(['version_id' => $rejected->id, 'status' => 'failed', 'stop_reason' => 'REVIEW_NOT_PASSED']);
        $review = ChapterReview::factory()->create(['submission_run_id' => $submission->id, 'verdict' => 'needs_revision']);
        $revision = app(ManualContentDrafts::class)->save($novel->id, $source->id, $actor, $source->title, $body, 0);

        try {
            app(ContentGenerationService::class)->saveManualCandidate($novel->id, $source->id, $actor, $revision, '原样重提');
            $this->fail('已驳回正文不能原样重提');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('版本 '.$rejected->version.' 完全相同，该版本已被提交评审 #'.$review->id.' 驳回', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('manual_content_drafts')->count());
        $this->assertSame($source->id, $source->chapter->fresh()->current_candidate_version_id);
    }

    /** 历史版本可作为人工编辑起点：从工作台链接或编辑页动作载入其正文，发布为新候选，不直接改候选指针、不调用模型。 */
    public function test_history_version_loads_as_editing_start(): void
    {
        [$novel, $actor, $source] = $this->source();
        $body = str_replace('。', '，她停了一下。', B04ContentTest::body());
        $older = ChapterVersion::factory()->create(['run_id' => $source->run_id, 'chapter_id' => $source->chapter_id, 'version' => $source->version + 1, 'title' => '旧版章名',
            'body' => $body, 'body_hash' => hash('sha256', $body), 'character_count' => ContentSchema::count($body)]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);

        Livewire::withQueryParams(['chapter' => 1, 'from_version' => $older->id])->test(EditChapterDraft::class, ['record' => $novel->id])
            ->assertSet('data.body', EditChapterDraft::displayBody($older->body))->assertSet('data.title', '旧版章名');
        $this->assertSame($source->id, $source->chapter->fresh()->current_candidate_version_id);

        Livewire::test(EditChapterDraft::class, ['record' => $novel->id])
            ->set('data.body', $source->body.'她记下日期。')->call('saveDraft')
            ->callAction('loadVersion', ['version_id' => $older->id])->assertHasNoActionErrors()->assertSet('data.body', EditChapterDraft::displayBody($older->body))
            ->callAction('publish', ['reason' => '退回旧版本作为编辑起点'])->assertHasNoActionErrors();
        $latest = ChapterVersion::latest('id')->firstOrFail();
        $this->assertSame($older->body, $latest->body);
        $this->assertSame($latest->id, $source->chapter->fresh()->current_candidate_version_id);
        $this->assertSame(0, CostLog::count());
        Http::assertNothingSent();
    }

    /** 编辑框按推进检查问题与本地重复原文标记需要调整的片段；输入仍自动保存工作草稿。 */
    public function test_editor_marks_issues_and_autosaves(): void
    {
        [$novel, $actor, $source] = $this->source();
        $lines = explode("\n", $source->body);
        $repeated = '窗台上的湿度计仍停在偏高的刻度';
        $lines[0] .= $repeated.'。';
        $lines[1] .= $repeated.'。';
        $body = implode("\n", $lines);
        $source = ChapterVersion::factory()->create(['run_id' => $source->run_id, 'chapter_id' => $source->chapter_id, 'version' => $source->version + 1,
            'body' => $body, 'body_hash' => hash('sha256', $body), 'character_count' => ContentSchema::count($body)]);
        $source->chapter->update(['current_candidate_version_id' => $source->id]);
        $quote = mb_substr($lines[2], 0, 10);
        $result = ['verdict' => 'insufficient', 'continuity_issues' => [['type' => 'repeated_action', 'reason' => '同一动作重演。', 'evidence' => [['quote' => $quote, 'paragraph' => 3]]]]];
        $check = ChapterProgressionCheck::factory()->create(['chapter_id' => $source->chapter_id, 'version_id' => $source->id, 'body_hash' => $source->body_hash, 'verdict' => 'insufficient', 'result' => $result]);
        $source->chapter->update(['progression_result' => ['check_id' => $check->id, 'version_id' => $source->id, 'body_hash' => $source->body_hash, ...$result]]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);

        $page = Livewire::test(EditChapterDraft::class, ['record' => $novel->id]);
        $marks = $page->instance()->issueMarks();
        $this->assertContains(['kind' => 'check', 'label' => '前后重复或时序矛盾：同一动作重演。', 'quote' => $quote], $marks);
        // 重复片段延伸到两处完全相同的整段，并去掉首尾标点。
        $this->assertContains(['kind' => 'repeat', 'label' => '重复原文', 'quote' => $repeated], $marks);
        $page->assertSee('推进检查指出的问题')->assertSee('本地检出的重复原文');

        // 输入经 afterStateUpdated 自动保存工作草稿，不建立候选。
        $revision = $page->get('revision');
        $page->set('data.body', $body.'她记下日期。');
        $this->assertSame($revision + 1, $page->get('revision'));
        $this->assertSame(2, ChapterVersion::count());
    }

    /** 编辑框按段缩进、段间空行显示，保存时还原为每段一行，往返不改变正文。 */
    public function test_editor_formats_paragraphs_and_restores_storage_format(): void
    {
        $body = "第一段。\n“第二段。”她说。\n第三段。";
        $display = EditChapterDraft::displayBody($body);
        $this->assertSame("　　第一段。\n\n　　“第二段。”她说。\n\n　　第三段。", $display);
        $this->assertSame($body, EditChapterDraft::storedBody($display));
        $this->assertSame($body, EditChapterDraft::storedBody("  第一段。 \n\n\n　　“第二段。”她说。\r\n第三段。\n"));
    }

    /** 载入历史版本后，推进检查问题改用该版本自己的检查记录，不再显示当前候选的问题。 */
    public function test_marks_follow_loaded_version_check(): void
    {
        [$novel, $actor, $source] = $this->source();
        $body = str_replace('。', '，她停了一下。', B04ContentTest::body());
        $older = ChapterVersion::factory()->create(['run_id' => $source->run_id, 'chapter_id' => $source->chapter_id, 'version' => $source->version + 1,
            'body' => $body, 'body_hash' => hash('sha256', $body), 'character_count' => ContentSchema::count($body)]);
        // 引文取两版不同的段尾：旧版带“她停了一下”，当前版的原段尾在旧版中已不存在。
        $olderQuote = mb_substr(explode("\n", $older->body)[1], -12);
        $currentQuote = mb_substr(explode("\n", $source->body)[0], -6);
        foreach ([[$older, $olderQuote, '旧版问题。'], [$source, $currentQuote, '当前版问题。']] as [$version, $quote, $reason]) {
            ChapterProgressionCheck::factory()->create(['chapter_id' => $version->chapter_id, 'version_id' => $version->id, 'body_hash' => $version->body_hash, 'verdict' => 'insufficient',
                'result' => ['verdict' => 'insufficient', 'continuity_issues' => [['type' => 'repeated_action', 'reason' => $reason, 'evidence' => [['quote' => $quote]]]]]]);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);

        $current = Livewire::test(EditChapterDraft::class, ['record' => $novel->id])->instance()->markSource();
        $this->assertSame($source->version, $current['version']);
        $this->assertSame(['前后重复或时序矛盾：当前版问题。'], array_column(array_filter($current['marks'], fn (array $mark): bool => $mark['kind'] === 'check'), 'label'));

        $loaded = Livewire::withQueryParams(['chapter' => 1, 'from_version' => $older->id])->test(EditChapterDraft::class, ['record' => $novel->id]);
        $marked = $loaded->instance()->markSource();
        $this->assertSame($older->version, $marked['version']);
        $this->assertSame(['前后重复或时序矛盾：旧版问题。'], array_column(array_filter($marked['marks'], fn (array $mark): bool => $mark['kind'] === 'check'), 'label'));
        $loaded->assertSee('推进检查问题来自版本 '.$older->version);

        // 改动后仍按引文匹配找到旧版；与当前候选正文完全一致时直接用当前候选，即使旧版问题的引文也在其中。
        $loaded->set('data.body', EditChapterDraft::displayBody($older->body.'她记下日期。'));
        $this->assertSame($older->version, $loaded->instance()->markSource()['version']);
        $loaded->set('data.body', EditChapterDraft::displayBody($source->body));
        $this->assertSame($source->version, $loaded->instance()->markSource()['version']);
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
