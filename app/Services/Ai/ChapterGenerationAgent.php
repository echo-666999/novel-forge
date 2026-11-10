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

    /** 固定文本取自冻结发布版本，动态章号与输出契约仍由程序处理。 */
    public function instructions(): string
    {
        // 单段修复属于编辑任务，不能同时要求重写整章，否则会诱发扩写或照抄旧段。
        if (isset($this->options->executionContext['draft_repair_slot'])) {
            return '你正在编辑已有小说的一个段落，不是创作新章节。仅修改repair_source.text，保留关键行动、人物立场、因果顺序和事实类别。surrounding_paragraphs只用于衔接，不得重复其事件。按repair_measurement实测差额完成压缩或扩写；优先删除重复解释和非必要细节，不能删除推进所必需的行动。标题使用original_title。遵守作者原始创意和rule_catalog。'
                .ContentExecutionContract::outputInstructions($this->options->chapterStep, $this->options->executionContext);
        }

        return $this->contractInstructions().$this->options->templateInstructions
            .ContentExecutionContract::outputInstructions($this->options->chapterStep, $this->options->executionContext);
    }

    /** 生成和检查使用分离的固定中文指令。 */
    private function contractInstructions(): string
    {
        $instructions = $this->options->builtinInstructions ?? ContentExecutionContract::instructions($this->options->chapterStep);
        $number = $this->options->executionContext['chapter_number'] ?? 1;

        // 分支导入的是首章专项实验模板，不能将首章情节通过替换章号施加给后九章。
        if (($this->options->executionContext['accepted_branch_continuation'] ?? false) === true) {
            $instructions = ContentExecutionContract::instructions($this->options->chapterStep)
                .'人物有具体且不完全相容的眼前愿望，通过行动、对白、阻力和代价推动关系与剧情。职业核查是冲突中的工具，不应成为整章主线；避免重复文书流程、反复讨论计划而没有实际变化。采用当前章计划及正式前文，不重演已完成的接手、退单、搬运冲突。';
        }

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
