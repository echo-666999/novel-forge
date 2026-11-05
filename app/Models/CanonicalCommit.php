<?php

namespace App\Models;

use Database\Factories\CanonicalCommitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 不可变章节正式提交回执。 */
#[Fillable(['submission_run_id', 'novel_id', 'chapter_id', 'version_id', 'validation_id', 'previous_state_version', 'state_version', 'idempotency_key', 'request_hash', 'committed_by', 'reason', 'approvals', 'result', 'created_at'])]
class CanonicalCommit extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<CanonicalCommitFactory>
     */
    use HasFactory;

    public $timestamps = false;

    /** 禁止覆盖历史；可变运行只允许状态和消费计数演进。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('正式或候选审计产物不可覆盖'));
        static::deleting(fn () => throw new LogicException('故事提交历史不可删除'));
    }

    /** 保留结构化快照、整数和不可变时间类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submission_run_id' => 'integer', 'novel_id' => 'integer', 'chapter_id' => 'integer', 'version_id' => 'integer', 'validation_id' => 'integer', 'previous_state_version' => 'integer', 'state_version' => 'integer', 'approvals' => 'array', 'result' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
