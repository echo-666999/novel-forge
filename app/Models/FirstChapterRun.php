<?php

namespace App\Models;

use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Ramsey\Uuid\Uuid;

/** 每本新小说唯一的自动授权、断点及恢复记录。 */
#[Fillable(['novel_id', 'author_id', 'chapter_limit', 'policy', 'policy_hash', 'commit_key', 'status', 'step', 'artifacts', 'history', 'error', 'lease_token', 'lease_expires_at'])]
class FirstChapterRun extends Model
{
    /** 隔离测试使用工厂，不自行调用供应商。 */
    use HasFactory;

    /** 未刷新记录前也保持旧首章授权默认值。 */
    protected $attributes = ['chapter_limit' => 1];

    /** 冻结授权与归属；运行历史不可通过模型删除。 */
    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->isDirty(['novel_id', 'author_id', 'chapter_limit', 'policy', 'policy_hash', 'commit_key'])) {
                throw new LogicException('首章自动授权不可覆盖');
            }
        });
        static::deleting(fn () => throw new LogicException('首章自动授权历史不可删除'));
    }

    /** JSON、编号和领取时间保持明确类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['chapter_limit' => 'integer', 'novel_id' => 'integer', 'author_id' => 'integer', 'policy' => 'array', 'artifacts' => 'array', 'history' => 'array', 'lease_expires_at' => 'immutable_datetime'];
    }

    /** 校验委托范围和不可变摘要，提交时再次调用。 */
    public function assertDelegation(int $novelId, int $authorId): void
    {
        if (! in_array($this->chapter_limit, [1, 10], true) || $this->novel_id !== $novelId || $this->author_id !== $authorId || PlanningJson::hash($this->policy) !== PlanningJson::hash(self::policy($this->chapter_limit === 10 ? 3 : (($this->policy['version'] ?? null) === 'b10-v1' ? 1 : 2)))
            || ! hash_equals($this->policy_hash, PlanningJson::hash($this->policy))) {
            throw new LogicException('自动授权来源不匹配');
        }
    }

    /** 仅返回操作者已明确承担费用风险的历史请求，后续未知请求不能自动继承确认。
     * @return list<int>
     */
    public function acknowledgedUnknownLogIds(): array
    {
        return collect($this->history)->filter(fn (array $entry): bool => ($entry['action'] ?? null) === 'retry'
            && ($entry['confirm_unknown'] ?? false) === true && ($entry['operator_id'] ?? null) === $this->author_id)
            ->flatMap(fn (array $entry): array => $entry['confirmed_unknown_log_ids'] ?? [])
            ->filter(fn ($id): bool => is_int($id) && $id > 0)->unique()->values()->all();
    }

    /** 每章使用独立且可重放的提交键，首章保持旧键兼容。 */
    public function chapterCommitKey(int $number): string
    {
        if ($number < 1 || $number > $this->chapter_limit) {
            throw new LogicException('章节超出自动授权范围');
        }

        return $number === 1 ? $this->commit_key : (string) Uuid::uuid5($this->commit_key, 'chapter-'.$number);
    }

    /** 冻结新小说一章或十章范围；不豁免推进检查、事实校验或来源校验。
     * @return array<string, mixed>
     */
    public static function policy(int $version = 2): array
    {
        if ($version === 3) {
            return [...self::policy(2), 'version' => 'c01-v1', 'chapter' => 10];
        }
        $policy = ['version' => $version === 1 ? 'b10-v1' : 'b10-v2', 'chapter' => 1, 'setting_decisions' => true, 'adopt_proposed_entities' => true,
            'canonical_commit' => true, 'hard_gates_required' => true, 'human_reading' => false];

        // 旧授权保持原范围；新授权允许每种明确失败反馈自动恢复一次。
        return $version === 1 ? $policy : [...$policy, 'automatic_recovery' => 'distinct_failure_once'];
    }
}
