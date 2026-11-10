<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Models\NovelOutlineChapter;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use InvalidArgumentException;

/** 只读校验历史协议及共同规划来源，不创建运行、不发送模型请求。 */
final class LegacyContentSources
{
    /** 核对设定、大纲及冻结输入，供新旧协议复用。 */
    public function assertPlanningSources(ChapterGenerationRun $run): void
    {
        $snapshot = $run->snapshot;
        $confirmation = NovelSettingConfirmation::query()->findOrFail($snapshot['confirmation_id']);
        $source = NovelOutlineChapter::query()->findOrFail($run->outline_chapter_id);
        $plan = $source->plan;
        $origin = NovelStage::query()->findOrFail($source->source_stage_id);
        $recorded = collect(data_get($origin->output, 'data.chapters', []))->firstWhere('chapter_number', $run->chapter->number);
        $chapterData = collect($source->attributesToArray())->except(['id', 'outline_plan_id', 'source_stage_id'])->all();
        $settingStage = NovelStage::query()->findOrFail($confirmation->source_stage_id);
        // 全书走向只由首个大纲批次产生，后续批次承接冻结走向但不重复输出；第11章起须与首批比对。
        $roadmapOrigin = data_get($origin->output, 'data.roadmap') !== null ? $origin
            : NovelStage::query()->where('novel_id', $plan->novel_id)->where('stage', Stage::Outline->value)->where('status', StageStatus::Completed->value)
                ->where('input->outline_plan_id', $plan->id)->orderBy('id')->first();
        $checks = [
            '运行输入摘要' => PlanningJson::hash([$snapshot, $run->config]) === $run->input_hash,
            '确认内容摘要' => $confirmation->hasValidHash(),
            '确认来源摘要' => $confirmation->source_hash === (new ConfirmSettingService)->sourceHash($settingStage),
            '小说归属' => $confirmation->novel_id === $run->chapter->novel_id && $plan->novel_id === $confirmation->novel_id,
            '确认身份' => $confirmation->content_hash === $snapshot['confirmation_hash'] && $plan->setting_confirmation_id === $confirmation->id,
            '大纲状态' => $plan->status === 'completed' && $plan->id === $snapshot['outline_plan_id'] && $source->chapter_number === $run->chapter->number,
            '大纲完整性' => $plan->next_chapter === $plan->target_chapters + 1 && $plan->chapters()->count() === $plan->target_chapters,
            '全书走向' => PlanningJson::hash($plan->roadmap) === PlanningJson::hash($snapshot['roadmap']) && PlanningJson::hash($plan->roadmap) === PlanningJson::hash(data_get($roadmapOrigin?->output, 'data.roadmap')),
            '真实来源' => $run->chapter->novel->generation_mode === GenerationMode::SettingReal && $settingStage->stage === Stage::Setting && $settingStage->status === StageStatus::Completed,
            '章计划归属' => data_get($origin->input, 'outline_plan_id') === $plan->id,
            '阶段归属' => $origin->novel_id === $plan->novel_id && $origin->stage === Stage::Outline && $origin->status === StageStatus::Completed,
            '大纲章节摘要' => PlanningJson::hash($recorded) === PlanningJson::hash($chapterData),
            '冻结章计划' => PlanningJson::hash($source->attributesToArray()) === PlanningJson::hash($snapshot['source_chapter']),
            '确认设定' => PlanningJson::hash($snapshot['confirmed_setting']) === PlanningJson::hash($confirmation->setting),
            '作者决定' => PlanningJson::hash($snapshot['decisions']) === PlanningJson::hash($confirmation->decisions),
        ];
        // 后续章摘要只能来自对应前序正式版本，篡改或误注入未来章节立即拒绝。
        if ($run->chapter->number > 1) {
            $checks['前文摘要与正式来源'] = PlanningJson::hash($snapshot['prior_context'] ?? []) === PlanningJson::hash(app(CanonicalChapterContext::class)->build($run->chapter->novel, $run->chapter->number));
        }
        foreach ($checks as $label => $valid) {
            if (! $valid) {
                throw new InvalidArgumentException('首章冻结来源不匹配：'.$label);
            }
        }
    }

