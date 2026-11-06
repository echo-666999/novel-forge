<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 把模型随引用给出的新实体定义确定性转换为待批准提议，不补写定义或批准实体。 */
final class InlineEntityResolver
{
    /** 每次解析独立建立映射；相同名称必须拥有一致定义，证据只合并真实段号。
     * @return array<string, mixed>
     */
    public function expand(array $data): array
    {
        ContentSchema::keys($data, ['events', 'patch']);
        ContentSchema::listing($data['events']);
        ContentSchema::listing($data['patch']);
        $proposals = [];
        $names = [];
        foreach ($data['events'] as &$event) {
            ContentSchema::keys($event, ['key', 'event_type', 'narrative_order', 'actor_ref', 'target_refs', 'time_scope', 'fact_category', 'summary', 'evidence_ids']);
            ContentSchema::listing($event['target_refs']);
            $event['actor_ref'] = $this->reference($event['actor_ref'], $proposals, $names);
            foreach ($event['target_refs'] as &$reference) {
                $reference = $this->reference($reference, $proposals, $names);
            }
            unset($reference);
        }
        unset($event);
        foreach ($data['patch'] as &$patch) {
            ContentSchema::keys($patch, ['entity_ref', 'property', 'value_ref', 'value_text', 'before_ref', 'fact_category', 'source_event_key']);
            foreach (['entity_ref', 'value_ref', 'before_ref'] as $field) {
                $patch[$field] = $this->reference($patch[$field], $proposals, $names, $field !== 'entity_ref');
            }
        }
        unset($patch);

        return ['proposed_entities' => array_values($proposals), ...$data];
    }

    /** 只允许既有编号或完整新定义；模型不能给出没有定义的new-N。
     * @param  array<string, array<string, mixed>>  $proposals
     * @param  array<string, string>  $names
     */
    private function reference(mixed $value, array &$proposals, array &$names, bool $allowEmpty = false): string
    {
        if (is_string($value)) {
            if (($allowEmpty && $value === '') || preg_match('/^entity-[1-9][0-9]*$/D', $value) === 1) {
                return $value;
            }
            throw new InvalidArgumentException('INLINE_REFERENCE_INVALID');
        }
        ContentSchema::keys($value, ['type', 'name', 'aliases', 'description', 'core', 'evidence_ids']);
        ContentSchema::text($value['name']);
        ContentSchema::listing($value['evidence_ids']);
        $name = SubmissionValidator::name($value['name']);
        if (isset($names[$name])) {
            $key = $names[$name];
            $existing = $proposals[$key];
            unset($existing['key'], $existing['evidence_ids']);
            $definition = $value;
            unset($definition['evidence_ids']);
            if (PlanningJson::hash($existing) !== PlanningJson::hash($definition)) {
                throw new InvalidArgumentException('INLINE_ENTITY_CONFLICT');
            }
            $proposals[$key]['evidence_ids'] = array_values(array_unique([...$proposals[$key]['evidence_ids'], ...$value['evidence_ids']], SORT_REGULAR));

            return $key;
        }
        $key = 'new-'.(count($proposals) + 1);
        $names[$name] = $key;
        $proposals[$key] = ['key' => $key, ...$value];

        return $key;
    }
}
