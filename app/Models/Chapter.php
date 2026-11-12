<?php

namespace App\Models;

use App\Enums\ChapterStatus;
use Database\Factories\ChapterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 稳定章节节点骨架，正文和正式基线将在独立版本表中保存。 */
#[Fillable(['novel_id', 'number', 'title', 'objective', 'status', 'current_candidate_version_id', 'progression_result', 'formal_version_id', 'canonical_commit_id', 'committed_at'])]
class Chapter extends Model
{
    /** 使用隔离测试工厂创建关联数据。
     * @use HasFactory<ChapterFactory>
     */
    use HasFactory;

    /** 未刷新数据库前也可读取一致的初始运行状态。
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** 定义枚举、结构化快照和不可变时间的转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progression_result' => 'array',
            'formal_version_id' => 'integer',
            'canonical_commit_id' => 'integer',
            'committed_at' => 'immutable_datetime',
            'number' => 'integer',
            'status' => ChapterStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** 所属小说。
     * @return BelongsTo<Novel, $this>
     */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** 选择列表等处的展示名：统一以本章编号开头，去掉章名里已自带的中文章号，避免出现「第4章 第4章」。 */
    public function numberedTitle(): string
    {
        $name = trim((string) preg_replace('/^第[〇零一二三四五六七八九十百千万两0-9]+[章节回][\s：:、.\-]*/u', '', trim((string) $this->title)));

        return trim('第'.$this->number.'章 '.$name);
    }
}
