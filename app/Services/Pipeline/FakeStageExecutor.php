<?php

namespace App\Services\Pipeline;

use App\Models\Novel;
use App\Models\NovelStage;

/** 六个固定阶段共用的确定性假执行器，不调用 AI、章节生产或文件导出。 */
final class FakeStageExecutor implements StageExecutorInterface
{
    /** 只产生模拟元信息，不把夹具伪装成小说设定、正文或正式产物。 */
    public function execute(Novel $novel, NovelStage $stage): StageResult
    {
        return StageResult::completed(
            ['simulation' => true, 'artifact_type' => 'simulation_metadata', 'stage' => $stage->stage->value, 'marker' => $stage->stage->value.'-ok'],
            ['billing_status' => 'simulated', 'currency' => null, 'estimated_cost' => '0.00000000', 'actual_cost' => '0.00000000'],
        );
    }
}
