<?php

namespace App\Jobs;

use App\Enums\NovelStatus;
use App\Models\Novel;
use App\Services\Pipeline\FakeStageExecutor;
use App\Services\Pipeline\NovelPipelineService;
use App\Services\Pipeline\StageExecutorRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** B01 专用模拟启动任务，不因全局供应商绑定变化转成真实模型调用。 */
class RunNovelPipelineJob implements ShouldQueue
{
    use Queueable;

    /** 队列重试不等于作者授权恢复，失败只尝试一次。 */
    public int $tries = 1;

    /** 小于 Redis 当前90秒的重新投递间隔，避免正常任务重叠消费。 */
    public int $timeout = 60;

    /** 固定 Redis 异步连接，冻结创建时细纲选项，不在任务载荷中存创意或密钥。 */
    public function __construct(public int $novelId, public bool $enableFineOutline)
    {
        $this->onConnection('redis');
        $this->afterCommit();
    }

    /** 复用既有并发、额度及历史保护；不存在的小说不创建替代记录。 */
    public function handle(): void
    {
        $novel = Novel::query()->find($this->novelId);
        if ($novel === null) {
            return;
        }
        // 显式构造模拟执行器，不解析可被后续真实供应商替换的全局执行契约。
        $pipeline = new NovelPipelineService(new StageExecutorRegistry(new FakeStageExecutor));
        $pipeline->run($novel, $this->enableFineOutline);
    }

    /** 任务未能启动时仅标记未领用小说，不回收 running 尝试或改写阶段历史。 */
    public function failed(?Throwable $exception): void
    {
        Novel::query()->whereKey($this->novelId)->where('status', NovelStatus::Pending->value)
            ->where('execution_count', 0)->whereDoesntHave('stages')->update(['status' => NovelStatus::Error]);
        Log::warning('小说模拟启动任务未完成', ['novel_id' => $this->novelId, 'error_category' => $exception === null ? 'unknown' : class_basename($exception)]);
    }
}
