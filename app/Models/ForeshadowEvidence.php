<?php

namespace App\Models;

use Database\Factories\ForeshadowEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 伏笔事实注释仅引用正式正文，原文、理由及操作者永不覆盖。 */
#[Fillable(['foreshadow_id', 'novel_id', 'revision', 'from_status', 'to_status', 'commit_id', 'chapter_id', 'version_id', 'chapter_number', 'body_hash', 'evidence', 'reason', 'recorded_by', 'request_key', 'request_hash', 'created_at'])]
class ForeshadowEvidence extends Model
{
    /** 隔离测试工厂。
     * @use HasFactory<ForeshadowEvidenceFactory>
     */
    use HasFactory;

    /** 证据只记录创建时间，不存在可编辑时间。 */
    public $timestamps = false;

    /** 禁止更新或删除任何证据。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('伏笔证据不可覆盖'));
        static::deleting(fn () => throw new LogicException('伏笔证据不可删除'));
    }

    /** 结构化段落及来源编号用于严格核验。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['foreshadow_id' => 'integer', 'novel_id' => 'integer', 'revision' => 'integer', 'commit_id' => 'integer', 'chapter_id' => 'integer', 'version_id' => 'integer', 'chapter_number' => 'integer', 'evidence' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
