<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\User;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\RevisionWorkbench;
use InvalidArgumentException;
use Livewire\Livewire;

/** 复用真实执行器及HTTP夹具，核验定点修订、再次评审和服务端轮次边界。 */
class C07RevisionWorkbenchTest extends B04ExecutionTest
{
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
                'issues' => $issue, 'execution_limit' => 30, 'accept_request' => true])
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

    /** 两轮后拒绝第三轮和旧恢复入口；拒绝不创建运行或请求。 */
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
                $this->assertStringContainsString('人工', $exception->getMessage());
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
}
