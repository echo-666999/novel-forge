<?php

namespace App\Services\Pipeline;

use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Jobs\RunChapterStepJob;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\ProductionRun;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 首次与恢复共用的有限执行器；一次消息最多一次HTTP，不自动创建后继。 */
final class ContentExecutionService
{
    /** 正文篇幅或分段未通过、仍在本运行额度内继续修复的请求结果标记。 */
    public const IN_RUN_REPAIR = 'draft_repair_in_run';

    /** 每次领用和回写均核对规划、版本、预算、细纲及受检正文。 */
    public function validate(ChapterGenerationRun $run): void
    {
        app(LegacyContentSources::class)->assertPlanningSources($run);
        $config = $run->config;
        $initial = $config['initial_step'] ?? null;
        $manual = $initial === 'check' && isset($config['manual_edit']);
        // 已认可对照稿是新分支独立来源，必须重新检查，不能借用旧版推进结论。
        $imported = $initial === 'check' && isset($config['accepted_comparison']);
        $endingRecheck = $initial === 'check' && isset($config['automated_ending_recheck']);
        $base = ($manual || $imported || $endingRecheck) ? 1 : ($initial === 'fine' ? 3 : 2);
        $cap = $base + ($initial === 'revise' ? 0 : 2 * ($config['max_retries'] ?? 0));
        if (! ContentExecutionContract::readable($config['version'] ?? null) || $run->request_limit !== ($config['request_cap'] ?? null)
            || ! in_array($config['max_retries'] ?? null, [0, 1], true) || $run->retry_count > $config['max_retries']
            || ! in_array($initial, ['fine', 'draft', 'revise', 'check'], true) || ($initial === 'check' && ((! $manual && ! $imported && ! $endingRecheck) || $config['max_retries'] !== 0 || (($manual || $endingRecheck) && $run->parent_run_id === null) || ($imported && $run->parent_run_id !== null))) || ($initial === 'fine' && ! $config['enable_fine_outline'])
            || $run->requests_used < 0 || $run->requests_used > $run->request_limit || $run->request_limit < $base || $run->request_limit > $cap || $run->length_repair_count !== 0
            || data_get($config, 'authorization.scope') !== 'bounded_content') {
            throw new InvalidArgumentException('正文协议或冻结额度无效');
        }
        // 冻结版本运行只读核验其冻结摘要；新请求只由当前版本发出。
        foreach (ContentExecutionContract::protocol($config['version']) as $step => $hash) {
            if (($config['protocol'][$step] ?? null) !== $hash) {
                throw new InvalidArgumentException('冻结指令已变化');
            }
        }
        if ($run->parent_run_id !== null) {
            $parent = ChapterGenerationRun::where('chapter_id', $run->chapter_id)->findOrFail($run->parent_run_id);
            $recovery = $run->snapshot['recovery'];
            if ($parent->input_hash !== $recovery['parent_input_hash'] || PlanningJson::hash([$parent->snapshot, $parent->config]) !== $parent->input_hash
                || $parent->status !== $recovery['parent_status'] || $parent->stop_reason !== $recovery['parent_stop_reason']) {
                throw new InvalidArgumentException('恢复父运行已变化');
            }
            if ($run->source_version_id !== null) {
                $source = ChapterVersion::where('chapter_id', $run->chapter_id)->findOrFail($run->source_version_id);
                if ($source->body_hash !== $recovery['source_body_hash'] || hash('sha256', $source->body) !== $source->body_hash
                    || PlanningJson::hash(app(LegacyContentSources::class)->sourceLineage($parent, $source)) !== PlanningJson::hash($recovery['source_lineage'])) {
                    throw new InvalidArgumentException('恢复正文祖先链已变化');
                }
            }
            if ($recovery['fine_stage_id'] !== null && ($run->fine_stage_id !== $recovery['fine_stage_id'] || PlanningJson::hash($run->plan_data) !== $recovery['plan_hash'])) {
                throw new InvalidArgumentException('复用细纲已变化');
            }
        }
        if ($run->config['enable_fine_outline'] && $run->plan_data !== null) {
            $fine = NovelStage::findOrFail($run->fine_stage_id);
            if ($fine->novel_id !== $run->chapter->novel_id || $fine->stage !== Stage::FineOutline || $fine->status !== StageStatus::Completed
                || PlanningJson::hash(data_get($fine->output, 'data.result')) !== PlanningJson::hash($run->plan_data)
                || ($run->parent_run_id === null && data_get($fine->input, 'run_id') !== $run->id)) {
                throw new InvalidArgumentException('细纲产物与来源不一致');
            }
            ContentSchema::fine($run->plan_data, array_column($run->snapshot['source_chapter']['progression_points'], 'id'));
        } elseif (! $run->config['enable_fine_outline'] && PlanningJson::hash($run->plan_data) !== PlanningJson::hash($run->snapshot['source_chapter'])) {
            throw new InvalidArgumentException('关闭细纲时必须直接沿用B03章计划');
        }
        $version = $this->candidate($run);
        if ($endingRecheck && ($version === null || $version->id !== $run->source_version_id
            || $version->id !== $config['automated_ending_recheck']['version_id'] || $version->body_hash !== $config['automated_ending_recheck']['body_hash']
            || ContentExecutionContract::incompleteParagraphs($version->body) !== [])) {
            throw new InvalidArgumentException('自动段尾复查来源无效');
        }
        // 人工候选绑定独立来源阶段、作者及正文摘要，不复用旧模型评审。
        if ($manual) {
            $edit = $config['manual_edit'];
            $stage = $version === null ? null : NovelStage::find($version->source_stage_id);
            if ($version === null || $version->run_id !== $run->id || $version->parent_version_id !== $run->source_version_id
                || $edit['source_version_id'] !== $run->source_version_id || $stage?->stage !== Stage::Content || $stage?->status !== StageStatus::Completed
                || $version->body_hash !== $edit['body_hash'] || $version->title !== $edit['title'] || $edit['author_id'] !== $config['author_id']
                || $stage?->novel_id !== $run->chapter->novel_id || data_get($stage?->input, 'run_id') !== $run->id
                || data_get($stage?->input, 'author_id') !== $config['author_id'] || data_get($stage?->output, 'mode') !== 'manual_edit'
                || data_get($stage?->output, 'body_hash') !== $version->body_hash) {
                throw new InvalidArgumentException('人工候选来源或正文不一致');
            }
        }
        if ($imported) {
            $lineage = $config['accepted_comparison'];
            $stage = $version === null ? null : NovelStage::find($version->source_stage_id);
            $source = ChapterVersion::find($lineage['source_version_id'] ?? 0);
            if ($version === null || $version->run_id !== $run->id || $version->body_hash !== ($lineage['body_hash'] ?? null)
                || hash('sha256', $version->body) !== $version->body_hash || $version->character_count !== ContentSchema::count($version->body)
                || $source === null || $source->chapter->novel_id !== ($lineage['source_novel_id'] ?? null)
                || $source->chapter->novel_id === $run->chapter->novel_id || $source->body_hash !== hash('sha256', $source->body)
                || data_get($lineage, 'approval.body_sha256') !== $version->body_hash || data_get($lineage, 'approval.actor_id') !== $config['author_id']
                || data_get($lineage, 'approval.decision') !== 'approve' || $run->chapter->number !== 1
                || PlanningJson::hash($lineage) !== PlanningJson::hash(data_get($run->chapter->novel->generation_config, 'acceptance_branch'))
                || $stage?->novel_id !== $run->chapter->novel_id || $stage?->stage !== Stage::Content || $stage?->status !== StageStatus::Completed
                || data_get($stage?->input, 'run_id') !== $run->id || data_get($stage?->input, 'mode') !== 'accepted_comparison_import'
                || data_get($stage?->output, 'body_hash') !== $version->body_hash) {
                throw new InvalidArgumentException('验收分支正文来源或作者认可不一致');
            }
        }
        $result = $run->chapter->progression_result;
        if ($result !== null) {
            $check = ChapterProgressionCheck::where('chapter_id', $run->chapter_id)->findOrFail($result['check_id']);
            if ($version === null || $check->version_id !== $version->id || $check->body_hash !== $version->body_hash
                || $result['version_id'] !== $version->id || $result['body_hash'] !== $version->body_hash
                || PlanningJson::hash($check->result) !== PlanningJson::hash(collect($result)->except(['check_id', 'version_id', 'body_hash'])->all())) {
                throw new InvalidArgumentException('检查与当前正文不一致');
            }
        }
    }

