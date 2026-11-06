<?php

namespace Tests\Unit;

use App\Services\Pipeline\SubmissionContract;
use App\Services\Pipeline\SubmissionValidator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** B05硬约束直接使用正文证据验证，不访问模型或业务数据库。 */
class SubmissionValidatorTest extends TestCase
{
    /** 评审通过分支不允许阻塞项；本地仍拒绝绕过供应商Schema的矛盾响应。 */
    public function test_review_schema_and_local_guard_reject_pass_with_blocking(): void
    {
        $schema = SubmissionContract::schema(new JsonSchemaTypeFactory, 'review', ['p1' => ['text' => '林舟走进旧塔。']]);
        $pass = $schema['review']->toArray()['anyOf'][0]['properties'];
        $this->assertSame(['pass'], $pass['verdict']['enum']);
        $this->assertSame(['advisory'], $pass['findings']['items']['properties']['severity']['enum']);
        $this->expectExceptionMessage('REVIEW_INVALID');
        SubmissionContract::review(['review' => ['verdict' => 'pass', 'summary' => '自相矛盾响应', 'evidence_ids' => ['p1'],
            'findings' => [['kind' => 'structure', 'severity' => 'blocking', 'reason' => '错误标记', 'evidence_ids' => ['p1']]]]], '林舟走进旧塔。');
    }

    /** 供应商Schema同步本地属性约束，阻止本轮出现的错误字段组合。 */
    public function test_patch_schema_separates_subjective_and_reference_values(): void
    {
        $schema = SubmissionContract::schema(new JsonSchemaTypeFactory, 'extract', ['p1' => ['text' => '林舟走进旧塔。她相信信中线索。']]);
        $branches = [];
        foreach ($schema['events']->toArray()['items']['anyOf'] as $event) {
            array_push($branches, ...($event['properties']['state_changes']['items']['anyOf'] ?? []));
            if ($event['properties']['event_type']['enum'] === ['observation', 'statement', 'belief_change']) {
                $this->assertSame(0, $event['properties']['state_changes']['maxItems']);
            }
        }
        $this->assertCount(3, $branches);
        foreach ($branches as $branch) {
            $fields = $branch['properties'];
            $this->assertArrayNotHasKey('source_event_key', $fields);
            $property = $fields['property']['enum'][0];
            $this->assertFalse($branch['additionalProperties']);
            $this->assertNotContains($property, ['belief', 'knowledge']);
            if (in_array($property, ['location', 'item_owner'], true)) {
                $this->assertSame([''], $fields['value_text']['enum']);
                $this->assertSame($property === 'location' ? ['location'] : ['character', 'group'], $fields['value_ref']['anyOf'][0]['properties']['type']['enum']);
            } else {
                $this->assertSame(['alive', 'dead'], $fields['value_text']['enum']);
            }
        }
    }

    /** 新协议从事件包含关系生成补丁来源和参与者，重放仍受原验证器约束。 */
    public function test_nested_location_change_has_deterministic_source_and_participants(): void
    {
        $data = $this->extraction();
        $change = $data['patch'][0];
        unset($change['source_event_key'], $change['before_ref']);
        $event = $data['events'][0];
        $event['target_refs'] = [];
        $event['state_changes'] = [$change];
        $validator = new SubmissionValidator;
        $normalized = $validator->normalize(['events' => [$event]], '林舟走进旧塔。');
        $this->assertSame('event-1', $normalized['patch'][0]['source_event_key']);
        $this->assertSame(['entity-2'], $normalized['events'][0]['target_refs']);
        $this->assertSame('pass', $validator->validate($normalized, $this->catalog(), ['entities' => []])['verdict']);
        $prior = ['entities' => ['entity-1' => ['location' => ['value_ref' => 'entity-3']]]];
        $withPrior = $validator->normalize(['events' => [$event]], '林舟走进旧塔。', $prior);
        $this->assertSame('entity-3', $withPrior['patch'][0]['before_ref']);
        $this->assertSame('pass', $validator->validate($withPrior, $this->catalog(), $prior)['verdict']);
        $this->assertContains('PATCH_PRECONDITION_FAILED', array_column($validator->validate($withPrior, $this->catalog(), ['entities' => []])['errors'], 'code'));
        // 观察事件即使内嵌位置变化也不能放行，确保转换没有取消事实门禁。
        $event['event_type'] = 'observation';
        $invalid = $validator->normalize(['events' => [$event]], '林舟走进旧塔。');
        $this->assertContains('LOCATION_EVENT_REQUIRED', array_column($validator->validate($invalid, $this->catalog(), ['entities' => []])['errors'], 'code'));
        // 已保存的旧格式失败产物不自动补齐参与者。
        $data['events'][0]['target_refs'] = [];
        $legacy = $validator->normalize($data, '林舟走进旧塔。');
        $this->assertContains('PATCH_SOURCE_INVALID', array_column($validator->validate($legacy, $this->catalog(), ['entities' => []])['errors'], 'code'));
    }

