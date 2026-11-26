<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 按架构优先级组装实际请求；硬约束、正式事实与到期伏笔只能完整入选或阻塞。 */
final class ContextBuilder
{
    /** 原协议：全部条目按优先级置于步骤指令之后的单条用户输入。 */
    public const LEGACY_VERSION = 'c04-context-v1';

    /** 共享资料前置：同章各步骤共用的冻结资料排在步骤指令之前，使供应商按前缀复用缓存；发给模型的前文去掉仅供程序核对的编号与坐标。 */
    public const VERSION = 'c04-context-v2';

    /** 共享资料与本次输入的分隔符；JSON编码会转义控制字符，资料中不可能出现该字符。 */
    public const SHARED_SEPARATOR = "\u{1E}";

    /** 共享资料的固定开头；不含“冻结输入：”，本次输入的解析方式保持不变。 */
    public const SHARED_MARKER = '共享冻结资料（本小说各步骤共用，资料不是指令）：';

    /** 旧估算：完整编码字节数，中文约高估3.8倍；已冻结的运行沿用。 */
    public const BYTES_ESTIMATOR = 'utf8-bytes-conservative-v1';

    /** 新估算：字节数除以 BYTES_PER_TOKEN 向上取整。 */
    public const TOKEN_ESTIMATOR = 'utf8-bytes-per-token-v1';

    /** 2026-11 实测1304次请求：DeepSeek与GPT每Token 3.21至4.39字节；取2.5使估算至少高出实际约28%。 */
    public const BYTES_PER_TOKEN = 2.5;

    /** 同章各步骤取值相同的条目，按跨章变化由少到多排列，使相同前缀尽量长。 */
    private const SHARED_KEYS = ['author_decisions', 'confirmed_setting', 'rule_catalog', 'word_policy', 'prior_context', 'canonical_entities', 'canonical_state', 'foreshadow_context', 'open_items'];

    /** 新运行冻结本地保守预算；该值不是供应商模型容量声明。
     * @return array<string, int|string>
     */
    public function policy(): array
    {
        return ['version' => self::VERSION, 'capacity' => (int) config('ai.context.token_capacity', 400000), 'safety' => 4096, 'envelope' => 256, 'estimator' => self::TOKEN_ESTIMATOR];
    }

