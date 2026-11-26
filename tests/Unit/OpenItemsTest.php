<?php

namespace Tests\Unit;

use App\Services\Ai\SubmissionAgent;
use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\OpenItemLedger;
use App\Services\Pipeline\SubmissionContract;
use App\Services\Pipeline\SubmissionValidator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 待核台账的提取结构、门禁与各步骤指令；旧运行完全不受影响。 */
class OpenItemsTest extends TestCase
{
    private const BODY = '沈禾把照片比对结果写进待核栏。';

    /** 只含一个观察事件的最小提取。
     * @return array<string, mixed>
     */
    private function extraction(array $openItems): array
    {
        return ['events' => [['key' => 'event-1', 'event_type' => 'observation', 'narrative_order' => 1, 'actor_ref' => 'entity-1', 'target_refs' => [], 'time_scope' => 'present',
            'fact_category' => 'objective_confirmed', 'summary' => '沈禾比对照片', 'evidence_ids' => ['p1'], 'state_changes' => []]], 'open_items' => $openItems];
    }

    /** 合法的新开与确认绑定原文证据；门禁拒绝目录外编号与同章重复结论。 */
    public function test_entries_bind_evidence_and_gate_rejects_unknown_or_duplicate(): void
    {
        $validator = new SubmissionValidator;
        $data = $validator->normalize($this->extraction([
            ['action' => 'open', 'item_id' => '', 'claim' => '照片里的人是谁待核', 'evidence_ids' => ['p1']],
            ['action' => 'confirm', 'item_id' => 'open-7-1', 'claim' => '笔迹确为外婆所写', 'evidence_ids' => ['p1']],
        ]), self::BODY);
        $this->assertSame(self::BODY, $data['open_items'][0]['evidence'][0]['quote']);
        $this->assertSame([], $validator->openItemErrors($data, ['open-7-1']));
        // 揭示不引用编号，门禁不检查目录。
        $reveal = $validator->normalize($this->extraction([['action' => 'reveal', 'item_id' => '', 'claim' => '外婆的调阅单日期是火灾前一周的星期三', 'evidence_ids' => ['p1']]]), self::BODY);
        $this->assertSame([], $validator->openItemErrors($reveal, []));
        $this->assertSame([['code' => 'OPEN_ITEM_UNKNOWN', 'reference' => 'open-7-1']], $validator->openItemErrors($data, []));

        $twice = $validator->normalize($this->extraction([
            ['action' => 'confirm', 'item_id' => 'open-7-1', 'claim' => '确认', 'evidence_ids' => ['p1']],
            ['action' => 'refute', 'item_id' => 'open-7-1', 'claim' => '推翻', 'evidence_ids' => ['p1']],
        ]), self::BODY);
        $this->assertSame([['code' => 'OPEN_ITEM_DUPLICATE', 'reference' => 'open-7-1']], $validator->openItemErrors($twice, ['open-7-1']));

        // 旧提取没有该字段时结果不含台账，门禁也不运行。
        $legacy = $this->extraction([]);
        unset($legacy['open_items']);
        $this->assertArrayNotHasKey('open_items', $validator->normalize($legacy, self::BODY));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformed(): array
    {
        return ['reveal_with_id' => [['action' => 'reveal', 'item_id' => 'open-7-1', 'claim' => '揭示', 'evidence_ids' => ['p1']]],
            'open_with_id' => [['action' => 'open', 'item_id' => 'open-7-1', 'claim' => '待核', 'evidence_ids' => ['p1']]],
            'confirm_without_id' => [['action' => 'confirm', 'item_id' => '', 'claim' => '确认', 'evidence_ids' => ['p1']]],
            'unknown_action' => [['action' => 'close', 'item_id' => 'open-7-1', 'claim' => '关闭', 'evidence_ids' => ['p1']]],
            'blank_claim' => [['action' => 'open', 'item_id' => '', 'claim' => ' ', 'evidence_ids' => ['p1']]],
            'extra_key' => [['action' => 'open', 'item_id' => '', 'claim' => '待核', 'evidence_ids' => ['p1'], 'note' => '多余']]];
    }

    /** @param  array<string, mixed>  $entry */
    #[DataProvider('malformed')]
    public function test_malformed_entries_are_rejected(array $entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SubmissionValidator)->normalize($this->extraction([$entry]), self::BODY);
    }

