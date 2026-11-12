<?php

namespace Tests\Feature;

use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\NovelOutlinePlan;
use App\Models\User;
use App\Services\Pipeline\CanonicalBaselines;
use App\Services\Pipeline\StyleSignals;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 跨章规则文风信号只从近章正式正文计算，不发送请求。 */
class StyleSignalsTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移应用表，隔离于真实数据和供应商。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 清理正文历史后才能回滚迁移。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 三章以上出现的模板句按最长共同形式列出，两章出现的不列；开头结尾取最近三章。 */
    public function test_signals_flag_cross_chapter_phrases_openings_and_endings(): void
    {
        $novel = Novel::factory()->create();
        $template = '沈禾按双人流程请小林到场核对封口条';
        $this->commit($novel, 1, '第一日上午，'.$template.'。她把旧照片摊开，又看了一遍。门外下着雨，两人都没说话。');
        $this->commit($novel, 2, '第二日清晨，'.$template.'。档案柜第三格空着，灯管嗡嗡作响。');
        $this->commit($novel, 3, '傍晚时分，'.$template.'。巷口的修鞋摊收了，她把旧照片摊开。');
        $this->commit($novel, 4, '母亲在厨房里切葱，问她今晚回不回来吃饭。陌生号码又发来一句话：柜子还在。');
        $this->commit($novel, 5, '这一章不在第五章之前的窗口里，也不应该出现。');

        $this->assertNull(app(StyleSignals::class)->context($novel, 1));
        $signals = app(StyleSignals::class)->context($novel, 5);
        $this->assertSame(StyleSignals::VERSION, $signals['version']);
        $this->assertSame(['from' => 1, 'to' => 4], $signals['window']);
        $this->assertSame([['phrase' => $template, 'chapters' => [1, 2, 3]]], $signals['overused_phrases']);
        $this->assertSame([2, 3, 4], array_column($signals['recent_openings'], 'chapter'));
        $this->assertSame('母亲在厨房里切葱，问她今晚回不回来吃饭。', $signals['recent_openings'][2]['text']);
        $this->assertSame('陌生号码又发来一句话：柜子还在。', $signals['recent_endings'][2]['text']);
        $this->assertStringContainsString('不得为避开重复删减事实', $signals['instruction']);

        $hints = StyleSignals::inspect('母亲在厨房里切葱，问她今晚回不回来。随后'.$template.'。', $signals);
        $this->assertSame([['type' => 'overused_phrase', 'text' => $template, 'chapters' => [1, 2, 3]], ['type' => 'similar_opening', 'text' => '母亲在厨房里切葱，问她今晚回不回来。', 'chapters' => [4]]], $hints);
        $this->assertSame([], StyleSignals::inspect('雨停了。她走进修复室。', $signals));
    }

    /** 建一章并把指定正文设为正式版本。 */
    private function commit(Novel $novel, int $number, string $body): void
    {
        $plan = NovelOutlinePlan::where('novel_id', $novel->id)->first() ?? NovelOutlinePlan::factory()->create(['novel_id' => $novel->id]);
        $outline = NovelOutlineChapter::factory()->create(['outline_plan_id' => $plan->id, 'chapter_number' => $number]);
        $chapter = Chapter::factory()->create(['novel_id' => $novel->id, 'number' => $number]);
        $run = ChapterGenerationRun::factory()->create(['outline_chapter_id' => $outline->id, 'chapter_id' => $chapter->id]);
        $version = ChapterVersion::factory()->create(['run_id' => $run->id, 'body' => $body, 'body_hash' => hash('sha256', $body)]);
        // 文风信号按基线提交链读取正文：建一条衔接的提交记录挂在初始基线上。
        $baseline = app(CanonicalBaselines::class)->active($novel->refresh());
        $previous = CanonicalCommit::where('baseline_id', $baseline->id)->max('state_version') ?? 0;
        $commit = CanonicalCommit::create(['submission_run_id' => null, 'validation_id' => null, 'novel_id' => $novel->id, 'chapter_id' => $chapter->id, 'version_id' => $version->id,
            'previous_state_version' => $previous, 'state_version' => $previous + 1, 'idempotency_key' => (string) Str::uuid7(), 'request_hash' => str_repeat('0', 64),
            'committed_by' => User::factory()->create()->id, 'reason' => '文风信号夹具', 'approvals' => [], 'result' => ['body_hash' => $version->body_hash], 'created_at' => now(),
            'baseline_id' => $baseline->id, 'chapter_number' => $number, 'origin' => 'submission', 'source_commit_id' => null]);
        $chapter->update(['formal_version_id' => $version->id, 'canonical_commit_id' => $commit->id]);
    }
}
