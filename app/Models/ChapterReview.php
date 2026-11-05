<?php

namespace App\Models;

use Database\Factories\ChapterReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 不可变章节基础评审。 */
#[Fillable(['submission_run_id', 'novel_id', 'chapter_id', 'version_id', 'body_hash', 'verdict', 'result', 'result_hash', 'cost_log_id', 'created_at'])]
class ChapterReview extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<ChapterReviewFactory>
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
        return ['submission_run_id' => 'integer', 'novel_id' => 'integer', 'chapter_id' => 'integer', 'version_id' => 'integer', 'result' => 'array', 'cost_log_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
