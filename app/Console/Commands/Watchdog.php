<?php

namespace App\Console\Commands;

use App\Services\Pipeline\ProductionWatchdog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** 每分钟由调度器执行；只做本地对账和重新投递，不发送任何模型请求。 */
#[Signature('app:watchdog')]
#[Description('检查生产运行：对账领取超时的运行，重新投递丢失消息的待执行运行')]
class Watchdog extends Command
{
    /** 输出本轮处理的运行编号，便于在日志中追溯。 */
    public function handle(ProductionWatchdog $watchdog): int
    {
        $result = $watchdog->sweep();
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
