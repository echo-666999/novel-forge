<?php

namespace App\Services\Pipeline;

use App\Models\Novel;
use App\Models\StoryStructureVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** 卷弧范围校验和冻结服务；历史正式章位不能通过移动节点改写。 */
final class StoryStructure
{
    /** 保存新版本，冻结要求连续覆盖全书且每卷的弧连续覆盖该卷。
     * @param  list<array<string,mixed>>  $volumes
     */
    public function save(int $novelId, User $actor, array $volumes, bool $freeze, string $reason, ?int $expectedId): StoryStructureVersion
    {
        abort_unless($actor->exists, 403);

        return DB::transaction(function () use ($novelId, $actor, $volumes, $freeze, $reason, $expectedId): StoryStructureVersion {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $latest = StoryStructureVersion::where('novel_id', $novelId)->latest('id')->first();
            if ($latest?->id !== $expectedId) {
                throw ValidationException::withMessages(['version' => '卷弧规划已有新版本，请刷新后编辑。']);
            }
            $rules = ['volumes' => 'required|array|min:1|max:100', 'reason' => 'required|string|max:2000'];
            foreach (['volumes.*', 'volumes.*.arcs.*'] as $prefix) {
                foreach (['title', 'goal', 'turning_point', 'climax', 'ending_state'] as $field) {
                    $rules[$prefix.'.'.$field] = 'required|string|max:2000';
                }
                $rules[$prefix.'.start'] = 'required|integer|min:1|max:'.$novel->target_chapters;
                $rules[$prefix.'.end'] = 'required|integer|min:1|max:'.$novel->target_chapters;
            }
            $rules['volumes.*.arcs'] = 'required|array|min:1|max:100';
            Validator::make(['volumes' => $volumes, 'reason' => $reason], $rules)->validate();
            // 按作者顺序保存列表，并剔除未声明字段；JSONB对象排序不能改变卷弧顺序。
            $volumes = array_values(array_map(function (array $volume): array {
                $node = $this->node($volume);
                $node['arcs'] = array_values(array_map(fn (array $arc): array => $this->node($arc), $volume['arcs']));

                return $node;
            }, $volumes));
            $this->ranges($volumes, 1, $novel->target_chapters, $freeze);
            foreach ($volumes as $volume) {
                $this->ranges($volume['arcs'], (int) $volume['start'], (int) $volume['end'], $freeze);
            }
            if ($freeze && $novel->chapters_done > 0) {
                $previous = StoryStructureVersion::where('novel_id', $novelId)->where('status', 'frozen')->latest('id')->first();
                if ($previous === null || $this->past($previous->structure, $novel->chapters_done) !== $this->past($volumes, $novel->chapters_done)) {
                    throw ValidationException::withMessages(['volumes' => '涉及已提交章位的规划变化，须走历史变更影响流程，不能在此直接冻结。']);
                }
            }

            return StoryStructureVersion::create(['novel_id' => $novelId, 'parent_id' => $latest?->id, 'status' => $freeze ? 'frozen' : 'draft',
                'structure' => $volumes, 'content_hash' => PlanningJson::hash($volumes), 'reason' => $reason, 'actor_id' => $actor->id, 'created_at' => now()]);
        });
    }

    /** 新运行冻结当前章所在卷弧；没有作者规划时保持旧行为。 */
    public function context(Novel $novel, int $number): ?array
    {
        $version = StoryStructureVersion::where('novel_id', $novel->id)->where('status', 'frozen')->latest('id')->first();
        if ($version === null) {
            return null;
        }
        if (! hash_equals($version->content_hash, PlanningJson::hash($version->structure))) {
            throw ValidationException::withMessages(['structure' => '冻结卷弧摘要损坏，拒绝生成。']);
        }
        foreach ($version->structure as $volume) {
            foreach ($volume['arcs'] as $arc) {
                if ($number >= $arc['start'] && $number <= $arc['end']) {
                    $volumeMetadata = $volume;
                    unset($volumeMetadata['arcs']);

                    return ['version_id' => $version->id, 'content_hash' => $version->content_hash,
                        'visibility' => '作者未来规划，不代表角色已知或已经发生的事实', 'volume' => $volumeMetadata, 'arc' => $arc];
                }
            }
        }
        throw ValidationException::withMessages(['structure' => '冻结卷弧未覆盖当前章节。']);
    }

    /** 冻结结构只接受白名单字段，章号统一转成整数。 */
    private function node(array $value): array
    {
        return ['title' => $value['title'], 'start' => (int) $value['start'], 'end' => (int) $value['end'],
            'goal' => $value['goal'], 'turning_point' => $value['turning_point'], 'climax' => $value['climax'], 'ending_state' => $value['ending_state']];
    }

    /** 无重叠且顺序递增；冻结时禁止缺章。 */
    private function ranges(array $nodes, int $start, int $end, bool $complete): void
    {
        $next = $start;
        foreach ($nodes as $node) {
            if ($node['start'] < $next || $node['end'] < $node['start'] || $node['end'] > $end || ($complete && $node['start'] != $next)) {
                throw ValidationException::withMessages(['volumes' => '卷弧范围重叠、越界或冻结范围存在缺章。']);
            }
            $next = (int) $node['end'] + 1;
        }
        if ($complete && $next !== $end + 1) {
            throw ValidationException::withMessages(['volumes' => '冻结规划必须连续覆盖目标范围。']);
        }
    }

    /** 对已提交章位涉及的卷与弧完整比较，不能借修改父卷绕过历史边界。 */
    private function past(array $volumes, int $through): string
    {
        $past = [];
        foreach ($volumes as $volume) {
            if ((int) $volume['start'] > $through) {
                continue;
            }
            $volume['arcs'] = array_values(array_filter($volume['arcs'], fn (array $arc): bool => (int) $arc['start'] <= $through));
            $past[] = $volume;
        }

        return PlanningJson::hash($past);
    }
}
