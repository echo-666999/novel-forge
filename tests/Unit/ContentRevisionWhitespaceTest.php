<?php

namespace Tests\Unit;

use App\Services\Pipeline\ContentContinuationContract;
use App\Services\Pipeline\ContentExecutionContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** 防止供应商只改变排版却声称完成文学修订。 */
class ContentRevisionWhitespaceTest extends TestCase
{
    /** 空白分隔不得误判残句，真实残句仍使用原始行段号报告。 */
    public function test_blank_lines_are_not_incomplete_sentences(): void
    {
        $this->assertSame([], ContentExecutionContract::incompleteParagraphs("甲来了。\n\n乙离开。"));
        $this->assertSame([['paragraph' => 3, 'text' => '乙走到']], ContentExecutionContract::incompleteParagraphs("甲来了。\n\n乙走到"));
        $this->assertFalse(ContentExecutionContract::hasCompleteEnding(''));
    }

    /** 增加空行、空格及零宽字符不构成真实改写。 */
    public function test_format_only_revision_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('所有范围替换均未产生变化');
        ContentContinuationContract::revise(['ranges' => [['start_id' => 'p2', 'end_id' => 'p3', 'text' => "乙。\n\n 丙。\u{200B}", 'reason' => '声称已改善', 'addresses' => ['issue']]]], "甲。\n乙。\n丙。", ['issue' => []]);
    }

    /** 内容真正改变时允许调整段落，并逐字保留范围外原文。 */
    public function test_real_change_preserves_outside_text(): void
    {
        $actual = ContentContinuationContract::revise(['ranges' => [['start_id' => 'p2', 'end_id' => 'p3', 'text' => "乙拒绝。\n\n丙离开。", 'reason' => '增加选择后果', 'addresses' => ['issue']]]], "甲。\n乙。\n丙。", ['issue' => []]);
        $this->assertSame("甲。\n乙拒绝。\n\n丙离开。", $actual);
    }
}
