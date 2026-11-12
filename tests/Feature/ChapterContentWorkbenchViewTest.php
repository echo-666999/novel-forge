<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** 正文工作台的弹窗位置、正式稿展示和章节选择标签。 */
class ChapterContentWorkbenchViewTest extends TestCase
{
    use DatabaseMigrations;

    /** 只运行项目迁移与设置迁移，跳过 SQLite 不支持的 pgvector 扩展迁移。
     * @return array{'--path': list<string>}
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 后台资源页依赖当前面板解析路由。 */
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    /** 只清理已确认的内存库，迁移回滚保护保持开启。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 带页面所需篇幅策略快照的章节运行。 */
    private function createRun(): ChapterGenerationRun
    {
        return ChapterGenerationRun::factory()->create(['snapshot' => ['novel' => ['chapter_length_policy' => ['min_characters' => 2000, 'max_characters' => 3000]]]]);
    }

    /** 指定正文的版本，摘要与正文保持一致。 */
    private function createVersion(ChapterGenerationRun $run, string $body): ChapterVersion
    {
        return ChapterVersion::factory()->create(['run_id' => $run->id, 'body' => $body, 'body_hash' => hash('sha256', $body)]);
    }

    /** 表格位于「运行与历史」标签内；动作弹窗须先于标签渲染，否则其他标签下的头部按钮弹窗不可见。 */
    public function test_action_modals_render_before_tabs(): void
    {
        $run = $this->createRun();

        $html = Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])->html();

        $modalsPosition = strpos($html, 'wire:partial="action-modals"');
        $tabsPosition = strpos($html, 'fi-sc-tabs');
        $this->assertNotFalse($modalsPosition);
        $this->assertNotFalse($tabsPosition);
        $this->assertLessThan($tabsPosition, $modalsPosition);
        $this->assertSame(1, substr_count($html, 'wire:partial="action-modals"'));
    }

    /** 正式稿可能晚于候选再修订，正文标签须展示正式版本而非旧候选。 */
    public function test_formal_chapter_shows_formal_version_body(): void
    {
        $run = $this->createRun();
        $candidate = $this->createVersion($run, '旧候选正文段落。');
        $formal = $this->createVersion($run, '正式提交后的正文段落。');
        $run->chapter->update(['current_candidate_version_id' => $candidate->id, 'formal_version_id' => $formal->id]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('正式稿正文')
            ->assertSee('已正式提交的版本（只读）')
            ->assertSee('正式提交后的正文段落。')
            ->assertDontSee('旧候选正文段落。')
            ->assertDontSee('当前正文候选');
    }

    /** 未提交章节仍展示当前候选。 */
    public function test_unsubmitted_chapter_shows_candidate_body(): void
    {
        $run = $this->createRun();
        $run->chapter->update(['current_candidate_version_id' => $this->createVersion($run, '候选正文段落。')->id]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('当前正文候选')
            ->assertSee('本运行已保存的候选版本')
            ->assertSee('候选正文段落。')
            ->assertDontSee('正式稿正文');
    }

    /** 章名自带章号时只保留一个编号，未带章号的章名保持不变。 */
    public function test_numbered_title_removes_duplicated_chapter_prefix(): void
    {
        $this->assertSame('第4章 送样与旧邻', (new Chapter(['number' => 4, 'title' => '第4章 送样与旧邻']))->numberedTitle());
        $this->assertSame('第7章 残页前夜', (new Chapter(['number' => 7, 'title' => '第7章残页前夜']))->numberedTitle());
        $this->assertSame('第12章 双人在场', (new Chapter(['number' => 12, 'title' => '第十二章：双人在场']))->numberedTitle());
        $this->assertSame('第1章 缺口钥匙', (new Chapter(['number' => 1, 'title' => '缺口钥匙']))->numberedTitle());
    }
}
