<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 仅转换新协议的事件内状态变化，来源由包含关系确定；旧失败补丁不进入该转换。 */
final class EventChangeResolver
{
    /** 保留模型给出的变化内容、事实类别和前置状态，不生成额外事实。
     * @return array<string, mixed>
     */
    public function expand(array $data, array $priorState = []): array
    {
        ContentSchema::keys($data, ['events']);
        ContentSchema::listing($data['events']);
        $patches = [];
        foreach ($data['events'] as &$event) {
            ContentSchema::keys($event, ['key', 'event_type', 'narrative_order', 'actor_ref', 'target_refs', 'time_scope', 'fact_category', 'summary', 'evidence_ids', 'state_changes']);
            ContentSchema::listing($event['state_changes']);
            foreach ($event['state_changes'] as $change) {
                ContentSchema::keys($change, ['entity_ref', 'property', 'value_ref', 'value_text', 'fact_category']);
                if (! in_array($change['property'], ['location', 'item_owner', 'life_status'], true)) {
                    throw new InvalidArgumentException('EVENT_CHANGE_UNSUPPORTED');
                }
                $patches[] = [...$change, 'before_ref' => '', 'source_event_key' => $event['key']];
            }
            unset($event['state_changes']);
        }
        unset($event);
        $normalized = (new InlineEntityResolver)->expand(['events' => $data['events'], 'patch' => $patches]);
        // 前置值来自冻结投影和本轮先前变化，不让模型猜测数据库状态；提交仍检查状态版本。
        foreach ($normalized['patch'] as &$patch) {
            if (in_array($patch['property'], ['location', 'item_owner'], true)) {
                $patch['before_ref'] = $priorState['entities'][$patch['entity_ref']][$patch['property']]['value_ref'] ?? '';
                $priorState['entities'][$patch['entity_ref']][$patch['property']] = ['value_ref' => $patch['value_ref']];
            }
        }
        unset($patch);
        // 状态变化本来就是模型事件的一部分，其涉及实体统一进入该事件的参与者集合。
        foreach ($normalized['events'] as &$event) {
            foreach ($normalized['patch'] as $patch) {
                if ($patch['source_event_key'] !== $event['key']) {
                    continue;
                }
                foreach ([$patch['entity_ref'], $patch['value_ref']] as $reference) {
                    if ($reference !== '' && $reference !== $event['actor_ref'] && ! in_array($reference, $event['target_refs'], true)) {
                        $event['target_refs'][] = $reference;
                    }
                }
            }
        }
        unset($event);

        return $normalized;
    }
}
