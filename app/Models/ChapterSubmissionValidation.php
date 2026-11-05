<?php

namespace App\Models;

use Database\Factories\ChapterSubmissionValidationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 不可变确定性验证结果。 */
#[Fillable(['submission_run_id', 'review_id', 'extraction_id', 'verdict', 'input_hash', 'result', 'created_at'])]
class ChapterSubmissionValidation extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<ChapterSubmissionValidationFactory>
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
        return ['submission_run_id' => 'integer', 'review_id' => 'integer', 'extraction_id' => 'integer', 'result' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