    /** 按原协议核对历史产物，保留旧版身份边界。 */
    public function assertSources(ChapterGenerationRun $run): void
    {
        $this->assertPlanningSources($run);
        $snapshot = $run->snapshot;
        $plan = NovelOutlineChapter::findOrFail($run->outline_chapter_id)->plan;
        if ($run->parent_run_id !== null) {
            $parent = ChapterGenerationRun::query()->where('chapter_id', $run->chapter_id)->findOrFail($run->parent_run_id);
            $quality = in_array($run->config['version'] ?? null, [ContentQualityContract::VERSION, ContentReadingContract::VERSION, ContentContinuationContract::LEGACY_VERSION, ContentContinuationContract::CANONICAL_VERSION, ContentContinuationContract::RANGE_VERSION, ContentContinuationContract::BUDGET_VERSION, ContentContinuationContract::LONG_VERSION, ContentContinuationContract::SLOTS_VERSION, ContentContinuationContract::VERSION], true);
            $anchored = $quality || ($run->config['version'] ?? null) === ContentSchema::ANCHORED_VERSION;
            $source = ChapterVersion::query()->where('chapter_id', $run->chapter_id)->where('run_id', $anchored ? data_get($snapshot, 'recovery.source_owner_run_id') : $parent->id)->findOrFail($run->source_version_id);
            if ($parent->input_hash !== data_get($snapshot, 'recovery.parent_input_hash') || PlanningJson::hash([$parent->snapshot, $parent->config]) !== $parent->input_hash
                || $parent->status !== (in_array($run->config['version'] ?? null, [ContentContinuationContract::LEGACY_VERSION, ContentContinuationContract::CANONICAL_VERSION, ContentContinuationContract::RANGE_VERSION, ContentContinuationContract::BUDGET_VERSION, ContentContinuationContract::LONG_VERSION, ContentContinuationContract::SLOTS_VERSION, ContentContinuationContract::VERSION], true) ? data_get($snapshot, 'recovery.parent_status') : (($run->config['version'] ?? null) === ContentReadingContract::VERSION ? 'completed' : 'paused')) || $parent->stop_reason !== ($anchored ? data_get($snapshot, 'recovery.parent_stop_reason') : 'length_invalid') || $source->body_hash !== data_get($snapshot, 'recovery.source_body_hash') || hash('sha256', $source->body) !== $source->body_hash
                || PlanningJson::hash($parent->plan_data) !== data_get($snapshot, 'recovery.old_fine_hash')) {
                throw new InvalidArgumentException('恢复来源运行或正文已变化');
            }
            if ($anchored && (ContentSchema::count($source->body) !== $source->character_count
                || PlanningJson::hash($this->sourceLineage($parent, $source)) !== PlanningJson::hash(data_get($snapshot, 'recovery.source_lineage'))
                || PlanningJson::hash(ContentSchema::anchors($source->body)) !== PlanningJson::hash($run->config[$quality ? 'source_anchor_manifest' : 'anchor_manifest']))) {
                throw new InvalidArgumentException('冻结祖先链或非空锚点清单变化');
            }
        }
        if ($run->plan_data !== null) {
            $fine = NovelStage::query()->findOrFail($run->fine_stage_id);
            $expectedFineRun = $run->id;
            if (in_array($run->config['version'] ?? null, [ContentSchema::BOUNDED_VERSION, ContentSchema::ANCHORED_VERSION, ContentQualityContract::VERSION, ContentReadingContract::VERSION, ContentContinuationContract::LEGACY_VERSION, ContentContinuationContract::CANONICAL_VERSION, ContentContinuationContract::RANGE_VERSION, ContentContinuationContract::BUDGET_VERSION, ContentContinuationContract::LONG_VERSION, ContentContinuationContract::SLOTS_VERSION, ContentContinuationContract::VERSION], true)) {
                $expectedFineRun = data_get($snapshot, 'recovery.reused_fine_run_id');
                if ($run->fine_stage_id !== data_get($snapshot, 'recovery.reused_fine_stage_id') || $run->fine_stage_id !== $parent->fine_stage_id || PlanningJson::hash($run->plan_data) !== PlanningJson::hash($parent->plan_data)) {
                    throw new InvalidArgumentException('复用细纲来源身份不一致');
                }
            }
            if ($fine->status !== StageStatus::Completed || $fine->novel_id !== $plan->novel_id || $fine->stage !== Stage::FineOutline || data_get($fine->input, 'run_id') !== $expectedFineRun || PlanningJson::hash(data_get($fine->output, 'data.result')) !== PlanningJson::hash($run->plan_data)) {
                throw new InvalidArgumentException('冻结细纲已变化');
            }
        }
        if (in_array($run->config['version'] ?? null, [ContentSchema::BOUNDED_VERSION, ContentSchema::ANCHORED_VERSION], true)) {
            $policy = $snapshot['novel']['chapter_length_policy'];
            $limit = $run->config['version'] === ContentSchema::ANCHORED_VERSION ? 4 : 2;
            if ($run->request_limit !== $limit || $run->length_repair_count > 1 || ($limit === 4 && $run->retry_count > 1) || PlanningJson::hash($run->config['paragraph_budget']) !== PlanningJson::hash(ContentSchema::paragraphBudget($source->character_count, $policy['min_characters'], $policy['max_characters']))) {
                throw new InvalidArgumentException('段落预算或本轮请求硬上限已变化');
            }
        }
        if (in_array($run->config['version'] ?? null, [ContentQualityContract::VERSION, ContentReadingContract::VERSION], true)) {
            if ($run->request_limit !== (($run->config['version'] ?? null) === ContentReadingContract::VERSION ? 2 : 4) || $run->retry_count > 1 || $run->length_repair_count !== 0
                || PlanningJson::hash($run->config['quality_rules']) !== PlanningJson::hash(ContentQualityContract::rules($snapshot))) {
                throw new InvalidArgumentException('质量修复硬上限或冻结规则变化');
            }
        }
        if (($run->config['version'] ?? null) === ContentReadingContract::VERSION) {
            $findings = [];
            foreach (ContentReadingAudit::inspect($source->body)['issues'] as $index => $issue) {
                $findings['reading-'.($index + 1)] = $issue;
            }
            if (($parent->config['version'] ?? null) !== ContentQualityContract::VERSION || $parent->requests_used !== 2 || $parent->request_limit !== 4 || $parent->retry_count !== 0
                || $run->config['parent_requests_used'] !== 2 || $run->config['shared_request_limit'] !== 4
                || $parent->requests_used + $run->requests_used > 4 || $run->config['reading_audit_version'] !== ContentReadingAudit::VERSION
                || PlanningJson::hash($findings) !== PlanningJson::hash($run->config['reading_findings'])) {
                throw new InvalidArgumentException('全文复核清单或跨运行四次共享额度变化');
            }
        }
        $version = $this->candidate($run);
        $result = $run->chapter->progression_result;
        if ($result !== null) {
            $check = ChapterProgressionCheck::query()->where('chapter_id', $run->chapter_id)->findOrFail($result['check_id']);
            if ($version === null || $check->version_id !== $version->id || $check->body_hash !== $version->body_hash || PlanningJson::hash($check->result) !== PlanningJson::hash(collect($result)->except(['check_id', 'version_id', 'body_hash'])->all())) {
                throw new InvalidArgumentException('既有推进检查与正文身份不一致');
            }
        }
    }

