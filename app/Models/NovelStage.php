<?php

namespace App\Models;

use App\Enums\Stage;
use App\Enums\StageStatus;
use Database\Factories\NovelStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 单次阶段执行记录，保存输入输出快照而不自动推进流水线。 */
#[Fillable(['novel_id', 'stage', 'status', 'execution_count', 'input', 'output', 'error_message', 'started_at', 'finished_at'])]
class NovelStage extends Model
{
    /** 使用隔离测试工厂创建关联数据。
     * @use HasFactory<NovelStageFactory>
     */
    use HasFactory;

    /** 未刷新数据库前也可读取一致的初始运行状态。
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'execution_count' => 0,
    ];

    /** 定义枚举、结构化快照和不可变时间的转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => Stage::class,
            'status' => StageStatus::class,
            'execution_count' => 'integer',
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
}
