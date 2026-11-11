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

    /** 固定文本取自冻结发布版本；输出结构和业务校验仍由程序控制。 */
    public function instructions(): string
    {
        return ($this->options->builtinInstructions ?? $this->contractInstructions()).$this->options->templateInstructions.self::outputInstructions($this->options->submissionPurpose);
    }

    /** 程序输出约束附加在模板之后，后台补充文本不能覆盖证据上限与提取容量。 */
    public static function outputInstructions(?string $purpose): string
    {
        // JSON模式同样必须遵守程序证据上限，不能用全章编号列表冒充精确引用。
        return ($purpose === 'review' ? "\n独立核验要求：reading_hints仅为待核线索，不能照抄成已确认缺陷。相同短语不等于重复行动；须核对当前全文，明确说明同一事件在哪里被重新演示。报价、项目总价和资金缺口须分别核对，不能混为同一金额。blocking必须是有当前原文证据的具体问题，需区分或可能存在的疑问只能作为建议。文学质量仍须真实审查；不要建议编造未经确认的人物往事来凑情感。" : '')
            .($purpose === 'extract' ? "\n伏笔流转：若输入含 foreshadow_context 且有带回收窗口的伏笔，只在本章正文确实完成时于 foreshadows 中报告：plant 为本章首次以表面线索埋设；reinforce 为本章引入了新的相关信息或影响了人物选择；payoff 为本章用已发生的证据把表面线索与隐藏含义连接起来，并让人物据此作出改变主线的选择。单纯再提名称、重复旧句子或旁白解释都不算，未完成时不要报告。evidence_ids 指向完成该动作的段落。" : '')
            .($purpose === 'extract' ? "\n提取容量约束：输出紧凑JSON，不使用缩进和无意义空行。只提取影响剧情、后续知情或状态的关键事件，通常10至18项，不把每个小动作和每句对白拆成事件。覆盖所有正文段落中的关键行动，保持时序，不合并不同时间的事件。summary与新实体description使用简短的一句话，避免重复背景介绍；实体别名只保留必要别名，重复引用仍须保持完整且完全一致的定义。禁止为节省长度省略章末行动、人物出入、转交及状态变化。" : '')
            ."\n程序输出约束：每个 evidence_ids 数组必须包含1至4个互不重复的当前正文段落ID。review.evidence_ids也最多4个，选择最能支撑总体结论的段落；其余依据分散到各条finding，不能列出全部段落突破上限。";
    }

    /** 两个用途指令分离。 */
    private function contractInstructions(): string
    {
        return SubmissionContract::instructions($this->options->submissionPurpose);
    }

    /** 输出严格绑定当前正文证据目录。
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return SubmissionContract::schema($schema, $this->options->submissionPurpose, $this->options->submissionContext['anchors'], $this->options->submissionContext['entity_catalog'] ?? [], $this->options->submissionContext['foreshadow_ids'] ?? []);
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
