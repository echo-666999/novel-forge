<?php

namespace App\Services\Ai;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/** 无工具、无会话存储的短文本 Agent，限制单次验收输出与步骤数。 */
#[MaxTokens(128)]
#[MaxSteps(1)]
final class TextGenerationAgent implements Agent
{
    use Promptable;

    /** 固定短指令仅验证文本连通，不要求供应商具备结构化输出能力。 */
    public function instructions(): string
    {
        return '请简短、准确地回答用户请求。';
    }
}
