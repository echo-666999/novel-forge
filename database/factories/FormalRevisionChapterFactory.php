<?php

namespace Database\Factories;

use App\Models\CanonicalCommit;
use App\Models\FormalRevision;
use App\Models\FormalRevisionChapter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 正式稿修订逐章记录测试数据。
 * @extends Factory<FormalRevisionChapter>
 */
class FormalRevisionChapterFactory extends Factory
{
    /** 默认待生成建议。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['formal_revision_id' => FormalRevision::factory(), 'source_commit_id' => CanonicalCommit::factory(),
            'chapter_number' => fn (array $a): int => CanonicalCommit::findOrFail($a['source_commit_id'])->chapter_number,
            'source_version_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['source_commit_id'])->version_id,
            'signals' => [], 'suggestions' => null, 'decisions' => null, 'candidate_version_id' => null, 'equivalence' => null,
            'status' => 'pending', 'error' => null, 'cost_log_ids' => []];
    }
}
