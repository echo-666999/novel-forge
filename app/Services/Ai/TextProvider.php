<?php

namespace App\Services\Ai;

/** 文本供应商契约；适配器只调用模型，不负责数据库记账或重试。 */
interface TextProvider
{
    /** 按冻结的模型和短文本参数返回统一结果，失败时抛出安全异常。 */
    public function generate(string $prompt, string $model, ?TextRequestOptions $options = null): TextGenerationResult;
}
