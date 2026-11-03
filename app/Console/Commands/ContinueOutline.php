<?php

namespace App\Console\Commands;

use App\Services\Pipeline\OutlineGenerationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 人工明确继续失败或暂停的大纲，不重跑成功章节或清零额度。 */
#[Signature('app:continue-outline {plan : 大纲计划编号} {--confirm-unknown : 已核查并接受未知调用可能重复计费} {--max-execution-count= : 显式提高该小说执行上限} {--reason= : 提高上限的原因}')]
#[Description('继续现有分批大纲；未知结果须额外确认，加额度须填写原因')]
class ContinueOutline extends Command
{
    /** 命令只入队，返回当前计划状态，不冒充模型生成完成。 */
    public function handle(OutlineGenerationService $service): int
    {
        try {
            $id = filter_var($this->argument('plan'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $rawLimit = $this->option('max-execution-count');
            $limit = $rawLimit === null ? null : filter_var($rawLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if ($id === false || $limit === false) {
                $this->error('计划编号及执行上限必须为合法正整数。');

                return self::FAILURE;
            }
            $plan = $service->continue($id, (bool) $this->option('confirm-unknown'), $limit, $this->option('reason'));
            $this->line('计划状态：'.$plan->statusLabel().'；下一章：'.$plan->next_chapter.'。');

            return in_array($plan->status, ['pending', 'running', 'completed'], true) ? self::SUCCESS : self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));
        } catch (Throwable) {
            $this->error('大纲续跑未完成，请核查计划、数据库和队列；未自动重发。');
        }

        return self::FAILURE;
    }
}
