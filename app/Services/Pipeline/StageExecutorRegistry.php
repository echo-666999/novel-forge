<?php

namespace App\Services\Pipeline;

use App\Enums\Stage;

/** 固定注册表；只覆盖既有六个枚举阶段，不提供动态扩展或任意类解析入口。 */
final class StageExecutorRegistry
{
    /** 默认注入纯假数据执行器，测试可通过契约注入故障执行器。 */
    public function __construct(private StageExecutorInterface $executor) {}

    /** 仅解析固定阶段，A05 不根据外部输入选择供应商或执行器类名。 */
    public function forStage(Stage $stage): StageExecutorInterface
    {
        return match ($stage) {
            Stage::Setting, Stage::Outline, Stage::FineOutline, Stage::Content, Stage::Polish, Stage::Export => $this->executor,
        };
    }
}
