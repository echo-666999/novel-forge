<?php

namespace Tests\Unit;

use App\Services\Ai\DeepSeekJsonOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DeepSeek JSON 规范化只做可审计的机械修复，不改动根对象内容。 */
class DeepSeekJsonOutputTest extends TestCase
{
    /** 完整根对象之后的多余闭合括号被移除并记录偏移，字符串内的括号不受影响。 */
    public function test_trailing_brackets_after_complete_root_are_removed_with_offsets(): void
    {
        $result = DeepSeekJsonOutput::normalize('{"answers":["结局}保持]"]}}');
        $this->assertSame('{"answers":["结局}保持]"]}', $result['text']);
        $this->assertSame([strlen('{"answers":["结局}保持]"]}')], $result['removed_trailing_offsets']);
        $this->assertSame([], $result['removed_comma_offsets']);
    }

    /** 尾逗号与多余括号可以同时出现，两类偏移分别记录。 */
    public function test_comma_and_bracket_repairs_are_recorded_separately(): void
    {
        $result = DeepSeekJsonOutput::normalize('{"a":[1,]}}');
        $this->assertSame('{"a":[1]}', $result['text']);
        $this->assertSame([7], $result['removed_comma_offsets']);
        $this->assertSame([9], $result['removed_trailing_offsets']);
    }

    /** 截断、根对象后还有其他文字、缺少闭合等情况保持原文，交由适配器拒绝。
     * @return array<string, array{string}>
     */
    public static function rejected(): array
    {
        return ['截断' => ['{"a":1'], '尾随文字' => ['{"a":1}说明'], '缺少括号' => ['{"a":{"b":1}'], '多余引号' => ['{"a":"x""}']];
    }

    /** 无法机械修复的响应不做任何修改。 */
    #[DataProvider('rejected')]
    public function test_unrepairable_responses_are_left_unchanged(string $text): void
    {
        $result = DeepSeekJsonOutput::normalize($text);
        $this->assertSame($text, $result['text']);
        $this->assertSame([], $result['removed_trailing_offsets']);
        $this->assertFalse(json_validate($result['text']));
    }
}
