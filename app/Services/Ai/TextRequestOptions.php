<?php

namespace App\Services\Ai;

use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\SubmissionContract;
use InvalidArgumentException;

/** 受限请求参数，不允许调用方绕过单次请求和阶段超时边界。 */
final readonly class TextRequestOptions
{
    /** 参数仅由用途工厂生成；正文仅接受当前统一协议。
     * @param  array{start: int, end: int, total: int}|null  $outlineRange
     * @param  array<string, mixed>|null  $executionContext
     */
    private function __construct(public int $maxOutputTokens, public int $timeoutSeconds, public ?float $temperature, public bool $structuredSetting, public ?array $outlineRange = null, public ?string $chapterStep = null, public ?array $executionContext = null, public ?string $submissionPurpose = null, public ?array $submissionContext = null, public bool $settingDecision = false) {}

    /** 自动授权下的设定决策仍经过独立结构化请求与计费审计。 */
    public static function decision(): self
    {
        return new self(8192, 180, 0.0, true, settingDecision: true);
    }

    /** 独立提交准备用途，不与B04步骤或额度混用。 */
    public static function submission(string $purpose, array $context): self
    {
        if (! in_array($purpose, ['review', 'extract'], true) || ($context['anchors'] ?? []) === []) {
            throw new InvalidArgumentException('提交准备用途或正文目录无效');
        }
        ksort($context['anchors'], SORT_STRING);

        return new self($purpose === 'review' ? 4096 : 8192, 180, 0.0, false, submissionPurpose: $purpose, submissionContext: $context);
    }

    /** 新旧协议选项隔离；统一执行器显式冻结版本并稳定JSONB目录顺序。 */
    public static function executionChapter(string $step, float $temperature, array $context, int $bodyTokens = 8192): self
    {
        self::setting($temperature);
        if (! in_array($step, ['fine', 'draft', 'revise', 'check'], true) || $bodyTokens < 1 || $bodyTokens > 8192) {
            throw new InvalidArgumentException('统一正文步骤无效');
        }
        foreach (['anchors', 'rules', 'findings'] as $field) {
            ksort($context[$field], SORT_STRING);
        }
        $context['version'] = ContentExecutionContract::VERSION;

        return new self(in_array($step, ['fine', 'check'], true) ? 4096 : $bodyTokens, 180, $step === 'check' ? 0.0 : $temperature, false, null, $step, $context);
    }

    /** 正文请求的版本固定，不再根据已退役的策略字段推测版本。 */
    public function chapterVersion(): string
    {
        return ContentExecutionContract::VERSION;
    }

    /** 保持 A03 短文本连通验证的原有行为。 */
    public static function probe(): self
    {
        return new self(128, 30, null, false);
    }

    /** 冻结 B02 输出上限，温度只接受模型支持的有限数值。 */
    public static function setting(float $temperature): self
    {
        if (! is_finite($temperature) || $temperature < 0 || $temperature > 2) {
            throw new InvalidArgumentException('设定生成温度必须在0至2之间');
        }

        return new self(4096, 45, $temperature, true);
    }

    /** 冻结大纲单批范围和调用边界，不允许任意扩展 Schema 或批次大小。 */
    public static function outline(float $temperature, int $start, int $end, int $total, int $timeoutSeconds = 60): self
    {
        self::setting($temperature);
        if (! in_array($timeoutSeconds, [60, 180], true) || $start < 1 || $end < $start || $end > $total || $end - $start >= 10) {
            throw new InvalidArgumentException('大纲批次范围不合法');
        }

        return new self(8192, $timeoutSeconds, $temperature, false, ['start' => $start, 'end' => $end, 'total' => $total]);
    }

    /** 区分结构化文本与原有短文本，不改变 B02 的布尔兼容属性。 */
    public function isStructured(): bool
    {
        return $this->structuredSetting || $this->outlineRange !== null || $this->chapterStep !== null || $this->submissionPurpose !== null;
    }

    /** 保存实际非敏感参数，默认请求保持原有三字段结构。
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $parameters = ['max_output_tokens' => $this->maxOutputTokens, 'timeout_seconds' => $this->timeoutSeconds, 'attempt_limit' => 1];

        if ($this->settingDecision) {
            return [...$parameters, 'temperature' => 0.0, 'schema_version' => 'b10-setting-decision-v1', 'strict' => true];
        }

        if ($this->submissionPurpose !== null) {
            return [...$parameters, 'temperature' => 0.0, 'schema_version' => SubmissionContract::VERSION, 'purpose' => $this->submissionPurpose,
                'submission_run_id' => $this->submissionContext['run_id'], 'version_id' => $this->submissionContext['version_id'], 'body_hash' => $this->submissionContext['body_hash'], 'strict' => true];
        }

        if ($this->chapterStep !== null) {
            return [...$parameters, 'temperature' => $this->temperature, 'schema_version' => $this->chapterVersion(), 'strict' => true, 'step' => $this->chapterStep];
        }

        if ($this->outlineRange !== null) {
            return [...$parameters, 'temperature' => $this->temperature, 'schema_version' => 'b03-outline-v1', 'strict' => true, 'range' => $this->outlineRange];
        }

        return $this->structuredSetting ? [...$parameters, 'temperature' => $this->temperature, 'schema_version' => 'b02-setting-v1', 'strict' => true] : $parameters;
    }
}
