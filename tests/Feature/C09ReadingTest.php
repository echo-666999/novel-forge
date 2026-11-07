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
