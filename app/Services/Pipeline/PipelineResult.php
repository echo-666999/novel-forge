<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Models\Novel;

/** 流水线停止时的状态快照；完成仅代表模拟阶段完成，不代表正式提交。 */
final readonly class PipelineResult
{
    /** 冻结返回字段，避免后续模型对象刷新改变已有结果。 */
    private function __construct(
        public int $novelId,
        public NovelStatus $status,
        public ?Stage $currentStage,
        public int $executionCount,
        public int $maxExecutionCount,
        public string $progress,
        public string $reason,
        public bool $simulation,
    ) {}

    /** 从刚读取的持久化状态创建返回快照。 */
    public static function fromNovel(Novel $novel, string $reason): self
    {
        return new self($novel->id, $novel->status, $novel->current_stage, $novel->execution_count, $novel->max_execution_count, $novel->progress, $reason, $novel->generation_mode === GenerationMode::Simulation);
    }

    /** 输出明确带有模拟标识的命令行结果。
     * @return array{simulation: bool, novel_id: int, status: string, current_stage: ?string, execution_count: int, max_execution_count: int, progress: string, reason: string}
     */
    public function toArray(): array
    {
        return ['simulation' => $this->simulation, 'novel_id' => $this->novelId, 'status' => $this->status->value, 'current_stage' => $this->currentStage?->value, 'execution_count' => $this->executionCount, 'max_execution_count' => $this->maxExecutionCount, 'progress' => $this->progress, 'reason' => $this->reason];
    }
}
