<?php

namespace App\Services\Pipeline;

use App\Enums\ChapterStatus;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\FirstChapterRun;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\StoryEvent;
use App\Models\StoryOutboxEvent;
use App\Models\StoryStateProjection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** 正式状态唯一写入口：同一事务保存事件、投影、章节、计数及发件箱。 */
final class CanonicalCommitService
{
    /** 作者按冻结提取编号批准实体；重复相同请求返回原回执，不触发模型。
     * @param  list<string>  $approvedEntityKeys  明确批准的提议编号
     */
    public function commit(int $versionId, int $reviewId, int $extractionId, int $validationId, int $expectedState, string $idempotencyKey, User $operator, string $reason, array $approvedEntityKeys = [], bool $acknowledged = false, ?int $automationId = null): CanonicalCommit
    {
        ContentSchema::text($reason);
        if (! Str::isUuid($idempotencyKey) || ! $acknowledged || $expectedState < 0) {
            throw new InvalidArgumentException('AUTHOR_COMMIT_CONFIRMATION_REQUIRED');
        }
        ContentSchema::listing($approvedEntityKeys);
        foreach ($approvedEntityKeys as $key) {
            ContentSchema::text($key);
        }
        sort($approvedEntityKeys, SORT_STRING);
        $requestHash = PlanningJson::hash([$versionId, $reviewId, $extractionId, $validationId, $expectedState, $operator->id, $reason, $approvedEntityKeys]);
        // 自动授权纳入幂等身份，既有人工提交的请求摘要保持兼容。
        if ($automationId !== null) {
            $requestHash = PlanningJson::hash([$requestHash, $automationId]);
        }
        $version = ChapterVersion::findOrFail($versionId);

        return DB::transaction(function () use ($version, $reviewId, $extractionId, $validationId, $expectedState, $idempotencyKey, $operator, $reason, $approvedEntityKeys, $requestHash, $automationId): CanonicalCommit {
            $novel = Novel::lockForUpdate()->findOrFail($version->chapter->novel_id);
            $delegation = null;
            if ($automationId !== null) {
                $delegation = FirstChapterRun::findOrFail($automationId);
                $delegation->assertDelegation($novel->id, $operator->id);
                if ($version->chapter->number > $delegation->chapter_limit || $expectedState !== $version->chapter->number - 1 || $idempotencyKey !== $delegation->chapterCommitKey($version->chapter->number)) {
                    throw new InvalidArgumentException('AUTOMATION_SCOPE_MISMATCH');
                }
            }
            $existing = CanonicalCommit::where('novel_id', $novel->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new InvalidArgumentException('IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            $chapter = Chapter::lockForUpdate()->findOrFail($version->chapter_id);
            $review = ChapterReview::findOrFail($reviewId);
            $extraction = ChapterEventExtraction::findOrFail($extractionId);
            $validation = ChapterSubmissionValidation::findOrFail($validationId);
            $run = ChapterSubmissionRun::lockForUpdate()->findOrFail($review->submission_run_id);
            if ($novel->state_version !== $expectedState || $run->expected_state_version !== $expectedState) {
                throw new InvalidArgumentException('STALE_STATE');
            }
            if ($chapter->formal_version_id !== null || $chapter->canonical_commit_id !== null) {
                throw new InvalidArgumentException('CHAPTER_ALREADY_COMMITTED');
            }
            if (! in_array($run->status, ['ready', 'awaiting_approval'], true) || $run->version_id !== $version->id || $run->requests_used !== 2
                || $run->config['operator_id'] !== $operator->id || $extraction->submission_run_id !== $run->id
                || $validation->submission_run_id !== $run->id || $validation->review_id !== $reviewId || $validation->extraction_id !== $extractionId
                || ! in_array($validation->verdict, ['pass', 'requires_approval'], true)
                || $validation->input_hash !== PlanningJson::hash([$run->input_hash, $review->result_hash, $extraction->result_hash, []])) {
                throw new InvalidArgumentException('SUBMISSION_NOT_READY');
            }
            $preparation = app(ChapterSubmissionService::class);
            $preparation->assertCurrent($run);
            $original = app(SubmissionValidator::class)->validate($extraction->result, $run->snapshot['entity_catalog'], $run->snapshot['state']);
            if (PlanningJson::hash($validation->result) !== PlanningJson::hash($original) || $validation->verdict !== $original['verdict']) {
                throw new InvalidArgumentException('VALIDATION_STALE');
            }
            // 批准只消除新实体门禁，所有硬错误仍必须通过确定性复验。
            $verified = $preparation->validateArtifacts($run, $extraction, $approvedEntityKeys);
            if ($verified->verdict !== 'pass') {
                throw new InvalidArgumentException($verified->verdict === 'requires_approval' ? 'UNAPPROVED_ENTITY' : 'VALIDATION_BLOCKED');
            }
            $nextState = $expectedState + 1;
            $receipt = ['novel_id' => $novel->id, 'chapter_id' => $chapter->id, 'version_id' => $version->id, 'body_hash' => $version->body_hash,
                'previous_state_version' => $expectedState, 'state_version' => $nextState, 'chapters_done' => $novel->chapters_done + 1,
                'author_story_approval' => $automationId === null, 'approval_mode' => $automationId === null ? 'manual' : 'delegated_policy', 'automation_id' => $automationId, 'event_count' => count($extraction->result['events'])];
            $commit = CanonicalCommit::create(['submission_run_id' => $run->id, 'novel_id' => $novel->id, 'chapter_id' => $chapter->id, 'version_id' => $version->id,
                'validation_id' => $verified->id, 'previous_state_version' => $expectedState, 'state_version' => $nextState,
                'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash, 'committed_by' => $operator->id, 'reason' => $reason,
                'approvals' => ['author_story_approval' => $automationId === null, 'approval_mode' => $automationId === null ? 'manual' : 'delegated_policy', 'automation_id' => $automationId, 'policy_hash' => $delegation?->policy_hash, 'approved_entity_keys' => $approvedEntityKeys, 'proposals_hash' => PlanningJson::hash($extraction->result['proposed_entities']),
                    'definitions' => $extraction->result['proposed_entities']], 'result' => $receipt, 'created_at' => now()]);
            $mapping = [];
            foreach ($run->snapshot['entity_catalog'] as $key => $entity) {
                $mapping[$key] = $entity['id'];
            }
            foreach ($extraction->result['proposed_entities'] as $proposal) {
                $definition = array_intersect_key($proposal, array_flip(['name', 'type', 'aliases', 'description']));
                $entity = StoryEntity::create(['novel_id' => $novel->id, 'type' => $proposal['type'], 'name' => $proposal['name'], 'normalized_name' => SubmissionValidator::name($proposal['name']),
                    'aliases' => $proposal['aliases'], 'definition' => $definition, 'definition_hash' => PlanningJson::hash($definition),
                    'origin' => ['kind' => 'canonical_commit', 'commit_id' => $commit->id, 'version_id' => $version->id, 'body_hash' => $version->body_hash, 'evidence' => $proposal['evidence']],
                    'approved_by' => $operator->id, 'approved_at' => now()]);
                $mapping[$proposal['key']] = $entity->id;
            }
            $eventIds = [];
            foreach ($extraction->result['events'] as $event) {
                $patches = array_values(array_filter($extraction->result['patch'], fn (array $patch): bool => $patch['source_event_key'] === $event['key']));
                $payload = $this->mapReferences(['time_scope' => $event['time_scope'], 'summary' => $event['summary'], 'target_refs' => $event['target_refs'], 'patch' => $patches], $mapping);
                $saved = StoryEvent::create(['commit_id' => $commit->id, 'novel_id' => $novel->id, 'chapter_id' => $chapter->id, 'version_id' => $version->id,
                    'actor_entity_id' => $mapping[$event['actor_ref']], 'event_key' => $event['key'], 'narrative_order' => $event['narrative_order'],
                    'event_type' => $event['event_type'], 'fact_category' => $event['fact_category'], 'payload' => $payload, 'evidence' => $event['evidence'], 'created_at' => now()]);
                $eventIds[$event['key']] = $saved->id;
            }
            $state = $this->mapReferences($verified->result['state'], $mapping, $eventIds);
            StoryStateProjection::create(['commit_id' => $commit->id, 'novel_id' => $novel->id, 'state_version' => $nextState, 'state' => $state, 'state_hash' => PlanningJson::hash($state), 'created_at' => now()]);
            $chapter->update(['status' => ChapterStatus::Committed, 'formal_version_id' => $version->id, 'canonical_commit_id' => $commit->id, 'committed_at' => now()]);
            // 计划章节总数及暂停状态不随单章正式提交改变，也不启动下一章。
            $novel->update(['chapters_done' => $novel->chapters_done + 1, 'state_version' => $nextState]);
            StoryOutboxEvent::create(['commit_id' => $commit->id, 'event_type' => 'chapter.committed', 'payload' => [...$receipt, 'commit_id' => $commit->id], 'created_at' => now()]);
            $run->update(['status' => 'committed', 'stop_reason' => 'CANONICAL_COMMITTED', 'control_history' => [...$run->control_history,
                ['action' => 'canonical_commit', 'commit_id' => $commit->id, 'operator_id' => $operator->id, 'at' => now()->toIso8601String()]]]);

            return $commit;
        }, 1);
    }

    /** 只转换结构化实体引用；原文摘要及证据不做文本替换。
     * @return array<string|int, mixed>
     */
    private function mapReferences(array $data, array $entities, array $events = []): array
    {
        $mapped = [];
        foreach ($data as $key => $value) {
            $newKey = is_string($key) && isset($entities[$key]) ? 'entity-'.$entities[$key] : $key;
            if (is_array($value)) {
                $value = $key === 'target_refs' ? array_map(fn (string $ref): string => 'entity-'.$entities[$ref], $value) : $this->mapReferences($value, $entities, $events);
            } elseif (in_array($key, ['entity_ref', 'value_ref', 'before_ref'], true) && $value !== '') {
                $value = 'entity-'.$entities[$value];
            }
            $mapped[$newKey] = $value;
        }
        if (isset($data['source_event_key']) && ! isset($data['source_event_id']) && isset($events[$data['source_event_key']])) {
            $mapped['source_event_id'] = $events[$data['source_event_key']];
        }

        return $mapped;
    }
}