    /** 候选必须属于当前运行或经过祖先校验的明确源版本。 */
    private function candidate(ChapterGenerationRun $run): ?ChapterVersion
    {
        $id = $run->chapter->current_candidate_version_id;
        if ($id === null) {
            return null;
        }
        $version = ChapterVersion::where('chapter_id', $run->chapter_id)->findOrFail($id);
        if (($version->run_id !== $run->id && $version->id !== $run->source_version_id) || hash('sha256', $version->body) !== $version->body_hash || ContentSchema::count($version->body) !== $version->character_count) {
            throw new InvalidArgumentException('候选归属或摘要无效');
        }

        return $version;
    }

    /** 确定性质量问题；照搬细纲与可读性极端值只对当前协议运行检查，冻结运行的已保存结论不因新规则改变。
     * @return list<array<string, mixed>>
     */
    private function qualityIssues(ChapterGenerationRun $run, string $body): array
    {
        $issues = ContentQualityContract::issues($body, $run->snapshot['novel']['chapter_length_policy']);
        if (($run->config['version'] ?? null) === ContentExecutionContract::VERSION) {
            $issues = [...$issues, ...ContentQualityContract::planCopies($body, $run->plan_data), ...ContentQualityContract::echoedDialogue($body),
                ...ContentReadabilityMetrics::issues(ContentReadabilityMetrics::measure($body))];
        }

        return $issues;
    }

    /** 合并当前风险和已保存意见；规则提示不直接裁定语义错误。
     * @return array<string, array<string, mixed>>
     */
    public function findings(ChapterGenerationRun $run, ?ChapterVersion $version): array
    {
        $issues = $version === null ? [] : [...$this->qualityIssues($run, $version->body), ...ContentReadingAudit::inspect($version->body)['issues']];
        foreach ($run->control_history as $control) {
            if (($control['action'] ?? null) === 'automated_finding' && ($control['body_hash'] ?? null) === $version?->body_hash) {
                $issues[] = ['type' => 'automated_finding', 'reason' => $control['reason'], 'quote' => $control['quote']];
            }
            if (($control['action'] ?? null) === 'full_read_failed') {
                foreach ($control['notes'] as $note) {
                    $issues[] = ['type' => 'full_read', 'reason' => $note];
                }
            }
        }
        $result = $run->chapter->progression_result ?? [];
        foreach ([...($result['points'] ?? []), $result['hook'] ?? []] as $point) {
            if (($point['met'] ?? true) === false) {
                $issues[] = ['type' => 'progression', 'reason' => $point['reason']];
            }
        }
        // 当前协议的检查问题带上证据段号，修订范围才能被限制在问题附近；冻结运行保持原问题编号不变。
        $located = ($run->config['version'] ?? null) === ContentExecutionContract::VERSION;
        foreach ([...($result['conflicts'] ?? []), ...($result['continuity_issues'] ?? [])] as $issue) {
            $issues[] = ['type' => 'check', 'reason' => $issue['reason'], ...($located && isset($issue['evidence_ids']) ? ['evidence_ids' => $issue['evidence_ids']] : [])];
        }
        foreach ($result['reading_reviews'] ?? [] as $review) {
            if (! $review['fixed']) {
                $issues[] = ['type' => 'review', 'reason' => $review['reason']];
            }
        }
        $findings = $run->config['recovery_findings'] ?? [];
        foreach ($issues as $issue) {
            $issue['source_body_hash'] = $version?->body_hash;
            $findings['finding-'.substr(PlanningJson::hash($issue), 0, 16)] = $issue;
        }
        // 只从当前正文的复查结论移除已解决项目；旧运行配置与检查记录仍完整保留。
        if ($version !== null && ($result['body_hash'] ?? null) === $version->body_hash) {
            foreach ($result['reading_reviews'] ?? [] as $review) {
                if (($review['fixed'] ?? false) && in_array($review['disposition'] ?? null, ['resolved', 'benign'], true)) {
                    unset($findings[$review['id']]);
                }
            }
        }
        if ($findings === []) {
            $findings['whole-chapter'] = ['type' => 'whole_chapter', 'reason' => '核对完整推进、连贯时序与章末钩子'];
        }
        ksort($findings, SORT_STRING);

        return $findings;
    }

