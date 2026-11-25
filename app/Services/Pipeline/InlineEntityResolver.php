<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 把模型随引用给出的新实体定义确定性转换为待批准提议，不补写定义或批准实体。 */
final class InlineEntityResolver
{
    /** 每次解析独立建立映射；相同名称必须拥有一致定义，证据只合并真实段号。
     * 先按原遍历顺序收集全部内联定义，提议编号与单次遍历一致；模型在其他位置只写了已完整定义的名称时，按同一提议引用。
     *
     * @return array<string, mixed>
     */
    public function expand(array $data): array
    {
        ContentSchema::keys($data, ['events', 'patch']);
        ContentSchema::listing($data['events']);
        ContentSchema::listing($data['patch']);
        $proposals = [];
        $names = [];
        foreach ($data['events'] as $event) {
            ContentSchema::keys($event, ['key', 'event_type', 'narrative_order', 'actor_ref', 'target_refs', 'time_scope', 'fact_category', 'summary', 'evidence_ids']);
            ContentSchema::listing($event['target_refs']);
            foreach ([$event['actor_ref'], ...$event['target_refs']] as $reference) {
                $this->define($reference, $proposals, $names);
            }
        }
        foreach ($data['patch'] as $patch) {
            ContentSchema::keys($patch, ['entity_ref', 'property', 'value_ref', 'value_text', 'before_ref', 'fact_category', 'source_event_key']);
            foreach (['entity_ref', 'value_ref', 'before_ref'] as $field) {
                $this->define($patch[$field], $proposals, $names);
            }
        }
        // 一次列出全部无法解析的引用和改法，失败原因随重跑回填给模型，避免只看到停止代码而重复同一错误。
        $invalid = [];
        foreach ($data['events'] as $event) {
            foreach ([$event['actor_ref'], ...$event['target_refs']] as $reference) {
                $invalid[] = $this->invalidReference($reference, $names, false);
            }
        }
        foreach ($data['patch'] as $patch) {
            foreach (['entity_ref', 'value_ref', 'before_ref'] as $field) {
                $invalid[] = $this->invalidReference($patch[$field], $names, $field !== 'entity_ref');
            }
        }
        $invalid = array_values(array_unique(array_filter($invalid, 'is_string')));
        if ($invalid !== []) {
            throw new SubmissionRejection('INLINE_REFERENCE_INVALID', '以下引用既不是entity_catalog中的entity-N编号，也没有在本次输出任何位置给出完整定义：「'.implode('」「', $invalid)
                .'」。entity_catalog之外的新人物、地点、物品（包括章纲中出现但不在entity_catalog里的），必须在首次引用处写完整对象{type,name,aliases,description,core,evidence_ids}；之后可重复同一对象，或只写同一名称。');
        }
        foreach ($data['events'] as &$event) {
            $event['actor_ref'] = $this->reference($event['actor_ref'], $proposals, $names);
            foreach ($event['target_refs'] as &$reference) {
                $reference = $this->reference($reference, $proposals, $names);
            }
            unset($reference);
        }
        unset($event);
        foreach ($data['patch'] as &$patch) {
            foreach (['entity_ref', 'value_ref', 'before_ref'] as $field) {
                $patch[$field] = $this->reference($patch[$field], $proposals, $names, $field !== 'entity_ref');
            }
        }
        unset($patch);

        return ['proposed_entities' => array_values($proposals), ...$data];
    }

    /** 只允许既有编号、完整新定义或本次输出中已完整定义的名称；模型不能给出没有定义的new-N或未定义的名称。
     * @param  array<string, array<string, mixed>>  $proposals
     * @param  array<string, string>  $names
     */
    private function reference(mixed $value, array &$proposals, array &$names, bool $allowEmpty = false): string
    {
        if (is_string($value)) {
            if (($allowEmpty && $value === '') || preg_match('/^entity-[1-9][0-9]*$/D', $value) === 1) {
                return $value;
            }
            $name = trim($value) === '' ? null : SubmissionValidator::name($value);
            if ($name !== null && isset($names[$name])) {
                return $names[$name];
            }
            throw new InvalidArgumentException('INLINE_REFERENCE_INVALID');
        }

        return $this->define($value, $proposals, $names);
    }

    /** 字符串引用无法解析时返回其原文（空引用标为“空引用”），可解析或为对象时返回 null。
     * @param  array<string, string>  $names
     */
    private function invalidReference(mixed $value, array $names, bool $allowEmpty): ?string
    {
        if (! is_string($value) || ($allowEmpty && $value === '') || preg_match('/^entity-[1-9][0-9]*$/D', $value) === 1) {
            return null;
        }
        if (trim($value) === '') {
            return '空引用';
        }

        return isset($names[SubmissionValidator::name($value)]) ? null : $value;
    }

    /** 完整新定义转为待批准提议；同名定义必须一致，证据只合并真实段号。字符串引用留给第二遍解析。
     * @param  array<string, array<string, mixed>>  $proposals
     * @param  array<string, string>  $names
     */
    private function define(mixed $value, array &$proposals, array &$names): ?string
    {
        if (is_string($value)) {
            return null;
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
