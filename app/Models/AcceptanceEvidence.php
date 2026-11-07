<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 验收证据只追加，更正使用新记录保留原结论。 */
#[Fillable(['batch_id', 'actor_id', 'kind', 'case_key', 'payload', 'payload_hash', 'created_at'])]
class AcceptanceEvidence extends Model
{
    /** 禁用可变更新时间。 */
    public $timestamps = false;

    /** 禁止静默改分或删除失败证据。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('验收证据不可覆盖'));
        static::deleting(fn () => throw new LogicException('验收证据不可删除'));
    }

    /** 固定证据与时间类型。 */
    protected function casts(): array
    {
        return ['batch_id' => 'integer', 'actor_id' => 'integer', 'payload' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
