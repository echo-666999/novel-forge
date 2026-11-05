<?php

namespace App\Services\Ai;

use App\Services\Pipeline\SubmissionContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/** 无工具的单次评审或提取Agent，不写正文也不批准实体。 */
#[Strict]
#[MaxSteps(1)]
final class SubmissionAgent implements Agent, HasStructuredOutput
{
    /** 参数由准备运行冻结。 */
    public function __construct(private TextRequestOptions $options) {}

    use Promptable;

    /** 两个用途指令分离。 */
    public function instructions(): string
    {
        return SubmissionContract::instructions($this->options->submissionPurpose);
    }

    /** 输出严格绑定当前正文证据目录。
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return SubmissionContract::schema($schema, $this->options->submissionPurpose, $this->options->submissionContext['anchors']);
    }

    /** 输出容量固定，不能自动扩大。 */
    public function maxTokens(): int
    {
        return $this->options->maxOutputTokens;
    }

    /** 评审与提取使用冻结零温度。 */
    public function temperature(): float
    {
        return 0.0;
    }
}
