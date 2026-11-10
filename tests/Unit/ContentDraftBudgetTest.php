<?php

namespace Tests\Unit;

use App\Services\Pipeline\ContentExecutionContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** 字数反馈必须可用于后继修复，不能放宽冻结的单段限制。 */
class ContentDraftBudgetTest extends TestCase
{
    /** 同时报告过短与过长的槽位，计数包含中文标点。 */
    public function test_reports_all_failed_paragraph_lengths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('p1实际2字，要求3至4字且不得含空白或Markdown；p2实际5字，要求3至4字且不得含空白或Markdown');
        ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['p1' => '短。', 'p2' => '这一段长。']], ['slots' => ['p1', 'p2'], 'min' => 3, 'max' => 4]);
    }

    /** 合法边界原样组装，程序不补字、不截断、不改正文。 */
    public function test_accepts_exact_bounds_without_modifying_prose(): void
    {
        $data = ContentExecutionContract::body(['title' => '标题', 'paragraphs' => ['p1' => '她来了。', 'p2' => '走吧。']], ['slots' => ['p1', 'p2'], 'min' => 3, 'max' => 4, 'complete_paragraphs' => true]);
        $this->assertSame("她来了。\n走吧。", $data['body']);
    }

    /** 单段恢复替换合法槽位且逐字保留其余正文。 */
    public function test_partial_repair_preserves_other_slots_and_budget(): void
    {
        $budget = ['slots' => ['p1', 'p2'], 'min' => 3, 'max' => 4, 'complete_paragraphs' => true];
        $draft = ['title' => '标题', 'paragraphs' => ['p1' => '她来了。', 'p2' => '走。']];
        $result = ContentExecutionContract::mergeParagraphRepair($draft, ['title' => '标题', 'paragraphs' => ['p2' => '走吧。']], $budget, 'p2');
        $this->assertSame($draft['paragraphs']['p1'], $result['paragraphs']['p1']);
        $this->assertSame('走吧。', $result['paragraphs']['p2']);
        $this->assertSame(3, ContentExecutionContract::repairBudget($budget, 'p2')['min']);
        $instructions = ContentExecutionContract::outputInstructions('draft', ['budget' => ['slots' => ['p2'], 'min' => 344, 'max' => 406], 'draft_repair_slot' => 'p2']);
        $this->assertStringContainsString('每段约375字', $instructions);
        $this->assertStringContainsString('344至406', $instructions);
        $this->expectException(InvalidArgumentException::class);
        ContentExecutionContract::mergeParagraphRepair($draft, ['title' => '标题', 'paragraphs' => ['p2' => '走。']], $budget, 'p2');
    }
}
