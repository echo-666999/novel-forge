<?php

namespace App\Console\Commands;

use App\Models\ChapterSubmissionRun;
use App\Models\User;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/** 将已完成的原文事件核对留为可追溯回执，不批准新实体也不正式提交。 */
#[Signature('app:review-chapter-events {run} {--extraction=} {--result-hash=} {--accept} {--reject} {--finding=*}')]
#[Description('记录B05事件提取的原文阅读验收或拒绝原因')]
class ReviewChapterEvents extends Command
{
    /** 明确选择接受或拒绝，并绑定实际读取的提取编号及摘要。 */
    public function handle(ChapterSubmissionService $service): int
    {
        try {
            if (! ctype_digit((string) $this->argument('run')) || ! ctype_digit((string) $this->option('extraction'))
                || (bool) $this->option('accept') === (bool) $this->option('reject')) {
                throw new InvalidArgumentException('必须明确运行、提取编号和唯一阅读结论');
            }
            $run = ChapterSubmissionRun::findOrFail((int) $this->argument('run'));
            $result = $service->recordEventReading($run->id, (int) $this->option('extraction'), (string) $this->option('result-hash'),
                User::findOrFail($run->config['operator_id']), (bool) $this->option('accept'), $this->option('finding'));
            $this->line(PlanningJson::encode($result->only(['id', 'status', 'stop_reason', 'control_history'])));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
