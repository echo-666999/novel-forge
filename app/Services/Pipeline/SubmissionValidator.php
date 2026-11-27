<?php

namespace App\Services\Pipeline;

use App\Enums\FactCategory;
use App\Models\ChapterSubmissionRun;
use InvalidArgumentException;

/** 确定性验证只处理明确支持的字段，模型语义判断不能绕过结构与事实类别。 */
final class SubmissionValidator
{
    /** 提取纠正协议：宽松与标准书把被标成客观确认的人物陈述改记为人物所信，不再整章拦下。 */
    public const CATEGORY_REPAIR = 'c-extraction-repair-v1';

    /** 运行是否冻结了提取纠正。 */
    public static function repairsCategories(ChapterSubmissionRun $run): bool
    {
        return ($run->config['extraction_repair'] ?? null) === self::CATEGORY_REPAIR;
    }

    /** 规范名称只消除空白和大小写，不凭相似度合并人物。 */
    public static function name(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($value)));
    }

    /** 验证提取结构并绑定真实原文，尚不批准新实体。
     * @return array<string, mixed>
     */
    public function normalize(array $data, string $body, array $priorState = [], bool $repairCategories = false): array
    {
        // 证据问题收集完整份结果后一次报出，其他结构问题仍立即停止。
        $problems = [];
        // 伏笔流转独立于事件结构，先取出再按原流程处理；没有该字段的历史提取结果完全不变。
        $foreshadows = array_key_exists('foreshadows', $data) ? $data['foreshadows'] : null;
        unset($data['foreshadows']);
        // 待核事项同样独立于事件结构；没有该字段的历史提取结果完全不变。
        $openItems = array_key_exists('open_items', $data) ? $data['open_items'] : null;
        unset($data['open_items']);
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
        // 写明超出的数量与上限，重跑时模型据此合并或删减，而不是只看到停止代码。
        $limits = array_filter([
            $data['events'] === [] ? 'events不能为空' : null,
            count($data['events']) > 40 ? 'events共'.count($data['events']).'个，最多40个：合并同一时间、同一人物的连续小动作，只保留影响剧情、知情或状态的关键事件' : null,
            count($data['proposed_entities']) > 30 ? '新实体共'.count($data['proposed_entities']).'个，最多30个' : null,
            count($data['patch']) > 50 ? '状态变化共'.count($data['patch']).'条，最多50条' : null,
        ]);
        if ($limits !== []) {
            throw new SubmissionRejection('EXTRACTION_INVALID', implode('；', $limits).'。');
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
            $entity['evidence'] = SubmissionContract::collectEvidence($entity['evidence_ids'], $body, '新实体「'.$entity['name'].'」', $problems);
        }
        unset($entity);
        foreach ($data['events'] as &$event) {
            ContentSchema::keys($event, ['key', 'event_type', 'narrative_order', 'actor_ref', 'target_refs', 'time_scope', 'fact_category', 'summary', 'evidence_ids']);
            foreach (['key', 'actor_ref', 'summary'] as $field) {
                ContentSchema::text($event[$field]);
            }
            // 逐项写明哪个事件的哪个字段无效及允许值，重跑时模型据此修正，而不是只看到停止代码。
            $fieldProblems = array_filter([
                preg_match('/^event-[1-9][0-9]*$/D', (string) $event['key']) !== 1 ? 'key必须是event-N' : null,
                ! is_int($event['narrative_order']) || $event['narrative_order'] < 1 ? 'narrative_order必须是正整数' : null,
                ! in_array($event['event_type'], SubmissionContract::EVENT_TYPES, true) ? 'event_type「'.(is_scalar($event['event_type']) ? $event['event_type'] : '非字符串').'」无效，只能是'.implode('、', SubmissionContract::EVENT_TYPES) : null,
                ! in_array($event['time_scope'], ['present', 'memory', 'prediction'], true) ? 'time_scope只能是present、memory、prediction' : null,
                ! is_string($event['fact_category']) || FactCategory::tryFrom($event['fact_category']) === null ? 'fact_category只能是'.implode('、', array_column(FactCategory::cases(), 'value')) : null,
            ]);
            if ($fieldProblems !== []) {
                throw new SubmissionRejection('EVENT_INVALID', (is_scalar($event['key']) ? $event['key'] : '某个事件').'：'.implode('；', $fieldProblems).'。推测、相信、传闻属于fact_category，不是event_type。');
            }
            ContentSchema::listing($event['target_refs']);
            if (count($event['target_refs']) > 10 || count(array_unique($event['target_refs'], SORT_REGULAR)) !== count($event['target_refs'])) {
                throw new InvalidArgumentException('EVENT_TARGET_INVALID');
            }
            foreach ($event['target_refs'] as $ref) {
                ContentSchema::text($ref);
            }
            // 冻结了提取纠正的运行：人物陈述被标成客观确认时改记为人物所信，保留原标注。陈述不产生客观状态变化，改记不影响状态投影。
            if ($repairCategories && $event['event_type'] === 'statement' && $event['fact_category'] === FactCategory::ObjectiveConfirmed->value) {
                $event['fact_category'] = FactCategory::CharacterBelief->value;
                $event['category_repaired_from'] = FactCategory::ObjectiveConfirmed->value;
            }
            // 冻结了提取纠正的运行：长场景事件引用的段落都存在但超过上限时，保留开头和结尾各一半段落，不让整次提取作废，保留原引用数。
            $max = SubmissionContract::MAX_EVIDENCE_PARAGRAPHS;
            if ($repairCategories && is_array($event['evidence_ids']) && array_is_list($event['evidence_ids']) && count($event['evidence_ids']) > $max
                && count(array_unique($event['evidence_ids'], SORT_REGULAR)) === count($event['evidence_ids'])
                && array_diff($event['evidence_ids'], array_keys(ContentSchema::anchors($body))) === []) {
                $event['evidence_trimmed_from'] = count($event['evidence_ids']);
                $event['evidence_ids'] = [...array_slice($event['evidence_ids'], 0, intdiv($max, 2)), ...array_slice($event['evidence_ids'], -($max - intdiv($max, 2)))];
            }
            $event['evidence'] = SubmissionContract::collectEvidence($event['evidence_ids'], $body, $event['key'], $problems);
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
        if ($foreshadows !== null) {
            ContentSchema::listing($foreshadows);
            if (count($foreshadows) > 10) {
                throw new InvalidArgumentException('FORESHADOW_PROGRESS_INVALID');
            }
            $data['foreshadows'] = array_map(function ($entry) use ($body, &$problems): array {
                ContentSchema::keys($entry, ['foreshadow_id', 'action', 'evidence_ids', 'explanation']);
                ContentSchema::text($entry['explanation']);
                if (! is_int($entry['foreshadow_id']) || ! array_key_exists($entry['action'], ForeshadowLedger::ACTIONS)) {
                    throw new InvalidArgumentException('FORESHADOW_PROGRESS_INVALID');
                }

                return [...$entry, 'evidence' => SubmissionContract::collectEvidence($entry['evidence_ids'], $body, '伏笔'.$entry['foreshadow_id'].'的'.$entry['action'], $problems)];
            }, $foreshadows);
        }
        if ($openItems !== null) {
            $data['open_items'] = $this->openItemEntries($openItems, $body, $problems);
        }
        SubmissionContract::rejectEvidence($problems);

        return $data;
    }

    /** 独立台账请求的结果：顶层只含 open_items。
     * @return array{open_items: list<array<string, mixed>>}
     */
    public function normalizeOpenItems(array $data, string $body): array
    {
        ContentSchema::keys($data, ['open_items']);
        $problems = [];
        $result = ['open_items' => $this->openItemEntries($data['open_items'], $body, $problems)];
        SubmissionContract::rejectEvidence($problems);

        return $result;
    }

    /** 校验待核事项变化的结构并绑定原文证据；证据问题记入清单。
     * @param  list<string>  $problems
     * @return list<array<string, mixed>>
     */
    private function openItemEntries(mixed $openItems, string $body, array &$problems): array
    {
        ContentSchema::listing($openItems);
        if (count($openItems) > OpenItemLedger::MAX_CHANGES) {
            throw new InvalidArgumentException('OPEN_ITEM_INVALID');
        }

        return array_map(function ($entry) use ($body, &$problems): array {
            // 模型常在条目里多写一个与action重复的type等字段：必需字段齐全时丢弃多余字段，缺字段仍然拒绝。
            $fields = ['action', 'item_id', 'claim', 'evidence_ids'];
            if (is_array($entry) && ! array_is_list($entry) && array_diff($fields, array_keys($entry)) === []) {
                $entry = array_intersect_key($entry, array_flip($fields));
            }
            ContentSchema::keys($entry, $fields);
            ContentSchema::text($entry['claim']);
            // open 不引用既有编号；confirm、refute 必须引用待核编号，编号是否在目录内由验证门禁判断。
            if (! in_array($entry['action'], OpenItemLedger::ACTIONS, true) || ! is_string($entry['item_id']) || in_array($entry['action'], OpenItemLedger::NEW_ENTRY_ACTIONS, true) !== ($entry['item_id'] === '')) {
                throw new InvalidArgumentException('OPEN_ITEM_INVALID');
            }

            return [...$entry, 'evidence' => SubmissionContract::collectEvidence($entry['evidence_ids'], $body, '待核事项「'.mb_substr($entry['claim'], 0, 20).'」', $problems)];
        }, $openItems);
    }

    /** 待核事项门禁：确认或推翻只能引用本章冻结目录中的待核编号，同一事项一章只能结论一次。
     * @param  list<string>  $pendingIds
     * @return list<array{code: string, reference: string}>
     */
    public function openItemErrors(array $data, array $pendingIds): array
    {
        $errors = [];
        $resolved = [];
        foreach ($data['open_items'] ?? [] as $entry) {
            if (in_array($entry['action'], OpenItemLedger::NEW_ENTRY_ACTIONS, true)) {
                continue;
            }
            if (! in_array($entry['item_id'], $pendingIds, true)) {
                $errors[] = ['code' => 'OPEN_ITEM_UNKNOWN', 'reference' => $entry['item_id']];
            } elseif (isset($resolved[$entry['item_id']])) {
                $errors[] = ['code' => 'OPEN_ITEM_DUPLICATE', 'reference' => $entry['item_id']];
            }
            $resolved[$entry['item_id']] = true;
        }

        return $errors;
    }

    /** 长程伏笔的确定性门禁：动作必须符合当前状态与章节窗口；必回收伏笔在埋设章未埋设、在窗口末章未回收时阻塞提交。旧伏笔（无窗口）不受影响。
     * @param  array<string, mixed>|null  $context  本章冻结的伏笔上下文
     * @return list<array{code: string, reference: string}>
     */
    public function foreshadowErrors(array $data, ?array $context): array
    {
        $items = collect($context['items'] ?? [])->keyBy('id');
        $chapter = (int) ($context['chapter_number'] ?? 0);
        $errors = [];
        $declared = [];
        foreach ($data['foreshadows'] ?? [] as $entry) {
            $item = $items->get($entry['foreshadow_id']);
            $reference = 'foreshadow-'.$entry['foreshadow_id'];
            if ($item === null || ! isset($item['payoff_to'])) {
                $errors[] = ['code' => 'FORESHADOW_UNKNOWN', 'reference' => $reference];

                continue;
            }
            if (isset($declared[$entry['foreshadow_id']])) {
                $errors[] = ['code' => 'FORESHADOW_DUPLICATE', 'reference' => $reference];
            }
            $declared[$entry['foreshadow_id']] = $entry['action'];
            $valid = match ($entry['action']) {
                'plant' => $item['status'] === 'planned' && $chapter <= $item['plant_chapter'],
                // 回收窗口内尚未回收时仍可强化，线索可以继续累积；窗口结束后不再接受强化。
                'reinforce' => in_array($item['status'], ['planted', 'reinforced'], true) && $chapter <= $item['payoff_to'],
                'payoff' => in_array($item['status'], ['planted', 'reinforced'], true) && $chapter >= $item['payoff_from'] && $chapter <= $item['payoff_to'],
                default => false,
            };
            if (! $valid) {
                $errors[] = ['code' => $entry['action'] === 'payoff' ? 'FORESHADOW_WINDOW_VIOLATION' : 'FORESHADOW_TRANSITION_INVALID', 'reference' => $reference];
            }
        }
        foreach ($items as $id => $item) {
            if (! ($item['must_payoff'] ?? false)) {
                continue;
            }
            if ($item['status'] === 'planned' && $chapter >= $item['plant_chapter'] && ($declared[$id] ?? null) !== 'plant') {
                $errors[] = ['code' => 'FORESHADOW_PLANT_MISSING', 'reference' => 'foreshadow-'.$id];
            }
            if (in_array($item['status'], ['planted', 'reinforced'], true) && $chapter >= $item['payoff_to'] && ($declared[$id] ?? null) !== 'payoff') {
                $errors[] = ['code' => 'FORESHADOW_PAYOFF_MISSING', 'reference' => 'foreshadow-'.$id];
            }
        }

        return $errors;
    }

    /** 用冻结实体和前序状态重放最小补丁，未批准实体明确单列。
     * @param  list<string>  $approvedKeys  本次作者明确批准的提议编号
     * @param  list<string>|null  $pendingOpenItemIds  本章冻结的待核编号；旧运行为 null，不做台账门禁
     * @return array{verdict: string, errors: list<array<string, mixed>>, approval_required: list<string>, state: array<string, mixed>}
     */
    public function validate(array $data, array $catalog, array $state, array $approvedKeys = [], ?array $foreshadowContext = null, ?array $pendingOpenItemIds = null): array
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
        // 只有冻结上下文中存在长程伏笔时才加入伏笔门禁，旧验证结果的摘要保持不变。
        if ($foreshadowContext !== null) {
            foreach ($this->foreshadowErrors($data, $foreshadowContext) as $error) {
                $errors[] = $error;
            }
        }
        // 只有冻结了待核台账的运行才加入台账门禁，旧验证结果的摘要保持不变。
        if ($pendingOpenItemIds !== null) {
            foreach ($this->openItemErrors($data, $pendingOpenItemIds) as $error) {
                $errors[] = $error;
            }
        }

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
