<?php

namespace App\Models;

use Database\Factories\ChapterSubmissionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 有限章节提交准备及授权。 */
#[Fillable(['novel_id', 'chapter_id', 'version_id', 'body_hash', 'expected_state_version', 'snapshot', 'config', 'input_hash', 'status', 'step', 'invocation_id', 'requests_used', 'request_limit', 'stop_reason', 'control_history'])]
class ChapterSubmissionRun extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<ChapterSubmissionRunFactory>
     */
    use HasFactory;

    /** 禁止覆盖历史；可变运行只允许状态和消费计数演进。 */
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['novel_id', 'chapter_id', 'version_id', 'body_hash', 'expected_state_version', 'snapshot', 'config', 'input_hash', 'request_limit']) || $record->requests_used < $record->getRawOriginal('requests_used')) {
                throw new LogicException('冻结提交输入及领用请求不可覆盖');
            }
        });
        static::deleting(fn () => throw new LogicException('故事提交历史不可删除'));
    }

    /** 保留结构化快照、整数和不可变时间类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'chapter_id' => 'integer', 'version_id' => 'integer', 'expected_state_version' => 'integer', 'snapshot' => 'array', 'config' => 'array', 'requests_used' => 'integer', 'request_limit' => 'integer', 'control_history' => 'array'];
    }

    /** 读取本运行的chapter冻结归属。
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /** 读取本运行的version冻结归属。
     * @return BelongsTo<ChapterVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ChapterVersion::class, 'version_id');
    }

    /** 读取本运行的novel冻结归属。
     * @return BelongsTo<Novel, $this>
     */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }
}
