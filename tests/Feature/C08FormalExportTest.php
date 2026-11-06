<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\FormalChapterExport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 导出只读正式基线，覆盖十章顺序、候选隔离、Unicode和实际下载响应。 */
class C08FormalExportTest extends TestCase
{
    use DatabaseMigrations;

    /** 迁移仅运行于内存测试库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 禁止意外AI调用，并建立后台登录上下文。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    /** 只清理隔离夹具，不触碰正式开发数据。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 逆序插入十章，保证导出依据章序且标题取正式版本而非可变章节标题。 */
    private function history(): Novel
    {
        $novel = Novel::factory()->create(['title' => '书名𠮷😀', 'chapters_done' => 10, 'state_version' => 10]);
        foreach (range(10, 1) as $number) {
            $chapter = Chapter::factory()->create(['novel_id' => $novel->id, 'number' => $number, 'title' => '不应导出的候选标题']);
            $run = ChapterGenerationRun::factory()->create(['chapter_id' => $chapter->id]);
            $body = '正式第'.$number.'章，𠮷😀。'."\n第二段保持完整。";
            $version = ChapterVersion::factory()->create(['run_id' => $run->id, 'title' => ($number === 2 ? '第二章 ' : '').'正式章名'.$number, 'body' => $body, 'body_hash' => hash('sha256', $body)]);
            $submission = ChapterSubmissionRun::factory()->create(['version_id' => $version->id]);
            $validation = ChapterSubmissionValidation::factory()->create(['submission_run_id' => $submission->id]);
            $commit = CanonicalCommit::factory()->create(['validation_id' => $validation->id, 'previous_state_version' => $number - 1, 'state_version' => $number, 'result' => ['body_hash' => $version->body_hash]]);
            $candidate = ChapterVersion::factory()->create(['run_id' => $run->id, 'body' => '未正式提交的候选不得导出']);
            $chapter->update(['formal_version_id' => $version->id, 'canonical_commit_id' => $commit->id, 'current_candidate_version_id' => $candidate->id]);
        }

        return $novel;
    }

    /** 两种下载格式均包含目录、完整正文及正确顺序，且不调用AI。 */
    #[DataProvider('formats')]
    public function test_download_preserves_formal_content_and_order(string $format): void
    {
        $novel = $this->history();
        $export = app(FormalChapterExport::class)->build($novel->id, 10, $format);
        $this->assertCount(10, $export['manifest']);
        $position = -1;
        foreach (range(1, 10) as $number) {
            $body = '正式第'.$number.'章，𠮷😀。'."\n第二段保持完整。";
            $current = strpos($export['content'], $body);
            $this->assertNotFalse($current);
            $this->assertGreaterThan($position, $current);
            $position = $current;
            $this->assertSame(2, substr_count($export['content'], '第'.$number.'章 正式章名'.$number."\n"));
        }
        $this->assertStringNotContainsString('未正式提交', $export['content']);
        $this->assertStringNotContainsString('不应导出', $export['content']);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->callAction('exportFormal', data: ['format' => $format, 'end_chapter' => 10])
            ->assertHasNoActionErrors()->assertFileDownloaded($export['filename'], $export['content'], $export['mime']);
        Http::assertNothingSent();
    }

    /** 对外只开放两种文本格式。 */
    public static function formats(): array
    {
        return [['txt'], ['md']];
    }

    /** 指针损坏、缺章、正文被篡改及越界范围均拒绝，不静默输出部分作品。 */
    #[DataProvider('invalidSources')]
    public function test_invalid_baseline_is_rejected(string $kind): void
    {
        $novel = $this->history();
        $chapter = $novel->chapters()->where('number', 5)->firstOrFail();
        if ($kind === 'missing') {
            $chapter->update(['number' => 11]);
        } elseif ($kind === 'candidate') {
            $chapter->update(['formal_version_id' => $chapter->current_candidate_version_id]);
        } elseif ($kind === 'hash') {
            DB::table('chapter_versions')->where('id', $chapter->formal_version_id)->update(['body' => '篡改正文']);
        }
        $this->expectException(InvalidArgumentException::class);
        app(FormalChapterExport::class)->build($novel->id, $kind === 'range' ? 11 : 10, 'txt');
    }

    /** 每项破坏独立的正式来源边界。 */
    public static function invalidSources(): array
    {
        return [['missing'], ['candidate'], ['hash'], ['range']];
    }
}
