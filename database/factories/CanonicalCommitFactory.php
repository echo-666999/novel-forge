<?php

namespace Database\Factories;

use App\Models\CanonicalCommit;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<CanonicalCommit>
 */
class CanonicalCommitFactory extends Factory
{
    /** 关联工厂仅提供约束测试数据，不替代真实服务验收。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['validation_id' => ChapterSubmissionValidation::factory(),
            'submission_run_id' => fn (array $a): int => ChapterSubmissionValidation::findOrFail($a['validation_id'])->submission_run_id,
            'novel_id' => fn (array $a): int => ChapterSubmissionRun::findOrFail($a['submission_run_id'])->novel_id,
            'chapter_id' => fn (array $a): int => ChapterSubmissionRun::findOrFail($a['submission_run_id'])->chapter_id,
            'version_id' => fn (array $a): int => ChapterSubmissionRun::findOrFail($a['submission_run_id'])->version_id,
            'previous_state_version' => 0, 'state_version' => 1, 'idempotency_key' => (string) Str::uuid7(),
            'request_hash' => str_repeat('0', 64), 'committed_by' => User::factory(), 'reason' => '隔离数据库约束测试',
            'approvals' => ['fixture' => true], 'result' => ['fixture' => true], 'created_at' => now()];
    }
}
