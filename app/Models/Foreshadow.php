<?php

namespace App\Models;

use Database\Factories\ForeshadowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** 伏笔计划不可覆盖，当前状态仅随追加证据演进。 */
#[Fillable(['novel_id', 'title', 'description', 'plant_chapter', 'target_chapter', 'status', 'revision', 'request_key', 'request_hash', 'created_by', 'surface_clue', 'hidden_meaning', 'payoff_effect', 'knowledge_constraint', 'payoff_from', 'payoff_to', 'must_payoff', 'planned_before_generation'])]
class Foreshadow extends Model
{
    /** 隔离测试工厂。
     * @use HasFactory<ForeshadowFactory>
     */
    use HasFactory;

    /** 阻止篡改计划或删除账本。 */
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['status', 'revision', 'updated_at']) !== []) {
                throw new LogicException('伏笔计划不可覆盖');
            }
        });
        static::deleting(fn () => throw new LogicException('伏笔账本不可删除'));
    }

    /** 数值类型参与严格边界和并发校验。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'plant_chapter' => 'integer', 'target_chapter' => 'integer', 'revision' => 'integer', 'payoff_from' => 'integer', 'payoff_to' => 'integer', 'must_payoff' => 'boolean', 'planned_before_generation' => 'boolean'];
    }

    /** 证据链按修订序号追加，不覆盖旧结论。
     * @return HasMany<ForeshadowEvidence, $this>
     */
    public function evidence(): HasMany
    {
        // 只取小说活动基线上的证据链；被替换基线的证据保留但不参与当前账本。
        return $this->hasMany(ForeshadowEvidence::class)->whereIn('baseline_id', Novel::query()->select('active_baseline_id')->whereColumn('novels.id', 'foreshadow_evidence.novel_id'))->orderBy('revision');
    }

    /** 统一面向作者的状态名称。 */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'planned' => '计划中', 'planted' => '已埋设', 'reinforced' => '已强化', 'resolved' => '已回收', 'due' => '待回收', 'overdue' => '已逾期未回收', default => '状态异常',
        };
    }

    /** 是否使用长程契约（有明确回收窗口）；旧伏笔只有最迟回收章号。 */
    public function hasWindow(): bool
    {
        return $this->payoff_to !== null;
    }

    /** 回收窗口的起止章号；旧伏笔视为第1章至最迟回收章号。
     * @return array{0: int, 1: int}
     */
    public function window(): array
    {
        return $this->hasWindow() ? [$this->payoff_from, $this->payoff_to] : [1, $this->target_chapter];
    }

    /** 按已提交章数推导展示状态：进入回收窗口未回收为“待回收”，超过窗口未回收为“已逾期”。账本状态本身只随正文证据变化。 */
    public function displayStatus(int $chaptersDone): string
    {
        if (in_array($this->status, ['planted', 'reinforced'], true) && $this->hasWindow()) {
            $next = $chaptersDone + 1;
            if ($chaptersDone >= $this->payoff_to) {
                return 'overdue';
            }
            if ($next >= $this->payoff_from) {
                return 'due';
            }
        }

        return $this->status;
    }
}
