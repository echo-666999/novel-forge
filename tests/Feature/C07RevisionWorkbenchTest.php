<?php

namespace Tests\Feature;

use App\Enums\StageStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\EditChapterDraft;
use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\ParagraphDiff;
use App\Services\Pipeline\RevisionWorkbench;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;

/** 复用真实执行器及HTTP夹具，核验定点修订、再次评审和服务端轮次边界。 */
class C07RevisionWorkbenchTest extends B04ExecutionTest
{
    /** 修订两次都因格式停下后，已保存响应按当前规则复验：仍不合格时拒绝且不改状态；合格时采用并继续检查，不重发修订请求。 */
    public function test_saved_revision_can_be_revalidated_without_new_request(): void
    {
        $this->scenario = 'meta_buried';
        $run = $this->start();
        $this->drain();
        $this->assertSame('invalid_schema', $run->fresh()->stop_reason);
        $this->assertSame('revise', $run->fresh()->next_step);
        $page = Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])->assertActionVisible('revalidateRevision');
        try {
            app(ContentGenerationService::class)->revalidateRevision($run->id, User::firstOrFail(), '复验');
            $this->fail('仍不合格的已保存响应不能采用');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('已保存的修订响应仍未通过当前校验', $exception->getMessage());
        }
        $this->assertSame('paused', $run->fresh()->status);

        // 模拟校验规则更新后第一次修订响应已合格：改写保存的响应内容（仅测试夹具）。
        $source = ChapterVersion::sole();
        $stages = NovelStage::where('input->run_id', $run->id)->where('input->step', 'revise')->orderBy('id')->get();
        $first = CostLog::findOrFail($stages[0]->output['data']['cost_log_id']);
        $response = json_decode($first->response_text, true);
        $id = $response['ranges'][0]['start_id'];
        $text = str_replace('此段约350字。', '核对原档完成调查。', ContentSchema::anchors($source->body)[$id]['text']).'修订后取得新证据。';
        $response['ranges'][0]['text'] = [$text];
        DB::table('cost_logs')->where('id', $first->id)->update(['response_text' => json_encode($response, JSON_UNESCAPED_UNICODE)]);
        $requests = CostLog::count();
        $used = $run->fresh()->requests_used;

        $page->callAction('revalidateRevision', ['reason' => '规则更新后复验'])->assertHasNoActionErrors();
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertSame(['stage_id' => $stages[0]->id, 'cost_log_id' => $first->id], array_intersect_key(collect($run->fresh()->control_history)->last(), ['stage_id' => 1, 'cost_log_id' => 1]));
        $this->drain();
        $run = $run->fresh();
        $this->assertSame('awaiting_review', $run->stop_reason);
        // 只多出推进检查一次请求，修订本身未重发，也不计修订次数。
        $this->assertSame($requests + 1, CostLog::count());
        $this->assertSame($used + 1, $run->requests_used);
        $reused = NovelStage::where('input->run_id', $run->id)->where('input->reused_revision->stage_id', $stages[0]->id)->sole();
        $this->assertSame(StageStatus::Completed, $reused->status);
        $this->assertSame(2, ChapterVersion::count());
        $this->assertStringContainsString('修订后取得新证据。', ChapterVersion::latest('id')->firstOrFail()->body);
        // 同一响应不能重复登记采用。
        $this->assertNull(app(ContentExecutionService::class)->revalidatableRevision($run)['entry']);
    }

    /** 运行中状态标签直接写出正在执行的步骤；“查看完整Prompt”只加载当前运行的请求并能打开。 */
    public function test_active_step_badge_and_prompt_modal_scoped_to_run(): void
    {
        $run = $this->start();
        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])->assertSee('待处理：章节细纲')->assertDontSee('当前步骤：');
        $this->drain();
        $first = $run->fresh();
        $logs = CostLog::whereIn('novel_stage_id', NovelStage::where('input->run_id', $first->id)->pluck('id'))->pluck('id');
        $this->assertGreaterThan(0, $logs->count());

        // 另起一次修订运行，弹窗只列新运行自己的请求。
        app(ContentGenerationService::class)->recordReading($first->id, User::firstOrFail(), true, ['原候选已核对']);
        $page = Livewire::test(ViewChapterContent::class, ['record' => $first->chapter->novel_id])->assertSee('当前步骤：')
            ->callAction('reviseIssues', data: ['parent_id' => $first->id, 'version_id' => ChapterVersion::sole()->id, 'issues' => '人物口吻不一致：请把首段对白改为克制的表达。', 'accept_request' => true]);
        $this->drain();
        $child = ChapterGenerationRun::latest('id')->firstOrFail();
        $childLogs = CostLog::whereIn('novel_stage_id', NovelStage::where('input->run_id', $child->id)->pluck('id'))->pluck('id');
        $html = Livewire::test(ViewChapterContent::class, ['record' => $child->chapter->novel_id])->mountAction(TestAction::make('viewPrompts')->schemaComponent('content-attempts'))
            ->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('本运行共 '.$childLogs->count().' 次请求', $html);
        foreach ($childLogs as $id) {
            $this->assertStringContainsString('请求 #'.$id.' ', $html);
        }
        foreach ($logs as $id) {
            $this->assertStringNotContainsString('请求 #'.$id.' ', $html);
        }
        $this->assertStringContainsString('系统提示', $html);
    }

    /** 默认两轮用完后仍有入口：须勾选确认才能追加，最多四轮；工作台始终提供人工编辑入口，修订问题预填检查未通过项。 */
    public function test_rounds_beyond_default_need_confirmation_and_manual_edit_is_reachable(): void
    {
        $this->start();
        $this->drain();
        $service = app(ContentGenerationService::class);
        $round = function (bool $extra = false) use ($service): void {
            $parent = ChapterGenerationRun::latest('id')->firstOrFail();
            $service->recover($parent->id, User::firstOrFail(), '作者指定问题修订', 3, 500, issues: ['人物口吻不一致：请把首段对白改得克制。'],
                expectedVersionId: $parent->chapter->fresh()->current_candidate_version_id, extraRound: $extra);
            $this->drain();
        };
        $round();
        $round();
        $novelId = ChapterGenerationRun::latest('id')->firstOrFail()->chapter->novel_id;
        $page = Livewire::test(ViewChapterContent::class, ['record' => $novelId])->assertActionVisible('reviseIssues')->assertActionVisible('manualEdit')
            ->assertActionHasUrl('manualEdit', NovelResource::getUrl('editor', ['record' => $novelId, 'chapter' => 1]));
        // 不勾选追加确认会被表单拦下，服务端同样拒绝。
        $page->callAction('reviseIssues', data: ['issues' => '人物口吻不一致：第三轮。', 'accept_request' => true])->assertHasActionErrors(['extra_round']);
        try {
            $round();
            $this->fail('未确认不能追加第三轮');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('追加第3轮须明确确认', $exception->getMessage());
        }
        $round(true);
        $this->assertTrue(ChapterGenerationRun::latest('id')->firstOrFail()->config['workbench']['extra_round']);
        $round(true);
        Livewire::test(ViewChapterContent::class, ['record' => $novelId])->assertActionHidden('reviseIssues')->assertActionVisible('manualEdit')->assertActionVisible('rewriteChapter');
        try {
            $round(true);
            $this->fail('超过四轮不能继续定点修订');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('修订已达到4轮上限', $exception->getMessage());
        }

        // 工作台放宽本章上限（只能调高、最多10轮、留档）后可继续追加，直到新上限。
        $chapter = ChapterGenerationRun::latest('id')->firstOrFail()->chapter;
        Livewire::test(ViewChapterContent::class, ['record' => $novelId])->assertActionVisible('raiseRoundLimit')
            ->callAction('raiseRoundLimit', ['limit' => 11, 'reason' => '超上限'])->assertHasActionErrors(['limit']);
        $this->assertNull($chapter->fresh()->revision_round_limit);
        Livewire::test(ViewChapterContent::class, ['record' => $novelId])
            ->callAction('raiseRoundLimit', ['limit' => 5, 'reason' => '剩余问题继续修订'])->assertHasNoActionErrors()
            ->assertActionVisible('reviseIssues');
        $this->assertSame(5, $chapter->fresh()->revision_round_limit);
        $this->assertSame(['old' => 4, 'new' => 5], array_intersect_key(collect(ChapterGenerationRun::latest('id')->firstOrFail()->control_history)->last(), ['old' => 1, 'new' => 1]));
        try {
            app(RevisionWorkbench::class)->raiseLimit($chapter, User::firstOrFail(), 5, '不能不调高');
            $this->fail('新上限须大于当前上限');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('新上限须大于当前的 5 轮', $exception->getMessage());
        }
        $round(true);
        $this->assertSame(5, app(RevisionWorkbench::class)->rounds($chapter->id));
        $this->assertStringContainsString('达到上限', app(RevisionWorkbench::class)->status(ChapterGenerationRun::latest('id')->firstOrFail()));
        $chapter->update(['revision_round_limit' => null]);

        // 轮次用完后仍可整章重写：新运行不带工作台标记、从正文生成起步，生成新候选并检查。
        $versions = ChapterVersion::count();
        Livewire::test(ViewChapterContent::class, ['record' => $novelId])
            ->callAction('rewriteChapter', ['request_limit' => 5, 'accept_request' => true])->assertHasNoActionErrors();
        $rewrite = ChapterGenerationRun::latest('id')->firstOrFail();
        // 整章重写多留一次格式补发，补发后仍够自动修订并复查。
        $this->assertSame(5, $rewrite->request_limit);
        $this->assertNull(data_get($rewrite->config, 'workbench'));
        $this->assertSame('draft', $rewrite->config['initial_step']);
        $this->drain();
        $this->assertSame($versions + 1, ChapterVersion::count());
        $this->assertSame(5, app(RevisionWorkbench::class)->rounds($rewrite->chapter_id));
    }

    /** 历史版本列表显示各版推进检查结论，旧版本可作为人工编辑起点。 */
    public function test_version_table_shows_check_verdict_and_edit_from_version(): void
    {
        $parent = $this->start();
        $this->drain();
        $first = ChapterVersion::sole();
        app(ContentGenerationService::class)->recover($parent->id, User::firstOrFail(), '作者指定问题修订', 3, 500, issues: ['人物口吻不一致：请把首段对白改得克制。'], expectedVersionId: $first->id);
        $this->drain();
        $novelId = $parent->chapter->novel_id;
        Livewire::test(ViewChapterContent::class, ['record' => $novelId])
            ->assertTableColumnStateSet('check_verdict', '通过', $first)
            ->assertTableActionVisible('editFromVersion', $first)
            ->assertTableActionHidden('editFromVersion', ChapterVersion::latest('id')->firstOrFail())
            ->assertTableActionHasUrl('editFromVersion', NovelResource::getUrl('editor', ['record' => $novelId, 'chapter' => 1, 'from_version' => $first->id]), $first);
    }

    /** 修订问题预填当前版本推进检查的前后矛盾与本地重复线索。 */
    public function test_revise_issues_prefill_failed_check_items(): void
    {
        $run = $this->start();
        $this->drain();
        $chapter = $run->chapter->fresh();
        $version = ChapterVersion::findOrFail($chapter->current_candidate_version_id);
        $chapter->update(['progression_result' => [...$chapter->progression_result, 'version_id' => $version->id,
            'continuity_issues' => [['type' => 'repeated_action', 'reason' => '小陶两次接单离开，动作重演。', 'evidence_ids' => ['p1']]]]]);
        Livewire::test(ViewChapterContent::class, ['record' => $chapter->novel_id])->mountAction('reviseIssues')
            ->assertActionDataSet(fn (array $data): bool => str_contains($data['issues'], '前后重复或时序矛盾：小陶两次接单离开，动作重演。'));
    }

    /** 修订问题预填当前候选所有未解决的问题：核心冲突排在最前，未解决的复核项与针对本版本的评审意见都列入；
     * 已解决或判为合理表达的复核项、针对其他版本的评审不预填。 */
    public function test_revise_issues_prefill_all_pending_issues(): void
    {
        $run = $this->start();
        $this->drain();
        $chapter = $run->chapter->fresh();
        $version = ChapterVersion::findOrFail($chapter->current_candidate_version_id);
        $chapter->update(['progression_result' => [...$chapter->progression_result, 'version_id' => $version->id,
            'conflicts' => [['reason' => '规则r1：外婆已故；正文让外婆当面说话。']],
            'reading_reviews' => [
                ['id' => 'f1', 'fixed' => false, 'disposition' => 'unresolved', 'reason' => '日期载体仍不统一：p45是打印件，p49又写照片背面。'],
                ['id' => 'f2', 'fixed' => true, 'disposition' => 'resolved', 'reason' => '裁切画面已区分。'],
                ['id' => 'f3', 'fixed' => true, 'disposition' => 'benign', 'reason' => '母亲口述属于家庭记忆。'],
            ]]]);
        $submission = ChapterSubmissionRun::factory()->create(['version_id' => $version->id, 'status' => 'failed', 'stop_reason' => 'REVIEW_NOT_PASSED']);
        ChapterReview::factory()->create(['submission_run_id' => $submission->id, 'result' => ['verdict' => 'needs_revision', 'findings' => [
            ['kind' => 'continuity', 'reason' => '沈禾不可能读到照片背面的日期。', 'evidence' => []],
        ]]]);
        $older = $version->replicate()->fill(['version' => $version->version + 1, 'created_at' => now(),
            'source_stage_id' => NovelStage::where('novel_id', $chapter->novel_id)->whereNotIn('id', ChapterVersion::pluck('source_stage_id'))->firstOrFail()->id]);
        $older->save();
        $stale = ChapterSubmissionRun::factory()->create(['version_id' => $older->id, 'status' => 'failed', 'stop_reason' => 'REVIEW_NOT_PASSED']);
        ChapterReview::factory()->create(['submission_run_id' => $stale->id,
            'result' => ['verdict' => 'needs_revision', 'findings' => [['kind' => 'continuity', 'reason' => '旧版本的评审问题。', 'evidence' => []]]]]);
        Livewire::test(ViewChapterContent::class, ['record' => $chapter->novel_id])->mountAction('reviseIssues')
            ->assertActionDataSet(function (array $data): bool {
                $lines = explode("\n", $data['issues']);
                $this->assertSame('核心冲突：规则r1：外婆已故；正文让外婆当面说话。', $lines[0]);
                $this->assertContains('复核未解决：日期载体仍不统一：p45是打印件，p49又写照片背面。', $lines);
                $this->assertContains('提交评审意见：沈禾不可能读到照片背面的日期。', $lines);
                $this->assertStringNotContainsString('裁切画面已区分', $data['issues']);
                $this->assertStringNotContainsString('家庭记忆', $data['issues']);
                $this->assertStringNotContainsString('旧版本的评审问题', $data['issues']);

                return true;
            });
    }

    /** 运行排队或进行中时没有停止原因，不显示空的“停止原因与下一步”；停下后才显示。 */
    public function test_stop_reason_hidden_while_run_is_active(): void
    {
        $run = $this->start();
        $this->assertNull($run->fresh()->stop_reason);
        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])->assertDontSee('停止原因与下一步')->assertDontSee('尚无停止原因');
        $this->drain();
        Livewire::test(ViewChapterContent::class, ['record' => $run->chapter->novel_id])->assertSee('停止原因与下一步');
    }

    /** 剩余额度只够两次时，弹窗默认上限仍按本轮最多三次请求预留，直接提交不会被额度拦下。 */
    public function test_workbench_revision_default_limit_covers_three_requests(): void
    {
        $parent = $this->start();
        $this->drain();
        $source = ChapterVersion::sole();
        app(ContentGenerationService::class)->recordReading($parent->id, User::firstOrFail(), true, ['原候选已核对']);
        $novel = $parent->chapter->novel->fresh();
        $novel->update(['max_execution_count' => $novel->execution_count + 2]);
        Livewire::test(ViewChapterContent::class, ['record' => $novel->id])
            ->mountAction('reviseIssues')
            ->setActionData(['issues' => '人物口吻不一致：请把首段对白改为克制的表达。', 'accept_request' => true])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($novel->execution_count + 3, $novel->fresh()->max_execution_count);
        $this->assertNotSame($parent->id, ChapterGenerationRun::latest('id')->firstOrFail()->id);
    }

    /** 后台提出口吻问题后生成新版本，源正文保留，实际两份请求均携带该问题。 */
    public function test_workbench_ui_revises_then_independently_reviews(): void
    {
        $parent = $this->start();
        $this->drain();
        $source = ChapterVersion::sole();
        app(ContentGenerationService::class)->recordReading($parent->id, User::firstOrFail(), true, ['原候选已核对，现追加定点风格意见']);
        $before = CostLog::count();
        $issue = '人物口吻不一致：请把首段对白改为克制的表达，保持事实不变。';
        Livewire::test(ViewChapterContent::class, ['record' => $parent->chapter->novel_id])
            ->callAction('reviseIssues', data: ['parent_id' => $parent->id, 'version_id' => $source->id,
                'issues' => $issue, 'accept_request' => true])
            ->assertHasNoActionErrors();
        $child = ChapterGenerationRun::latest('id')->firstOrFail();
        $this->assertNotSame($parent->id, $child->id);
        $this->drain();
        $this->assertSame('awaiting_review', $child->fresh()->stop_reason);
        $this->assertSame(2, $child->fresh()->requests_used);
        $this->assertSame($before + 2, CostLog::count());
        $candidate = ChapterVersion::latest('id')->firstOrFail();
        $this->assertSame($source->id, $candidate->parent_version_id);
        $this->assertSame($source->body, $source->fresh()->body);
        $this->assertNotSame($source->body_hash, $candidate->body_hash);
        $this->assertSame(array_slice(explode("\n", $source->body), 1), array_slice(explode("\n", $candidate->body), 1));
        foreach (CostLog::latest('id')->limit(2)->get() as $log) {
            $this->assertStringContainsString($issue, $log->request_parameters['prompt_audit']['user_prompt']);
        }
        $this->assertSame('pass', $child->fresh()->chapter->progression_result['verdict']);
        $this->assertNull($child->fresh()->chapter->formal_version_id);
        Livewire::test(ViewChapterContent::class, ['record' => $parent->chapter->novel_id])->assertSee($issue)->assertSee('1 / 2');
    }

    /** 两轮后未经确认的第三轮和旧恢复入口都被拒绝；拒绝不创建运行或请求。 */
    public function test_workbench_round_limit_cannot_be_reset_by_recovery(): void
    {
        $run = $this->start();
        $this->drain();
        $service = app(ContentGenerationService::class);
        for ($i = 0; $i < 2; $i++) {
            $run = $service->recover($run->id, User::firstOrFail(), '指定问题', 2, 30,
                issues: ['人物口吻不一致'], expectedVersionId: $run->fresh()->chapter->current_candidate_version_id);
            $this->drain();
        }
        $this->assertSame(2, app(RevisionWorkbench::class)->rounds($run->chapter_id));
        $count = CostLog::count();
        foreach ([true, false] as $workbench) {
            try {
                $service->recover($run->id, User::firstOrFail(), '试图继续', 2, 30,
                    issues: $workbench ? ['人物口吻不一致'] : null,
                    expectedVersionId: $run->fresh()->chapter->current_candidate_version_id);
                $this->fail('达到轮次上限仍创建了运行');
            } catch (InvalidArgumentException $exception) {
                // 工作台入口要求明确确认追加轮次；旧恢复入口提示改用指定问题或人工编辑。
                $this->assertStringContainsString($workbench ? '追加第3轮须明确确认' : '人工编辑正文', $exception->getMessage());
            }
        }
        $this->assertSame($count, CostLog::count());
        $this->assertSame(3, ChapterGenerationRun::count());
    }

    /** 过期版本和非作者不能越权创建修订，原候选保持不变。 */
    public function test_workbench_rejects_stale_version_and_wrong_author(): void
    {
        $parent = $this->start();
        $this->drain();
        foreach ([[User::firstOrFail(), 9999], [User::factory()->create(), ChapterVersion::sole()->id]] as [$author, $version]) {
            try {
                app(ContentGenerationService::class)->recover($parent->id, $author, '指定问题', 2, 30,
                    issues: ['人物口吻不一致'], expectedVersionId: $version);
                $this->fail('无效来源被接受');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('边界已变化', $exception->getMessage());
            }
        }
        $this->assertSame(1, ChapterGenerationRun::count());
    }

    /** 复评未解决指定问题时明确暂停，不能把模型生成成功当成复评通过。 */
    public function test_workbench_unresolved_review_stops_without_automatic_next_round(): void
    {
        $parent = $this->start();
        $this->drain();
        $this->scenario = 'insufficient';
        $child = app(ContentGenerationService::class)->recover($parent->id, User::firstOrFail(), '口吻复核', 2, 30,
            issues: ['人物口吻不一致'], expectedVersionId: ChapterVersion::sole()->id);
        $this->drain();
        $this->assertSame('paused', $child->fresh()->status);
        $this->assertSame('retry_limit', $child->fresh()->stop_reason);
        $this->assertSame(2, $child->fresh()->requests_used);
        $this->assertSame(2, ChapterGenerationRun::count());
        $this->assertStringContainsString('未解决', app(RevisionWorkbench::class)->reviewSummary($child->fresh()));
    }

    /** 重复同一授权返回同一运行，改问题重放则拒绝，正式版本始终不可修订。 */
    public function test_workbench_idempotency_and_formal_protection(): void
    {
        $parent = $this->start();
        $this->drain();
        $source = ChapterVersion::sole();
        $service = app(ContentGenerationService::class);
        $child = $service->recover($parent->id, User::firstOrFail(), '指定问题', 2, 30,
            issues: ['人物口吻不一致'], expectedVersionId: $source->id);
        $same = $service->recover($parent->id, User::firstOrFail(), '指定问题', 2, 30,
            issues: ['人物口吻不一致'], expectedVersionId: $source->id);
        $this->assertSame($child->id, $same->id);
        try {
            $service->recover($parent->id, User::firstOrFail(), '指定问题', 2, 30,
                issues: ['另一条问题'], expectedVersionId: $source->id);
            $this->fail('不同问题被当作同一次授权');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('请求已变化', $exception->getMessage());
        }
        $this->drain();
        $child->chapter->update(['formal_version_id' => $child->fresh()->chapter->current_candidate_version_id]);
        $this->expectException(InvalidArgumentException::class);
        $service->recover($child->id, User::firstOrFail(), '正式版本越界', 2, 30,
            issues: ['人物口吻不一致'], expectedVersionId: $child->fresh()->chapter->current_candidate_version_id);
    }

    /** 人工编辑页勾选评审问题交给 AI：按工作台定点修订并复查，结果成为新候选；可查看相对上一版的改动，也可不采纳回到上一版。 */
    public function test_editor_ai_revises_selected_issues_and_can_revert(): void
    {
        $run = $this->start();
        $this->drain();
        $version = ChapterVersion::sole();
        $quote = mb_substr(explode("\n", $version->body)[0], 0, 12);
        $submission = ChapterSubmissionRun::factory()->create(['version_id' => $version->id, 'status' => 'failed', 'stop_reason' => 'REVIEW_NOT_PASSED']);
        ChapterReview::factory()->create(['submission_run_id' => $submission->id, 'result' => ['verdict' => 'needs_revision', 'findings' => [
            ['kind' => 'continuity', 'severity' => 'blocking', 'reason' => '首段时间交代与后文矛盾。', 'evidence' => [['quote' => $quote, 'paragraph' => 1]]],
        ]]]);
        $novelId = $run->chapter->novel_id;

        $page = Livewire::withQueryParams(['chapter' => 1])->test(EditChapterDraft::class, ['record' => $novelId])->assertActionVisible('aiRevise');
        $this->assertSame([0], array_keys($page->instance()->aiIssueOptions()));
        $page->callAction('aiRevise', ['issues' => [0], 'accept_request' => true])->assertHasNoActionErrors();
        $child = ChapterGenerationRun::latest('id')->firstOrFail();
        $this->assertSame($run->id, $child->parent_run_id);
        $this->assertStringContainsString('首段时间交代与后文矛盾。', $child->config['workbench']['issues'][0]);
        $this->assertStringContainsString($quote, $child->config['workbench']['issues'][0]);
        $this->drain();
        $revised = ChapterVersion::latest('id')->firstOrFail();
        $this->assertSame($version->id, $revised->parent_version_id);

        $page = Livewire::withQueryParams(['chapter' => 1])->test(EditChapterDraft::class, ['record' => $novelId])
            ->assertSet('versionId', $revised->id)->assertSee('当前候选是 AI 按你选的 1 条问题修改后的版本')->assertActionVisible('revertAi');
        $diff = $page->instance()->compareBase();
        $this->assertStringContainsString('相对上一版（版本 '.$version->version.'）', $diff['heading']);
        $this->assertGreaterThan(0, ParagraphDiff::changes($diff['rows']));
        $page->callAction('revertAi')->assertHasNoActionErrors();
        $this->assertSame($version->body, DB::table('manual_content_drafts')->value('body'));
        DB::table('manual_content_drafts')->delete();
    }

    /** 推进检查已通过且编辑区没有改动时，人工编辑页直接提供“提交”，不需要重新保存或检查。 */
    public function test_editor_submits_checked_candidate_directly(): void
    {
        // 正式提交只能由本书负责账号发起：用负责账号生成并提交。
        $novel = B04ContentTest::readyNovel(true, 20);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500]]);
        $owner = User::findOrFail(NovelSettingConfirmation::where('novel_id', $novel->id)->firstOrFail()->confirmed_by);
        $run = app(ContentGenerationService::class)->start($novel, $owner);
        $this->drain();
        Livewire::actingAs($owner)->withQueryParams(['chapter' => 1])->test(EditChapterDraft::class, ['record' => $run->chapter->novel_id])
            ->assertActionHidden('saveAndSubmit')->assertActionVisible('submitChecked')
            ->callAction('submitChecked', ['reason' => '检查通过后提交', 'confirm' => true])->assertHasNoActionErrors()->assertNotNotified('未能开始');
        $this->assertSame('b04_accepted', $run->fresh()->stop_reason);
        $submission = ChapterSubmissionRun::where('version_id', ChapterVersion::sole()->id)->sole();
        $this->assertSame('检查通过后提交', data_get($submission->config, 'auto_commit.reason'));
    }
}
