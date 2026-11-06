<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Jobs\RunSettingJob;
use App\Models\CostLog;
use App\Models\Novel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 作者显式重试设定；保留旧尝试和费用，不能把成功结果重新收费生成。 */
final class RetrySettingService
{
    /** 原子检查资格后重新入队，不在命令 HTTP 或锁事务中调用模型。 */
    public function retry(int $novelId, bool $confirmUnknown = false, bool $dispatch = true): NovelCreationResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('请在事务之外重试设定');
        }
        $token = (string) Str::uuid7();
        $novel = DB::transaction(function () use ($novelId, $confirmUnknown, $token): Novel {
            $novel = Novel::query()->lockForUpdate()->findOrFail($novelId);
            if ($novel->generation_mode !== GenerationMode::SettingReal || ! in_array($novel->status, [NovelStatus::Error, NovelStatus::Paused], true)) {
                throw new InvalidArgumentException('仅错误或暂停的真实设定小说允许重试');
            }
            if ($novel->stages()->where('status', StageStatus::Running->value)->exists() || $novel->stages()->where('status', StageStatus::Completed->value)->exists()) {
                throw new InvalidArgumentException('已有运行中或成功的阶段，拒绝重试');
            }
            if ($novel->execution_count >= $novel->max_execution_count) {
                throw new InvalidArgumentException('已达到执行次数上限，不能重试');
            }
            $latest = $novel->stages()->latest('id')->first();
            if ($latest !== null && ($latest->stage !== Stage::Setting || $latest->status !== StageStatus::Failed)) {
                throw new InvalidArgumentException('阶段历史不支持设定重试');
            }
            $uncertainLog = CostLog::query()->where('novel_id', $novel->id)->whereIn('status', ['unknown', 'pending'])->exists();
            if (! $confirmUnknown && ($uncertainLog || data_get($latest?->output, 'data.outcome') === 'unknown')) {
                throw new InvalidArgumentException('上次结果未知，核查后使用 --confirm-unknown 显式确认可能重复计费');
            }
            $config = $novel->generation_config;
            if (! is_array($config) || ! isset($config['model'], $config['schema_version'])) {
                throw new InvalidArgumentException('缺少冻结参数，拒绝重新构造调用');
            }
            $novel->update(['status' => NovelStatus::Pending, 'pause_requested' => false, 'generation_config' => [...$config, 'invocation_id' => $token]]);

            return $novel;
        });
        // 自动首章由独立编排器继续，不另发旧任务。
        if (! $dispatch) {
            return new NovelCreationResult($novel, true);
        }
        try {
            Bus::dispatch(new RunSettingJob($novel->id, $token));

            return new NovelCreationResult($novel->refresh(), true);
        } catch (Throwable $exception) {
            // 回执未知时可能已经消费，只有仍待领取且令牌相同才标记异常。
            Novel::query()->whereKey($novel->id)->where('status', NovelStatus::Pending->value)->where('generation_config->invocation_id', $token)->update(['status' => NovelStatus::Error]);
            Log::warning('设定重试投递未确认', ['novel_id' => $novel->id, 'error_category' => class_basename($exception)]);

            return new NovelCreationResult($novel->refresh(), false);
        }
    }
}
