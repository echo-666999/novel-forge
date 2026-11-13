<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ChapterDirectory;
use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Models\Chapter;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
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

    /** 未指定章号时打开最近一章未提交的章节，并可切换章节；指定章号时仍按指定打开。 */
    public function test_workbench_opens_latest_unsubmitted_chapter(): void
    {
        $first = $this->createRun();
        $first->chapter->update(['formal_version_id' => $this->createVersion($first, '第一章正式正文。')->id]);
        $chapter = Chapter::factory()->create(['novel_id' => $first->chapter->novel_id, 'number' => 2]);
        $second = ChapterGenerationRun::factory()->create(['chapter_id' => $chapter->id, 'snapshot' => $first->snapshot]);
        $chapter->update(['current_candidate_version_id' => $this->createVersion($second, '第二章待提交候选。')->id]);

        Livewire::test(ViewChapterContent::class, ['record' => $chapter->novel_id])
            ->assertSet('chapterNumber', 2)->assertSee('第二章待提交候选。')->assertActionVisible('selectChapter')
            ->callAction('selectChapter', ['number' => 1])->assertSet('chapterNumber', 1)->assertSee('第一章正式正文。');
    }

    /** 章节目录只为未正式提交的章节提供“正文与提交”入口。 */
    public function test_directory_offers_workbench_only_for_unsubmitted_chapters(): void
    {
        $first = $this->createRun();
        $first->chapter->update(['formal_version_id' => $this->createVersion($first, '第一章正式正文。')->id]);
        $second = Chapter::factory()->create(['novel_id' => $first->chapter->novel_id, 'number' => 2]);

        Livewire::test(ChapterDirectory::class, ['record' => $first->chapter->novel_id])
            ->assertTableActionHidden('workbench', $first->chapter)
            ->assertTableActionVisible('workbench', $second);
    }

    /** 目录合并章号与章名、状态带标签、字数取正式稿或候选，不再显示内部记录编号；可按章名搜索。 */
    public function test_directory_shows_compact_columns_and_searches_title(): void
    {
        $first = $this->createRun();
        $first->chapter->update(['title' => '缺口钥匙', 'formal_version_id' => $this->createVersion($first, '第一章正式正文。')->id]);
        $second = Chapter::factory()->create(['novel_id' => $first->chapter->novel_id, 'number' => 2, 'title' => '移交清单']);

        Livewire::test(ChapterDirectory::class, ['record' => $first->chapter->novel_id])
            ->assertSee('第1章 缺口钥匙')->assertSee('第2章 移交清单')->assertSee('字数')
            ->assertDontSee('候选正文记录编号')->assertDontSee('正式正文记录编号')
            ->searchTable('移交')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first->chapter]);
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

    /** 逐点检查显示冻结细纲的推进内容，全文复核显示原风险说明，均不再暴露内部编号。 */
    public function test_check_results_show_plan_and_original_finding_instead_of_ids(): void
    {
        $run = ChapterGenerationRun::factory()->create([
            'snapshot' => ['novel' => ['chapter_length_policy' => ['min_characters' => 2000, 'max_characters' => 3000]], 'source_chapter' => ['progression_points' => [
                ['id' => 'chapter-1-point-1', 'subject' => '沈禾与移交清单', 'change_type' => 'information', 'before' => '未注意编号连续性', 'after' => '确认缺号对应专柜编号段'],
            ]]],
            'config' => ['recovery_findings' => ['finding-abc' => ['type' => 'echoed_dialogue', 'reason' => '第25段叙述先转述了第26段对白']]],
        ]);
        $run->chapter->update(['progression_result' => [
            'points' => [['id' => 'chapter-1-point-1', 'met' => true, 'reason' => '沈禾圈出跳号处', 'evidence' => [['paragraph' => 4, 'quote' => '十七号段呢？']]]],
            'reading_reviews' => [['id' => 'finding-abc', 'fixed' => true, 'disposition' => 'benign', 'reason' => '属于概述后展开', 'evidence' => [['paragraph' => 25, 'quote' => '含混带过']]]],
        ]]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('推进点1 · 沈禾与移交清单（信息变化）')
            ->assertSee('未注意编号连续性 → 确认缺号对应专柜编号段')
            ->assertSee('第4段：十七号段呢？')
            ->assertSee('复核1 · 叙述转述对白')
            ->assertSee('第25段叙述先转述了第26段对白')
            ->assertSee('复核为合理表达')
            ->assertSee('第25段：含混带过')
            ->assertDontSee('chapter-1-point-1')
            ->assertDontSee('finding-abc');
    }

    /** 连贯性问题显示中文类型，核心冲突拆出被违反的设定与具体说明，证据为单行列表。 */
    public function test_continuity_and_conflicts_show_readable_rows(): void
    {
        $run = $this->createRun();
        $run->chapter->update(['progression_result' => [
            'continuity_issues' => [['type' => 'repeated_action', 'reason' => '沈禾两次起身走向门口', 'evidence' => [['paragraph' => 7, 'quote' => '她起身走向门口。']]]],
            'conflicts' => [['reason' => '规则R2：钥匙只能由沈禾保管；第9段钥匙出现在小陶手里', 'evidence' => [['paragraph' => 9, 'quote' => '小陶掂了掂钥匙。']]]],
            'setting_violations' => [['rule_id' => 'R2', 'reason' => '第9段钥匙出现在小陶手里', 'evidence_ids' => ['p9']]],
        ]]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('问题1 · 重复动作')
            ->assertSee('需修订')
            ->assertSee('第7段：她起身走向门口。')
            ->assertSee('冲突1 · 违反设定规则')
            ->assertSee('钥匙只能由沈禾保管')
            ->assertSee('第9段：小陶掂了掂钥匙。')
            ->assertDontSee('repeated_action')
            ->assertDontSee('规则R2：');
    }

    /** 章末钩子显示触发状态、细纲计划钩子、判断理由和单行证据。 */
    public function test_hook_check_shows_status_plan_and_evidence(): void
    {
        $run = $this->createRun();
        $run->update(['plan_data' => ['hook' => '调阅单存根日期与火灾前一周吻合']]);
        $run->chapter->update(['progression_result' => [
            'hook' => ['met' => false, 'reason' => '章末停在吃饭，没有触发存根悬念', 'evidence' => [['paragraph' => 48, 'quote' => '门合上后，修复室里只剩空调的低响。']]],
        ]]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('章末钩子')
            ->assertSee('未触发')
            ->assertSee('调阅单存根日期与火灾前一周吻合')
            ->assertSee('章末停在吃饭，没有触发存根悬念')
            ->assertSee('第48段：门合上后，修复室里只剩空调的低响。');
    }

    /** 可读性逐项显示实测值与状态；本地复核命中重复原文时显示线索、提醒和单行原文位置。 */
    public function test_readability_and_local_reading_clues_show_readable_rows(): void
    {
        $run = $this->createRun();
        $body = "她把清单登记编号后交接归档。\n她又把清单登记编号后交接归档。";
        $run->chapter->update(['current_candidate_version_id' => $this->createVersion($run, $body)->id]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('直接对白占比：0%')
            ->assertSee('过低')
            ->assertSee('流程词密度：每千字')
            ->assertSee('过高')
            ->assertSee('仅观察')
            ->assertSee('线索1 · 重复原文')
            ->assertSee('须人工复核')
            ->assertSee('模型通过不能视为全文验收通过')
            ->assertSee('第2段：');
    }

    /** 细纲场景以时间地点作标题并显示推进点序号，钩子安排合为一条；文风提示显示中文类型与章节。 */
    public function test_outline_and_style_hints_show_readable_rows(): void
    {
        $run = ChapterGenerationRun::factory()->create([
            'snapshot' => ['novel' => ['chapter_length_policy' => ['min_characters' => 2000, 'max_characters' => 3000]],
                'source_chapter' => ['progression_points' => [['id' => 'chapter-1-point-1'], ['id' => 'chapter-1-point-2']]],
                'style_signals' => ['overused_phrases' => [['phrase' => '心里一沉', 'chapters' => [1, 2]]]]],
            'plan_data' => [
                'scenes' => [['time' => '同日中午', 'place' => '修复室', 'goal' => '沈禾想对上缺号', 'action' => '把清单与复印件并排', 'change' => '确认缺号并非漏装', 'point_ids' => ['chapter-1-point-2']]],
                'hook' => '存根日期与火灾前一周吻合',
                'hook_transition' => ['trigger' => '背面铅笔写着十七', 'next_question' => '谁调阅了专柜？', 'point_ids' => ['chapter-1-point-1', 'chapter-1-point-2']],
                'adjustments' => ['不重复观察钥匙的步骤'],
            ],
        ]);
        $run->chapter->update(['current_candidate_version_id' => $this->createVersion($run, '她心里一沉。')->id]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('场景1 · 同日中午 · 修复室')
            ->assertSee('对应推进点2')
            ->assertSee('沈禾想对上缺号')
            ->assertDontSee('关键对白')
            ->assertSee('章末钩子安排')
            ->assertSee('对应推进点1、推进点2')
            ->assertSee('谁调阅了专柜？')
            ->assertSee('不重复观察钥匙的步骤')
            ->assertSee('提示1 · 沿用近章反复出现的短语')
            ->assertSee('仅供参考')
            ->assertSee('第1、2章')
            ->assertDontSee('chapter-1-point-2');
    }

    /** 工作台显示轮次标签、指定问题与冻结来源，复评完成后按逐项卡片展示结论。 */
    public function test_revision_workbench_shows_rounds_issues_and_review_rows(): void
    {
        $run = ChapterGenerationRun::factory()->create(['requests_used' => 2,
            'snapshot' => ['novel' => ['chapter_length_policy' => ['min_characters' => 2000, 'max_characters' => 3000]]],
            'config' => [
                'workbench' => ['round' => 1, 'round_limit' => 2, 'version_id' => 77, 'issues' => ['小陶口吻前后不一致']],
                'recovery_findings' => ['finding-abc' => ['type' => 'author_issue', 'reason' => '小陶口吻前后不一致']],
            ]]);
        $revised = $this->createVersion($run, '修订后正文。');
        $run->chapter->update(['current_candidate_version_id' => $revised->id, 'progression_result' => [
            'version_id' => $revised->id, 'verdict' => 'pass',
            'reading_reviews' => [['id' => 'finding-abc', 'fixed' => true, 'disposition' => 'resolved', 'reason' => '对白已统一为回避语气', 'evidence' => [['paragraph' => 1, 'quote' => '修订后正文。']]]],
        ]]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('已授权 1 / 2 轮')
            ->assertSee('可发起修订')
            ->assertSee('冻结正文记录 #77')
            ->assertSee('复评正文记录 #'.$revised->id)
            ->assertSee('复评通过，等待全文核对')
            ->assertSee('对白已统一为回避语气')
            ->assertSee('第1段：修订后正文。')
            ->assertDontSee('finding-abc');
    }

    /** 提交准备按评审问题、事件提取、确定性验证分卡片显示，证据为单行原文。 */
    public function test_submission_sections_show_readable_cards(): void
    {
        $run = $this->createRun();
        $version = $this->createVersion($run, '她把钥匙放进物证袋。');
        $run->chapter->update(['current_candidate_version_id' => $version->id]);
        $submission = ChapterSubmissionRun::factory()->create(['version_id' => $version->id, 'status' => 'awaiting_approval', 'requests_used' => 2]);
        $quote = ['paragraph' => 2, 'quote' => '她把钥匙放进物证袋。'];
        $review = ChapterReview::factory()->create(['submission_run_id' => $submission->id, 'result' => ['verdict' => 'pass', 'summary' => '结构完整。', 'findings' => [
            ['kind' => 'continuity', 'severity' => 'blocking', 'reason' => '钥匙位置前后矛盾。', 'evidence' => [$quote]],
        ]]]);
        $extraction = ChapterEventExtraction::factory()->create(['submission_run_id' => $submission->id, 'result' => [
            'events' => [['narrative_order' => 1, 'summary' => '沈禾封存钥匙', 'event_type' => 'statement', 'time_scope' => 'memory', 'evidence' => [$quote]]],
            'patch' => [], 'foreshadows' => [],
            'proposed_entities' => [['key' => 'new-1', 'core' => false, 'name' => '档案馆门厅', 'type' => 'location', 'aliases' => [], 'description' => '大门外的门厅。', 'evidence' => [$quote]]],
        ]]);
        ChapterSubmissionValidation::factory()->create(['submission_run_id' => $submission->id, 'review_id' => $review->id, 'extraction_id' => $extraction->id,
            'result' => ['verdict' => 'pass', 'errors' => [], 'approval_required' => ['new-1']]]);

        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])
            ->assertSee('提交准备 #'.$submission->id.' · 已用请求 2 / 2')
            ->assertSee('硬检查通过，等待作者批准新实体')
            ->assertSee('评审通过')
            ->assertSee('钥匙位置前后矛盾。')
            ->assertSee('第2段：她把钥匙放进物证袋。')
            ->assertSee('事件 1 · 状态变化 0 · 新实体 1')
            ->assertSee('新实体1')
            ->assertSee('档案馆门厅（新实体）')
            ->assertSee('需要作者批准')
            ->assertSee('尚未正式提交');
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
