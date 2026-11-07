<?php

namespace Tests\Feature;

use App\Services\Pipeline\WorkerHeartbeat;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** 健康显示只来源于实际消费者事件，缓存故障不冒充正常。 */
class C09SystemHealthTest extends TestCase
{
    /** HTTP内同步任务不能伪造独立Worker在线。 */
    public function test_sync_queue_does_not_create_worker_heartbeat(): void
    {
        Cache::forget('novelforge:worker-heartbeat');
        Event::dispatch(new JobProcessing('sync', new SyncJob(app(), '{}', 'sync', null)));
        $this->assertNull(Cache::get('novelforge:worker-heartbeat'));
    }

    /** 无观测、近期和超时状态严格区分，未出现事件时读取不会生成记录。 */
    public function test_worker_observation_and_staleness_are_not_assumed_health(): void
    {
        Cache::forget('novelforge:worker-heartbeat');
        $service = app(WorkerHeartbeat::class);
        $this->assertStringContainsString('尚无有效', $service->summary());
        Event::dispatch(new Looping('redis', 'default'));
        $this->assertStringContainsString('最近观测', $service->summary());
        $this->assertStringContainsString('不代表全部消费者健康', $service->summary());
        $this->travel(301)->seconds();
        $this->assertStringContainsString('观测已过期', $service->summary());
        $this->travelBack();
        Cache::forget('novelforge:worker-heartbeat');
    }
}
