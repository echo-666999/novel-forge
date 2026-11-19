<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
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
        // 运行冻结扩展输出时整章初稿可用到32768，超过即拒绝。
        $this->assertSame(32768, TextRequestOptions::executionChapter('draft', 0, [...$context, 'extended_output' => true], 32768)->maxOutputTokens);
        try {
            TextRequestOptions::executionChapter('draft', 0, [...$context, 'extended_output' => true], 32769);
            $this->fail('超过扩展输出上限必须拒绝');
        } catch (InvalidArgumentException) {
        }
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
            if (! isset($payload['draft_repair_slot']) && ! isset($payload['draft_repair_slots'])) {
                $keys = array_keys($parts);
                $parts[$keys[0]] = [['kind' => 'narration', 'text' => '短。']];
                $parts[$keys[1]] = [['kind' => 'narration', 'text' => '短。']];
                $original = $parts;
            } elseif (++$repairAttempts === 1) {
                // 两个场景只是字数不足，合并为一次扩写请求。
                $this->assertStringContainsString('编辑已有小说的几个场景', $log->request_parameters['prompt_audit']['system_prompt']);
                $this->assertStringNotContainsString('写完整中文', $log->request_parameters['prompt_audit']['system_prompt']);
                $this->assertArrayNotHasKey('candidate_roadmap', $payload);
                $this->assertSame(array_slice(array_keys($original), 0, 2), $payload['draft_repair_slots']);
                $this->assertSame(array_diff_key($original, array_flip($payload['draft_repair_slots'])), $payload['surrounding_paragraphs']);
                $this->assertArrayNotHasKey('previous_failed_draft', $payload);
                // 两个场景都仍不达标，后继请求必须收到更接近预算的尝试，不能误当成可提交正文。
                $parts[array_key_first($parts)] = [['kind' => 'narration', 'text' => '仍然太短。']];
                $parts[array_key_last($parts)] = [['kind' => 'narration', 'text' => '短。']];
            } elseif ($repairAttempts === 2) {
                $first = $payload['draft_repair_slots'][0];
                $this->assertSame([['kind' => 'narration', 'text' => '仍然太短。']], $payload['repair_source']['text'][$first]);
                $this->assertSame(5, $payload['repair_measurement']['actual_characters'][$first]);
                $parts[$first] = [['kind' => 'narration', 'text' => '短。']];
                $parts[$payload['draft_repair_slots'][1]] = [['kind' => 'narration', 'text' => '短。']];
            } elseif ($repairAttempts === 3) {
                // 更差的新尝试不能覆盖此前更接近预算的完整场景，恢复运行同样继承。
                $this->assertSame([['kind' => 'narration', 'text' => '仍然太短。']], $payload['repair_source']['text'][$payload['draft_repair_slots'][0]]);
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
        // 一次恢复即可：继承最接近预算的场景尝试，一次合并扩写修好两个场景。
        $run = $drive(app(ContentGenerationService::class)->recover($run->id, User::firstOrFail(), '逐段修复验收'));
        $this->assertSame(3, $repairAttempts);
        $version = ChapterVersion::findOrFail($run->chapter->current_candidate_version_id);
        $this->assertSame('check', $run->next_step);
        $paragraphs = explode("\n", $version->body);
        $this->assertCount(6, $paragraphs);
        $this->assertSame(array_column(array_merge(...array_slice(array_values($original), 2)), 'text'), array_slice($paragraphs, 4));
    }

    /** 整章正文达到输出上限被截断：标为截断而非供应商失败，保留已完整的场景，在同一运行内只补写未写完的场景。 */
    public function test_truncated_draft_keeps_complete_scenes_and_writes_the_rest(): void
    {
        $novel = B04ContentTest::readyNovel(true, 50);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], 'generation_config' => [...($novel->generation_config ?? []), 'acceptance_branch' => ['key' => 'test']]]);
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail());
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $original = [];
        $repairs = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => function ($request) use (&$original, &$repairs) {
            $log = CostLog::latest('id')->firstOrFail();
            $payload = json_decode(explode('冻结输入：', $log->request_parameters['prompt_audit']['user_prompt'], 2)[1], true);
            $parts = [];
            foreach ($payload['budget']['slots'] as $slot) {
                $parts[$slot] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] + $payload['budget']['max'], 2), '甲');
            }
            if (! isset($payload['draft_repair_slot']) && ! isset($payload['draft_repair_slots'])) {
                $this->assertStringContainsString('不要在思考中先写出正文', $log->request_parameters['prompt_audit']['system_prompt']);
                // 第一个场景完整写出，第二个场景写到一半时达到输出上限。
                $original = $parts;
                $text = json_encode(['title' => '截断测试', 'paragraphs' => $parts], JSON_UNESCAPED_UNICODE);
                $response = B02SettingTest::response(mb_strcut($text, 0, strpos($text, '"scene_2"') + 40), 'incomplete');
                $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
                $response['usage']['output_tokens_details'] = ['reasoning_tokens' => 14000];
            } else {
                $repairs[] = $payload;
                $this->assertStringContainsString('补写', $log->request_parameters['prompt_audit']['system_prompt']);
                $response = B02SettingTest::response(json_encode(['title' => '截断测试', 'paragraphs' => array_intersect_key($parts, array_flip($payload['draft_repair_slots'] ?? [$payload['draft_repair_slot']]))], JSON_UNESCAPED_UNICODE));
            }
            $response['model'] = $request['model'];

            return Http::response($response);
        }]);
        for ($step = 0; $step < 4 && $run->refresh()->status === 'pending'; $step++) {
            app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        }
        $truncated = CostLog::where('status', 'failed')->latest('id')->firstOrFail();
        $this->assertSame('output_truncated', $truncated->error_category);
        $stage = NovelStage::findOrFail($truncated->novel_stage_id);
        $this->assertSame(ContentExecutionService::IN_RUN_REPAIR, $stage->output['data']['outcome']);
        $this->assertSame(['scene_2', 'scene_3'], $stage->output['data']['truncated_draft']['missing']);
        // 未写完的两个场景合并为一次补写请求，已完整的场景只作上下文。
        $this->assertCount(1, $repairs);
        $this->assertSame(['scene_2', 'scene_3'], $repairs[0]['draft_repair_slots']);
        $this->assertSame(['scene_2' => 'write', 'scene_3' => 'write'], $repairs[0]['repair_measurement']['directions']);
        $this->assertSame(['scene_1' => $original['scene_1']], $repairs[0]['surrounding_paragraphs']);
        $this->assertStringContainsString('被截断', $repairs[0]['previous_response_failure']);
        $this->assertSame('check', $run->next_step);
        $version = ChapterVersion::findOrFail($run->chapter->current_candidate_version_id);
        $this->assertSame(array_column($original['scene_1'], 'text'), array_slice(explode("\n", $version->body), 0, 2));
    }

    /** 截断后额度不足时停在“输出被截断”；恢复运行继承已完整的场景，只补写未写完的场景。 */
    public function test_truncated_draft_recovery_writes_only_missing_scenes(): void
    {
        $novel = B04ContentTest::readyNovel(true, 50);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], 'generation_config' => [...($novel->generation_config ?? []), 'acceptance_branch' => ['key' => 'test']]]);
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail());
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $novel->refresh()->update(['max_execution_count' => $novel->execution_count + 1]);
        $repairs = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => function ($request) use (&$repairs) {
            $log = CostLog::latest('id')->firstOrFail();
            $payload = json_decode(explode('冻结输入：', $log->request_parameters['prompt_audit']['user_prompt'], 2)[1], true);
            $parts = [];
            foreach ($payload['budget']['slots'] as $slot) {
                $parts[$slot] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] + $payload['budget']['max'], 2), '乙');
            }
            if (! isset($payload['draft_repair_slot']) && ! isset($payload['draft_repair_slots'])) {
                $text = json_encode(['title' => '截断测试', 'paragraphs' => $parts], JSON_UNESCAPED_UNICODE);
                $response = B02SettingTest::response(mb_strcut($text, 0, strpos($text, '"scene_3"') + 30), 'incomplete');
                $response['incomplete_details'] = ['reason' => 'max_output_tokens'];
            } else {
                $repairs[] = $payload;
                $response = B02SettingTest::response(json_encode(['title' => '截断测试', 'paragraphs' => [$payload['draft_repair_slot'] => $parts[$payload['draft_repair_slot']]]], JSON_UNESCAPED_UNICODE));
            }
            $response['model'] = $request['model'];

            return Http::response($response);
        }]);
        app(ContentExecutionService::class)->runStep($run->id, $run->refresh()->invocation_id, app(AiTextGenerationService::class));
        $this->assertSame('output_truncated', $run->refresh()->stop_reason);
        $this->assertSame([], $repairs);
        $child = app(ContentGenerationService::class)->recover($run->id, User::firstOrFail(), '补写截断场景', 3, $novel->refresh()->execution_count + 3);
        for ($step = 0; $step < 3 && $child->refresh()->status === 'pending'; $step++) {
            app(ContentExecutionService::class)->runStep($child->id, $child->invocation_id, app(AiTextGenerationService::class));
        }
        // 单个未写完的场景按“补写”处理，不重写已完整的前两个场景。
        $this->assertCount(1, $repairs);
        $this->assertSame('scene_3', $repairs[0]['draft_repair_slot']);
        $this->assertSame('write', $repairs[0]['repair_measurement']['direction']);
        $this->assertSame([], $repairs[0]['repair_source']['text']);
        $this->assertSame('check', $child->next_step);
        $this->assertNotNull($child->chapter->current_candidate_version_id);
    }

    /** 正文照搬细纲时不进入整章修订：在同一运行内按场景改写被指出的句子，改好后直接生成候选。 */
    public function test_plan_copy_is_repaired_per_scene_without_revision(): void
    {
        $novel = B04ContentTest::readyNovel(true, 50);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], 'generation_config' => [...($novel->generation_config ?? []), 'acceptance_branch' => ['key' => 'test']]]);
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail());
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $repairs = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => function ($request) use (&$repairs) {
            $log = CostLog::latest('id')->firstOrFail();
            $payload = json_decode(explode('冻结输入：', $log->request_parameters['prompt_audit']['user_prompt'], 2)[1], true);
            $parts = [];
            foreach ($payload['budget']['slots'] as $slot) {
                $parts[$slot] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] + $payload['budget']['max'], 2), '甲');
            }
            if (count($parts) > 1) {
                // 首稿第一个场景照搬细纲行动句，其余篇幅与分段均合格。
                $scene = $payload['slot_plan']['scene_1'];
                $copied = collect(['action', 'goal', 'obstacle', 'emotional_beat', 'change'])->flatMap(fn (string $field): array => preg_split('/“[^”]*”|[，。；：！？…（）《》\s]+/u', (string) ($scene[$field] ?? '')))
                    ->first(fn (string $fragment): bool => mb_strlen($fragment) >= 14);
                $this->assertNotNull($copied);
                $narration = $parts['scene_1'][0]['text'];
                $parts['scene_1'][0]['text'] = $copied.'，'.mb_substr($narration, mb_strlen($copied) + 1);
            } else {
                $repairs[] = $payload['repair_measurement']['direction'];
                $this->assertStringContainsString('逐字照搬细纲', $payload['previous_response_failure']);
                // 文字问题修复去掉细纲叙述字段，改发须避开的原句清单；台词要点等上下文保留。
                $this->assertSame(['scene_1'], array_keys($payload['slot_plan']));
                $this->assertArrayNotHasKey('action', $payload['slot_plan']['scene_1']);
                $this->assertArrayNotHasKey('obstacle', $payload['slot_plan']['scene_1']);
                $this->assertArrayHasKey('key_dialogue', $payload['slot_plan']['scene_1']);
                $this->assertContains('守塔人劝她别多管闲事并提醒旧航线的事早就没人在意', $payload['plan_sentences_to_avoid']);
                $this->assertStringContainsString('plan_sentences_to_avoid', $log->request_parameters['prompt_audit']['system_prompt']);
            }
            $response = B02SettingTest::response(json_encode(['title' => '照搬测试', 'paragraphs' => $parts], JSON_UNESCAPED_UNICODE));
            $response['model'] = $request['model'];

            return Http::response($response);
        }]);
        $run->refresh();
        for ($step = 0; $step < 4 && $run->status === 'pending' && $run->next_step === 'draft'; $step++) {
            app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
            $run->refresh();
        }
        $this->assertSame(['quality'], $repairs);
        $this->assertNotNull($run->chapter->fresh()->current_candidate_version_id);
        $this->assertSame('check', $run->next_step);
        $this->assertSame(0, NovelStage::where('input->run_id', $run->id)->where('input->step', 'revise')->count());
    }

    /** 多个场景只有文字问题时合并为一次修复请求：只返回这些场景，其余场景逐字保留，额度不再按场景逐个消耗。 */
    public function test_quality_issues_in_several_scenes_are_repaired_in_one_request(): void
    {
        $novel = B04ContentTest::readyNovel(true, 50);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], 'generation_config' => [...($novel->generation_config ?? []), 'acceptance_branch' => ['key' => 'test']]]);
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail());
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $repairs = [];
        $original = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => function ($request) use (&$repairs, &$original) {
            $log = CostLog::latest('id')->firstOrFail();
            $payload = json_decode(explode('冻结输入：', $log->request_parameters['prompt_audit']['user_prompt'], 2)[1], true);
            $parts = [];
            foreach ($payload['budget']['slots'] as $slot) {
                $parts[$slot] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] + $payload['budget']['max'], 2), '甲');
            }
            if (! isset($payload['draft_repair_slot']) && ! isset($payload['draft_repair_slots'])) {
                // 首稿第一、三场景都照搬同一句细纲，第二场景合格。
                $copied = '守塔人劝她别多管闲事并提醒旧航线的事早就没人在意';
                foreach (['scene_1', 'scene_3'] as $slot) {
                    $parts[$slot][0]['text'] = $copied.'，'.mb_substr($parts[$slot][0]['text'], mb_strlen($copied) + 1);
                }
                $parts['scene_2'] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] + $payload['budget']['max'], 2), '乙');
                $original = $parts;
            } else {
                $repairs[] = $payload;
                $this->assertStringContainsString('编辑已有小说的几个场景', $log->request_parameters['prompt_audit']['system_prompt']);
            }
            $response = B02SettingTest::response(json_encode(['title' => '合并修复测试', 'paragraphs' => $parts], JSON_UNESCAPED_UNICODE));
            $response['model'] = $request['model'];

            return Http::response($response);
        }]);
        $run->refresh();
        for ($step = 0; $step < 4 && $run->status === 'pending' && $run->next_step === 'draft'; $step++) {
            app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
            $run->refresh();
        }
        $this->assertCount(1, $repairs);
        $this->assertSame(['scene_1', 'scene_3'], $repairs[0]['draft_repair_slots']);
        $this->assertSame(['scene_1', 'scene_3'], $repairs[0]['budget']['slots']);
        $this->assertSame(['scene_1', 'scene_3'], array_keys($repairs[0]['slot_plan']));
        foreach ($repairs[0]['slot_plan'] as $scene) {
            $this->assertSame([], array_intersect(array_keys($scene), ['action', 'goal', 'obstacle', 'emotional_beat', 'change']));
            $this->assertArrayHasKey('key_dialogue', $scene);
        }
        $this->assertContains('守塔人劝她别多管闲事并提醒旧航线的事早就没人在意', $repairs[0]['plan_sentences_to_avoid']);
        $this->assertSame(['scene_2'], array_keys($repairs[0]['surrounding_paragraphs']));
        $this->assertSame('quality', $repairs[0]['repair_measurement']['direction']);
        $this->assertSame($original['scene_3'], $repairs[0]['repair_source']['text']['scene_3']);
        $this->assertStringContainsString('scene_3第1段逐字照搬细纲', $repairs[0]['previous_response_failure']);
        // 首稿、一次合并修复后直接生成候选；未修复的场景逐字保留。
        $this->assertSame(3, $run->requests_used);
        $this->assertSame('check', $run->next_step);
        $repair = NovelStage::where('input->run_id', $run->id)->where('input->step', 'draft')->latest('id')->firstOrFail();
        $this->assertSame(['scene_1', 'scene_3'], $repair->output['data']['repair_slots']);
        $version = ChapterVersion::findOrFail($run->chapter->fresh()->current_candidate_version_id);
        $this->assertStringNotContainsString('守塔人劝她', $version->body);
        $this->assertSame(array_column($original['scene_2'], 'text'), array_slice(explode("\n", $version->body), 2, 2));
    }

    /** 多个场景只是字数不足时合并为一次扩写；合格的场景先合入，仍偏短的场景下一次单独补写，额度不再按场景逐个耗尽。 */
    public function test_short_scenes_are_expanded_in_one_request(): void
    {
        $novel = B04ContentTest::readyNovel(true, 50);
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], 'generation_config' => [...($novel->generation_config ?? []), 'acceptance_branch' => ['key' => 'test']]]);
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail());
        $this->assertSame($run->config['extended_output_tokens'], $run->config['repair_max_output_tokens'] ?? null);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $repairs = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => function ($request) use (&$repairs) {
            $log = CostLog::latest('id')->firstOrFail();
            $payload = json_decode(explode('冻结输入：', $log->request_parameters['prompt_audit']['user_prompt'], 2)[1], true);
            $middle = intdiv($payload['budget']['min'] + $payload['budget']['max'], 2);
            $parts = [];
            foreach ($payload['budget']['slots'] as $slot) {
                $parts[$slot] = B04ContentTest::sceneParagraphs($middle, '甲');
            }
            if (! isset($payload['draft_repair_slot']) && ! isset($payload['draft_repair_slots'])) {
                // 首稿每个场景都只写了约七成，分段合格。
                foreach ($parts as $slot => $paragraphs) {
                    $parts[$slot] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] * 7, 10), '甲');
                }
            } else {
                $repairs[] = ['payload' => $payload, 'system' => $log->request_parameters['prompt_audit']['system_prompt']];
                // 合并扩写时第三个场景仍然偏短，留给下一次单独补写。
                if (count($repairs) === 1) {
                    $parts['scene_3'] = B04ContentTest::sceneParagraphs(intdiv($payload['budget']['min'] * 8, 10), '甲');
                }
            }
            $response = B02SettingTest::response(json_encode(['title' => '扩写测试', 'paragraphs' => $parts], JSON_UNESCAPED_UNICODE));
            $response['model'] = $request['model'];

            return Http::response($response);
        }]);
        $run->refresh();
        for ($step = 0; $step < 5 && $run->status === 'pending' && $run->next_step === 'draft'; $step++) {
            app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
            $run->refresh();
        }
        $this->assertCount(2, $repairs);
        $batch = $repairs[0]['payload'];
        $this->assertSame(['scene_1', 'scene_2', 'scene_3'], $batch['draft_repair_slots']);
        $this->assertSame('length', $batch['repair_measurement']['direction']);
        $this->assertSame(['scene_1' => 'expand', 'scene_2' => 'expand', 'scene_3' => 'expand'], $batch['repair_measurement']['directions']);
        // 扩写要按细纲补足内容：保留完整细纲，不附照搬清单，也不附逐字自检规则。
        $this->assertArrayHasKey('action', $batch['slot_plan']['scene_1']);
        $this->assertArrayNotHasKey('plan_sentences_to_avoid', $batch);
        $this->assertStringContainsString('scene_1原实测', $repairs[0]['system']);
        $this->assertStringNotContainsString('程序会逐字检查', $repairs[0]['system']);
        // 第二次只补仍偏短的第三个场景，前两个场景已合入。
        $this->assertSame('scene_3', $repairs[1]['payload']['draft_repair_slot']);
        $this->assertArrayNotHasKey('draft_repair_slots', $repairs[1]['payload']);
        $this->assertSame('expand', $repairs[1]['payload']['repair_measurement']['direction']);
        $this->assertSame(['scene_1', 'scene_2'], array_keys($repairs[1]['payload']['surrounding_paragraphs']));
        $merged = NovelStage::where('input->run_id', $run->id)->where('input->step', 'draft')->orderBy('id')->get()[1];
        $this->assertSame(ContentExecutionService::IN_RUN_REPAIR, $merged->output['data']['outcome']);
        $this->assertStringContainsString('scene_3实际', $merged->output['data']['validation_error']);
        // 细纲、首稿、合并扩写、单场景补写共四次请求后生成候选。
        $this->assertSame(4, $run->requests_used);
        $this->assertSame('check', $run->next_step);
        $this->assertNotNull($run->chapter->fresh()->current_candidate_version_id);
    }
}
