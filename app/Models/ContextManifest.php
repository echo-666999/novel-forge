<?php

namespace App\Models;

use Database\Factories\ContextManifestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 请求前持久化的不可变上下文清单，包括未发送的容量阻塞。 */
#[Fillable(['novel_id', 'chapter_number', 'purpose', 'status', 'document', 'document_hash', 'created_at'])]
class ContextManifest extends Model
{
    /** 隔离验收夹具。
     * @use HasFactory<ContextManifestFactory>
     */
    use HasFactory;

    /** 清单只有创建时间，不存在覆盖更新时间。 */
    public $timestamps = false;

    /** 防止把历史请求替换成重新组装的当前上下文。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('上下文清单不可覆盖'));
        static::deleting(fn () => throw new LogicException('上下文清单不可删除'));
    }

    /** JSON与数值保留类型，便于摘要复验。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'chapter_number' => 'integer', 'document' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