    /** 请求上下文由已冻结输入和不可变产物推导，不修改运行配置。
     * @return array{payload: array<string, mixed>, context: array<string, mixed>}
     */
    private function request(ChapterGenerationRun $run, ?ChapterVersion $version): array
    {
        $step = $run->next_step;
        $fine = $run->config['enable_fine_outline'];
        $budget = $step === 'fine' ? null : ContentExecutionContract::budget($run->snapshot['novel']['chapter_length_policy'], $run->plan_data, $fine);
        // 自动首章要求每个生成段落完整结束，避免定长解码在句中截断。
        if ($budget !== null && ProductionRun::coveringChapter($run->chapter->novel_id, $run->chapter->number) !== null) {
            $budget['complete_paragraphs'] = true;
        }
        $context = ['chapter_number' => $run->chapter->number, 'accepted_branch_continuation' => $run->chapter->number > 1 && isset($run->snapshot['acceptance_branch']), 'anchors' => $version === null ? [] : ContentSchema::anchors($version->body), 'rules' => ContentQualityContract::rules($run->snapshot),
            'findings' => $this->findings($run, $version), 'budget' => $budget];
        if (($step === 'draft' && isset($run->config['draft_max_output_tokens'])) || ($step === 'revise' && isset($run->config['revision_max_output_tokens'])) || ($step === 'check' && isset($run->config['check_max_output_tokens']))) {
            $context['deepseek_reasoning_effort'] = 'low';
            // 运行冻结了扩展输出容量，请求选项据此放行超过8192的上限。
            $context['extended_output'] = true;
        }
        // 原始创意保留具体时间、视角和限制；失败反馈只用于修复响应，不成为故事事实。
        $payload = ['chapter_number' => $run->chapter->number, 'prior_context' => $run->snapshot['prior_context'] ?? null, 'writing_requirements' => ['原始创意中的时间、人物称谓及具体限制不得被规划摘要覆盖。', '以可观察的具体行动、对白、阻力及结果推进，每段须有新信息；不反复宣告调查方向明确或决心坚定。', '不得输出故事将在后续展开、待续等写作说明。每段必须以完整句子的结束标点收束，不允许为满足字数而在句中截断或把半句拆到下一段。'], 'author_idea' => $run->snapshot['novel']['premise_raw'], ...(isset($run->snapshot['novel']['creative_brief']) ? ['creative_brief' => $run->snapshot['novel']['creative_brief']] : []), 'previous_response_failure' => $run->config['recovery_validation_error'] ?? null, 'author_decisions' => $run->snapshot['decisions'], 'confirmed_setting' => $run->snapshot['confirmed_setting'], 'candidate_roadmap' => $run->snapshot['roadmap'],
            'rule_catalog' => $context['rules'], 'chapter_plan' => $run->plan_data ?? $run->snapshot['source_chapter'], 'word_policy' => $run->snapshot['novel']['chapter_length_policy'],
            'progression_points' => $run->snapshot['source_chapter']['progression_points'], 'findings' => $context['findings'], 'count_rule' => ContentSchema::COUNT_VERSION];
        // 字数失败时提供原段和实际计数错误，避免后继请求看不到失败正文而盲目重写。
        // 修复依据先取恢复运行冻结的失败草稿，再按本运行内已失败的正文请求更新。
        $recovery = $step === 'draft' ? $this->draftSource($run) : null;
        if (isset($recovery['draft'])) {
            $payload['previous_failed_draft'] = $recovery['draft'];
        }
        if (isset($recovery['validation_error'])) {
            $payload['previous_response_failure'] = $recovery['validation_error'];
        }
        if ($step === 'fine') {
            // 场景数随冻结篇幅确定，细纲不能排出每场只够写流程摘要的密集场景。
            $context['scene_range'] = ContentExecutionContract::sceneRange($run->snapshot['novel']['chapter_length_policy']);
            $payload['scene_range'] = $context['scene_range'];
        }
        if ($step === 'check') {
            // 复核同一证据不等于重演行动；疑问必须保留为张力，不能伪装成已确认缺陷。
            $payload['evaluation_guidance'] = ['逐项以当前evidence_catalog阅读全文判断，不复述前轮findings作为本轮事实。',
                '对同一编号、姓名或物品的必要复核、交接与引用可以重复出现；只有没有新增动作、证据或结果而重新演示已经完成的事件，才报告重复行动。',
                'continuity_issues必须是已确认的具体错误，说明哪两处动作或时序不相容，或哪些完整语句只是无效复述。需要区分、可能重复、建议压缩不属于已确认错误；未决疑点放入story_tensions。',
                'finding_reviews与continuity_issues不得自相矛盾；认为旧问题benign或resolved时，不把相同证据与相同理由重新列为缺陷。实际存在的新问题仍必须明确报告并引用当前段落。'];
        }
        if ($budget !== null) {
            $payload['budget'] = $budget;
            $payload['slot_plan'] = [];
            foreach ($budget['slots'] as $index => $slot) {
                $payload['slot_plan'][$slot] = ['order' => $index + 1, 'role' => $index === $budget['count'] - 1 ? '本章推进完成后停在冻结钩子的当下悬念' : '推进新的冲突、信息及结果，不重演已经完成的动作'];
                if ($fine) {
                    $payload['slot_plan'][$slot] += $run->plan_data['scenes'][$index];
                }
            }
            $payload['effective_chapter_contract'] = ['progression_points' => $run->snapshot['source_chapter']['progression_points'], 'hook' => $run->plan_data['hook'], 'hook_transition' => $run->plan_data['hook_transition'] ?? null];
        }
        if (in_array($step, ['revise', 'check'], true)) {
            if ($version === null) {
                throw new InvalidArgumentException('修订或检查缺少当前正文');
            }
            $payload[$step === 'check' ? 'evidence_catalog' : 'available_anchors'] = $context['anchors'];
            $payload['body_hash'] = $version->body_hash;
            $policy = $run->snapshot['novel']['chapter_length_policy'];
            $payload['character_budget'] = ['current' => $version->character_count, 'minimum_change' => $policy['min_characters'] - $version->character_count,
                'maximum_change' => $policy['max_characters'] - $version->character_count, 'target_change' => intdiv($policy['min_characters'] + $policy['max_characters'], 2) - $version->character_count];
        }

        // 只读取冻结快照，恢复和重试不能混入后续账本变更。
        // 卷弧为作者未来约束，来自冻结快照而非当前可变规划。
        // 作者场景也进入细纲、正文及检查请求，不能读取后来修订的版本。
        // 作者关系和规则保留作者视角，与正式状态分栏传递。
        if (array_key_exists('author_entity_definitions', $run->snapshot)) {
            $payload['author_entity_definitions'] = $run->snapshot['author_entity_definitions'];
        }
        if (array_key_exists('author_scene_plan', $run->snapshot)) {
            $payload['author_scene_plan'] = $run->snapshot['author_scene_plan'];
        }
        if (array_key_exists('author_structure', $run->snapshot)) {
            $payload['author_structure'] = $run->snapshot['author_structure'];
        }
        if (array_key_exists('foreshadow_context', $run->snapshot)) {
            $payload['foreshadow_context'] = $run->snapshot['foreshadow_context'];
        }
        // 跨章文风信号只给写作和修订作避免模板化的提示，检查步骤不据此判定问题。
        if (array_key_exists('style_signals', $run->snapshot) && in_array($step, ['draft', 'revise'], true)) {
            $payload['style_signals'] = $run->snapshot['style_signals'];
        }

        // 分支恢复按实测失败槽位逐段修复，其他段逐字保留，整章最终仍执行原契约。
        // 新运行按冻结的模型能力决定；旧运行没有该字段时沿用原验收分支判定。
        // 来自冻结版本的失败草稿按旧槽位或旧文本格式切分，与当前段落列表不一致时只作参考，改为整章重写。
        if ($step === 'draft' && self::slotRepair($run) && isset($recovery['draft']['response']['paragraphs'])
            && self::sameSlots($recovery['draft']['response']['paragraphs'], $budget['slots'])) {
            $draft = $recovery['draft']['response'];
            $repairSlot = null;
            foreach ($budget['slots'] as $slot) {
                try {
                    ContentExecutionContract::body(['title' => $draft['title'], 'paragraphs' => [$slot => $draft['paragraphs'][$slot]]], ContentExecutionContract::repairBudget($budget, $slot));
                } catch (InvalidArgumentException) {
                    $repairSlot = $slot;
                    break;
                }
            }
            $wholeValid = false;
            $byTotal = false;
            if ($repairSlot === null) {
                try {
                    ContentExecutionContract::body($draft, $budget);
                    $wholeValid = true;
                } catch (InvalidArgumentException) {
                    // 各场景都在容差内而整章合计越界时，修离原区间最远的场景；都在区间内时照常整章重写。
                    $distances = [];
                    foreach ($budget['slots'] as $slot) {
                        $distances[$slot] = ContentExecutionContract::lengthDistance(ContentSchema::count(ContentExecutionContract::slotText($draft['paragraphs'][$slot])), $budget);
                    }
                    arsort($distances);
                    $repairSlot = reset($distances) > 0 ? array_key_first($distances) : null;
                    $byTotal = $repairSlot !== null;
                }
            }
            if ($repairSlot !== null) {
                $slot = $repairSlot;
                // 单段修复默认低推理；供应商失败恢复时关闭推理，避免再次耗尽同一输出预算。
                $context['deepseek_reasoning_effort'] = isset($run->config['repair_max_output_tokens']) ? 'low' : (data_get($run->snapshot, 'recovery.parent_stop_reason') === 'provider_failed' ? 'none' : 'low');
                $context['draft_repair_slot'] = $slot;
                $context['full_draft_budget'] = $budget;
                $context['budget'] = ContentExecutionContract::repairBudget($budget, $slot);
                $payload['budget'] = $context['budget'];
                $payload['slot_plan'] = [$slot => $payload['slot_plan'][$slot]];
                $payload['draft_repair_slot'] = $slot;
                // 最近完整单段即使字数未达标也可继续压缩，避免每次从更长的初稿重做。
                $repairSource = $recovery['repair']['paragraphs'][$slot] ?? $draft['paragraphs'][$slot];
                $payload['repair_source'] = ['slot' => $slot, 'text' => $repairSource, 'instruction' => '只修复此处完整场景；其余场景只作上下文，不得改写或复制。'];
                $payload['original_title'] = $draft['title'];
                $payload['surrounding_paragraphs'] = array_diff_key($draft['paragraphs'], [$slot => true]);
                $currentCount = ContentSchema::count(ContentExecutionContract::slotText($repairSource));
                $targetCount = intdiv($budget['min'] + $budget['max'], 2);
                $context['repair_current_count'] = $currentCount;
                // 字数在容差内而分段规则失败时只调格式，不能被误导成扩写或压缩；因整章合计越界选中的场景按原区间增删。
                [$lowest, $highest] = $byTotal ? [$budget['min'], $budget['max']] : ContentExecutionContract::tolerated($budget);
                $context['repair_direction'] = match (true) {
                    $currentCount > $highest => 'shorten',
                    $currentCount < $lowest => 'expand',
                    default => 'format',
                };
                $payload['repair_measurement'] = ['slot' => $slot, 'actual_characters' => $currentCount, 'target_characters' => $targetCount,
                    'required_change' => $context['repair_direction'] === 'format' ? 0 : $targetCount - $currentCount, 'direction' => $context['repair_direction'],
                    'instruction' => match ($context['repair_direction']) {
                        'shorten' => '本场景超长，只做压缩，删除重复描述、流程细节与非必要动作，保留已有关键行动和对白，不把后文动作提前写入。',
                        'expand' => '本场景过短，补足在场人物的对白、反应与行动后果，不重复前后场景事件。',
                        default => '本场景字数合格，只按previous_response_failure修正分段：对白独立成段，碎句合并为完整叙述段，不增删情节。',
                    }];
                // 局部修复保留整份草稿作邻段上下文，不重复发送未来大纲、历史事件与全量设定。
                // 这些来源仍冻结在运行中，完成后整章检查会重新读取，不能借此跳过事实校验。
                $payload = array_intersect_key($payload, array_flip(['chapter_number', 'author_idea', 'writing_requirements', 'rule_catalog',
                    'count_rule', 'previous_response_failure', 'original_title', 'surrounding_paragraphs', 'budget', 'slot_plan', 'draft_repair_slot', 'repair_source', 'repair_measurement']));
            } elseif ($wholeValid) {
                // 各场景都已合格时整份草稿直接采用，不再为已合格的草稿重新付费生成。
                $context['reused_draft'] = ['cost_log_id' => $recovery['draft']['cost_log_id'] ?? null, 'merged_stage_id' => $recovery['draft']['merged_stage_id'] ?? null,
                    'hash' => PlanningJson::hash($draft)];
            }
        }

        // 统一AI入口按此冻结预算组装并保存实际请求清单。
        if (isset($run->snapshot['context_policy'])) {
            $context['context_policy'] = $run->snapshot['context_policy'];
            $context['context_source'] = ['content_run_id' => $run->id, 'input_hash' => $run->input_hash, 'chapter_number' => $run->chapter->number];
        }

        return ['payload' => $payload, 'context' => $context, 'base' => $recovery['draft']['response'] ?? null];
    }

