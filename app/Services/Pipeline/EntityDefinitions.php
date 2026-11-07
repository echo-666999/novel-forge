<?php

namespace App\Services\Pipeline;

use App\Models\EntityDefinitionVersion;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** 人物、世界实体及初始关系的作者版本服务；动态事实只读。 */
final class EntityDefinitions
{
    /** 新增或修订候选，不隐式批准，也不改变旧定义。 */
    public function propose(int $novelId, User $actor, array $definition, string $reason, ?int $entityId = null, ?int $parentId = null): EntityDefinitionVersion
    {
        abort_unless($actor->exists, 403);

        return DB::transaction(function () use ($novelId, $actor, $definition, $reason, $entityId, $parentId): EntityDefinitionVersion {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $entity = $entityId === null ? null : StoryEntity::where('novel_id', $novelId)->findOrFail($entityId);
            if ($parentId !== null) {
                $parent = EntityDefinitionVersion::where('novel_id', $novelId)->findOrFail($parentId);
                if ($parent->entity_id !== $entityId || EntityDefinitionVersion::where('parent_id', $parentId)->exists()) {
                    throw ValidationException::withMessages(['definition' => '来源版本已有后继或实体不一致，请刷新。']);
                }
            }
            $definition = $this->validate($novelId, $definition, $reason, $entity);

            return EntityDefinitionVersion::create(['novel_id' => $novelId, 'entity_id' => $entityId, 'parent_id' => $parentId, 'status' => 'candidate', 'definition' => $definition,
                'content_hash' => PlanningJson::hash($definition), 'effective_from' => $novel->chapters_done + 1, 'reason' => $reason, 'actor_id' => $actor->id, 'created_at' => now()]);
        });
    }

    /** 明确批准候选；既有实体身份不改名，别名合并也必须经过冲突检查。 */
    public function approve(int $novelId, int $id, User $actor, string $reason, bool $acknowledged): EntityDefinitionVersion
    {
        abort_unless($actor->exists, 403);
        if (! $acknowledged) {
            throw ValidationException::withMessages(['acknowledged' => '必须明确批准此设定版本。']);
        }

        return DB::transaction(function () use ($novelId, $id, $actor, $reason): EntityDefinitionVersion {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            // 有未提交章节时保持其冻结实体目录，不能让已有准备运行失效且无法恢复。
            if ($novel->chapters()->whereNull('canonical_commit_id')->exists()) {
                throw ValidationException::withMessages(['version' => '存在未提交章节，先处理其运行；设定候选保留，不能中途更换实体目录。']);
            }
            $version = EntityDefinitionVersion::where('novel_id', $novelId)->findOrFail($id);
            if (($version->entity_id !== null && EntityDefinitionVersion::where('entity_id', $version->entity_id)->where('status', 'approved')->where('id', '>', $id)->exists()) || $version->status !== 'candidate' || EntityDefinitionVersion::where('parent_id', $id)->exists() || $version->content_hash !== PlanningJson::hash($version->definition)) {
                throw ValidationException::withMessages(['version' => '候选已处理或摘要不一致。']);
            }
            $entity = $version->entity_id === null ? null : StoryEntity::where('novel_id', $novelId)->findOrFail($version->entity_id);
            $definition = $this->validate($novelId, $version->definition, $reason, $entity);
            $names = array_map(SubmissionValidator::name(...), [$definition['name'], ...$definition['aliases']]);
            foreach (StoryEntity::where('novel_id', $novelId)->get() as $other) {
                if ($other->id === $entity?->id) {
                    continue;
                }
                $otherDefinition = $this->effective($other, $novel->chapters_done + 1);
                if (array_intersect($names, array_map(SubmissionValidator::name(...), [$other->name, ...$otherDefinition['aliases']])) !== []) {
                    throw ValidationException::withMessages(['definition' => '名称或别名与另一实体冲突，不自动合并身份。']);
                }
            }
            if ($entity === null) {
                $base = array_intersect_key($definition, array_flip(['name', 'type', 'aliases', 'description']));
                $entity = StoryEntity::create(['novel_id' => $novelId, 'type' => $definition['type'], 'name' => $definition['name'], 'normalized_name' => SubmissionValidator::name($definition['name']),
                    'aliases' => $definition['aliases'], 'definition' => $base, 'definition_hash' => PlanningJson::hash($base),
                    'origin' => ['kind' => 'author_definition', 'candidate_id' => $version->id, 'effective_from' => $novel->chapters_done + 1], 'approved_by' => $actor->id, 'approved_at' => now()]);
            }

            return EntityDefinitionVersion::create(['novel_id' => $novelId, 'entity_id' => $entity->id, 'parent_id' => $id, 'status' => 'approved', 'definition' => $definition,
                'content_hash' => PlanningJson::hash($definition), 'effective_from' => $novel->chapters_done + 1, 'reason' => $reason, 'actor_id' => $actor->id, 'created_at' => now()]);
        });
    }

