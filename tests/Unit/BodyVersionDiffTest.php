<?php

namespace Tests\Unit;

use App\Services\Pipeline\BodyVersionDiff;
use PHPUnit\Framework\TestCase;

/** Unicode与原始换行在全文区段对照中不丢失。 */
class BodyVersionDiffTest extends TestCase
{
    /** 同一组区段能无损重建两侧正文，含末尾无换行的场景。 */
    public function test_changed_sections_preserve_complete_before_and_after_text(): void
    {
        $before = "相同前文\r\n棉袄𠮷\n相同末尾";
        $after = "相同前文\r\n新棉袄😀\n相同末尾";
        $rows = BodyVersionDiff::compare($before, $after);
        $this->assertCount(4, $rows);
        $this->assertSame($before, $rows[0]['text'].$rows[1]['text'].$rows[3]['text']);
        $this->assertSame($after, $rows[0]['text'].$rows[2]['text'].$rows[3]['text']);
        $this->assertSame([['kind' => '相同全文', 'text' => $after]], BodyVersionDiff::compare($after, $after));
        $this->assertSame([['kind' => '新版区段（新增 / 替换）', 'text' => $after]], BodyVersionDiff::compare('', $after));
    }

    /** 段落对照：相隔很远的两处修改分别报告，中间未变化段落不被误标；段内只高亮变化文字，含多字节字符。 */
    public function test_paragraph_diff_reports_separate_changes_and_highlights_inside_paragraph(): void
    {
        $before = "第一段。\n第二段原文𠮷。\n第三段。\n第四段。\n第五段。\n第六段将被删除。";
        $after = "第一段。\n第二段改写😀。\n第三段。\n第四段。\n插入的新段。\n第五段。";
        $diff = BodyVersionDiff::paragraphs($before, $after);

        $this->assertFalse($diff['identical']);
        $this->assertSame(['modified' => 1, 'added' => 1, 'removed' => 1, 'unchanged' => 4], $diff['counts']);
        $changes = array_values(array_filter($diff['hunks'], fn (array $hunk): bool => $hunk['type'] === 'change'));
        $this->assertSame('modified', $changes[0]['kind']);
        $this->assertSame([['第二段', '原文𠮷', '。']], $changes[0]['old']);
        $this->assertSame([['第二段', '改写😀', '。']], $changes[0]['new']);
        $this->assertSame(['added', 5], [$changes[1]['kind'], $changes[1]['new_no']]);
        $this->assertSame(['removed', 6], [$changes[2]['kind'], $changes[2]['old_no']]);
        // 第三、四段作为一组未变化内容折叠。
        $this->assertSame([3, 4], array_column(array_values(array_filter($diff['hunks'], fn (array $hunk): bool => $hunk['type'] === 'same'))[1]['paragraphs'], 'no'));

        $same = BodyVersionDiff::paragraphs($after, $after);
        $this->assertTrue($same['identical']);
        $this->assertSame(6, $same['counts']['unchanged']);
    }
}
