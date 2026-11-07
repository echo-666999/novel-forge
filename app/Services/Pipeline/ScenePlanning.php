<?php

namespace App\Services\Pipeline;

use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\ScenePlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** 负责场景版本、覆盖和字数校验；作者计划不是正式剧情事实。 */
final class ScenePlanning
{
    /** 保存完整场景列表；拆分合并由作者提供新目标和衔接，不自动复制文字凑数。 */
    public function save(int $novelId, int $outlineId, User $actor, array $scenes, bool $freeze, string $reason, ?int $expectedId): ScenePlanVersion
    {
        abort_unless($actor->exists, 403);

        return DB::transaction(function () use ($novelId, $outlineId, $actor, $scenes, $freeze, $reason, $expectedId): ScenePlanVersion {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $outline = NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $novelId))->findOrFail($outlineId);
            $latest = ScenePlanVersion::where('outline_chapter_id', $outlineId)->latest('id')->first();
            if ($latest?->id !== $expectedId) {
                throw ValidationException::withMessages(['expected_id' => '已有新场景版本，请刷新后重试。']);
            }
            // 已开始的章节不得通过本入口改变冻结规划；需要另建显式恢复或历史修订流程。
            if (Chapter::where('novel_id', $novelId)->where('number', $outline->chapter_number)->exists()) {
                throw ValidationException::withMessages(['scenes' => '此章已经开始生成，不能从场景规划入口改动旧运行。']);
            }
            Validator::make(compact('reason', 'scenes'), ['reason' => 'required|string|max:2000', 'scenes' => 'required|array|min:1|max:20',
                'scenes.*.title' => 'required|string|max:200', 'scenes.*.goal' => 'required|string|max:2000',
                'scenes.*.pov' => 'required|string|max:200', 'scenes.*.time' => 'required|string|max:500', 'scenes.*.place' => 'required|string|max:500',
                'scenes.*.participants' => 'required|string|max:1000', 'scenes.*.conflict' => 'required|string|max:2000',
                'scenes.*.change' => 'required|string|max:2000', 'scenes.*.transition' => 'required|string|max:2000',
                'scenes.*.prohibited' => 'required|string|max:2000', 'scenes.*.point_ids' => 'required|array|min:1',
                'scenes.*.point_ids.*' => 'required|string', 'scenes.*.words' => 'required|integer|min:1|max:100000'])->validate();
            $scenes = array_values(array_map(fn (array $s): array => [...array_intersect_key($s, array_flip(['title', 'goal', 'pov', 'time', 'place', 'participants', 'conflict', 'change', 'transition', 'prohibited', 'point_ids'])), 'point_ids' => array_values(array_unique($s['point_ids'])), 'words' => (int) $s['words']], $scenes));
            $issues = $this->issues($novel, $outline, $scenes);
            if ($freeze && $issues !== []) {
                throw ValidationException::withMessages(['scenes' => implode('；', $issues)]);
            }

            return ScenePlanVersion::create(['novel_id' => $novelId, 'outline_chapter_id' => $outlineId, 'parent_id' => $expectedId, 'status' => $freeze ? 'frozen' : 'draft',
                'scenes' => $scenes, 'source_hash' => $this->sourceHash($novel, $outline), 'content_hash' => PlanningJson::hash($scenes), 'reason' => $reason, 'actor_id' => $actor->id, 'created_at' => now()]);
        });
    }

    /** 缺失推进点、未知引用、重复目标与章字数不匹配分别报告。 */
    public function issues(Novel $novel, NovelOutlineChapter $outline, array $scenes): array
    {
        $issues = [];
        $required = array_column($outline->progression_points, 'id');
        $covered = array_merge(...array_column($scenes, 'point_ids'));
        if (array_diff($required, $covered) !== []) {
            $issues[] = '未覆盖全部章节推进点';
        }
        if (array_diff($covered, $required) !== []) {
            $issues[] = '存在不属于本章的推进点';
        }
        $goals = array_map(fn ($s) => SubmissionValidator::name($s['goal']), $scenes);
        if (count(array_unique($goals)) !== count($goals)) {
            $issues[] = '场景目标重复，不能复制场景凑字';
        }
        $words = array_sum(array_column($scenes, 'words'));
        if ($words < $novel->chapter_length_policy['min_characters'] || $words > $novel->chapter_length_policy['max_characters']) {
            $issues[] = '场景目标字数合计不符合章字数政策';
        }

        return $issues;
    }

    /** 新运行读取最后冻结版本，旧运行直接使用已有快照。 */
    public function context(Novel $novel, NovelOutlineChapter $outline): ?array
    {
        $version = ScenePlanVersion::where('novel_id', $novel->id)->where('outline_chapter_id', $outline->id)->where('status', 'frozen')->latest('id')->first();
        if ($version === null) {
            return null;
        }
        if ($version->source_hash !== $this->sourceHash($novel, $outline) || $version->content_hash !== PlanningJson::hash($version->scenes) || $this->issues($novel, $outline, $version->scenes) !== []) {
            throw ValidationException::withMessages(['scenes' => '冻结场景来源或政策已变化，必须重新核对。']);
        }

        return ['version_id' => $version->id, 'content_hash' => $version->content_hash, 'source_hash' => $version->source_hash,
            'visibility' => '作者规划，不代表角色已知或已经发生；细纲与正文应逐项承接场景目标、推进点和禁止变更。', 'scenes' => $version->scenes];
    }

    /** 源规划或字数政策变化使旧冻结版本失效。 */
    private function sourceHash(Novel $novel, NovelOutlineChapter $outline): string
    {
        return PlanningJson::hash([$outline->attributesToArray(), $novel->chapter_length_policy]);
    }
}