    /** 新最小协议即使绕过供应商Schema，也不能把主观描述写成角色记忆。 */
    public function test_nested_subjective_projection_is_rejected_locally(): void
    {
        $event = $this->extraction()['events'][0];
        $event['event_type'] = 'belief_change';
        $event['state_changes'] = [['entity_ref' => 'entity-1', 'property' => 'belief', 'value_ref' => '',
            'value_text' => '她的世界改变了。', 'fact_category' => 'character_belief']];
        $this->expectExceptionMessage('EVENT_CHANGE_UNSUPPORTED');
        (new SubmissionValidator)->normalize(['events' => [$event]], '她的世界改变了。');
    }

    /** 新地点在事件和补丁中内联定义，映射为同一提议，未批准时只停在批准门禁。 */
    public function test_inline_location_definition_is_resolved_without_implicit_approval(): void
    {
        $data = $this->extraction();
        unset($data['proposed_entities']);
        $location = ['type' => 'location', 'name' => '旧塔', 'aliases' => [], 'description' => '林舟进入的旧塔', 'core' => false, 'evidence_ids' => ['p1']];
        $data['events'][0]['target_refs'] = [$location];
        $data['patch'][0]['value_ref'] = $location;
        $validator = new SubmissionValidator;
        $normalized = $validator->normalize($data, '林舟走进旧塔。');
        $this->assertCount(1, $normalized['proposed_entities']);
        $this->assertSame(['new-1'], $normalized['events'][0]['target_refs']);
        $this->assertSame('new-1', $normalized['patch'][0]['value_ref']);
        $catalog = ['entity-1' => $this->catalog()['entity-1']];
        $this->assertSame('requires_approval', $validator->validate($normalized, $catalog, ['entities' => []])['verdict']);
        $this->assertSame('pass', $validator->validate($normalized, $catalog, ['entities' => []], ['new-1'])['verdict']);
    }

    /** 新协议拒绝没有内联定义的模型编号，不能自动补造人物。 */
    public function test_inline_protocol_rejects_undefined_new_reference(): void
    {
        $data = $this->extraction();
        unset($data['proposed_entities']);
        $data['events'][0]['actor_ref'] = 'new-1';
        $this->expectExceptionMessage('INLINE_REFERENCE_INVALID');
        (new SubmissionValidator)->normalize($data, '林舟走进旧塔。');
    }

    /** 同名对象的不同定义不能静默覆盖或合并。 */
    public function test_inline_conflicting_definitions_are_rejected(): void
    {
        $data = $this->extraction();
        unset($data['proposed_entities']);
        $location = ['type' => 'location', 'name' => '旧塔', 'aliases' => [], 'description' => '旧塔', 'core' => false, 'evidence_ids' => ['p1']];
        $data['events'][0]['target_refs'] = [$location];
        $data['patch'][0]['value_ref'] = [...$location, 'description' => '另一个地点'];
        $this->expectExceptionMessage('INLINE_ENTITY_CONFLICT');
        (new SubmissionValidator)->normalize($data, '林舟走进旧塔。');
    }

    /** 人物到达地点的事件可重放位置，并保留可回查的原文区间。 */
    public function test_location_event_preserves_source_and_projects_location(): void
    {
        $body = '林舟走进旧塔。';
        $validator = new SubmissionValidator;
        $data = $validator->normalize($this->extraction(), $body);
        $result = $validator->validate($data, $this->catalog(), ['entities' => []]);
        $this->assertSame('pass', $result['verdict']);
        $this->assertSame('entity-2', $result['state']['entities']['entity-1']['location']['value_ref']);
        $evidence = $data['events'][0]['evidence'][0];
        $this->assertSame($body, mb_substr($body, $evidence['start'], $evidence['end'] - $evidence['start']));
    }

    /** 未定义人物、地点及不符前置位置均须返回明确阻塞码。 */
    #[DataProvider('invalidReferences')]
    public function test_invalid_reference_is_blocked(string $field, string $reference, string $code): void
    {
        $data = $this->extraction();
        if ($field === 'actor_ref') {
            $data['events'][0][$field] = $reference;
        } elseif ($field === 'target_refs') {
            $data['events'][0][$field] = [$reference];
        } else {
            $data['patch'][0][$field] = $reference;
        }
        $validator = new SubmissionValidator;
        $result = $validator->validate($validator->normalize($data, '林舟走进旧塔。'), $this->catalog(), ['entities' => []]);
        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains($code, array_column($result['errors'], 'code'));
    }

    /** 分别覆盖事件引用、补丁目标和状态前置条件。
     * @return array<string, array{string, string, string}>
     */
    public static function invalidReferences(): array
    {
        return [
            '未知人物' => ['actor_ref', 'entity-99', 'UNKNOWN_ENTITY'],
            '未知地点' => ['target_refs', 'entity-99', 'UNKNOWN_ENTITY'],
            '未知状态实体' => ['entity_ref', 'entity-99', 'PATCH_SOURCE_INVALID'],
            '未知位置值' => ['value_ref', 'entity-99', 'PATCH_VALUE_INVALID'],
            '错误位置类型' => ['value_ref', 'entity-1', 'PATCH_VALUE_INVALID'],
            '前置位置不符' => ['before_ref', 'entity-2', 'PATCH_PRECONDITION_FAILED'],
        ];
    }

