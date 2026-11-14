<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\ChapterGenerationAgent;
use App\Services\Ai\PromptTemplateManager;
use App\Services\Ai\TextRequestOptions;
use App\Services\Pipeline\AcceptanceBranch;
use App\Services\Pipeline\AcceptanceComparison;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** 复用真实离线生成夹具，验证分支不覆盖原书且必须重新检查。 */
class AcceptanceBranchTest extends B04ExecutionTest
{
    /** 分支授权仅在内存测试库清理，避免影响真实历史保护迁移。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('production_runs')->delete();
        parent::tearDown();
    }

    /** 构造已生成并经作者认可的独立对照稿，所有HTTP沿用父类假供应商。
     * @return array{0: ChapterGenerationRun, 1: ChapterVersion}
     */
    private function approvedSource(): array
    {
        Storage::fake('local');
        $run = $this->start(true);
        while ($run->status === 'pending') {
            app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
            $run->refresh();
        }
        $version = ChapterVersion::findOrFail($run->chapter->current_candidate_version_id);
        $novel = $run->chapter->novel;
        $plan = $novel->outlinePlans()->firstOrFail();
        $files = ['manifest' => ['source_novel_id' => $novel->id, 'source_version_id' => $version->id, 'source_hash' => $version->body_hash],
            'result' => ['status' => 'first_chapter_technical_pass_human_pending', 'completed_at' => now()->toIso8601String(), 'final_body_sha256' => $version->body_hash, 'final_candidate_characters' => $version->character_count],
            'candidate' => ['title' => $version->title, 'body' => $version->body], 'source-snapshot' => $run->snapshot,
            'outline' => ['chapters' => [$plan->chapters()->first()->only(['chapter_number', 'title', 'summary', 'objective', 'conflict', 'hook', 'progression_points'])], 'roadmap' => $plan->roadmap, 'handoff' => []],
            'fine' => $run->plan_data, 'templates' => app(PromptTemplateManager::class)->snapshot()];
        foreach ($files as $name => $data) {
            Storage::disk('local')->put('c10-acceptance-evidence/test-branch/'.$name.'.json', json_encode($data, JSON_THROW_ON_ERROR));
        }
        $reader = app(AcceptanceComparison::class);
        $reader->feedback($version->chapter, (int) $plan->confirmation->confirmed_by, ['fingerprint' => $reader->fingerprint($reader->latest($version->chapter)), 'decision' => 'approve', 'comment' => '测试作者明确认可此稿']);

        return [$run, $version];
    }

    /** 分支正文相同但版本独立；复制无AI调用且重复创建幂等，检查依旧走真实执行器。 */
    public function test_branch_import_is_isolated_idempotent_and_rechecked(): void
    {
        [$source, $version] = $this->approvedSource();
        $before = $source->chapter->novel->fresh()->attributesToArray();
        $calls = CostLog::count();
        $service = app(AcceptanceBranch::class);
        $automation = $service->create($source->chapter->novel_id, User::findOrFail($source->chapter->novel->outlinePlans()->first()->confirmation->confirmed_by), 'test-branch', 'gpt-4.1-mini', true);
        $this->assertNotSame($source->chapter->novel_id, $automation->novel_id);
        $this->assertSame($automation->id, $service->create($source->chapter->novel_id, User::findOrFail($source->chapter->novel->outlinePlans()->first()->confirmation->confirmed_by), 'test-branch', 'gpt-4.1-mini', true)->id);
        $this->assertSame($calls, CostLog::count());
        $run = ChapterGenerationRun::findOrFail($automation->artifacts['body_run_id']);
        $this->assertSame($version->body, ChapterVersion::findOrFail($run->chapter->current_candidate_version_id)->body);
        $this->assertNull($run->chapter->formal_version_id);
        $this->assertNull($run->chapter->progression_result);
        app(ContentGenerationService::class)->assertFrozenSources($run);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $this->assertSame($calls + 1, CostLog::count());
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $this->assertSame($before, $source->chapter->novel->fresh()->attributesToArray());
    }

    /** 无明确授权不能建立分支，即使已有认可记录也不能扩张授权。 */
    public function test_branch_requires_explicit_ten_chapter_authorization(): void
    {
        [$source] = $this->approvedSource();
        $count = Novel::count();
        try {
            app(AcceptanceBranch::class)->create($source->chapter->novel_id, User::findOrFail($source->chapter->novel->outlinePlans()->first()->confirmation->confirmed_by), 'test-branch', 'gpt-4.1-mini', false);
            $this->fail('未授权不应成功');
        } catch (InvalidArgumentException) {
            $this->assertSame($count, Novel::count());
            $this->assertSame(0, ProductionRun::count());
        }
    }

