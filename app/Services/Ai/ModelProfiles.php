<?php

namespace App\Services\Ai;

/** 模型配置的唯一读取入口：供应商路由、可选模型和能力都来自 config/ai.php 的 models。 */
final class ModelProfiles
{
    /** 返回模型的完整配置；未登记的模型按名称前缀识别供应商，且不具备任何扩展能力。
     * @return array{model: string, label: string, provider: string, selectable: bool, extended_output_tokens: ?int, review_reasoning: ?string, slot_repair: bool}
     */
    public static function profile(string $model): array
    {
        $model = trim($model);
        // 模型标识常含点号（如 gpt-4.1-mini），不能用点号路径读取配置。
        $configured = ((array) config('ai.models', []))[$model] ?? null;
        $profile = is_array($configured) ? $configured : [];

        return [
            'model' => $model,
            'label' => (string) ($profile['label'] ?? $model),
            'provider' => (string) ($profile['provider'] ?? (str_starts_with($model, 'deepseek-') ? 'deepseek' : 'openai')),
            'selectable' => (bool) ($profile['selectable'] ?? false),
            'extended_output_tokens' => isset($profile['extended_output_tokens']) ? (int) $profile['extended_output_tokens'] : null,
            'review_reasoning' => isset($profile['review_reasoning']) ? (string) $profile['review_reasoning'] : null,
            'slot_repair' => (bool) ($profile['slot_repair'] ?? false),
        ];
    }

    /** 真实请求的供应商。 */
    public static function provider(string $model): string
    {
        return self::profile($model)['provider'];
    }

    /** 后台可选择的模型，键为模型标识；当前值不在列表中时一并列出，避免旧设置无法保存。
     * @return array<string, string>
     */
    public static function options(?string $current = null): array
    {
        $options = [];
        foreach (array_keys((array) config('ai.models', [])) as $model) {
            $profile = self::profile($model);
            if ($profile['selectable']) {
                $options[$model] = $profile['label'].'（'.$model.'）';
            }
        }
        if (filled($current) && ! isset($options[$current])) {
            $options[$current] = self::profile($current)['label'].'（'.$current.'，未列入可选模型）';
        }

        return $options;
    }

    /** 新运行使用的扩展输出容量；未配置时返回空，调用方保持8192。 */
    public static function extendedOutputTokens(string $model): ?int
    {
        return self::profile($model)['extended_output_tokens'];
    }
}
