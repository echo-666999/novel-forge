<?php

namespace App\Models;

use Database\Factories\ForeshadowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** 伏笔计划不可覆盖，当前状态仅随追加证据演进。 */
#[Fillable(['novel_id', 'title', 'description', 'plant_chapter', 'target_chapter', 'status', 'revision', 'request_key', 'request_hash', 'created_by'])]
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
        return ['novel_id' => 'integer', 'plant_chapter' => 'integer', 'target_chapter' => 'integer', 'revision' => 'integer'];
    }

    /** 证据链按修订序号追加，不覆盖旧结论。
     * @return HasMany<ForeshadowEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(ForeshadowEvidence::class)->orderBy('revision');
    }

    /** 统一面向作者的状态名称。 */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'planned' => '计划中', 'planted' => '已埋设', 'reinforced' => '已强化', 'resolved' => '已回收', default => '状态异常',
        };
    }
}