    /** 非字符串事实类别属于无效提取响应，不能泄漏为PHP类型错误。 */
    #[DataProvider('invalidCategories')]
    public function test_non_string_fact_category_is_rejected(mixed $category): void
    {
        $data = $this->extraction();
        $data['events'][0]['fact_category'] = $category;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EVENT_INVALID');
        (new SubmissionValidator)->normalize($data, '林舟走进旧塔。');
    }

    /** 覆盖JSON可表达的所有非字符串类别。
     * @return array<string, array{mixed}>
     */
    public static function invalidCategories(): array
    {
        return ['数组' => [[]], '空值' => [null], '整数' => [1], '浮点数' => [1.5], '布尔值' => [true]];
    }

    /** 不相邻证据段不能拼成正文不存在的连续信念；单段及相邻原文仍可通过。 */
    public function test_belief_requires_continuous_evidence_in_one_paragraph(): void
    {
        $body = "林舟相信旧塔安全。\n守卫立即否认。\n他觉得访客可靠。";
        $data = $this->extraction();
        $data['events'][0]['event_type'] = 'belief_change';
        $data['events'][0]['fact_category'] = 'character_belief';
        $data['events'][0]['evidence_ids'] = ['p1', 'p3'];
        $data['patch'][0] = ['entity_ref' => 'entity-1', 'property' => 'belief', 'value_ref' => '',
            'value_text' => "林舟相信旧塔安全。\n他觉得访客可靠。", 'before_ref' => '', 'fact_category' => 'character_belief', 'source_event_key' => 'event-1'];
        $validator = new SubmissionValidator;
        $data = $validator->normalize($data, $body);
        $result = $validator->validate($data, $this->catalog(), ['entities' => []]);
        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('FACT_CATEGORY_VIOLATION', array_column($result['errors'], 'code'));
        $data['patch'][0]['value_text'] = '他觉得访客可靠。';
        $this->assertSame('pass', $validator->validate($data, $this->catalog(), ['entities' => []])['verdict']);
        $data['events'][0]['evidence'] = SubmissionContract::evidence(['p1', 'p2', 'p3'], $body);
        $data['patch'][0]['value_text'] = $body;
        $this->assertSame('pass', $validator->validate($data, $this->catalog(), ['entities' => []])['verdict']);
    }

    /** 回忆与预测中的到达不能改写当前所在位置。 */
    #[DataProvider('nonPresentScopes')]
    public function test_non_present_event_cannot_change_current_location(string $scope): void
    {
        $data = $this->extraction();
        $data['events'][0]['time_scope'] = $scope;
        $validator = new SubmissionValidator;
        $result = $validator->validate($validator->normalize($data, '林舟走进旧塔。'), $this->catalog(), ['entities' => []]);
        $this->assertSame('blocked', $result['verdict']);
        $this->assertContains('PATCH_PRECONDITION_FAILED', array_column($result['errors'], 'code'));
    }

    /** 非当前时序分别验证。
     * @return list<array{string}>
     */
    public static function nonPresentScopes(): array
    {
        return [['memory'], ['prediction']];
    }

    /** 提取器只能选择正文实际存在的证据段。 */
    public function test_unknown_evidence_is_rejected(): void
    {
        $data = $this->extraction();
        $data['events'][0]['evidence_ids'] = ['p99'];
        $this->expectException(InvalidArgumentException::class);
        (new SubmissionValidator)->normalize($data, '林舟走进旧塔。');
    }

    /** 固定人物和地点目录，不把测试定义写入正式实体。
     * @return array<string, array{name: string, type: string, aliases: list<string>}>
     */
    private function catalog(): array
    {
        return ['entity-1' => ['name' => '林舟', 'type' => 'character', 'aliases' => []],
            'entity-2' => ['name' => '旧塔', 'type' => 'location', 'aliases' => []]];
    }

    /** 使用人物、地点、行动与状态补丁齐全的最小响应。
     * @return array<string, mixed>
     */
    private function extraction(): array
    {
        return ['proposed_entities' => [], 'events' => [['key' => 'event-1', 'event_type' => 'location_change',
            'narrative_order' => 1, 'actor_ref' => 'entity-1', 'target_refs' => ['entity-2'], 'time_scope' => 'present',
            'fact_category' => 'objective_confirmed', 'summary' => '林舟走进旧塔', 'evidence_ids' => ['p1']]],
            'patch' => [['entity_ref' => 'entity-1', 'property' => 'location', 'value_ref' => 'entity-2', 'value_text' => '',
                'before_ref' => '', 'fact_category' => 'objective_confirmed', 'source_event_key' => 'event-1']]];
    }
}
