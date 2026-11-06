<?php

namespace App\Services\Pipeline;

use App\Enums\FactCategory;
use InvalidArgumentException;

/** 确定性验证只处理明确支持的字段，模型语义判断不能绕过结构与事实类别。 */
final class SubmissionValidator
{
    /** 规范名称只消除空白和大小写，不凭相似度合并人物。 */
    public static function name(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($value)));
    }

    /** 验证提取结构并绑定真实原文，尚不批准新实体。
     * @return array<string, mixed>
     */
    public function normalize(array $data, string $body, array $priorState = []): array
    {
        // 新请求内联定义；历史提取仍按原结构复验，不重写旧响应。
        if (! array_key_exists('patch', $data)) {
            $data = (new EventChangeResolver)->expand($data, $priorState);
        } elseif (! array_key_exists('proposed_entities', $data)) {
            $data = (new InlineEntityResolver)->expand($data);
        }
        ContentSchema::keys($data, ['proposed_entities', 'events', 'patch']);
        foreach ($data as $items) {
            ContentSchema::listing($items);
        }
        if ($data['events'] === [] || count($data['events']) > 40 || count($data['proposed_entities']) > 30 || count($data['patch']) > 50) {
            throw new InvalidArgumentException('EXTRACTION_INVALID');
        }
        foreach ($data['proposed_entities'] as &$entity) {
            ContentSchema::keys($entity, ['key', 'type', 'name', 'aliases', 'description', 'core', 'evidence_ids']);
            foreach (['key', 'name', 'description'] as $field) {
                ContentSchema::text($entity[$field]);
            }
            if (preg_match('/^new-[1-9][0-9]*$/D', $entity['key']) !== 1 || ! in_array($entity['type'], SubmissionContract::ENTITY_TYPES, true) || ! is_bool($entity['core'])) {
                throw new InvalidArgumentException('ENTITY_DEFINITION_INVALID');
            }
            ContentSchema::listing($entity['aliases']);
            if (count($entity['aliases']) > 10) {
                throw new InvalidArgumentException('ENTITY_ALIASES_INVALID');
            }
            foreach ($entity['aliases'] as $alias) {
                ContentSchema::text($alias);
            }
            $entity['evidence'] = SubmissionContract::evidence($entity['evidence_ids'], $body);
        }
        unset($entity);
        foreach ($data['events'] as &$event) {
            ContentSchema::keys($event, ['key', 'event_type', 'narrative_order', 'actor_ref', 'target_refs', 'time_scope', 'fact_category', 'summary', 'evidence_ids']);
            foreach (['key', 'actor_ref', 'summary'] as $field) {
                ContentSchema::text($event[$field]);
            }
            if (preg_match('/^event-[1-9][0-9]*$/D', $event['key']) !== 1 || ! is_int($event['narrative_order']) || $event['narrative_order'] < 1
                || ! in_array($event['event_type'], SubmissionContract::EVENT_TYPES, true) || ! in_array($event['time_scope'], ['present', 'memory', 'prediction'], true)
                || ! is_string($event['fact_category']) || FactCategory::tryFrom($event['fact_category']) === null) {
                throw new InvalidArgumentException('EVENT_INVALID');
            }
            ContentSchema::listing($event['target_refs']);
            if (count($event['target_refs']) > 10 || count(array_unique($event['target_refs'], SORT_REGULAR)) !== count($event['target_refs'])) {
                throw new InvalidArgumentException('EVENT_TARGET_INVALID');
            }
            foreach ($event['target_refs'] as $ref) {
                ContentSchema::text($ref);
            }
            $event['evidence'] = SubmissionContract::evidence($event['evidence_ids'], $body);
        }
        unset($event);
        foreach ($data['patch'] as $patch) {
            ContentSchema::keys($patch, ['entity_ref', 'property', 'value_ref', 'value_text', 'before_ref', 'fact_category', 'source_event_key']);
            foreach ($patch as $value) {
                if (! is_string($value)) {
                    throw new InvalidArgumentException('PATCH_INVALID');
                }
            }
            if (! in_array($patch['property'], SubmissionContract::PROPERTIES, true) || FactCategory::tryFrom($patch['fact_category']) === null) {
                throw new InvalidArgumentException('PATCH_UNSUPPORTED');
            }
        }

        return $data;
    }

    /** 用冻结实体和前序状态重放最小补丁，未批准实体明确单列。
     * @param  list<string>  $approvedKeys  本次作者明确批准的提议编号
     * @return array{verdict: string, errors: list<array<string, mixed>>, approval_required: list<string>, state: array<string, mixed>}
     */
    public function validate(array $data, array $catalog, array $state, array $approvedKeys = []): array
    {
        $errors = [];
        $add = function (string $code, string $reference) use (&$errors): void {
            $errors[] = ['code' => $code, 'reference' => $reference];
        };
        $names = [];
        foreach ($catalog as $ref => $entity) {
            foreach ([$entity['name'], ...$entity['aliases']] as $name) {
                $normalized = self::name($name);
                if (isset($names[$normalized]) && $names[$normalized] !== $ref) {
                    $add('AMBIGUOUS_ENTITY', $name);
                }
                $names[$normalized] = $ref;
            }
        }
        $proposed = [];
        foreach ($data['proposed_entities'] as $entity) {
            if (isset($proposed[$entity['key']]) || isset($catalog[$entity['key']])) {
                $add('DUPLICATE_ENTITY', $entity['key']);
            }
            $proposed[$entity['key']] = $entity;
            foreach ([$entity['name'], ...$entity['aliases']] as $name) {
                $normalized = self::name($name);
                if ($normalized === '' || (isset($names[$normalized]) && $names[$normalized] !== $entity['key'])) {
                    $add('ENTITY_ALIAS_COLLISION', $name);
                }
                $names[$normalized] = $entity['key'];
            }
        }
        $all = [...$catalog, ...$proposed];
        if (array_diff($approvedKeys, array_keys($proposed)) !== [] || count(array_unique($approvedKeys)) !== count($approvedKeys)) {
            $add('APPROVAL_INVALID', 'approved_keys');
        }
        $events = [];
        $previous = 0;
        foreach ($data['events'] as $event) {
            if (isset($events[$event['key']]) || $event['narrative_order'] <= $previous) {
                $add('EVENT_ORDER_INVALID', $event['key']);
            }
            $previous = $event['narrative_order'];
            $events[$event['key']] = $event;
            foreach ([$event['actor_ref'], ...$event['target_refs']] as $ref) {
                if (! isset($all[$ref])) {
                    $add('UNKNOWN_ENTITY', $ref);
                }
            }
            if (($event['event_type'] === 'statement' && $event['fact_category'] === 'objective_confirmed') || $event['fact_category'] === 'author_hidden_truth') {
                $add('FACT_CATEGORY_VIOLATION', $event['key']);
            }
            if ($event['time_scope'] === 'present' && data_get($state, 'entities.'.$event['actor_ref'].'.life_status.value_text') === 'dead'
                && in_array($event['event_type'], ['action', 'location_change', 'acquisition', 'transfer'], true)) {
                $add('DEAD_ACTOR', $event['key']);
            }
        }
        $state['entities'] ??= [];
        $seen = [];
        $lastOrder = 0;
        foreach ($data['patch'] as $patch) {
            $ref = $patch['entity_ref'];
            $property = $patch['property'];
            $event = $events[$patch['source_event_key']] ?? null;
            if (! isset($all[$ref]) || $event === null) {
                $add('PATCH_SOURCE_INVALID', $ref);

                continue;
            }
            if ($event['narrative_order'] < $lastOrder || $event['time_scope'] !== 'present' || ! in_array($ref, [$event['actor_ref'], ...$event['target_refs']], true)) {
                $add('PATCH_PRECONDITION_FAILED', $ref);
            }
            $lastOrder = $event['narrative_order'];
            $key = $event['key'].':'.$ref.':'.$property;
            if (isset($seen[$key]) && ! in_array($property, ['belief', 'knowledge'], true)) {
                $add('CONFLICTING_PATCH', $ref);
            }
            $seen[$key] = true;
            $current = $state['entities'][$ref][$property] ?? null;
            $category = $patch['fact_category'];
            if (in_array($property, ['belief', 'knowledge'], true)) {
                if ($patch['value_ref'] !== '' || $patch['before_ref'] !== '' || trim($patch['value_text']) === '' || ! in_array($category, ['character_belief', 'rumor', 'inference'], true)
                    || ! in_array($event['event_type'], ['belief_change', 'statement', 'observation'], true)
                    || ! $this->hasContinuousEvidence($event['evidence'], $patch['value_text'])) {
                    $add('FACT_CATEGORY_VIOLATION', $key);
                }
            } else {
                if ($category !== 'objective_confirmed' || $event['fact_category'] !== 'objective_confirmed' || $event['event_type'] === 'statement') {
                    $add('FACT_CATEGORY_VIOLATION', $key);
                }
                if ($property === 'life_status') {
                    if (($current['value_text'] ?? null) === 'dead' && $patch['value_text'] === 'alive') {
                        $add('LIFE_STATE_CONFLICT', $key);
                    }
                    if (! in_array($all[$ref]['type'], ['character', 'animal'], true) || $event['event_type'] !== 'life_change' || ! in_array($patch['value_text'], ['alive', 'dead'], true) || $patch['value_ref'] !== '' || $patch['before_ref'] !== '') {
                        $add('LIFE_CHANGE_UNSUPPORTED', $key);
                    }
                } else {
                    $target = $all[$patch['value_ref']] ?? null;
                    if (! in_array($patch['value_ref'], [$event['actor_ref'], ...$event['target_refs']], true)) {
                        $add('PATCH_SOURCE_INVALID', $key);
                    }
                    if ($target === null || ($property === 'location' && $target['type'] !== 'location')
                        || ($property === 'item_owner' && (! in_array($target['type'], ['character', 'group'], true) || $all[$ref]['type'] !== 'item')) || $patch['value_text'] !== '') {
                        $add('PATCH_VALUE_INVALID', $key);
                    }
                    if (($current['value_ref'] ?? '') !== $patch['before_ref']) {
                        $add('PATCH_PRECONDITION_FAILED', $key);
                    }
                    if ($property === 'item_owner' && ! in_array($event['event_type'], ['acquisition', 'transfer'], true)) {
                        $add('OWNERSHIP_EVENT_REQUIRED', $key);
                    }
                    if ($property === 'location' && $event['event_type'] !== 'location_change') {
                        $add('LOCATION_EVENT_REQUIRED', $key);
                    }
                    if ($property === 'item_owner' && ($current['value_ref'] ?? '') !== '' && $event['event_type'] !== 'transfer') {
                        $add('OWNERSHIP_CONFLICT', $key);
                    }
                }
            }
            $value = ['value_ref' => $patch['value_ref'], 'value_text' => $patch['value_text'], 'fact_category' => $category, 'source_event_key' => $event['key']];
            if (in_array($property, ['belief', 'knowledge'], true)) {
                $state['entities'][$ref][$property][] = $value;
            } else {
                $state['entities'][$ref][$property] = $value;
            }
        }
        // 同章死亡发生后的动作同样受生命状态限制，回忆不受此限制。
        foreach ($data['patch'] as $patch) {
            if ($patch['property'] !== 'life_status' || $patch['value_text'] !== 'dead') {
                continue;
            }
            $death = $events[$patch['source_event_key']] ?? null;
            foreach ($events as $event) {
                if ($death !== null && $event['narrative_order'] > $death['narrative_order'] && $event['actor_ref'] === $patch['entity_ref']
                    && $event['time_scope'] === 'present' && in_array($event['event_type'], ['action', 'location_change', 'acquisition', 'transfer'], true)) {
                    $add('DEAD_ACTOR', $event['key']);
                }
            }
        }
        $required = array_values(array_diff(array_keys($proposed), $approvedKeys));

        return ['verdict' => $errors !== [] ? 'blocked' : ($required !== [] ? 'requires_approval' : 'pass'), 'errors' => $errors, 'approval_required' => $required, 'state' => $state];
    }

    /** 信念与知识必须来自连续原文，按区间复原换行，禁止跳过正文拼接证据。
     * @param  list<array{paragraph: int, quote: string, start: int, end: int}>  $evidence
     */
    private function hasContinuousEvidence(array $evidence, string $text): bool
    {
        usort($evidence, fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $continuous = '';
        $previous = null;
        foreach ($evidence as $item) {
            // 字符间隔等于段号差时，中间只能是换行；否则重新开始连续区间。
            $gap = $previous === null ? 0 : $item['start'] - $previous['end'];
            $continuous = $previous !== null && $gap === $item['paragraph'] - $previous['paragraph']
                ? $continuous.str_repeat("\n", $gap).$item['quote']
                : $item['quote'];
            if (str_contains($continuous, $text)) {
                return true;
            }
            $previous = $item;
        }

        return false;
    }
}
