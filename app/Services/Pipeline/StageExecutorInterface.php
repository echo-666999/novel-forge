<?php

namespace App\Services\Pipeline;

use App\Models\Novel;
use App\Models\NovelStage;

/** 固定阶段的执行契约；执行器只返回结果，不推进状态、重试或创建子任务。 */
interface StageExecutorInterface
{
    /** 在领用事务提交后执行一次阶段，调用失败交由流水线记录。 */
    public function execute(Novel $novel, NovelStage $stage): StageResult;
}