    /** 修复依据的失败草稿：先取恢复运行冻结的草稿，再按本运行内继续修复的正文请求依次更新；只读不可变产物，不修改运行配置。
     * repair 是各场景最接近预算的一次单场景尝试，未通过也只作下次修复输入，绝不合入正文。
     *
     * @return array{draft: ?array<string, mixed>, repair: ?array<string, mixed>, validation_error: ?string, in_run: bool}|null
     */
    public function draftSource(ChapterGenerationRun $run): ?array
    {
        $draft = $run->config['recovery_draft'] ?? null;
        $repair = $run->config['recovery_repair'] ?? null;
        $error = $run->config['recovery_validation_error'] ?? null;
        $inRun = false;
        $stages = NovelStage::where('novel_id', $run->chapter->novel_id)->where('input->run_id', $run->id)->where('input->step', 'draft')
            ->where('status', StageStatus::Failed->value)->orderBy('id')->get();
        foreach ($stages as $stage) {
            $data = data_get($stage->output, 'data', []);
            if (($data['outcome'] ?? null) !== self::IN_RUN_REPAIR) {
                continue;
            }
            $error = $data['validation_error'] ?? $error;
            $log = CostLog::where('novel_stage_id', $stage->id)->whereKey($data['cost_log_id'] ?? 0)->where('status', 'succeeded')->first();
            $parts = $log?->structured_output['paragraphs'] ?? null;
            if (is_array($data['repaired_draft']['response'] ?? null)) {
                // 单场景修复已合入，其余场景逐字保留。
                $draft = ['cost_log_id' => $data['cost_log_id'] ?? null, 'response' => $data['repaired_draft']['response'], 'merged_stage_id' => $stage->id, 'merged_hash' => $data['repaired_draft']['hash']];
                $repair = null;
                $inRun = true;
            } elseif (! isset($data['repair_slot'])) {
                // 整章草稿未通过时以该次完整响应为下次修复依据。
                if (is_array($parts)) {
                    $draft = ['cost_log_id' => $log->id, 'response' => $log->structured_output];
                    $repair = null;
                    $inRun = true;
                }
            } elseif (is_array($parts[$data['repair_slot']] ?? null) && count($parts) === 1) {
                // 单场景修复本身未通过：保留原草稿，只在更接近预算时更新下次修复输入。
                $slot = $data['repair_slot'];
                $budget = ContentExecutionContract::budget($run->snapshot['novel']['chapter_length_policy'], $run->plan_data, $run->config['enable_fine_outline']);
                $distance = fn (array $paragraphs): int => ContentExecutionContract::lengthDistance(ContentSchema::count(ContentExecutionContract::slotText($paragraphs)), $budget);
                $previous = $repair['paragraphs'][$slot] ?? $draft['response']['paragraphs'][$slot] ?? null;
                if (! is_array($previous) || $distance($parts[$slot]) <= $distance($previous)) {
                    $repair = ['cost_log_id' => $log->id, 'paragraphs' => $parts];
                }
            }
        }

        return $draft === null && $error === null ? null : ['draft' => $draft, 'repair' => $repair, 'validation_error' => $error, 'in_run' => $inRun];
    }

