<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ChapterDirectory;
use App\Models\ChapterVersion;
use App\Models\User;
use App\Services\Pipeline\AcceptanceComparison;
use App\Services\Pipeline\ContentSchema;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** 证明私有对照稿可在目录阅读，且不越书、不覆盖正式历史。 */
class AcceptanceComparisonTest extends TestCase
{
    use DatabaseMigrations;

    /** 仅迁移应用表到内存库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 私有文件同样隔离，不能修改真实验收产物。 */
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    /** 清理隔离来源链，满足迁移的历史保护条件。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 模拟完整验收文件，绝不调用供应商。
     * @return array<string, mixed>
     */
    private function evidence(ChapterVersion $source, string $directory, string $body, string $completed): array
    {
        $result = ['status' => 'first_chapter_technical_pass_human_pending', 'completed_at' => $completed,
            'final_body_sha256' => hash('sha256', $body), 'final_candidate_characters' => ContentSchema::count($body)];
        foreach (['manifest' => ['source_novel_id' => $source->chapter->novel_id, 'source_version_id' => $source->id, 'source_hash' => $source->body_hash],
            'result' => $result, 'candidate' => ['title' => '新对照稿', 'body' => $body]] as $name => $data) {
            Storage::disk('local')->put('c10-acceptance-evidence/'.$directory.'/'.$name.'.json', json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        return $result;
    }

    /** 目录显示新稿，弹窗完整转义正文，正式版本及已存正文不变。 */
    public function test_directory_opens_comparison_without_replacing_formal_version(): void
    {
        $source = ChapterVersion::factory()->create();
        $source->chapter->update(['formal_version_id' => $source->id, 'current_candidate_version_id' => $source->id]);
        $body = "新稿交代回镇动机。\n<script>alert(1)</script>";
        $this->evidence($source, 'round-2', $body, '2026-11-09T10:00:00+08:00');
        $page = Livewire::test(ChapterDirectory::class, ['record' => $source->chapter->novel_id])
            ->assertSee('待验收对照稿')->assertSee('新对照稿')->assertSee('阅读新对照稿')->assertSee('阅读正式稿')
            ->mountTableAction('comparison', $source->chapter)
            ->assertActionMounted(TestAction::make('comparison')->table($source->chapter));
        // Filament弹层采用独立局部渲染，检查真正挂载的Schema而非列表的旧HTML。
        $modal = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('新稿交代回镇动机。', $modal);
        $this->assertStringContainsString('旧版评分不代表本稿验收结果', $modal);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $modal);
        $this->assertSame($source->id, $source->chapter->fresh()->formal_version_id);
        $this->assertSame(1, ChapterVersion::count());
        $this->assertSame($source->body_hash, hash('sha256', $source->fresh()->body));
    }

    /** 从真实弹层提交并重新打开可读到反馈，不改正式正文；重复反馈保留历史。 */
    public function test_feedback_can_be_saved_from_modal_and_reopened(): void
    {
        $source = ChapterVersion::factory()->create();
        $source->chapter->update(['formal_version_id' => $source->id]);
        $this->evidence($source, 'feedback-round', '回乡修复站的新稿。', '2026-11-09T10:00:00+08:00');
        $page = Livewire::test(ChapterDirectory::class, ['record' => $source->chapter->novel_id]);
        foreach (['需要增加回乡缘由。', '动机清晰，但对话仍需修改。'] as $comment) {
            $page->mountTableAction('comparison', $source->chapter)
                ->fillForm(['decision' => 'revise', 'comment' => $comment], 'mountedActionSchema0')
                ->callMountedAction()->assertHasNoActionErrors();
        }
        $files = Storage::disk('local')->files('c10-acceptance-evidence/feedback-round/feedback');
        $this->assertCount(2, $files);
        $payload = json_decode(Storage::disk('local')->get($files[0]), true);
        $this->assertSame((int) auth()->id(), $payload['actor_id']);
        $this->assertSame(hash('sha256', '回乡修复站的新稿。'), $payload['body_sha256']);
        $page->mountTableAction('comparison', $source->chapter);
        $modal = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('需要增加回乡缘由。', $modal);
        $this->assertStringContainsString('动机清晰，但对话仍需修改。', $modal);
        $this->assertSame($source->id, $source->chapter->fresh()->formal_version_id);
        $this->assertSame(1, ChapterVersion::count());
    }

    /** 反馈必须填写意见；阅读中产物被更新时拒绝旧稿意见误落到新稿。 */
    public function test_empty_feedback_and_changed_comparison_are_rejected(): void
    {
        $source = ChapterVersion::factory()->create();
        $this->evidence($source, 'feedback-round', '原对照稿。', '2026-11-09T10:00:00+08:00');
        $page = Livewire::test(ChapterDirectory::class, ['record' => $source->chapter->novel_id])
            ->mountTableAction('comparison', $source->chapter)
            ->fillForm(['decision' => 'revise', 'comment' => ''], 'mountedActionSchema0')
            ->callMountedAction()->assertHasActionErrors(['comment']);
        $this->evidence($source, 'feedback-round', '已经更新的新对照稿。', '2026-11-09T11:00:00+08:00');
        $page->fillForm(['decision' => 'revise', 'comment' => '旧稿反馈'], 'mountedActionSchema0')
            ->callMountedAction()->assertHasActionErrors(['comment']);
        $this->assertSame([], Storage::disk('local')->files('c10-acceptance-evidence/feedback-round/feedback'));
    }

    /** 只选本章最新完成且摘要一致的产物，不能串书或接受被改写的文件。 */
    public function test_latest_valid_evidence_is_scoped_and_tampered_body_is_rejected(): void
    {
        $source = ChapterVersion::factory()->create();
        $other = ChapterVersion::factory()->create();
        $this->evidence($source, 'older', '旧对照稿。', '2026-11-08T10:00:00+08:00');
        $this->evidence($source, 'newer', '最新对照稿。', '2026-11-09T10:00:00+08:00');
        $this->evidence($other, 'another-book', '其他书正文。', '2026-11-10T10:00:00+08:00');
        $reader = app(AcceptanceComparison::class);
        $this->assertSame('最新对照稿。', $reader->latest($source->chapter)['body']);
        Storage::disk('local')->put('c10-acceptance-evidence/newer/candidate.json', json_encode(['title' => '新稿', 'body' => '篡改正文。']));
        $this->assertSame('旧对照稿。', $reader->latest($source->chapter)['body']);
        $this->assertSame('其他书正文。', $reader->latest($other->chapter)['body']);
    }

    /** 失败实验以及错误来源章号不能显示为待验收新稿。 */
    public function test_failed_result_and_wrong_chapter_are_not_displayed(): void
    {
        $source = ChapterVersion::factory()->create();
        $result = $this->evidence($source, 'failed', '失败稿。', '2026-11-09T10:00:00+08:00');
        $result['status'] = 'failed_first_chapter_acceptance';
        Storage::disk('local')->put('c10-acceptance-evidence/failed/result.json', json_encode($result));
        $this->assertNull(app(AcceptanceComparison::class)->latest($source->chapter));
        Livewire::test(ChapterDirectory::class, ['record' => $source->chapter->novel_id])->assertDontSee('阅读新对照稿');
        $this->assertNull(app(AcceptanceComparison::class)->latest(ChapterVersion::factory()->create()->chapter));
    }
}
