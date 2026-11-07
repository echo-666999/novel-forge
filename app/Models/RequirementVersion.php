<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 作者要求的不可变版本，最新版本不改变任何旧运行快照。 */
#[Fillable(['novel_id', 'requirement_key', 'revision', 'source_quote', 'source_hash', 'description', 'category', 'strength', 'status', 'outline_chapter_id', 'evidence_quote', 'evidence_hash', 'reason', 'actor_id', 'created_at'])]
class RequirementVersion extends Model
{
    /** 测试工厂不会调用模型或修改正式版本。 */
    use HasFactory;

    /** 不存在可变更新时间。 */
    public $timestamps = false;

    /** 修订必须新增版本，禁止覆盖与删除历史。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('要求版本不可覆盖'));
        static::deleting(fn () => throw new LogicException('要求历史不可删除'));
    }

    /** 固定时间与编号类型。 */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'novel_id' => 'integer', 'outline_chapter_id' => 'integer', 'actor_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