    /** 按冻结的模型能力或验收分支启用单场景修复，与恢复运行的判定一致。 */
    private static function slotRepair(ChapterGenerationRun $run): bool
    {
        return (bool) ($run->config['slot_repair'] ?? false) || isset($run->snapshot['acceptance_branch']);
    }

    /** 正文篇幅或分段未通过时，在已授权额度内继续修复：须启用单场景修复，且修复后仍留一次推进检查的请求与执行额度。 */
    private function canRepairInRun(ChapterGenerationRun $run, Novel $novel, ?string $error): bool
    {
        return self::slotRepair($run)
            && $error !== null && preg_match('/^正文(场景|单段)预算或格式无效/u', $error) === 1
            && $run->requests_used + 2 <= $run->request_limit && $novel->execution_count + 2 <= $novel->max_execution_count;
    }

    /** 失败草稿的字段与当前预算槽位完全一致、且每个槽位都是段落列表时才能逐场景修复。
     * @param  list<string>  $slots
     */
    private static function sameSlots(mixed $paragraphs, array $slots): bool
    {
        if (! is_array($paragraphs) || array_filter($paragraphs, fn (mixed $slot): bool => ! is_array($slot) || ! array_is_list($slot)) !== []) {
            return false;
        }
        $keys = array_keys($paragraphs);
        sort($keys);
        sort($slots);

        return $keys === $slots;
    }