    /** 导入后正文被绕过不可变模型改写时，执行器必须在真实请求之前拒绝。 */
    public function test_imported_body_tampering_is_rejected_before_request(): void
    {
        [$source] = $this->approvedSource();
        $author = User::findOrFail($source->chapter->novel->outlinePlans()->first()->confirmation->confirmed_by);
        $automation = app(AcceptanceBranch::class)->create($source->chapter->novel_id, $author, 'test-branch', 'gpt-4.1-mini', true);
        $run = ChapterGenerationRun::findOrFail($automation->artifacts['body_run_id']);
        $calls = CostLog::count();
        DB::table('chapter_versions')->where('id', $run->chapter->current_candidate_version_id)->update(['body' => '被篡改的正文。']);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $this->assertSame('invalid_contract', $run->fresh()->stop_reason);
        $this->assertSame($calls, CostLog::count());
    }

    /** 作者最新反馈改为待修订时，不得借用旧认可建立分支。 */
    public function test_latest_rejection_blocks_branch_creation(): void
    {
        [$source, $version] = $this->approvedSource();
        $author = User::findOrFail($source->chapter->novel->outlinePlans()->first()->confirmation->confirmed_by);
        $reader = app(AcceptanceComparison::class);
        $this->travel(1)->seconds();
        $reader->feedback($version->chapter, $author->id, ['fingerprint' => $reader->fingerprint($reader->latest($version->chapter)), 'decision' => 'revise', 'comment' => '最新意见仍需修改']);
        $count = Novel::count();
        try {
            app(AcceptanceBranch::class)->create($source->chapter->novel_id, $author, 'test-branch', 'gpt-4.1-mini', true);
            $this->fail('旧认可不能覆盖新拒绝');
        } catch (InvalidArgumentException) {
            $this->assertSame($count, Novel::count());
        }
    }

    /** 后九章不能把实验首章专用情节替换章号后再次生成，普通小说模板行为保持不变。 */
    public function test_branch_continuation_does_not_replay_first_chapter_template(): void
    {
        foreach ([false, true] as $branch) {
            $options = TextRequestOptions::executionChapter('fine', 0, ['chapter_number' => 2, 'accepted_branch_continuation' => $branch, 'anchors' => [], 'rules' => [], 'findings' => []]);
            $body = '首章专用情节：必须重演搬运退单。';
            $items = ['builtin_fine' => ['id' => 999, 'scope' => 'builtin_fine', 'body' => $body, 'content_hash' => PlanningJson::hash(['builtin_fine', $body])]];
            $options = $options->withTemplates(['version' => 'c06-v1', 'items' => $items, 'hash' => PlanningJson::hash($items)], ['title' => '测试', 'genre' => '现实']);
            $instructions = (new ChapterGenerationAgent($options))->instructions();
            if ($branch) {
                $this->assertStringNotContainsString('必须重演搬运退单', $instructions);
                $this->assertStringContainsString('prior_context', $instructions);
            } else {
                $this->assertStringContainsString('必须重演搬运退单', $instructions);
            }
        }
    }

    /** 仅当前正文明确复核通过的旧问题退出下一轮输入，失败项仍保留。 */
    public function test_resolved_findings_are_not_repeated_in_recovery_input(): void
    {
        $body = "她来了。\n他离开。";
        $hash = hash('sha256', $body);
        $run = new ChapterGenerationRun(['snapshot' => ['novel' => ['chapter_length_policy' => ['min_characters' => 1, 'max_characters' => 100]]],
            'config' => ['recovery_findings' => ['resolved-old' => ['reason' => '旧问题'], 'open-old' => ['reason' => '仍有问题']]], 'control_history' => []]);
        $run->setRelation('chapter', new Chapter(['progression_result' => ['body_hash' => $hash, 'reading_reviews' => [
            ['id' => 'resolved-old', 'fixed' => true, 'disposition' => 'resolved', 'reason' => '已修好'],
        ]]]));
        $version = new ChapterVersion(['body' => $body, 'body_hash' => $hash]);
        $findings = app(ContentExecutionService::class)->findings($run, $version);
        $this->assertArrayNotHasKey('resolved-old', $findings);
        $this->assertArrayHasKey('open-old', $findings);
        $this->assertArrayHasKey('resolved-old', $run->config['recovery_findings']);
    }