    /** 当前章可用定义只用于新运行；已有调用继续使用冻结内容。 */
    public function effective(StoryEntity $entity, int $number): array
    {
        $version = EntityDefinitionVersion::where('novel_id', $entity->novel_id)->where('entity_id', $entity->id)->where('status', 'approved')->where('effective_from', '<=', $number)->latest('id')->first();
        if ($version === null) {
            return $entity->definition;
        }
        if ($version->content_hash !== PlanningJson::hash($version->definition)) {
            throw ValidationException::withMessages(['definition' => '作者实体版本摘要损坏。']);
        }

        return $version->definition;
    }

    /** 冻结作者扩展设定；关系是初始设定或未来约束，不作为已发生事件。 */
    public function context(int $novelId, int $number): array
    {
        return EntityDefinitionVersion::where('novel_id', $novelId)->where('status', 'approved')->where('effective_from', '<=', $number)->orderBy('id')->get()->groupBy('entity_id')->map(function ($versions): array {
            $version = $versions->last();
            if ($version->content_hash !== PlanningJson::hash($version->definition)) {
                throw ValidationException::withMessages(['definition' => '作者实体版本摘要损坏。']);
            }

            return ['entity_id' => $version->entity_id, 'version_id' => $version->id, 'effective_from' => $version->effective_from, 'content_hash' => $version->content_hash,
                'visibility' => '作者设定及未来约束；不作为已发生的关系变化、角色知情或正式状态。', 'definition' => $version->definition];
        })->values()->all();
    }

    /** 固定字段，关系必须指向同书已批准实体，不能破坏历史稳定身份。 */
    private function validate(int $novelId, array $definition, string $reason, ?StoryEntity $entity): array
    {
        // 后台空文本统一为空串，避免把未填写的可选特征误判为内容。
        foreach (['traits', 'rules'] as $field) {
            $definition[$field] = $definition[$field] ?? '';
        }
        Validator::make(['definition' => $definition, 'reason' => $reason], ['reason' => 'required|string|max:2000',
            'definition.name' => 'required|string|max:200', 'definition.type' => 'required|in:'.implode(',', [...SubmissionContract::ENTITY_TYPES, 'rule', 'ability']),
            'definition.aliases' => 'present|array|max:20', 'definition.aliases.*' => 'required|string|max:200', 'definition.description' => 'required|string|max:10000',
            'definition.traits' => 'present|string|max:10000', 'definition.rules' => 'present|string|max:10000', 'definition.scope' => 'required|string|max:2000',
            'definition.relationships' => 'present|array|max:50', 'definition.relationships.*.target_id' => 'required|integer', 'definition.relationships.*.description' => 'required|string|max:2000'])->validate();
        if ($entity !== null && ($entity->name !== $definition['name'] || $entity->type !== $definition['type'])) {
            throw ValidationException::withMessages(['definition' => '已批准实体的名称及类型为稳定身份；请使用别名候选，不可直接改名。']);
        }
        $definition = array_intersect_key($definition, array_flip(['name', 'type', 'aliases', 'description', 'traits', 'rules', 'scope', 'relationships']));
        $definition['aliases'] = array_values(array_unique($definition['aliases']));
        $definition['relationships'] = array_values(array_map(function (array $relationship) use ($novelId, $entity): array {
            $target = StoryEntity::where('novel_id', $novelId)->findOrFail($relationship['target_id']);
            if ($target->id === $entity?->id) {
                throw ValidationException::withMessages(['relationships' => '关系目标不能是自身。']);
            }

            return ['target_id' => $target->id, 'description' => $relationship['description']];
        }, $definition['relationships']));

        return $definition;
    }
}
