<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 作者接管一本书的审计记录；只追加，不可修改或删除。 */
#[Fillable(['novel_id', 'from_user_id', 'to_user_id', 'reason', 'created_at'])]
class NovelAuthorTakeover extends Model
{
    public $timestamps = false;

    /** 接管记录是授权依据，保存后不可改写。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('作者接管记录不可修改'));
        static::deleting(fn () => throw new LogicException('作者接管记录不可删除'));
    }

    /** 编号与时间按原类型读回。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'from_user_id' => 'integer', 'to_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
