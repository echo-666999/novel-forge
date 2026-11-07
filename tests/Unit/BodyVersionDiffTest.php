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
}
