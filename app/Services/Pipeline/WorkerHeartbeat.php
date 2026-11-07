<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** 消费者实际循环或领取任务时记录观测，不通过页面访问伪造心跳。 */
final class WorkerHeartbeat
{
    /** 缓存键仅保存安全运行元数据。 */
    private const KEY = 'novelforge:worker-heartbeat';

    /** 同一进程节流写入，避免空闲循环频繁访问缓存。 */
    private static int $lastWrite = 0;

    /** 缓存失败不阻止业务队列执行，不记录异常中的凭据。 */
    public function observe(string $connection, string $queue, bool $force = false): void
    {
        try {
            if (! $force && now()->timestamp - self::$lastWrite < 15 && Cache::has(self::KEY)) {
                return;
            }
            Cache::put(self::KEY, ['observed_at' => now()->toIso8601String(), 'timestamp' => now()->timestamp,
                'connection' => $connection, 'queue' => $queue, 'pid' => getmypid()], 900);
            self::$lastWrite = now()->timestamp;
        } catch (Throwable $error) {
            Log::warning('消费者心跳写入失败', ['category' => class_basename($error)]);
        }
    }

    /** 观测新鲜不代表全部消费者健康；无观测也不能直接断言服务已经停机。 */
    public function summary(): string
    {
        try {
            $value = Cache::get(self::KEY);
            if (! is_array($value)) {
                return '尚无有效消费者观测；请检查进程管理器，不能据此判断健康。';
            }
            $age = max(0, now()->timestamp - (int) $value['timestamp']);

            return ($age <= 300 ? '最近观测' : '观测已过期，请排查').'：'.$value['observed_at'].'，队列 '.$value['queue'].'，连接 '.$value['connection'].'，距今'.$age.'秒。此记录不代表全部消费者健康。';
        } catch (Throwable) {
            return '无法读取心跳缓存，健康状态未知。';
        }
    }
}
