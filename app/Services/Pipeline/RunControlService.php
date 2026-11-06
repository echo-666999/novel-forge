<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Jobs\RunNovelPipelineJob;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\Novel;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** 运行台只控制安全边界，保留正在返回的结果和全部失败历史。 */
final class RunControlService
{
    /** 请求暂停；在途调用只登记意图，待领取任务则立即停在原步骤。 */
    public function pause(int $novelId, User $operator, string $reason): void
    {
        ContentSchema::text($reason);
        DB::transaction(function () use ($novelId, $operator, $reason): void {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            if (! in_array($novel->status, [NovelStatus::Pending, NovelStatus::Running], true)
                || ChapterSubmissionRun::where('novel_id', $novelId)->whereIn('status', ['pending', 'running'])->exists()) {
                throw new InvalidArgumentException('当前没有可暂停的生成任务；提交准备须等待本次结果。');
            }
            $novel->pause_requested = true;
            $novel->run_control_history = [...($novel->run_control_history ?? []), $this->entry('pause', $operator, $reason)];
            if (! $novel->stages()->where('status', 'running')->exists()) {
                $novel->status = NovelStatus::Paused;
                $novel->outlinePlans()->where('status', 'pending')->update(['status' => 'paused', 'stop_reason' => 'user_paused']);
                ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novelId))
                    ->where('status', 'pending')->update(['status' => 'paused', 'stop_reason' => 'user_paused']);
            }
            $novel->save();
        });
    }

    /** 模拟恢复只入队，不在后台请求中同步执行；真实模式必须走对应专用服务。 */
    public function resumeSimulation(int $novelId, User $operator, int $limit, string $reason): void
    {
        ContentSchema::text($reason);
        $novel = DB::transaction(function () use ($novelId, $operator, $limit, $reason): Novel {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            if ($novel->generation_mode !== GenerationMode::Simulation || ! in_array($novel->status, [NovelStatus::Paused, NovelStatus::Error], true)
                || $novel->chapters()->exists() || $novel->stages()->where('status', 'running')->exists()) {
                throw new InvalidArgumentException('仅空闲且暂停或失败的模拟流水线可以恢复。');
            }
            if ($limit < $novel->max_execution_count || $limit <= $novel->execution_count || $limit > 2147483647) {
                throw new InvalidArgumentException('上限不能降低，且必须大于已执行次数。');
            }
            $novel->update(['status' => NovelStatus::Pending, 'pause_requested' => false, 'max_execution_count' => $limit,
                'run_control_history' => [...($novel->run_control_history ?? []), $this->entry('resume', $operator, $reason)]]);

            return $novel;
        });
        $fine = data_get($novel->stages()->oldest('id')->first()?->input, 'pipeline.enable_fine_outline', app(GeneralSettings::class)->enable_fine_outline);
        try {
            Bus::dispatch(new RunNovelPipelineJob($novel->id, $fine));
        } catch (Throwable) {
            // 投递回执未知时不覆盖已经被消费者领取的运行。
            Novel::whereKey($novel->id)->where('status', NovelStatus::Pending)->update(['status' => NovelStatus::Error]);
            throw new InvalidArgumentException('投递未确认，请核查队列和阶段记录后重试。');
        }
    }

    /** 操作日志只保存作者意图，不包含供应商认证信息。
     * @return array<string, mixed>
     */
    private function entry(string $action, User $operator, string $reason): array
    {
        return ['action' => $action, 'operator_id' => $operator->id, 'reason' => $reason, 'at' => now()->toIso8601String()];
    }
}
