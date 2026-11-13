<?php

namespace App\Services\Pipeline;

/**
 * 把评审、事件提取、确定性验证和提交回执的结构化结果整理成后台可读的分区数据，替代整段 JSON。
 *
 * 实体引用（entity-编号 / new-键）换成名称；字段码换成中文；原文引用保留，由视图默认折叠。
 */
final class SubmissionPresenter
{
    /** 事件类型。 */
    public const EVENT_TYPES = ['action' => '行动', 'observation' => '观察', 'statement' => '陈述', 'belief_change' => '认知变化', 'location_change' => '位置变化', 'acquisition' => '获得物品', 'transfer' => '转交物品', 'life_change' => '存亡变化'];

    /** 事实类别。 */
    public const FACT_CATEGORIES = ['objective_confirmed' => '客观确认', 'character_belief' => '人物认知', 'rumor' => '传闻', 'inference' => '推断', 'lie' => '谎言', 'author_hidden_truth' => '作者隐藏真相'];

    /** 时间范围。 */
    public const TIME_SCOPES = ['present' => '当前', 'memory' => '回忆', 'prediction' => '预期'];

    /** 状态属性。 */
    public const PROPERTIES = ['location' => '位置', 'belief' => '认知', 'knowledge' => '知情', 'item_owner' => '物品归属', 'life_status' => '存亡'];

    /** 评审问题类型。 */
    public const FINDING_KINDS = ['structure' => '结构', 'literary' => '文学表达', 'continuity' => '连贯性', 'rule' => '设定规则'];

    /** 伏笔动作。 */
    public const FORESHADOW_ACTIONS = ['plant' => '埋设', 'reinforce' => '强化', 'payoff' => '回收'];

    /** 实体类型。 */
    public const ENTITY_TYPES = ['character' => '人物', 'group' => '群体', 'location' => '地点', 'item' => '物品', 'organization' => '组织', 'animal' => '动物', 'rule' => '世界规则', 'ability' => '能力'];

    /** 整理全部分区；缺少的结果为 null，视图显示“尚无记录”。
     * @param  array<int|string, string>  $entityNames  正式实体编号 => 名称
     * @param  array<int|string, string>  $foreshadowTitles  伏笔编号 => 标题
     * @return array{review: ?array, extraction: ?array, validation: ?array, commit: ?array}
     */
    public static function present(?array $review, ?array $extraction, ?array $validation, ?array $commit, array $entityNames, array $foreshadowTitles): array
    {
        $proposals = [];
        foreach (FieldDiff::listOf($extraction['proposed_entities'] ?? null) as $proposal) {
            $proposal = (array) $proposal;
            $proposals[(string) ($proposal['key'] ?? '')] = FieldDiff::text($proposal['name'] ?? null) ?? (string) ($proposal['key'] ?? '');
        }
        $entity = function (mixed $ref) use ($entityNames, $proposals): string {
            $ref = (string) $ref;
            if (isset($proposals[$ref])) {
                return $proposals[$ref].'（新实体）';
            }
            $id = str_starts_with($ref, 'entity-') ? substr($ref, 7) : $ref;

            return $entityNames[$id] ?? $ref;
        };

        return [
            'review' => $review === null ? null : self::review($review),
            'extraction' => $extraction === null ? null : self::extraction($extraction, $entity, $foreshadowTitles),
            'validation' => $validation === null ? null : self::validation($validation, $entity),
            'commit' => $commit === null ? null : self::commit($commit),
        ];
    }

    /** 新实体批准选项的可读标签：名称、类型、描述与别名，不再显示 JSON。 */
    public static function proposalLabel(array $proposal): string
    {
        $type = self::ENTITY_TYPES[$proposal['type'] ?? ''] ?? FieldDiff::text($proposal['type'] ?? null) ?? '未标类型';
        $aliases = array_filter(array_map(FieldDiff::text(...), FieldDiff::listOf($proposal['aliases'] ?? null)));

        return (FieldDiff::text($proposal['name'] ?? null) ?? (string) ($proposal['key'] ?? '')).'（'.$type.'，'.(($proposal['core'] ?? false) ? '核心实体' : '新实体').'）'
            .'：'.(FieldDiff::text($proposal['description'] ?? null) ?? '无描述').($aliases === [] ? '' : '；别名：'.implode('、', $aliases));
    }

    /** 评审：结论、摘要、逐条问题（阻塞在前）。
     * @return array{passed: bool, verdict: string, summary: ?string, findings: list<array<string, mixed>>}
     */
    private static function review(array $review): array
    {
        $findings = array_map(fn (mixed $finding): array => [
            'kind' => self::FINDING_KINDS[((array) $finding)['kind'] ?? ''] ?? FieldDiff::text(((array) $finding)['kind'] ?? null) ?? '其他',
            'blocking' => (((array) $finding)['severity'] ?? null) === 'blocking',
            'reason' => FieldDiff::text(((array) $finding)['reason'] ?? null) ?? '',
            'quotes' => self::quotes(((array) $finding)['evidence'] ?? null),
        ], FieldDiff::listOf($review['findings'] ?? null));
        usort($findings, fn (array $a, array $b): int => $b['blocking'] <=> $a['blocking']);
        $passed = ($review['verdict'] ?? null) === 'pass';

        return ['passed' => $passed, 'verdict' => $passed ? '评审通过' : '需要修订', 'summary' => FieldDiff::text($review['summary'] ?? null), 'findings' => $findings];
    }

