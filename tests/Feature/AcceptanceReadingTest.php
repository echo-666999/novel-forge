<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\AcceptanceReading;
use App\Filament\Resources\Novels\Pages\AcceptanceWorkbench as WorkbenchPage;
use App\Models\AcceptanceBatch;
use App\Models\AcceptanceEvidence;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\AcceptanceWorkbench;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** 逐章验收阅读页：冻结正文与评分同屏，规则仍由验收服务校验，不调用模型。 */
class AcceptanceReadingTest extends TestCase
{
    use DatabaseMigrations;

    /** 测试使用隔离内存库。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 仅清理内存验收夹具。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('acceptance_evidence')->delete();
        DB::table('acceptance_batches')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 构造覆盖前两章的冻结批次，并以负责人身份登录后台。
     * @return array{0: Novel, 1: User, 2: AcceptanceBatch}
     */
    private function batch(): array
    {
        Http::preventStrayRequests();
        [$novel] = C02StoryStateTest::history();
        $novel->update(['chapters_done' => 6, 'state_version' => 6]);
        $actor = User::factory()->create();
        $batch = app(AcceptanceWorkbench::class)->create($novel->id, $actor, 'recovery', 2, '隔离测试，不代表真实作品通过');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($actor);

        return [$novel, $actor, $batch];
    }

    /** 冻结章节正文。 */
    private function body(AcceptanceBatch $batch, int $number): string
    {
        return ChapterVersion::findOrFail(collect($batch->snapshot['manifest'])->firstWhere('number', $number)['version_id'])->body;
    }

    /** 一份完整的达标评分输入，摘录模拟浏览器选区附带的换行与空格。
     * @return array<string, mixed>
     */
    private function rating(string $quote, int $score = 4): array
    {
        return ['scores' => array_fill_keys(array_keys(AcceptanceWorkbench::DIMENSIONS), $score), 'quote' => $quote,
            'reason' => '隔离逐章评分', 'repair' => '', 'blocking' => 0, 'read_confirmed' => true];
    }

    /** 默认打开第一章未评分章节，展示冻结正文与进度，表单不预填分数。 */
    public function test_page_shows_frozen_body_and_blank_form(): void
    {
        [$novel, , $batch] = $this->batch();
        Livewire::test(AcceptanceReading::class, ['record' => $novel->id, 'batch' => $batch->id])
            ->assertSet('chapterNumber', 1)
            ->assertSee($this->body($batch, 1))
            ->assertSee('已评分 0/2 章')
            ->assertSeeHtml('data-acceptance-body')
            ->assertSee('引用选中文字')
            ->assertSeeHtml('novelForgeAcceptanceSelection')
            // 处理脚本写在 x-on:click 双引号属性中，必须完整保留到最后一句，不能被双引号截断。
            ->assertSeeHtml("current + '\\n\\n' + text : text);\nwindow.novelForgeAcceptanceSelection = '';")
            ->assertSet('data.scores.plot', null)
            ->assertSet('data.read_confirmed', false);
    }

    /** 保存后追加评分证据并进入下一章，正文选区的空白差异不影响匹配。 */
    public function test_save_and_next_records_rating_and_moves_on(): void
    {
        [$novel, $actor, $batch] = $this->batch();
        $body = $this->body($batch, 1);
        $quote = ' '.mb_substr($body, 0, 3)."\n ".mb_substr($body, 3, 4).' ';
        Livewire::test(AcceptanceReading::class, ['record' => $novel->id, 'batch' => $batch->id])
            ->fillForm($this->rating($quote))
            ->call('saveAndNext')
            ->assertHasNoFormErrors()
            ->assertNotified('第1章评分已保存')
            ->assertRedirect(NovelResource::getUrl('acceptanceReading', ['record' => $novel, 'batch' => $batch->id, 'chapter' => 2]));
        $evidence = AcceptanceEvidence::sole();
        $this->assertSame('chapter_1', $evidence->case_key);
        $this->assertSame($actor->id, $evidence->actor_id);
        $this->assertTrue($evidence->payload['pass']);
        $this->assertSame(mb_substr($body, 0, 7), $evidence->payload['quote']);
        $progress = app(AcceptanceWorkbench::class)->progress($batch);
        $this->assertSame([1, 1, 2], [$progress['rated'], $progress['passed'], $progress['total']]);
        $this->assertSame(2, app(AcceptanceWorkbench::class)->nextChapterToRate($batch, 1));
        $this->assertSame(0, CostLog::count());
        Http::assertNothingSent();
    }

    /** 非本章原文和缺少修复期待的低分均回到表单字段，不写入证据。 */
    public function test_invalid_quote_and_missing_repair_show_field_errors(): void
    {
        [$novel, , $batch] = $this->batch();
        $page = Livewire::test(AcceptanceReading::class, ['record' => $novel->id, 'batch' => $batch->id, 'chapter' => 1])
            ->fillForm($this->rating('不是本章原文'))->call('save')
            ->assertHasErrors(['data.quote'])->assertNotified('人工评分未保存');
        $low = $this->rating(mb_substr($this->body($batch, 1), 0, 4), 2);
        $page->fillForm($low)->call('save')->assertHasErrors(['data.repair']);
        $this->assertSame(0, AcceptanceEvidence::count());
    }

    /** 未达标评分同样保存，并在章节导航中标记为未达标。 */
    public function test_low_score_with_repair_is_saved_as_failed(): void
    {
        [$novel, , $batch] = $this->batch();
        $data = [...$this->rating(mb_substr($this->body($batch, 1), 0, 4), 2), 'repair' => '补充背景交代'];
        Livewire::test(AcceptanceReading::class, ['record' => $novel->id, 'batch' => $batch->id, 'chapter' => 1])
            ->fillForm($data)->call('save')->assertHasNoFormErrors()->assertNotified('第1章评分已保存');
        $this->assertFalse(AcceptanceEvidence::sole()->payload['pass']);
        $this->assertSame('rejected', $batch->fresh()->status);
        $this->assertSame(1, app(AcceptanceWorkbench::class)->nextChapterToRate($batch, 2));
    }

    /** 非负责人只能阅读；批次外章节与他书批次返回404。 */
    public function test_access_boundaries(): void
    {
        [$novel, , $batch] = $this->batch();
        $this->actingAs(User::factory()->create());
        Livewire::test(AcceptanceReading::class, ['record' => $novel->id, 'batch' => $batch->id])
            ->assertSee('只有批次负责人可以评分')
            ->call('save')->assertForbidden();
        $this->get(NovelResource::getUrl('acceptanceReading', ['record' => $novel, 'batch' => $batch->id, 'chapter' => 5]))->assertNotFound();
        $other = Novel::factory()->create();
        $this->get(NovelResource::getUrl('acceptanceReading', ['record' => $other, 'batch' => $batch->id]))->assertNotFound();
        $this->assertSame(0, AcceptanceEvidence::count());
    }

    /** 工作台展示当前批次进度矩阵、外部证据取证说明及阅读评分入口。 */
    public function test_workbench_shows_progress_and_entry(): void
    {
        [$novel, , $batch] = $this->batch();
        Livewire::test(WorkbenchPage::class, ['record' => $novel->id])
            ->assertSee('批次 #'.$batch->id.' 逐章评分进度')
            ->assertSee('开始逐章阅读评分')
            ->assertSee('外部证据待办')
            ->assertSee(AcceptanceWorkbench::CASE_GUIDANCE['pre_generation_contract'])
            ->assertTableActionHasUrl('readAndRate', NovelResource::getUrl('acceptanceReading', ['record' => $novel, 'batch' => $batch->id]), $batch);
    }
}
