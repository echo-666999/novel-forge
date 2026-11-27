<?php

namespace Tests\Feature;

use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Services\Pipeline\ChapterHandoff;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** 章末交接：只取上一章正式版本检查时记下的交接，下一章指令先接住上一章到期的约定。 */
class ChapterHandoffTest extends TestCase
{
    use DatabaseMigrations;

    /** 设置迁移与配置迁移使用同一隔离数据库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 清除内存夹具，避免保护性回滚删除真实历史。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 只认正式版本的检查结果；没有正式版本、没有交接或第1章时为空。 */
    public function test_previous_reads_formal_version_check(): void
    {
        $run = ChapterGenerationRun::factory()->create();
        $chapter = $run->chapter;
        $chapter->update(['number' => 1]);
        $novel = $chapter->novel;
        $formal = ChapterVersion::factory()->create(['run_id' => $run->id]);
        $draft = ChapterVersion::factory()->create(['run_id' => $run->id]);
        $handoff = ['story_time' => '重生后第一天夜里', 'location' => '自家灶间', 'open_promises' => [['who' => '钱有福', 'when' => '明天', 'what' => '叫周小麦去后勤科', 'planned' => true]]];
        ChapterProgressionCheck::factory()->create(['version_id' => $formal->id, 'result' => ['points' => [], 'handoff' => $handoff]]);
        ChapterProgressionCheck::factory()->create(['version_id' => $draft->id, 'result' => ['points' => [], 'handoff' => [...$handoff, 'story_time' => '候选稿']]]);
        $this->assertNull(ChapterHandoff::previous($novel, 2));
        $chapter->update(['formal_version_id' => $formal->id]);
        $this->assertSame(['chapter_number' => 1, ...$handoff], ChapterHandoff::previous($novel, 2));
        $this->assertNull(ChapterHandoff::previous($novel, 1));
        $this->assertNull(ChapterHandoff::previous($novel, 3));

        $text = ChapterHandoff::planInstructions(ChapterHandoff::previous($novel, 2), 'draft');
        $this->assertStringContainsString('上一章（第1章）结束时：故事时间重生后第一天夜里，人物在自家灶间。上一章留下的约定：钱有福：明天，叫周小麦去后勤科。', $text);
        $this->assertStringContainsString('本章开头先交代结果', $text);
        $this->assertStringContainsString('细纲第一个场景的time', ChapterHandoff::planInstructions(null, 'fine'));
    }

    /** 交接结构不合格时抛出，由检查记为未检查；约定最多保留五条。 */
    public function test_parse(): void
    {
        $promise = ['who' => '甲', 'when' => '明天', 'what' => '见面', 'planned' => false];
        $this->assertCount(ChapterHandoff::MAX_PROMISES, ChapterHandoff::parse(['story_time' => '傍晚', 'location' => '厂门口', 'open_promises' => array_fill(0, 7, $promise)])['open_promises']);
        $this->expectException(InvalidArgumentException::class);
        ChapterHandoff::parse(['story_time' => '傍晚', 'location' => '厂门口', 'open_promises' => [[...$promise, 'planned' => 'no']]]);
    }
}
