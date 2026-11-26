<?php

namespace App\Models;

use App\Models\Concerns\PreservesJsonFloats;
use Database\Factories\ChapterOpenItemExtractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 不可变章节待核事项提取：台账沿正式提交链重放时读取。 */
#[Fillable(['submission_run_id', 'novel_id', 'chapter_id', 'version_id', 'body_hash', 'result', 'result_hash', 'cost_log_id', 'created_at'])]
class ChapterOpenItemExtraction extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<ChapterOpenItemExtractionFactory>
     */
    use HasFactory;

    use PreservesJsonFloats;

    public $timestamps = false;

    /** 禁止覆盖或删除历史产物。 */
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
