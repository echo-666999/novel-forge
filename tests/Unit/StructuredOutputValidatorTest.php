<?php

namespace Tests\Unit;

use App\Services\Ai\StructuredOutputValidator;
use PHPUnit\Framework\TestCase;

/** 统一结构校验：结构问题与文本形态问题分开归类，说明不回显正文。 */
class StructuredOutputValidatorTest extends TestCase
{
    /** 含 SDK 发给 Claude 前会剥离的约束（条数上限、数值范围、正则）与 DeepSeek 不保证的结构约束。
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['items', 'score', 'kind', 'text'], 'properties' => [
            'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => ['type' => 'string']],
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'kind' => ['type' => 'string', 'enum' => ['narration', 'dialogue']],
            'text' => ['type' => 'string', 'pattern' => '^[^\\s#*]+$'],
            'meta' => ['type' => 'object', 'properties' => (object) []],
        ]];
    }

    /** anyOf 备选：字符串分支只是枚举不符时按取值问题，不因对象分支“类型不符”误判为形状问题；两个分支形状都不符才算形状问题。 */
    public function test_any_of_alternatives_are_not_misread_as_shape_problems(): void
    {
        $schema = ['type' => 'object', 'required' => ['ref'], 'properties' => ['ref' => ['anyOf' => [
            ['type' => 'string', 'enum' => ['entity-1']],
            ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        ]]]];
        $validator = new StructuredOutputValidator;
        $this->assertSame(['shape' => [], 'values' => ['/ref：取值不在允许范围内']], $validator->validate('{"ref":"entity-999"}', $schema));
        $this->assertSame([], $validator->validate('{"ref":{"name":"新人物"}}', $schema)['shape']);
        $this->assertNotSame([], $validator->validate('{"ref":5}', $schema)['shape']);
    }

    /** 合格输出没有任何问题；空对象按对象而不是数组校验。 */
    public function test_valid_output_passes(): void
    {
        $this->assertSame(['shape' => [], 'values' => []], (new StructuredOutputValidator)->validate('{"items":["甲"],"score":3,"kind":"dialogue","text":"正文。","meta":{}}', self::schema()));
    }

    /** 多余字段、类型算形状问题（统一拒绝）；条数、数值、枚举、正则算取值问题（交业务契约）；不是JSON直接算形状问题。 */
    public function test_structural_and_text_problems_are_separated(): void
    {
        $found = (new StructuredOutputValidator)->validate('{"items":["甲","乙","丙"],"score":9,"kind":"旁白","text":"带 空格","extra":1,"meta":[]}', self::schema());
        $this->assertEqualsCanonicalizing(['/：出现未约定的字段 extra', '/meta：类型应为 object，实际为 array'], $found['shape']);
        $this->assertEqualsCanonicalizing(['/items：条目多于 2 条', '/score：数值超过上限 5', '/kind：取值不在允许范围内', '/text：文本形态不符合约定（篇幅、分段或标点）'], $found['values']);
        $this->assertStringNotContainsString('带 空格', implode('', $found['values']));
        $this->assertSame(['/：不是有效的 JSON'], (new StructuredOutputValidator)->validate('{"items":', self::schema())['shape']);
    }
}
