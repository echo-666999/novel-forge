<?php

namespace Tests\Unit;

use App\Services\Pipeline\ParagraphDiff;
use PHPUnit\Framework\TestCase;

/** 按段对比正文：未改动段折叠，改写段只高亮中间不同部分。 */
class ParagraphDiffTest extends TestCase
{
    /** 改写、删除、新增与未改动各自归类。 */
    public function test_compare_groups_paragraph_changes(): void
    {
        $rows = ParagraphDiff::compare("甲段。\n乙段原文。\n丙段。\n丁段。", "甲段。\n乙段新文。\n丙段。\n戊段。\n己段。");

        $this->assertSame(['unchanged', 'changed', 'unchanged', 'changed', 'added'], array_column($rows, 'type'));
        $this->assertSame(['乙段', '原', '文。'], $rows[1]['old']);
        $this->assertSame(['乙段', '新', '文。'], $rows[1]['new']);
        $this->assertSame('己段。', $rows[4]['text']);
        $this->assertSame(3, ParagraphDiff::changes($rows));
        $this->assertSame([['type' => 'unchanged', 'count' => 2]], ParagraphDiff::compare("一。\n二。", "一。\n\n二。"));
    }
}
