<?php

namespace Tests\Unit;

use App\Services\Pipeline\ContentExecutionContract;
use PHPUnit\Framework\TestCase;

/** 到期伏笔写进细纲与正文首稿的系统指令；没有义务时指令不变。 */
class ForeshadowInstructionsTest extends TestCase
{
    /** 只有 must_address=true 的伏笔成为义务，保留编号、标题与义务类型。 */
    public function test_only_must_address_items_become_obligations(): void
    {
        $context = ['items' => [
            ['id' => 12, 'title' => '修复室的旧照片', 'obligation' => 'overdue', 'must_address' => true, 'hidden_meaning' => '作者计划'],
            ['id' => 13, 'title' => '值班日志', 'obligation' => 'preserve', 'must_address' => false],
        ]];

        $this->assertSame([['id' => 12, 'title' => '修复室的旧照片', 'obligation' => 'overdue']], ContentExecutionContract::foreshadowObligations($context));
        $this->assertSame([], ContentExecutionContract::foreshadowObligations(null));
    }

    /** 细纲要求安排进场景并在 adjustments 记录；正文首稿要求写出；单场景修复与无义务时不追加。 */
    public function test_instructions_name_each_obligation_per_step(): void
    {
        $obligations = [['id' => 12, 'title' => '修复室的旧照片', 'obligation' => 'overdue'], ['id' => 10, 'title' => '缺口铜钥匙', 'obligation' => 'plant']];
        $budget = ['slots' => ['scene_1'], 'min' => 1000, 'max' => 1200];

        $fine = ContentExecutionContract::outputInstructions('fine', ['scene_range' => ['min' => 3, 'max' => 5], 'foreshadow_obligations' => $obligations]);
        $this->assertStringContainsString('#12「修复室的旧照片」（已逾期：必须在本章回收，不能再延后）', $fine);
        $this->assertStringContainsString('#10「缺口铜钥匙」（埋设', $fine);
        $this->assertStringContainsString('在adjustments中写一条“伏笔#编号→第N个场景', $fine);

        $draft = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget, 'foreshadow_obligations' => $obligations]);
        $this->assertStringContainsString('#12「修复室的旧照片」', $draft);
        $this->assertStringContainsString('只提到名字不算处理', $draft);

        $repair = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget, 'foreshadow_obligations' => $obligations, 'draft_repair_slot' => 'scene_1', 'repair_direction' => 'format']);
        $this->assertStringNotContainsString('本章必须处理的伏笔', $repair);
        $this->assertSame(ContentExecutionContract::outputInstructions('fine', ['scene_range' => ['min' => 3, 'max' => 5]]),
            ContentExecutionContract::outputInstructions('fine', ['scene_range' => ['min' => 3, 'max' => 5], 'foreshadow_obligations' => []]));
    }
}
