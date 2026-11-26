<?php

namespace Tests\Unit;

use App\Services\Ai\SubmissionAgent;
use App\Services\Pipeline\ContentExecutionContract;
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
        return ['open_with_id' => [['action' => 'open', 'item_id' => 'open-7-1', 'claim' => '待核', 'evidence_ids' => ['p1']]],
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

    /** 细纲、正文、修订与检查只在冻结台账时追加约束，检查把违反记入连续性问题；评审列为阻断。 */
    public function test_instructions_follow_frozen_ledger_per_step(): void
    {
        $budget = ['slots' => ['scene_1'], 'min' => 1000, 'max' => 1200];
        $draft = ContentExecutionContract::outputInstructions('draft', ['budget' => $budget, 'open_items' => true]);
        $this->assertStringContainsString('open_items.pending是前文尚未核实的事项', $draft);
        $this->assertStringNotContainsString('open_items', ContentExecutionContract::outputInstructions('draft', ['budget' => $budget]));
        $this->assertStringContainsString('记入continuity_issues', ContentExecutionContract::outputInstructions('check', ['open_items' => true]));
        $this->assertStringContainsString('open_items.pending是前文尚未核实的事项', ContentExecutionContract::outputInstructions('revise', ['open_items' => true, 'anchors' => ['p1' => [], 'p2' => []]]));
        $this->assertStringContainsString('rule类blocking问题', SubmissionAgent::openItemInstructions('review'));
        $this->assertStringContainsString('每章新开不超过5项', SubmissionAgent::openItemInstructions('extract'));
    }
}