    /** 事件提取：事件、状态变化、伏笔流转与新实体。
     * @param  callable(mixed): string  $entity
     * @param  array<int|string, string>  $foreshadowTitles
     * @return array<string, list<array<string, mixed>>>
     */
    private static function extraction(array $extraction, callable $entity, array $foreshadowTitles): array
    {
        $events = array_map(fn (mixed $event): array => [
            'order' => ((array) $event)['narrative_order'] ?? null,
            'summary' => FieldDiff::text(((array) $event)['summary'] ?? null) ?? '',
            'type' => self::EVENT_TYPES[((array) $event)['event_type'] ?? ''] ?? FieldDiff::text(((array) $event)['event_type'] ?? null),
            'category' => self::FACT_CATEGORIES[((array) $event)['fact_category'] ?? ''] ?? FieldDiff::text(((array) $event)['fact_category'] ?? null),
            'time' => self::TIME_SCOPES[((array) $event)['time_scope'] ?? ''] ?? FieldDiff::text(((array) $event)['time_scope'] ?? null),
            'actor' => isset(((array) $event)['actor_ref']) ? $entity(((array) $event)['actor_ref']) : null,
            'targets' => array_map($entity, FieldDiff::listOf(((array) $event)['target_refs'] ?? null)),
            'quotes' => self::quotes(((array) $event)['evidence'] ?? null),
        ], FieldDiff::listOf($extraction['events'] ?? null));
        usort($events, fn (array $a, array $b): int => ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX));
        $changes = array_map(fn (mixed $patch): array => [
            'entity' => $entity(((array) $patch)['entity_ref'] ?? ''),
            'property' => self::PROPERTIES[((array) $patch)['property'] ?? ''] ?? (string) (((array) $patch)['property'] ?? ''),
            'before' => isset(((array) $patch)['before_ref']) && ((array) $patch)['before_ref'] !== null ? $entity(((array) $patch)['before_ref']) : null,
            'after' => FieldDiff::text(((array) $patch)['value_text'] ?? null) ?? (isset(((array) $patch)['value_ref']) ? $entity(((array) $patch)['value_ref']) : '未填写'),
            'category' => self::FACT_CATEGORIES[((array) $patch)['fact_category'] ?? ''] ?? null,
        ], FieldDiff::listOf($extraction['patch'] ?? null));
        $foreshadows = array_map(fn (mixed $item): array => [
            'title' => $foreshadowTitles[((array) $item)['foreshadow_id'] ?? ''] ?? '伏笔 #'.(((array) $item)['foreshadow_id'] ?? '?'),
            'action' => self::FORESHADOW_ACTIONS[((array) $item)['action'] ?? ''] ?? (string) (((array) $item)['action'] ?? ''),
            'explanation' => FieldDiff::text(((array) $item)['explanation'] ?? null) ?? '',
            'quotes' => self::quotes(((array) $item)['evidence'] ?? null),
        ], FieldDiff::listOf($extraction['foreshadows'] ?? null));
        $entities = array_map(fn (mixed $proposal): array => [
            'label' => self::proposalLabel((array) $proposal),
            'quotes' => self::quotes(((array) $proposal)['evidence'] ?? null),
        ], FieldDiff::listOf($extraction['proposed_entities'] ?? null));

        return ['events' => $events, 'changes' => $changes, 'foreshadows' => $foreshadows, 'entities' => $entities];
    }

    /** 确定性验证：结论、错误与待作者批准的实体。
     * @param  callable(mixed): string  $entity
     * @return array{passed: bool, errors: list<string>, approvals: list<string>}
     */
    private static function validation(array $validation, callable $entity): array
    {
        $errors = [];
        foreach (FieldDiff::listOf($validation['errors'] ?? null) as $error) {
            $errors[] = is_array($error) ? implode('；', SettingVersionDiff::lines($error)) : (string) $error;
        }
        $approvals = array_map(fn (mixed $item): string => is_array($item) ? implode('；', SettingVersionDiff::lines($item)) : $entity($item), FieldDiff::listOf($validation['approval_required'] ?? null));

        return ['passed' => ($validation['verdict'] ?? null) === 'pass' && $errors === [], 'errors' => $errors, 'approvals' => $approvals];
    }

    /** 正式提交回执：只列作者需要的关键信息，摘要等技术字段放在末尾。
     * @return list<array{label: string, value: string}>
     */
    private static function commit(array $commit): array
    {
        $rows = [
            '提交来源' => match ($commit['origin'] ?? 'submission') {
                'revision' => '正式稿修订', 'rebase' => '基线重挂', default => '正式提交'
            },
            '审批方式' => ($commit['approval_mode'] ?? null) === 'delegated_policy' ? '自动授权策略（非作者逐章批准）' : '作者批准',
            '本章事件数' => isset($commit['event_count']) ? (string) $commit['event_count'] : null,
            '提交后已完成章数' => isset($commit['chapters_done']) ? (string) $commit['chapters_done'] : null,
            '故事状态版本' => isset($commit['state_version']) ? ($commit['previous_state_version'] ?? '?').' → '.$commit['state_version'] : null,
            '正式正文记录' => isset($commit['version_id']) ? '#'.$commit['version_id'] : null,
            '正文摘要' => FieldDiff::text($commit['body_hash'] ?? null),
        ];

        return array_values(array_map(fn (string $label, ?string $value): array => ['label' => $label, 'value' => $value], array_keys(array_filter($rows, fn (?string $value): bool => $value !== null)), array_filter($rows, fn (?string $value): bool => $value !== null)));
    }

    /** 原文引用：段号与原文。
     * @return list<array{paragraph: ?int, quote: string}>
     */
    private static function quotes(mixed $evidence): array
    {
        return array_values(array_filter(array_map(fn (mixed $item): ?array => FieldDiff::text(((array) $item)['quote'] ?? null) === null ? null
            : ['paragraph' => isset(((array) $item)['paragraph']) ? (int) ((array) $item)['paragraph'] : null, 'quote' => FieldDiff::text(((array) $item)['quote'])], FieldDiff::listOf($evidence))));
    }
}
