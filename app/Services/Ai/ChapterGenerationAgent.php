<?php

namespace App\Services\Ai;

use App\Services\Pipeline\ContentExecutionContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/** 只执行当前正文契约；旧Schema和Prompt不会被重新发送。 */
#[Strict]
#[MaxSteps(1)]
final class ChapterGenerationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /** 禁止将历史选项或通用短文本参数当作正文请求。 */
    public function __construct(private TextRequestOptions $options)
    {
        if ($options->chapterStep === null || ($options->executionContext['version'] ?? null) !== ContentExecutionContract::VERSION) {
            throw new InvalidArgumentException('正文Agent只接受当前有限协议');
        }
    }

    /** 由同一冻结选项拼接模板，程序契约保持独立且不可由后台覆盖。 */
    public function instructions(): string
    {
        return $this->contractInstructions().$this->options->templateInstructions;
    }

    /** 生成和检查使用分离的固定中文指令。 */
    private function contractInstructions(): string
    {
        $instructions = ContentExecutionContract::instructions($this->options->chapterStep);
        $number = $this->options->executionContext['chapter_number'] ?? 1;

        // 保留旧首章冻结指令；后续章明确当前章号及已提交前文的优先级。
        return $number === 1 ? $instructions : str_replace('首章', '第'.$number.'章', $instructions).'续接prior_context中的正式前文与上一章结尾，不重演已完成行动。事件摘要保留事实类别，传闻和信念不能升级为客观事实；候选大纲与正式前文冲突时承接正式事实，只推进当前章。';
    }

    /** Schema严格绑定本次冻结目录。
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ContentExecutionContract::schema($schema, $this->options->chapterStep, $this->options->executionContext);
    }

    /** 生成、修订和独立检查各自遵守固定容量。 */
    public function maxTokens(): int
    {
        return $this->options->maxOutputTokens;
    }

    /** 独立检查使用零温度，实际生成使用本次冻结值。 */
    public function temperature(): float
    {
        return $this->options->temperature;
    }
}
