<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ReadChapter;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\ReadingRecordService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 阅读和问题记录绑定不可变版本，不越书、不泄漏账号备注。 */
class C09ReadingTest extends TestCase
{
    use DatabaseMigrations;

    /** 在内存库迁移应用与设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 清理隔离证据后才能回滚保护历史的迁移。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('reading_records')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 全局记录编号与章内版本号不相等时，阅读器必须展示真实章内版本。 */
    public function test_reader_displays_chapter_version_instead_of_global_id(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $version = ChapterVersion::factory()->create(['version' => 7]);
        $version->chapter->update(['current_candidate_version_id' => $version->id]);
        $this->assertNotSame(7, $version->id);
        Livewire::test(ReadChapter::class, ['record' => $version->chapter->novel_id])
            ->assertSee('V7（记录 #'.$version->id.'）')
            ->assertDontSee('版本#'.$version->id)
            ->mountAction('versions')->assertSee('V7（记录 #'.$version->id.'）');
    }

    /** 已读幂等且不污染新版本；问题摘录必须来自原文。 */
    public function test_read_and_issue_records_are_version_scoped(): void
    {
        $version = ChapterVersion::factory()->create();
        $actor = User::factory()->create();
        $service = app(ReadingRecordService::class);
        $novelId = $version->chapter->novel_id;
        $service->record($novelId, $version->id, $actor, 'read');
        $service->record($novelId, $version->id, $actor, 'read');
        $service->record($novelId, $version->id, $actor, 'issue', '林澈', '这里需要说明来历');
        $this->assertSame(2, DB::table('reading_records')->count());
        $this->assertSame($version->body, $version->fresh()->body);
        $this->expectException(ValidationException::class);
        $service->record($novelId, $version->id, $actor, 'issue', '不存在的摘录', '说明');
    }

    /** 业务校验失败显示在弹窗内，保留问题说明并支持修正摘录后提交。 */
    public function test_issue_accepts_separate_excerpts_and_copy_whitespace_but_preserves_source(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $body = "林岚拖着工具箱走进修复站。\n中间一段不摘录。\n许澄说：“这里是一百四十二，下一条是一百五十一。”";
        $version = ChapterVersion::factory()->create(['body' => $body, 'body_hash' => hash('sha256', $body)]);
        $version->chapter->update(['current_candidate_version_id' => $version->id]);
        $quote = "\u{FEFF}林岚 拖着工具箱\r\n走进修复站。\r\n\r\n许澄说：“这里是一百四十二，\u{00A0}下一条是一百五十一。\u{200B}";
        Livewire::test(ReadChapter::class, ['record' => $version->chapter->novel_id])
            ->callAction('issue', ['quote' => $quote, 'note' => '开场缺少背景，叙事过于流程化'])
            ->assertHasNoActionErrors()->assertNotified('原文问题已保存');
        $this->assertDatabaseHas('reading_records', ['version_id' => $version->id,
            'quote' => "林岚拖着工具箱走进修复站。\n\n许澄说：“这里是一百四十二，下一条是一百五十一。",
            'note' => '开场缺少背景，叙事过于流程化']);
        $this->assertSame($body, $version->fresh()->body);
        // 标点或文字真正改变仍然拒绝，并指出错误发生在第二处摘录。
        $page = Livewire::test(ReadChapter::class, ['record' => $version->chapter->novel_id])
            ->callAction('issue', ['quote' => "林岚拖着工具箱走进修复站。\n\n许澄说：“这里是一百四十三，", 'note' => '待核对'])
            ->assertHasActionErrors(['quote'])->assertNotified('原文问题未保存');
        $this->assertStringContainsString('第2处摘录', $page->instance()->getErrorBag()->first('mountedActions.0.data.quote'));
        $this->assertSame(1, DB::table('reading_records')->count());
    }

    /** 说明校验错误不能错误归入摘录字段，避免误导作者反复修改原文。 */
    public function test_invalid_note_has_its_own_validation_field(): void
    {
        $version = ChapterVersion::factory()->create();
        try {
            app(ReadingRecordService::class)->record($version->chapter->novel_id, $version->id, User::factory()->create(), 'issue', '林澈', '');
            $this->fail('空白问题说明必须拒绝');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('note', $error->errors());
            $this->assertArrayNotHasKey('quote', $error->errors());
        }
    }

    /** 业务校验失败显示在弹窗内，保留问题说明并支持修正摘录后提交。 */
    public function test_issue_modal_shows_source_error_and_can_retry_without_losing_note(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $version = ChapterVersion::factory()->create();
        $version->chapter->update(['current_candidate_version_id' => $version->id]);
        $page = Livewire::test(ReadChapter::class, ['record' => $version->chapter->novel_id])
            ->callAction('issue', ['quote' => '不在原文中的摘录', 'note' => '缺少人物背景，叙事缺少情绪起伏'])
            ->assertHasActionErrors(['quote'])
            ->assertNotified('原文问题未保存')
            ->assertSet('mountedActions.0.data.note', '缺少人物背景，叙事缺少情绪起伏');
        $this->assertSame(0, DB::table('reading_records')->count());
        $page->fillForm(['quote' => '林澈'], 'mountedActionSchema0')
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('原文问题已保存');
        $this->assertDatabaseHas('reading_records', ['version_id' => $version->id, 'kind' => 'issue', 'quote' => '林澈', 'note' => '缺少人物背景，叙事缺少情绪起伏']);
    }

    /** 历史版本不能从另一小说URL打开，自己的备注不会展示给其他账号。 */
    public function test_reader_checks_lineage_and_hides_other_users_notes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $version = ChapterVersion::factory()->create();
        $chapter = $version->chapter;
        $chapter->update(['current_candidate_version_id' => $version->id]);
        $url = NovelResource::getUrl('reader', ['record' => $chapter->novel_id, 'chapter' => $chapter->number]);
        $this->get($url)->assertOk()->assertSee($version->body)->assertSee('候选 / 历史版本');
        Livewire::test(ReadChapter::class, ['record' => $chapter->novel_id])
            ->callAction('markRead')->assertHasNoActionErrors()->assertNotified('此版本已标记读完');
        app(ReadingRecordService::class)->record($chapter->novel_id, $version->id, $actor, 'issue', '林澈', '仅本账号可见的备注');
        $this->get($url)->assertSee('仅本账号可见的备注');
        $this->actingAs(User::factory()->create())->get($url)->assertDontSee('仅本账号可见的备注');
        $other = Novel::factory()->create();
        $this->get(NovelResource::getUrl('reader', ['record' => $other, 'version' => $version->id]))->assertNotFound();
    }
}
