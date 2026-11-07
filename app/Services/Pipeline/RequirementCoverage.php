<?php

namespace App\Services\Pipeline;

use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\RequirementVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 覆盖矩阵只接受作者决定及可定位证据，不自动豁免硬约束。 */
final class RequirementCoverage
{
    /** 并发编辑必须携带所见版本，拒绝静默覆盖他人较新决定。
     * @param  array<string,mixed>  $input
     */
    public function save(int $novelId, User $actor, array $input, ?int $expectedId = null): RequirementVersion
    {
        abort_unless($actor->exists, 403);
        $data = Validator::make($input, [
            'source_quote' => 'required|string|max:10000', 'description' => 'required|string|max:10000',
            'category' => 'required|string|max:100', 'strength' => 'required|in:hard,soft',
            'status' => 'required|in:unresolved,covered,conflict,waived', 'reason' => 'required|string|max:2000',
            'outline_chapter_id' => 'nullable|integer|min:1', 'evidence_quote' => 'nullable|string|max:10000',
            'acknowledged' => 'accepted',
        ])->validate();

        return DB::transaction(function () use ($novelId, $actor, $data, $expectedId): RequirementVersion {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $old = $expectedId === null ? null : RequirementVersion::where('novel_id', $novelId)->findOrFail($expectedId);
            if ($old !== null && RequirementVersion::where('novel_id', $novelId)->where('requirement_key', $old->requirement_key)->where('id', '>', $old->id)->exists()) {
                throw ValidationException::withMessages(['version' => '要求已有更新版本，请刷新后再决定。']);
            }
            if (trim($data['source_quote']) === '' || ! str_contains($novel->premise_raw, $data['source_quote'])) {
                throw ValidationException::withMessages(['source_quote' => '原句必须逐字来自当前小说原始创意。']);
            }
            foreach (['description', 'category', 'reason'] as $field) {
                if (trim($data[$field]) === '') {
                    throw ValidationException::withMessages([$field => '此字段不能只包含空白。']);
                }
            }
            $plan = null;
            if ($data['status'] === 'covered') {
                $plan = NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $novelId))->find($data['outline_chapter_id'] ?? 0);
                if ($plan === null || trim($data['evidence_quote'] ?? '') === '' || ! str_contains($this->planText($plan), $data['evidence_quote'])) {
                    throw ValidationException::withMessages(['evidence_quote' => '已覆盖必须引用本书章节计划中的真实原句。']);
                }
            }

            return RequirementVersion::create([
                'novel_id' => $novelId, 'requirement_key' => $old?->requirement_key ?? (string) Str::uuid7(), 'revision' => ($old?->revision ?? 0) + 1,
                'source_quote' => $data['source_quote'], 'source_hash' => hash('sha256', $novel->premise_raw),
                'description' => $data['description'], 'category' => $data['category'], 'strength' => $data['strength'], 'status' => $data['status'],
                'outline_chapter_id' => $plan?->id, 'evidence_quote' => $plan ? $data['evidence_quote'] : null,
                'evidence_hash' => $plan ? PlanningJson::hash($plan->toArray()) : null,
                'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now(),
            ]);
        });
    }

    /** 以可读计划字段匹配引用，不允许仅凭计划编号宣称覆盖。 */
    private function planText(NovelOutlineChapter $plan): string
    {
        return implode("\n", [$plan->title, $plan->summary, $plan->objective, $plan->conflict, $plan->hook, PlanningJson::encode($plan->progression_points)]);
    }
}
