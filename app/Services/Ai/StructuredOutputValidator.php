<?php

namespace App\Services\Ai;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/** 用发出的完整结构定义校验结构化输出，与供应商无关：
 * OpenAI 严格模式、Claude（SDK 会剥离长度/格式/条数等约束）与 DeepSeek（只保证 JSON 语法）因此受同一套标准约束。
 */
final class StructuredOutputValidator
{
    /** 形状约束：类型、必填、未约定字段。任何业务路径都无法使用形状不对的输出，在请求层统一拒绝。
     * 其余取值约束（枚举、条数、数值范围、长度、正则）各业务契约有更细的处理（丢弃无效范围、同轮重试、证据无效反馈、按段修复），
     * 只统一校验并留痕，交由业务契约裁决。
     */
    public const SHAPE_KEYWORDS = ['type', 'required', 'additionalProperties'];

    /** 最多收集的问题数，足够定位又不让错误信息过长。 */
    private const MAX_ERRORS = 20;

    /** 校验结果：shape 为必须拒绝的形状问题；values 为交由业务契约裁决的取值问题。均为“路径：说明”。
     * @param  string  $json  供应商返回的 JSON 原文（规范化后）；对象保持对象，避免空对象被当成数组。
     * @param  array<string, mixed>  $schema
     * @return array{shape: list<string>, values: list<string>}
     */
    public function validate(string $json, array $schema): array
    {
        $data = json_decode($json, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['shape' => ['/：不是有效的 JSON'], 'values' => []];
        }
        $validator = new Validator(max_errors: self::MAX_ERRORS, stop_at_first_error: false);
        $error = $validator->validate($data, Helper::toJSON($schema))->error();
        $found = $error === null ? ['shape' => [], 'values' => []] : self::classify($error);

        return ['shape' => array_values(array_unique($found['shape'])), 'values' => array_values(array_unique($found['values']))];
    }

    /** 展开到最底层原因并归类。anyOf/oneOf 的各分支是备选：只要有一个分支形状吻合、仅取值不符，整体按取值问题处理，
     * 不把其他分支的“类型不符”误当形状问题；所有分支形状都不吻合时，取形状问题最少的分支。
     *
     * @return array{shape: list<string>, values: list<string>}
     */
    private static function classify(ValidationError $error): array
    {
        $children = $error->subErrors();
        if ($children === []) {
            return in_array($error->keyword(), self::SHAPE_KEYWORDS, true) ? ['shape' => [self::describe($error)], 'values' => []] : ['shape' => [], 'values' => [self::describe($error)]];
        }
        $branches = array_map(self::classify(...), $children);
        if (in_array($error->keyword(), ['anyOf', 'oneOf'], true)) {
            usort($branches, fn (array $a, array $b): int => count($a['shape']) <=> count($b['shape']));

            return $branches[0];
        }

        return ['shape' => array_merge(...array_column($branches, 'shape')), 'values' => array_merge(...array_column($branches, 'values'))];
    }

    /** 中文说明：位置 + 违反的约束，不回显正文内容。 */
    private static function describe(ValidationError $error): string
    {
        $path = '/'.implode('/', $error->data()->fullPath());
        $args = $error->args();
        $reason = match ($error->keyword()) {
            'required' => '缺少字段 '.implode('、', (array) ($args['missing'] ?? [])),
            'additionalProperties' => '出现未约定的字段 '.implode('、', (array) ($args['properties'] ?? [])),
            'type' => '类型应为 '.implode('/', (array) ($args['expected'] ?? [])).'，实际为 '.($args['type'] ?? '未知'),
            'enum', 'const' => '取值不在允许范围内',
            'minItems' => '条目少于 '.($args['min'] ?? '?').' 条',
            'maxItems' => '条目多于 '.($args['max'] ?? '?').' 条',
            'minimum', 'exclusiveMinimum' => '数值小于下限 '.($args['min'] ?? '?'),
            'maximum', 'exclusiveMaximum' => '数值超过上限 '.($args['max'] ?? '?'),
            'minLength' => '文本短于 '.($args['min'] ?? '?').' 个字符',
            'maxLength' => '文本长于 '.($args['max'] ?? '?').' 个字符',
            'pattern' => '文本形态不符合约定（篇幅、分段或标点）',
            default => '不满足约束 '.$error->keyword(),
        };

        return $path.'：'.$reason;
    }
}