    /** 单次领用先提交费用所需来源；HTTP绝不处于业务事务内。 */
    public function runStep(int $id, string $token, AiTextGenerationService $ai): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('正文请求不能处于外层事务');
        }
        $reference = ChapterGenerationRun::find($id);
        if ($reference === null) {
            return;
        }
        if (($reference->config['version'] ?? null) !== ContentExecutionContract::VERSION) {
            Log::notice('旧正文协议消息只读拒绝，须显式恢复为新协议', ['run_id' => $id]);

            return;
        }
        $claim = DB::transaction(function () use ($id, $token): ?array {
            $reference = ChapterGenerationRun::findOrFail($id);
            $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'pending' || $run->invocation_id !== $token || $novel->status !== NovelStatus::Pending) {
                return null;
            }
            try {
                $this->validate($run);
                $version = $this->candidate($run);
                $request = $this->request($run, $version);
            } catch (Throwable) {
                $this->stop($run, $novel, 'invalid_contract');

                return null;
            }
            $reuse = isset($request['context']['reused_draft']);
            if (! $reuse && ($run->requests_used >= $run->request_limit || $novel->execution_count >= $novel->max_execution_count)) {
                $this->stop($run, $novel, 'execution_limit');

                return null;
            }
            if ($novel->stages()->where('status', 'running')->exists() || CostLog::where('novel_id', $novel->id)->where('status', 'pending')->exists()
                || ($run->next_step === 'revise' && ! $this->canRevise($run))) {
                $this->stop($run, $novel, 'retry_limit');

                return null;
            }
            $step = $run->next_step;
            $stageType = $step === 'fine' ? Stage::FineOutline : Stage::Content;
            $stage = NovelStage::create(['novel_id' => $novel->id, 'stage' => $stageType, 'status' => StageStatus::Running,
                'execution_count' => (int) $novel->stages()->where('stage', $stageType->value)->max('execution_count') + 1,
                'input' => ['pipeline' => ['version' => ContentExecutionContract::VERSION], 'run_id' => $run->id, 'step' => $step, 'invocation_id' => $token,
                    'input_hash' => $run->input_hash, 'version_id' => $version?->id, 'body_hash' => $version?->body_hash, 'request_ordinal' => $run->requests_used + 1,
                    // C04组装前来源摘要与供应商实际用户提示摘要分别保存。
                    (isset($request['context']['context_policy']) ? 'source_payload_hash' : 'payload_hash') => PlanningJson::hash($request['payload']),
                    ...($reuse ? ['reused_draft' => $request['context']['reused_draft']] : [])], 'started_at' => now()]);
            // 采用已合格的保存草稿不发送请求，不占用运行请求额度和小说执行次数。
            $run->update(['status' => 'running', 'requests_used' => $run->requests_used + ($reuse ? 0 : 1), 'retry_count' => $run->retry_count + ($step === 'revise' ? 1 : 0)]);
            $novel->update(['status' => NovelStatus::Running, 'current_stage' => $stageType, 'execution_count' => $novel->execution_count + ($reuse ? 0 : 1)]);
            $run->chapter->update(['status' => $step === 'check' ? ChapterStatus::Checking : ChapterStatus::Generating]);

            return [$run, $novel, $version, $stage, $request];
        });
        if ($claim === null) {
            return;
        }
        [$run, $novel, $source, $stage, $request] = $claim;
        $step = $run->next_step;
        $log = null;
        $data = null;
        $failure = null;
        $error = null;
        $started = hrtime(true);
        $repairedDraft = null;
        $droppedRanges = [];
        $reusedDraft = $request['context']['reused_draft'] ?? null;
        try {
            $tokens = isset($request['context']['draft_repair_slot']) ? ($run->config['repair_max_output_tokens'] ?? $run->config['max_output_tokens']) : $run->config['max_output_tokens'];
            // 首次正文的推理容量只来自新运行冻结配置，单段修复仍使用独立预算。
            if ($step === 'draft' && ! isset($request['context']['draft_repair_slot'])) {
                $tokens = $run->config['draft_max_output_tokens'] ?? $tokens;
            }
            if ($step === 'revise') {
                $tokens = $run->config['revision_max_output_tokens'] ?? $tokens;
            }
            if ($step === 'check') {
                $tokens = $run->config['check_max_output_tokens'] ?? $tokens;
            }
            if (isset($request['context']['reused_draft'])) {
                // 冻结在运行配置中的合格草稿原样采用，费用记录仍在原请求，不另行调用模型。
                $response = $request['base'];
            } else {
                $options = TextRequestOptions::executionChapter($step, $run->config['temperature'], $request['context'], $tokens);
                $log = $ai->generate('本次步骤 '.$step.'；冻结输入：'.PlanningJson::encode($request['payload']), 'real', $run->config['model'], $novel->id, $stage->id, 'b04-'.$step.'-prompt-v17', $options);
                if (preg_match('/^'.preg_quote($run->config['model'], '/').'(-\d{4}-\d{2}-\d{2})?$/D', $log->model ?? '') !== 1) {
                    throw new InvalidArgumentException('响应模型与冻结模型不一致');
                }
                $response = ContentSchema::decode($log->response_text);
            }
            if ($step === 'draft' && isset($request['context']['draft_repair_slot'])) {
                $repairedDraft = ContentExecutionContract::mergeParagraphRepair($request['base'], $response,
                    $request['context']['full_draft_budget'], $request['context']['draft_repair_slot']);
                $response = $repairedDraft;
            }
            $data = match ($step) {
                'fine' => ContentExecutionContract::fine($response, array_column($run->snapshot['source_chapter']['progression_points'], 'id'), $run->snapshot['novel']['chapter_length_policy']),
                'draft' => ContentExecutionContract::body($response, $request['context']['full_draft_budget'] ?? $request['context']['budget']),
                'revise' => (function () use ($response, $source, $request, &$droppedRanges): array {
                    // 没有问题编号的范围不是授权修订，丢弃并留档；其余范围仍按原契约严格校验。
                    [$response, $droppedRanges] = ContentExecutionContract::authorizedRanges($response);
                    // 范围只能落在所处理问题的附近，合计不超过约三分之一正文，防止修一句改半章。
                    ContentExecutionContract::revisionScope($response, $request['context']['anchors'], $request['context']['findings']);
                    // 替换文本与正文同样按段落列表校验分段规则，避免修订把对白重新塞回叙述段。
                    $response = ContentExecutionContract::revisionText($response, $request['context']['budget']['complete_paragraphs'] ?? false);

                    return ContentSchema::body(['title' => $source->title, 'body' => ContentContinuationContract::revise($response, $source->body, $request['context']['findings'])]);
                })(),
                'check' => ContentContinuationContract::check($response, $source->body, array_column($run->snapshot['source_chapter']['progression_points'], 'id'), $request['context']['rules'], $request['context']['findings']),
            };
            if ($step === 'fine') {
                ContentExecutionContract::budget($run->snapshot['novel']['chapter_length_policy'], $data, true);
            }
        } catch (AiGenerationException $exception) {
            $log = $exception->costLogId === null ? null : CostLog::find($exception->costLogId);
            // 上下文容量与预算拦截都发生在请求前，保留原因，不当作供应商失败。
            $budget = in_array($exception->category, ['budget_exhausted', 'budget_price_unknown'], true);
            // 供应商明确返回402（余额不足）单独标出，提示充值后恢复，不归入可自动重试的供应商失败。
            $failure = match (true) {
                $exception->category === 'context_capacity' => 'context_capacity',
                $budget => 'budget_exhausted',
                $exception->status === 'unknown' => 'unknown',
                $exception->category === 'http_402' => 'insufficient_balance',
                default => 'provider_failed',
            };
            $error = $exception->category === 'context_capacity' || $budget ? $exception->getMessage() : null;
        } catch (Throwable $exception) {
            $failure = $log?->status === 'succeeded' ? 'invalid_schema' : (CostLog::where('novel_stage_id', $stage->id)->exists() ? 'unknown' : 'audit_failed');
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : '本地处理异常，保留证据后显式恢复';
        }
        try {
            $repairSlot = $request['context']['draft_repair_slot'] ?? null;
            $next = DB::transaction(function () use ($id, $token, $stage, $source, $step, $data, $failure, $error, $log, $started, $repairedDraft, $droppedRanges, $reusedDraft, $repairSlot): bool {
                $reference = ChapterGenerationRun::findOrFail($id);
                $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
                $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
                $stage = NovelStage::lockForUpdate()->findOrFail($stage->id);
                if ($run->status !== 'running' || $run->invocation_id !== $token || $stage->status !== StageStatus::Running) {
                    return false;
                }
                $this->validate($run);
                if ($this->candidate($run)?->id !== $source?->id) {
                    throw new InvalidArgumentException('请求期间候选发生变化');
                }
                $stop = $failure;
                // 篇幅或分段未通过时，在已授权额度内继续修复，不再一次失败就停下等待手动恢复。
                $repairInRun = $failure === 'invalid_schema' && $step === 'draft' && $this->canRepairInRun($run, $novel, $error);
                if ($repairInRun) {
                    $stop = null;
                }
                $output = ['step' => $step, 'cost_log_id' => $log?->id, 'result' => $data, 'validation_error' => $error];
                if ($droppedRanges !== []) {
                    $output['dropped_ranges'] = $droppedRanges;
                }
                if ($reusedDraft !== null) {
                    $output['reused_draft'] = $reusedDraft;
                }
                if ($repairedDraft !== null) {
                    $output['repaired_draft'] = ['response' => $repairedDraft, 'hash' => PlanningJson::hash($repairedDraft), 'source_input_hash' => $run->input_hash];
                }
                if ($repairSlot !== null) {
                    $output['repair_slot'] = $repairSlot;
                }
                if ($repairInRun) {
                    $run->next_step = 'draft';
                } elseif ($stop === null && $step === 'fine') {
                    $run->plan_data = $data;
                    $run->fine_stage_id = $stage->id;
                    $run->next_step = 'draft';
                } elseif ($stop === null && in_array($step, ['draft', 'revise'], true)) {
                    $version = ChapterVersion::create(['chapter_id' => $run->chapter_id, 'run_id' => $run->id, 'source_stage_id' => $stage->id, 'parent_version_id' => $source?->id,
                        'version' => (int) ChapterVersion::where('chapter_id', $run->chapter_id)->max('version') + 1, 'title' => $data['title'], 'body' => $data['body'],
                        'body_hash' => hash('sha256', $data['body']), 'character_count' => ContentSchema::count($data['body']), 'count_version' => ContentSchema::COUNT_VERSION, 'created_at' => now()]);
                    $run->chapter->update(['title' => $version->title, 'current_candidate_version_id' => $version->id, 'progression_result' => null, 'status' => ChapterStatus::Checking]);
                    $issues = $this->qualityIssues($run, $version->body);
                    $output += ['version_id' => $version->id, 'quality_issues' => $issues, 'reading_audit' => ContentReadingAudit::inspect($version->body),
                        'readability' => ContentReadabilityMetrics::measure($version->body)];
                    if ($issues !== [] || $source?->body_hash === $version->body_hash) {
                        $run->next_step = 'revise';
                        $stop = $this->canRevise($run) ? null : 'body_quality_insufficient';
                    } else {
                        $run->next_step = 'check';
                    }
                } elseif ($stop === null) {
                    $check = ChapterProgressionCheck::create(['chapter_id' => $run->chapter_id, 'version_id' => $source->id, 'source_stage_id' => $stage->id,
                        'body_hash' => $source->body_hash, 'verdict' => $data['verdict'], 'result' => $data, 'created_at' => now()]);
                    $run->chapter->update(['progression_result' => ['check_id' => $check->id, 'version_id' => $source->id, 'body_hash' => $source->body_hash, ...$data],
                        'status' => $data['verdict'] === 'pass' ? ChapterStatus::Draft : ChapterStatus::RevisionRequired]);
                    $run->next_step = 'revise';
                    $stop = match ($data['verdict']) {
                        'pass' => 'awaiting_review', 'blocked' => 'blocked', default => $this->canRevise($run) ? null : 'retry_limit',
                    };
                    if ($stop === 'awaiting_review') {
                        $novel->progress = $run->config['enable_fine_outline'] ? '66.67' : '60.00';
                        $run->next_step = 'check';
                    }
                }
                $stage->update(['status' => ! $repairInRun && ($stop === null || $stop === 'awaiting_review') ? StageStatus::Completed : StageStatus::Failed,
                    'output' => ['simulation' => false, 'stage' => $stage->stage->value, 'data' => [...$output, 'outcome' => $repairInRun ? self::IN_RUN_REPAIR : $stop]],
                    'error_message' => $repairInRun ? $failure : ($stop === 'awaiting_review' ? null : $stop), 'cost' => $log?->only(['billing_status', 'currency', 'estimated_cost', 'actual_cost']),
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'finished_at' => now()]);
                // 已成功返回的产物先保存，再阻止下一步继续消费额度。
                if ($stop === null && $novel->pause_requested) {
                    $stop = 'user_paused';
                }
                $run->status = $stop === null ? 'pending' : ($stop === 'awaiting_review' ? 'completed' : 'paused');
                $run->stop_reason = $stop;
                $run->invocation_id = (string) Str::uuid7();
                $run->saveOrFail();
                $novel->status = $stop === null ? NovelStatus::Pending : NovelStatus::Paused;
                $novel->current_stage = Stage::Content;
                $novel->saveOrFail();

                return $stop === null;
            });
        } catch (Throwable) {
            $this->interrupted($id, $token);

            return;
        }
        if ($next) {
            $this->dispatch(ChapterGenerationRun::findOrFail($id));
        }
    }

    /** 所有修订共享同一运行计数，并为独立复查预留一次请求。 */
    public function canRevise(ChapterGenerationRun $run, bool $includeNovelLimit = true): bool
    {
        $id = $run->chapter->fresh()->current_candidate_version_id;
        $version = $id === null ? null : ChapterVersion::find($id);

        return $version !== null && count(ContentSchema::anchors($version->body)) >= 2
            && $run->retry_count < $run->config['max_retries'] && $run->request_limit - $run->requests_used >= 2
            && (! $includeNovelLimit || $run->chapter->novel->max_execution_count - $run->chapter->novel->execution_count >= 2);
    }

    /** 已授权自动验收可追加有原文依据的诊断，不能伪造人工阅读或直接改变通过状态。 */
    public function recordAutomatedFinding(int $id, int $automationId, User $author, int $versionId, string $quote, string $reason): void
    {
        ContentSchema::text($quote);
        ContentSchema::text($reason);
        DB::transaction(function () use ($id, $automationId, $author, $versionId, $quote, $reason): void {
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            $automation = ProductionRun::findOrFail($automationId);
            $automation->assertDelegation($run->chapter->novel_id, $author->id);
            $version = ChapterVersion::where('chapter_id', $run->chapter_id)->findOrFail($versionId);
            if (! in_array($run->status, ['paused', 'error', 'completed'], true) || $automation->status !== 'failed'
                || $run->chapter->formal_version_id !== null || $run->chapter->current_candidate_version_id !== $versionId
                || hash('sha256', $version->body) !== $version->body_hash || ! str_contains($version->body, $quote)) {
                throw new InvalidArgumentException('自动验收诊断来源或运行边界无效');
            }
            $run->update(['control_history' => [...$run->control_history, ['action' => 'automated_finding', 'automation_id' => $automationId,
                'operator_id' => $author->id, 'version_id' => $versionId, 'body_hash' => $version->body_hash, 'quote' => $quote,
                'reason' => $reason, 'mode' => 'delegated_diagnostic_not_human_rating', 'at' => now()->toIso8601String()]]]);
        });
    }

    /** 只记录全文结果；不通过不会投递模型请求或提高任何额度。 */
    public function recordReading(int $id, User $author, bool $accepted, array $notes, ?int $automationId = null): ChapterGenerationRun
    {
        if ($notes === []) {
            throw new InvalidArgumentException('全文核对必须填写结论');
        }
        foreach ($notes as $note) {
            ContentSchema::text($note);
        }

        return DB::transaction(function () use ($id, $author, $accepted, $notes, $automationId): ChapterGenerationRun {
            $reference = ChapterGenerationRun::findOrFail($id);
            $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            app(ContentGenerationService::class)->assertFrozenSources($run);
            $operator = $run->config['author_id'] ?? data_get($run->config, 'continuation_grant.operator_id') ?? data_get($run->control_history, '0.author_id');
            $result = $run->chapter->progression_result;
            // 操作人不符时写明需要的账号，避免作者误以为是正文状态问题。
            if (! app(BookAuthorship::class)->allows($novel->id, $author, $operator)) {
                throw new InvalidArgumentException('本章由账号「'.app(BookAuthorship::class)->requiredAccountName($novel->id, $operator).'」负责，只能由该账号记录全文核对，请换用该账号登录，或在小说页接管本书');
            }
            if ($run->status !== 'completed' || $run->stop_reason !== 'awaiting_review' || $novel->status !== NovelStatus::Paused
                || $result === null || $result['verdict'] !== 'pass') {
                throw new InvalidArgumentException('没有可核对的当前候选或操作人不符');
            }
            // 委托验收必须绑定同一小说和操作者，审计明确区分人工阅读。
            if ($automationId !== null) {
                ProductionRun::findOrFail($automationId)->assertDelegation($novel->id, $author->id);
            }
            $run->update(['status' => $accepted ? 'completed' : 'paused', 'stop_reason' => $accepted ? 'b04_accepted' : 'reading_failed',
                'control_history' => [...$run->control_history, ['action' => $accepted ? 'acceptance_complete' : 'full_read_failed', 'operator_id' => $author->id,
                    'version_id' => $result['version_id'], 'body_hash' => $result['body_hash'], 'check_id' => $result['check_id'], 'notes' => $notes,
                    'author_story_approval' => false, 'acceptance_mode' => $automationId === null ? 'manual_reading' : 'delegated_policy', 'automation_id' => $automationId, 'at' => now()->toIso8601String()]]]);

            return $run;
        });
    }

    /** 派发失败尚未发送HTTP，不能把零调用误标为未知付费。 */
    public function dispatch(ChapterGenerationRun $run): void
    {
        // 编排器正在推进本章时由它执行，禁止同时派发步骤任务；已失败的编排不会推进，恢复运行必须自行投递，否则停在待执行。
        if (ProductionRun::drivingChapter($run->chapter->novel_id, $run->chapter->number) !== null) {
            return;
        }

        try {
            Bus::dispatch(new RunChapterStepJob($run->id, $run->invocation_id, true));
        } catch (Throwable) {
            $this->interrupted($run->id, $run->invocation_id);
        }
    }

    /** 同一令牌只结算一次；未知费用保留，迟到回调不覆盖新结果。 */
    public function interrupted(int $id, string $token): void
    {
        $reference = ChapterGenerationRun::find($id);
        if ($reference === null || ! ContentExecutionContract::readable($reference->config['version'] ?? null)) {
            return;
        }
        DB::transaction(function () use ($id, $token): void {
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            $novel = Novel::lockForUpdate()->findOrFail($run->chapter->novel_id);
            if ($run->invocation_id !== $token || ! in_array($run->status, ['pending', 'running'], true)) {
                return;
            }
            $stage = NovelStage::where('novel_id', $novel->id)->where('status', 'running')->where('input->invocation_id', $token)->first();
            if ($stage !== null) {
                $log = CostLog::where('novel_stage_id', $stage->id)->latest('id')->first();
                if ($log?->status === 'pending') {
                    $log->update(['status' => 'unknown', 'error_category' => 'queue_interrupted', 'error_message' => '正文请求结果未知，未自动重发', 'finished_at' => now()]);
                }
                $stage->update(['status' => StageStatus::Failed, 'error_message' => 'unknown', 'output' => ['data' => ['outcome' => 'unknown', 'cost_log_id' => $log?->id]], 'finished_at' => now()]);
            }
            $this->stop($run, $novel, $stage === null ? 'dispatch_failed' : 'unknown');
        });
    }

    /** 暂停只影响候选生产状态，正式计数和故事状态始终保持。 */
    private function stop(ChapterGenerationRun $run, Novel $novel, string $reason): void
    {
        $run->update(['status' => 'paused', 'stop_reason' => $reason]);
        $novel->update(['status' => NovelStatus::Paused]);
    }
}
