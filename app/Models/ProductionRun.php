<?php

namespace App\Models;

use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Ramsey\Uuid\Uuid;

/** 章节范围生产运行：一次授权覆盖连续章号，保存断点及恢复记录；同一本书可先后有多次授权。 */
#[Fillable(['novel_id', 'author_id', 'start_chapter', 'predecessor_id', 'chapter_limit', 'policy', 'policy_hash', 'commit_key', 'status', 'step', 'artifacts', 'history', 'error', 'lease_token', 'lease_expires_at'])]
class ProductionRun extends Model
{
    /** 续写授权单次最多章数，控制一次授权的费用风险。 */
    public const MAX_RANGE = 10;

    /** 隔离测试使用工厂，不自行调用供应商。 */
    use HasFactory;

    /** 未刷新记录前也保持旧首章授权默认值。 */
    protected $attributes = ['start_chapter' => 1, 'chapter_limit' => 1];

    /** 冻结授权与归属；运行历史不可通过模型删除。 */
    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->isDirty(['novel_id', 'author_id', 'start_chapter', 'predecessor_id', 'chapter_limit', 'policy', 'policy_hash', 'commit_key'])) {
                throw new LogicException('生产运行授权不可覆盖');
            }
        });
        static::deleting(fn () => throw new LogicException('生产运行授权历史不可删除'));
    }

    /** JSON、编号和领取时间保持明确类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['start_chapter' => 'integer', 'predecessor_id' => 'integer', 'chapter_limit' => 'integer', 'novel_id' => 'integer', 'author_id' => 'integer', 'policy' => 'array', 'artifacts' => 'array', 'history' => 'array', 'lease_expires_at' => 'immutable_datetime'];
    }

    /** 校验委托范围和不可变摘要，提交时再次调用。旧授权仅限第1章起的一章或十章；续写授权冻结起止章与模型。 */
    public function assertDelegation(int $novelId, int $authorId): void
    {
        $expected = ($this->policy['version'] ?? null) === 'o2-v1'
            ? self::rangePolicy($this->start_chapter, $this->chapter_limit, $this->policy['model'] ?? null)
            : self::policy($this->chapter_limit === 10 ? 3 : (($this->policy['version'] ?? null) === 'b10-v1' ? 1 : 2));
        $legacyScope = $this->start_chapter === 1 && in_array($this->chapter_limit, [1, 10], true);
        $rangeScope = $this->start_chapter >= 1 && $this->chapter_limit >= $this->start_chapter && $this->chapter_limit - $this->start_chapter < self::MAX_RANGE;
        if (! (($this->policy['version'] ?? null) === 'o2-v1' ? $rangeScope : $legacyScope) || $this->novel_id !== $novelId || $this->author_id !== $authorId
            || PlanningJson::hash($this->policy) !== PlanningJson::hash($expected) || ! hash_equals($this->policy_hash, PlanningJson::hash($this->policy))) {
            throw new LogicException('自动授权来源不匹配');
        }
    }

    /** 本书最新一次生产运行。 */
    public static function latestFor(int $novelId): ?self
    {
        return self::where('novel_id', $novelId)->latest('id')->first();
    }

    /** 本书尚未完成的生产运行；同一时间最多一条。 */
    public static function activeFor(int $novelId): ?self
    {
        return self::where('novel_id', $novelId)->where('status', '!=', 'completed')->latest('id')->first();
    }

    /** 授权范围包含该章的生产运行；activeOnly 时只看未完成的运行。手动生成的章节不被任何运行覆盖。 */
    public static function coveringChapter(int $novelId, int $number, bool $activeOnly = false): ?self
    {
        return self::where('novel_id', $novelId)->where('start_chapter', '<=', $number)->where('chapter_limit', '>=', $number)
            ->when($activeOnly, fn ($query) => $query->where('status', '!=', 'completed'))->latest('id')->first();
    }

    /** 本运行冻结的模型；未冻结时由小说生成配置决定。 */
    public function frozenModel(): ?string
    {
        return $this->policy['model'] ?? null;
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

    /** 每章使用独立且可重放的提交键，首章保持旧键兼容；只允许授权范围内的章号。 */
    public function chapterCommitKey(int $number): string
    {
        if ($number < $this->start_chapter || $number > $this->chapter_limit) {
            throw new LogicException('章节超出自动授权范围');
        }

        return $number === 1 ? $this->commit_key : (string) Uuid::uuid5($this->commit_key, 'chapter-'.$number);
    }

    /** 续写授权：冻结起止章及可选模型，其余授权项与十章授权一致。
     * @return array<string, mixed>
     */
    public static function rangePolicy(int $start, int $end, ?string $model = null): array
    {
        return [...self::policy(2), 'version' => 'o2-v1', 'chapter' => $end, 'start' => $start, 'end' => $end, 'model' => $model];
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