    /** 自动诊断必须绑定当前原文，不能改变验收状态或伪装人工评分。 */
    public function test_automated_finding_requires_current_source_quote(): void
    {
        [$source] = $this->approvedSource();
        $author = User::findOrFail($source->chapter->novel->outlinePlans()->first()->confirmation->confirmed_by);
        $automation = app(AcceptanceBranch::class)->create($source->chapter->novel_id, $author, 'test-branch', 'gpt-4.1-mini', true);
        $body = ChapterGenerationRun::findOrFail($automation->artifacts['body_run_id']);
        $service = app(ContentExecutionService::class);
        $service->runStep($body->id, $body->invocation_id, app(AiTextGenerationService::class));
        $automation->update(['status' => 'failed']);
        $version = ChapterVersion::findOrFail($body->chapter->current_candidate_version_id);
        $service->recordAutomatedFinding($body->id, $automation->id, $author, $version->id, mb_substr($version->body, 0, 10), '隔离自动诊断');
        $this->assertSame('awaiting_review', $body->fresh()->stop_reason);
        $this->assertContains('隔离自动诊断', array_column($service->findings($body->fresh(), $version), 'reason'));
        $this->expectException(InvalidArgumentException::class);
        $service->recordAutomatedFinding($body->id, $automation->id, $author, $version->id, '原文不存在的摘录', '不能接受伪造证据');
    }

    /** 单段修复和显式验收分支可扩展低推理容量，普通正文不能借用。 */
    public function test_extra_reasoning_capacity_is_limited_to_partial_repair(): void
    {
        $context = ['anchors' => [], 'rules' => [], 'findings' => [], 'draft_repair_slot' => 'p1', 'deepseek_reasoning_effort' => 'low'];
        $options = TextRequestOptions::executionChapter('draft', 0, $context, 16384);
        $this->assertSame(16384, $options->maxOutputTokens);
        $this->assertSame(300, $options->timeoutSeconds);
        $check = TextRequestOptions::executionChapter('check', 0, [...$context, 'accepted_branch_continuation' => true], 16384);
        $this->assertSame(16384, $check->maxOutputTokens);
        $ordinary = TextRequestOptions::executionChapter('check', 0, ['anchors' => [], 'rules' => [], 'findings' => []]);
        $this->assertSame(4096, $ordinary->maxOutputTokens);
        unset($context['draft_repair_slot']);
        $draft = TextRequestOptions::executionChapter('draft', 0, [...$context, 'accepted_branch_continuation' => true], 16384);
        $this->assertSame(16384, $draft->maxOutputTokens);
        $this->assertSame(300, $draft->timeoutSeconds);
        $this->expectException(InvalidArgumentException::class);
        TextRequestOptions::executionChapter('draft', 0, $context, 16384);
    }

    /** 自动段尾误报恢复只重新检查，保留原候选和失败记录，不能伪装成新正文。 */
    public function test_false_automated_ending_failure_rechecks_unchanged_body(): void
    {
        $this->scenario = 'complete';
        [$parent, $version] = $this->approvedSource();
        $parent->update(['status' => 'paused', 'stop_reason' => 'reading_failed', 'control_history' => [...$parent->control_history,
            ['action' => 'full_read_failed', 'acceptance_mode' => 'delegated_policy', 'notes' => ['自动段落完整性检查未通过：隔离模拟旧版空行误报。']]]]);
        $count = ChapterVersion::count();
        $run = app(ContentGenerationService::class)->recover($parent->id, User::findOrFail($parent->config['author_id']), '修复空行误报后重新检查');
        $this->assertSame('check', $run->next_step);
        $this->assertSame(1, $run->request_limit);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $this->assertSame($version->id, $run->chapter->current_candidate_version_id);
        $this->assertSame($count, ChapterVersion::count());
        $this->assertSame('reading_failed', $parent->fresh()->stop_reason);
        $author = User::findOrFail($parent->config['author_id']);
        app(ContentExecutionService::class)->recordReading($run->id, $author, true, ['隔离复查确认']);
        $owner = User::findOrFail($version->chapter->novel->outlinePlans()->firstOrFail()->confirmation->confirmed_by);
        $submission = app(ChapterSubmissionService::class)->prepare($version->id, $owner, 0, '隔离复查来源验收', 100, false);
        $this->assertSame($run->id, $submission->snapshot['body_run_id']);
        $this->assertSame($version->id, $submission->version_id);
    }

