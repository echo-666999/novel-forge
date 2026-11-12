<?php

namespace App\Services\Ai;

use App\Services\Pipeline\FormalRevisionContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/** 正式稿修订的单次结构化请求：给出逐条润色建议，或核对润色前后事实等价；无工具，不写入正式历史。 */
#[Strict]
#[MaxSteps(1)]
final class FormalRevisionAgent implements Agent, HasStructuredOutput
{
    /** 参数由修订任务冻结。 */
    public function __construct(private TextRequestOptions $options) {}

    use Promptable;

    /** 指令固定在程序中，不套用后台模板。 */
    public function instructions(): string
    {
        return FormalRevisionContract::instructions($this->options->formalRevisionPurpose);
    }

    /** 输出结构绑定本章段落数、事件键和待定位证据。
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return FormalRevisionContract::schema($schema, $this->options->formalRevisionPurpose, $this->options->formalRevisionContext);
    }

    /** 输出容量固定。 */
    public function maxTokens(): int
    {
        return $this->options->maxOutputTokens;
    }

    /** 零温度。 */
    public function temperature(): float
    {
        return 0.0;
    }
}
