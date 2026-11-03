<?php

namespace App\Console\Commands;

use App\Services\Pipeline\RetrySettingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/** 显式重试失败设定，不修改历史、不增加额度、不自动调用第二次。 */
#[Signature('app:retry-setting {novel : 小说编号} {--confirm-unknown : 已核查未知结果并接受可能重复计费}')]
#[Description('将失败的真实设定重新入队；未知结果必须额外确认')]
class RetrySetting extends Command
{
    /** 命令只报告投递确认，不把入队冒充模型生成成功。 */
    public function handle(RetrySettingService $service): int
    {
        try {
            $id = filter_var($this->argument('novel'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new InvalidArgumentException('小说编号必须为正整数');
            }
            $result = $service->retry($id, (bool) $this->option('confirm-unknown'));
            $this->line($result->startupConfirmed ? '设定重试已入队，请查看后台结果。' : '重试投递未确认，请先检查后台状态，不要重复发送。');

            return $result->startupConfirmed ? self::SUCCESS : self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable) {
            $this->error('设定重试未完成，请核查小说、数据库和队列；未自动重发。');
        }

        return self::FAILURE;
    }
}