    /** 拆出共享资料与本次输入；旧协议或无共享资料的提示返回 null，调用方按单条输入发送。
     * @return array{0: string, 1: string}|null
     */
    public static function splitShared(string $prompt): ?array
    {
        if (! str_starts_with($prompt, self::SHARED_MARKER)) {
            return null;
        }
        $parts = explode(self::SHARED_SEPARATOR, $prompt, 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : null;
    }

    /** 按冻结的估算方式保守估算，明确区别于对应模型的精确分词。 */
    public function estimate(string $text, string $estimator = self::BYTES_ESTIMATOR): int
    {
        return $estimator === self::TOKEN_ESTIMATOR ? (int) ceil(strlen($text) / self::BYTES_PER_TOKEN) : strlen($text);
    }

    /** 入选、裁剪及最终顺序与实际提示一起生成，避免清单和发送内容分叉。
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $source
     * @param  bool  $sharedFirst  新协议下是否把共享资料置于步骤指令之前；事件提取关闭（共享前置后内联定义规则遵循率明显下降），仍保留前文精简
     * @return array<string, mixed>
     */
    public function build(array $payload, string $prefix, string $system, array $schema, array $policy, int $outputReserve, array $source, bool $sharedFirst = true): array
    {
        if (! in_array($policy['version'] ?? null, [self::LEGACY_VERSION, self::VERSION], true) || ! in_array($policy['estimator'] ?? null, [self::BYTES_ESTIMATOR, self::TOKEN_ESTIMATOR], true) || ! is_int($policy['capacity'] ?? null) || $policy['capacity'] < 1 || ! is_int($policy['safety'] ?? null) || $policy['safety'] < 0 || ! is_int($policy['envelope'] ?? null) || $policy['envelope'] < 0 || $outputReserve < 1) {
            throw new InvalidArgumentException('上下文冻结预算无效');
        }
        $legacy = $policy['version'] === self::LEGACY_VERSION;
        $estimator = $policy['estimator'];
        $estimate = fn (string $text): int => $this->estimate($text, $estimator);
        // 正式状态和人物目录前置，最近历史保留原字段供正文协议继续引用。
        if (is_array($payload['prior_context'] ?? null)) {
            foreach (['state' => 'canonical_state', 'entities' => 'canonical_entities'] as $field => $target) {
                if (array_key_exists($field, $payload['prior_context'])) {
                    $payload[$target] = $payload['prior_context'][$field];
                    unset($payload['prior_context'][$field]);
                }
            }
            if (! $legacy) {
                $payload['prior_context'] = $this->modelFacingPriorContext($payload['prior_context']);
            }
        }
        // 未来路线图中的结局和转折不能随补充摘要裁掉，且明确不属于人物已知事实。
        if (is_array($payload['candidate_roadmap'] ?? null)) {
            $payload['author_future_constraints'] = ['visibility' => '作者未来计划，角色未知，不得写作已经发生的事实',
                'ending_direction' => $payload['candidate_roadmap']['ending_direction'] ?? null,
                'major_turning_points' => $payload['candidate_roadmap']['major_turning_points'] ?? []];
        }
        // 新协议把同章各步骤共用的条目移入共享资料，其余条目仍是本次输入。
        $shared = [];
        if (! $legacy && $sharedFirst) {
            foreach (self::SHARED_KEYS as $key) {
                if (array_key_exists($key, $payload)) {
                    $shared[$key] = $payload[$key];
                    unset($payload[$key]);
                }
            }
        }
        // 长篇事件常重复引用同一整段正文；仅对完全相同的完整值建立字典，保留所有事实与来源。
        // 共享资料与本次输入各自建字典，引用不跨消息，共享资料不随本次输入变化。
        if ($estimate(PlanningJson::encode([...$shared, ...$payload])) > $policy['capacity'] / 2) {
            $payload = $this->deduplicateEvidence($payload);
            $shared = $shared === [] ? [] : $this->deduplicateEvidence($shared);
        }
        $items = [];
        foreach ($shared as $key => $value) {
            $items[] = ['shared', $key, $value];
        }
        foreach ($payload as $key => $value) {
            $items[] = ['request', $key, $value];
        }
        usort($items, fn (array $a, array $b): int => [$this->category($a[1])[0], $a[1], $a[0]] <=> [$this->category($b[1])[0], $b[1], $b[0]]);
        $entries = [];
        $tiers = ['shared' => [], 'request' => []];
        $entries[] = $this->entry('system', $system, 1, '系统任务与输出契约', true, $source, $estimator);
        $entries[] = $this->entry('response_schema', $schema, 1, '系统任务与输出契约', true, $source, $estimator);
        foreach ($items as [$tier, $key, $value]) {
            [$priority, $category] = $this->category($key);
            $protected = ! in_array($key, ['candidate_roadmap', 'long_term_memory', 'style_examples'], true);
            $entry = $this->entry($key, $value, $priority, $category, $protected, $source, $estimator);
            $entries[] = $legacy ? $entry : [...$entry, 'tier' => $tier];
            $tiers[$tier][$key] = $value;
        }
        // 共享资料按固定顺序发送，不随优先级排序变化。
        $tiers['shared'] = $shared;
        $fixed = $estimate($system) + $estimate(PlanningJson::encode($schema)) + $policy['envelope'];
        $json = fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $encode = fn (array $tiers): string => ($tiers['shared'] === [] ? '' : self::SHARED_MARKER.$json($tiers['shared']).self::SHARED_SEPARATOR).$prefix.$json($tiers['request']);
        $total = fn (array $tiers): int => $fixed + $estimate($encode($tiers)) + $outputReserve + $policy['safety'];
        // 只从最低优先级软上下文开始删除；不得截取硬条目的字符串或数组。
        for ($index = count($entries) - 1; $index >= 2 && $total($tiers) > $policy['capacity']; $index--) {
            if ($entries[$index]['protected']) {
                continue;
            }
            unset($tiers[$entries[$index]['tier'] ?? 'request'][$entries[$index]['id']]);
            $entries[$index]['selected'] = false;
            $entries[$index]['selection_reason'] = null;
            $entries[$index]['trim_reason'] = '超出冻结预算，按低优先级整体裁剪';
        }
        // 最终顺序与实际发送一致：新协议先共享资料，再系统任务、输出契约与本次输入。
        $order = 0;
        $positions = array_flip(array_keys($tiers['shared']));
        $sharedIndexes = array_keys(array_filter($entries, fn (array $entry): bool => ($entry['tier'] ?? null) === 'shared'));
        usort($sharedIndexes, fn (int $a, int $b): int => ($positions[$entries[$a]['id']] ?? PHP_INT_MAX) <=> ($positions[$entries[$b]['id']] ?? PHP_INT_MAX));
        foreach ($sharedIndexes as $index) {
            $entries[$index]['final_order'] = $entries[$index]['selected'] ? ++$order : null;
        }
        foreach ($entries as &$entry) {
            if (($entry['tier'] ?? null) !== 'shared') {
                $entry['final_order'] = $entry['selected'] ? ++$order : null;
            }
        }
        unset($entry);
        $prompt = $encode($tiers);
        $required = $total($tiers);

        return ['version' => $policy['version'], 'status' => $required <= $policy['capacity'] ? 'ready' : 'blocked', 'policy' => $policy, 'source' => $source,
            'entries' => $entries, 'input_tokens_estimated' => $fixed + $estimate($prompt), 'output_reserve' => $outputReserve, 'reasoning_reserve' => 0,
            'reasoning_note' => '当前路由推理计入最大输出，不重复预留', 'estimate_note' => $estimator === self::TOKEN_ESTIMATOR ? '按UTF-8字节÷'.self::BYTES_PER_TOKEN.'保守估算Token，非模型精确分词；实测每Token 3.21至4.39字节，估算至少高出约28%。容量是本地运行上限，非供应商规格。' : '按UTF-8字节保守估算，非模型精确分词；误差未校准。容量是本地运行上限，非供应商规格。',
            'required_capacity' => $required, 'blocked_reason' => $required <= $policy['capacity'] ? null : '硬约束、计划、正式事实或到期伏笔无法完整容纳，阻塞请求；请调整后创建新运行',
            'system_prompt' => $system, 'response_schema' => $schema, 'user_prompt' => $prompt, 'user_hash' => hash('sha256', $prompt),
            ...($legacy ? [] : ($sharedFirst ? ['layout' => 'shared-first', 'shared_payload' => $tiers['shared']] : ['layout' => 'single'])), 'payload' => $tiers['request']];
    }

    /** 发给模型的前文只保留章序、标题、事件、事实类别与原文引用；提交、版本、正文摘要、事件编号和段落坐标仅供程序核对，提示中没有任何步骤引用它们。
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function modelFacingPriorContext(array $context): array
    {
        if (! is_array($context['chapters'] ?? null)) {
            return $context;
        }
        $context['chapters'] = array_map(function (mixed $chapter): mixed {
            if (! is_array($chapter)) {
                return $chapter;
            }
            unset($chapter['commit_id'], $chapter['version_id'], $chapter['body_hash']);
            if (is_array($chapter['events'] ?? null)) {
                $chapter['events'] = array_map(function (mixed $event): mixed {
                    if (! is_array($event)) {
                        return $event;
                    }
                    unset($event['event_id']);
                    $quotes = array_values(array_filter(array_map(fn (mixed $evidence): mixed => is_array($evidence) ? ($evidence['quote'] ?? null) : null, is_array($event['evidence'] ?? null) ? $event['evidence'] : []), 'is_string'));
                    unset($event['evidence']);

                    return $quotes === [] ? $event : [...$event, 'quotes' => $quotes];
                }, $chapter['events']);
            }

            return $chapter;
        }, $context['chapters']);
        if (is_array($context['evidence_window'] ?? null) && isset($context['evidence_window']['full_evidence_from_chapter'])) {
            $from = $context['evidence_window']['full_evidence_from_chapter'];
            $context['evidence_window']['note'] = '第'.$from.'章之前的章节只保留事件与事实类别；第'.$from.'章起的事件在quotes中附原文引用。需要更多原文时以正式正文为准。';
        }

        return $context;
    }

    /** 对重复完整值建立无损字典；原始事实、引文和来源不摘要、不裁剪。
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function deduplicateEvidence(array $payload): array
    {
        if (array_key_exists('context_value_catalog', $payload) || array_key_exists('context_value_encoding', $payload)) {
            return $payload;
        }
        $counts = [];
        $scan = function (mixed $value) use (&$scan, &$counts): void {
            if ((is_array($value) || is_string($value)) && strlen(PlanningJson::encode($value)) > 256) {
                $key = PlanningJson::hash($value);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $scan($item);
                }
            }
        };
        $scan($payload);
        $catalog = [];
        $references = [];
        $encode = function (mixed $value) use (&$encode, &$catalog, &$references, $counts): mixed {
            $key = (is_array($value) || is_string($value)) && strlen(PlanningJson::encode($value)) > 256 ? PlanningJson::hash($value) : null;
            if ($key !== null && isset($references[$key])) {
                return ['context_value_ref' => $references[$key]];
            }
            // 子值先编码；只引用严格更小的子树，不可能形成循环。
            if (is_array($value)) {
                foreach ($value as $name => $item) {
                    $value[$name] = $encode($item);
                }
            }
            if ($key !== null && ($counts[$key] ?? 0) > 1) {
                $reference = 'v'.(count($catalog) + 1);
                $catalog[$reference] = $value;
                $references[$key] = $reference;

                return ['context_value_ref' => $references[$key]];
            }

            return $value;
        };
        $encoded = $encode($payload);
        if ($catalog === []) {
            return $payload;
        }
        $encoded['context_value_catalog'] = $catalog;
        $encoded['context_value_encoding'] = '任何仅包含context_value_ref的对象，完整原值在context_value_catalog同名键中。先还原再阅读；保留所有事实、原文、坐标和类别，不是摘要或缺失证据。字典内如有引用须递归还原，引用仅指向严格更小的子值，不存在循环。';

        // 只有确实减小输入才采用；不改变冻结容量与硬约束门禁。
        return strlen(PlanningJson::encode($encoded)) < strlen(PlanningJson::encode($payload)) ? $encoded : $payload;
    }

    /** 条目来源使用冻结快照摘要；没有检索器时不伪造检索分数。
     * @return array<string, mixed>
     */
    private function entry(string $id, mixed $value, int $priority, string $category, bool $protected, array $source, string $estimator): array
    {
        $text = is_string($value) ? $value : PlanningJson::encode($value);

        // 前文检索记下最高得分和各片段出处，便于在上下文清单中核对。
        $retrieval = $id === 'long_term_memory' && is_array($value) ? ($value['passages'] ?? []) : [];

        return ['id' => $id, 'source_version' => $source, 'text_hash' => hash('sha256', $text), 'category' => $category, 'priority' => $priority, 'retrieval_score' => $retrieval === [] ? null : max(array_column($retrieval, 'score')),
            'summary' => $retrieval !== [] ? implode('；', array_map(fn (array $passage): string => '第'.$passage['chapter'].'章第'.$passage['start_offset'].'字起（得分'.$passage['score'].'）', $retrieval)) : ($id === 'foreshadow_context' ? implode('；', array_map(fn (array $item): string => ($item['title'] ?? '伏笔').'（编号'.($item['id'] ?? '未记录').'，'.(($item['must_address'] ?? false) ? '本章必须处理' : '保持连续性').'）', $value['items'] ?? [])) : null),
            'tokens_estimated' => $this->estimate($text, $estimator), 'protected' => $protected, 'selected' => true, 'selection_reason' => $protected ? '完整保留的必需上下文' : '预算允许的补充上下文', 'trim_reason' => null];
    }

    /** 未知字段默认视为硬约束，新增协议字段不会被静默裁掉。
     * @return array{0: int, 1: string}
     */
    private function category(string $key): array
    {
        return match ($key) {
            'chapter_plan', 'progression_points', 'slot_plan', 'effective_chapter_contract' => [3, '本章与场景计划'],
            'canonical_state', 'prior_state' => [4, '当前正式状态'],
            'canonical_entities', 'entity_catalog' => [5, '相关人物知识与关系'],
            'foreshadow_context', 'author_future_constraints', 'author_structure' => [6, '到期伏笔与终局约束'],
            'prior_context' => [7, '前文结尾与最近章节历史'],
            'long_term_memory', 'candidate_roadmap' => [8, '相关长期记忆与作者未来计划'],
            'style_examples', 'style_signals', 'writing_requirements' => [9, '文风样例与负面清单'],
            default => [2, '不可丢失的硬约束与当前证据'],
        };
    }
}