    /** 提取Schema只在冻结台账时增加字段，编号只能为空或取待核目录。 */
    public function test_schema_field_follows_frozen_ledger(): void
    {
        $anchors = ['p1' => ['text' => self::BODY]];
        $schema = SubmissionContract::schema(new JsonSchemaTypeFactory, 'extract', $anchors, [], [], ['open-7-1']);
        $this->assertSame(['', 'open-7-1'], $schema['open_items']->toArray()['items']['properties']['item_id']['enum']);
        $this->assertArrayNotHasKey('open_items', SubmissionContract::schema(new JsonSchemaTypeFactory, 'extract', $anchors));
    }

    /** 超过15章未被提起的待核项转为长期悬置；被重复报告后恢复跟进。 */
    public function test_items_go_dormant_after_fifteen_quiet_chapters_and_revive_when_reported(): void
    {
        // 相隔16章转入悬置，恰好15章仍在跟进；重复报告刷新最近提起的章节。
        $items = [['id' => 'open-1-1', 'opened_chapter' => 20], ['id' => 'open-2-1', 'opened_chapter' => 10, 'repeated_in' => [30]], ['id' => 'open-3-1', 'opened_chapter' => 21]];

        [$active, $dormant] = OpenItemLedger::splitDormant($items, 36);
        $this->assertSame(['open-2-1', 'open-3-1'], array_column($active, 'id'));
        $this->assertSame(1, $dormant);
        $this->assertSame(['open-1-1', 'open-2-1', 'open-3-1'], array_column(OpenItemLedger::splitDormant($items, 35)[0], 'id'));
    }

    /** 重复判定：措辞不同的同一问题达到阈值，只是同属一条线索的不同问题低于阈值；套话不抬高相似度。 */
    public function test_similarity_separates_rephrased_duplicates_from_related_items(): void
    {
        $same = OpenItemLedger::similarity('主管出示的合影复印件与第17章修复室夹出的合影裁口分毫不差、同属外婆当年交的附件，但被裁掉的那个人是谁，尚待核实。',
            '主管抽出的外婆旧材料中那张合影复印件与沈禾修复室夹出的合影裁口分毫不差，但被裁掉的那个人是谁、为何被裁，尚待核实。');
        $related = OpenItemLedger::similarity('母亲说外婆退休前带回的旧帆布包底下蹭出一块印着字的硬纸角，纸上写的是什么、是否与旧案相关尚待核实。',
            '母亲回忆外婆退休前把一叠纸锁进五斗橱上头带锁的抽屉、钥匙用布包着压在米缸底下，那叠纸是什么、是否与旧案相关，尚待核实。');
        $this->assertGreaterThanOrEqual(OpenItemLedger::DUPLICATE_THRESHOLD, $same);
        $this->assertLessThan(OpenItemLedger::DUPLICATE_THRESHOLD, $related);
        $this->assertSame(0.0, OpenItemLedger::similarity('尚待核实。', '是谁，尚待核实'));
    }

    /** 细纲、正文、修订与检查只在冻结台账时追加约束，检查把违反记入连续性问题；评审列为阻断。 */
    public function test_instructions_follow_frozen_ledger_per_step(): void
    {
        $budget = ['slots' => ['scene_1'], 'min' => 1000, 'max' => 1200];
        $draft = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget, 'open_items' => true]);
        $this->assertStringContainsString('open_items.pending是前文尚未核实的事项', $draft);
        $this->assertStringContainsString('不能写成重新发现', $draft);
        $this->assertStringContainsString('当作第一次发现重新演示', ContentExecutionContract::outputInstructions('check', ['open_items' => true]));
        $this->assertStringContainsString('open_items.revealed中前文已揭示的事实', SubmissionAgent::openItemInstructions('review'));
        $this->assertStringContainsString('reveal：本章首次揭示', SubmissionContract::instructions('ledger'));
        $this->assertStringNotContainsString('open_items', ContentExecutionContract::outputInstructions('draft', ['budget' => $budget]));
        $this->assertStringContainsString('记入continuity_issues', ContentExecutionContract::outputInstructions('check', ['open_items' => true]));
        $this->assertStringContainsString('open_items.pending是前文尚未核实的事项', ContentExecutionContract::outputInstructions('revise', ['open_items' => true, 'anchors' => ['p1' => [], 'p2' => []]]));
        $this->assertStringContainsString('rule类blocking问题', SubmissionAgent::openItemInstructions('review'));
        $this->assertStringContainsString('每章新开不超过5项', SubmissionAgent::openItemInstructions('extract'));
    }
}