    /** 多段失败逐次合并并保留其余原文，最终整章通过后才创建正文版本。 */
    public function test_branch_partial_recovery_preserves_successful_slots(): void
    {
        $novel = B04ContentTest::readyNovel(true, 50);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], 'generation_config' => [...($novel->generation_config ?? []), 'acceptance_branch' => ['key' => 'test']]]);
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail());
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $original = [];
        $repairAttempts = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => function ($request) use (&$original, &$repairAttempts) {
            $log = CostLog::latest('id')->firstOrFail();
            $payload = json_decode(explode('冻结输入：', $log->request_parameters['prompt_audit']['user_prompt'], 2)[1], true);
            $parts = [];
            foreach ($payload['budget']['slots'] as $slot) {
                $parts[$slot] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] + $payload['budget']['max'], 2), '甲');
            }
            if (count($parts) > 1) {
                $keys = array_keys($parts);
                $parts[$keys[0]] = [['kind' => 'narration', 'text' => '短。']];
                $parts[$keys[1]] = [['kind' => 'narration', 'text' => '短。']];
                $original = $parts;
            } elseif (++$repairAttempts === 1) {
                $this->assertStringContainsString('编辑已有小说的一个场景', $log->request_parameters['prompt_audit']['system_prompt']);
                $this->assertStringNotContainsString('写完整中文', $log->request_parameters['prompt_audit']['system_prompt']);
                $this->assertArrayNotHasKey('candidate_roadmap', $payload);
                $this->assertSame(array_diff_key($original, [$payload['draft_repair_slot'] => true]), $payload['surrounding_paragraphs']);
                $this->assertArrayNotHasKey('previous_failed_draft', $payload);
                // 第一次单段仍不达标，后继请求必须收到它，不能误当成可提交正文。
                $parts[array_key_first($parts)] = [['kind' => 'narration', 'text' => '仍然太短。']];
            } elseif ($repairAttempts === 2) {
                $this->assertSame([['kind' => 'narration', 'text' => '仍然太短。']], $payload['repair_source']['text']);
                $this->assertSame(5, $payload['repair_measurement']['actual_characters']);
                $parts[array_key_first($parts)] = [['kind' => 'narration', 'text' => '短。']];
            } elseif ($repairAttempts === 3) {
                // 更差的新尝试不能覆盖此前更接近预算的完整单段。
                $this->assertSame([['kind' => 'narration', 'text' => '仍然太短。']], $payload['repair_source']['text']);
            }
            $response = B02SettingTest::response(json_encode(['title' => '修复测试', 'paragraphs' => $parts], JSON_UNESCAPED_UNICODE));
            $response['model'] = $request['model'];

            return Http::response($response);
        }]);
        // 同一运行内的继续修复由队列推进；测试中逐步执行，直到运行停下。
        $drive = function (ChapterGenerationRun $run): ChapterGenerationRun {
            for ($step = 0; $step < 6 && $run->status === 'pending'; $step++) {
                app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
                $run->refresh();
            }

            return $run;
        };
        $run = $drive($run->refresh());
        // 首个运行在已授权额度内连续修复两次仍未达标，为推进检查留出一次请求后才停下。
        $this->assertSame('invalid_schema', $run->fresh()->stop_reason);
        $this->assertSame(2, $repairAttempts);
        $this->assertSame(4, $run->fresh()->requests_used);
        $this->assertNull($run->fresh()->chapter->current_candidate_version_id);
        // 一次恢复即可：继承最接近预算的单段尝试，修好首个场景后在同一运行内接着修第二个场景。
        $run = $drive(app(ContentGenerationService::class)->recover($run->id, User::firstOrFail(), '逐段修复验收'));
        $this->assertSame(4, $repairAttempts);
        $version = ChapterVersion::findOrFail($run->chapter->current_candidate_version_id);
        $this->assertSame('check', $run->next_step);
        $paragraphs = explode("\n", $version->body);
        $this->assertCount(6, $paragraphs);
        $this->assertSame(array_column(array_merge(...array_slice(array_values($original), 2)), 'text'), array_slice($paragraphs, 4));
    }
}
