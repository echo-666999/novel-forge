<?php

namespace App\Services\Pipeline;

use App\Models\ProductionRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 生产运行看门狗：只做本地对账，不发送、不重发任何模型请求。
 *
 * - 领取租约过期且超过宽限期的运行：按中断对账，在途请求记为结果未知，运行停下等作者核对后恢复。
 * - 长时间停在待执行的运行：视为队列消息丢失，重新投递同一断点；领取是原子的，重复消息不会重复执行。
 */
final class ProductionWatchdog
{
    /** 租约到期后再等待的分钟数，给命令行同步执行的较长步骤留出余量。 */
    public const RECONCILE_GRACE_MINUTES = 10;

    /** 待执行运行超过此分钟数仍未被领取，视为投递丢失。 */
    public const STALE_PENDING_MINUTES = 15;

    /** 最近一次检查结果的缓存键，只保存编号和时间。 */
    private const KEY = 'novelforge:production-watchdog';

    public function __construct(private ProductionRunService $runs) {}

    /** 执行一轮检查，单个运行处理失败不影响其他运行。
     * @return array{reconciled: list<int>, redispatched: list<int>, failed: list<array{id: int, error: string}>}
     */
    public function sweep(): array
    {
        $result = ['reconciled' => [], 'redispatched' => [], 'failed' => []];
        $expired = ProductionRun::where('status', 'running')->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now()->subMinutes(self::RECONCILE_GRACE_MINUTES))->orderBy('id')->pluck('id');
        foreach ($expired as $id) {
            try {
                $this->runs->reconcile($id, null);
                $result['reconciled'][] = $id;
            } catch (Throwable $error) {
                report($error);
                $result['failed'][] = ['id' => $id, 'error' => $error->getMessage()];
            }
        }
        $stale = ProductionRun::where('status', 'pending')->where('updated_at', '<', now()->subMinutes(self::STALE_PENDING_MINUTES))->orderBy('id')->get();
        foreach ($stale as $run) {
            // 先在仍为待执行且未被改动的前提下记录，再投递；期间被领取则跳过，避免覆盖运行中的历史。
            $history = [...$run->history, ['action' => 'watchdog_redispatch', 'step' => $run->step, 'at' => now()->toIso8601String()]];
            $marked = ProductionRun::whereKey($run->id)->where('status', 'pending')->where('updated_at', $run->updated_at)
                ->update(['history' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            if ($marked === 1) {
                $this->runs->dispatch($run);
                $result['redispatched'][] = $run->id;
            }
        }
        $this->remember($result);

        return $result;
    }

    /** 系统健康页展示最近一次检查；从未运行时明确说明，不冒充健康。 */
    public function summary(): string
    {
        try {
            $value = Cache::get(self::KEY);
            if (! is_array($value)) {
                return '尚无看门狗检查记录；请确认调度器（schedule:work 或 cron）正在运行。';
            }
            $age = max(0, now()->timestamp - (int) $value['timestamp']);

            return ($age <= 180 ? '最近检查' : '检查已过期，请确认调度器正在运行').'：'.$value['checked_at'].'，距今'.$age.'秒；本轮对账中断运行 '
                .count($value['reconciled']).' 个、重新投递 '.count($value['redispatched']).' 个、处理失败 '.count($value['failed']).' 个。';
        } catch (Throwable) {
            return '无法读取看门狗记录，状态未知。';
        }
    }

    /** 缓存失败不影响对账结果，只记录类别不记录内容。
     * @param  array{reconciled: list<int>, redispatched: list<int>, failed: list<array{id: int, error: string}>}  $result
     */
    private function remember(array $result): void
    {
        try {
            Cache::put(self::KEY, ['checked_at' => now()->toIso8601String(), 'timestamp' => now()->timestamp, ...$result], 86400);
        } catch (Throwable $error) {
            Log::warning('看门狗记录写入失败', ['category' => class_basename($error)]);
        }
    }
}