    /** 校验正文属于同章祖先链，每一环输入摘要均须有效。 */
    public function sourceLineage(ChapterGenerationRun $parent, ChapterVersion $source): array
    {
        $lineage = [];
        $seen = [];
        $cursor = $parent;
        while (true) {
            if (isset($seen[$cursor->id]) || $cursor->chapter_id !== $source->chapter_id || PlanningJson::hash([$cursor->snapshot, $cursor->config]) !== $cursor->input_hash) {
                throw new InvalidArgumentException('正文祖先来源链无效');
            }
            $seen[$cursor->id] = true;
            $lineage[] = ['run_id' => $cursor->id, 'input_hash' => $cursor->input_hash];
            if ($cursor->id === $source->run_id) {
                return $lineage;
            }
            if ($cursor->parent_run_id === null) {
                throw new InvalidArgumentException('正文不属于恢复运行的祖先');
            }
            $cursor = ChapterGenerationRun::findOrFail($cursor->parent_run_id);
        }
    }

    /** 历史候选必须属于运行或其冻结来源。 */
    private function candidate(ChapterGenerationRun $run): ?ChapterVersion
    {
        $id = Chapter::query()->findOrFail($run->chapter_id)->current_candidate_version_id;
        if ($id === null) {
            return null;
        }
        $version = ChapterVersion::query()->where('chapter_id', $run->chapter_id)->findOrFail($id);
        $sourceOwner = in_array($run->config['version'] ?? null, [ContentSchema::ANCHORED_VERSION, ContentQualityContract::VERSION, ContentReadingContract::VERSION, ContentContinuationContract::LEGACY_VERSION, ContentContinuationContract::CANONICAL_VERSION, ContentContinuationContract::RANGE_VERSION, ContentContinuationContract::BUDGET_VERSION, ContentContinuationContract::LONG_VERSION, ContentContinuationContract::SLOTS_VERSION, ContentContinuationContract::VERSION], true) ? data_get($run->snapshot, 'recovery.source_owner_run_id') : $run->parent_run_id;
        if ($version->run_id !== $run->id && ! ($run->parent_run_id !== null && $version->id === $run->source_version_id && $version->run_id === $sourceOwner)) {
            throw new InvalidArgumentException('当前正文不属于本运行或授权恢复来源');
        }
        if (hash('sha256', $version->body) !== $version->body_hash) {
            throw new InvalidArgumentException('正文摘要不匹配');
        }

        return $version;
    }
}
