<?php

namespace Database\Factories;

use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 关系约束夹具，不冒充真实业务流转验收。
 * @extends Factory<ForeshadowEvidence>
 */
class ForeshadowEvidenceFactory extends Factory
{
    /** 从同一提交派生小说、章节与版本，避免跨书证据。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['commit_id' => CanonicalCommit::factory(),
            'novel_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->novel_id,
            'baseline_id' => fn (array $a): ?int => CanonicalCommit::findOrFail($a['commit_id'])->baseline_id,
            'chapter_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->chapter_id,
            'version_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->version_id,
            'chapter_number' => fn (array $a): int => Chapter::findOrFail($a['chapter_id'])->number,
            'foreshadow_id' => fn (array $a): int => Foreshadow::factory()->create(['novel_id' => $a['novel_id']])->id,
            'body_hash' => fn (array $a): string => ChapterVersion::findOrFail($a['version_id'])->body_hash,
            'revision' => 1, 'from_status' => 'planned', 'to_status' => 'planted', 'evidence' => [], 'reason' => '隔离关系约束', 'recorded_by' => User::factory(),
            'request_key' => (string) Str::uuid7(), 'request_hash' => PlanningJson::hash(['fixture' => true]), 'created_at' => now()];
    }
}
