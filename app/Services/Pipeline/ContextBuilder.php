<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 按架构优先级组装实际请求；硬约束、正式事实与到期伏笔只能完整入选或阻塞。 */
final class ContextBuilder
{
    /** 新运行冻结本地保守预算；该值不是供应商模型容量声明。
     * @return array<string, int|string>
     */
    public function policy(): array
    {
        return ['version' => 'c04-context-v1', 'capacity' => (int) config('ai.context.capacity', 262144), 'safety' => 4096, 'envelope' => 256, 'estimator' => 'utf8-bytes-conservative-v1'];
    }

    /** 以完整编码字节数作保守估算，明确区别于对应模型的精确分词。 */
    public function estimate(string $text): int
    {
        return strlen($text);
    }

    /** 入选、裁剪及最终顺序与实际提示一起生成，避免清单和发送内容分叉。
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function build(array $payload, string $prefix, string $system, array $schema, array $policy, int $outputReserve, array $source): array
    {
        if (($policy['version'] ?? null) !== 'c04-context-v1' || ($policy['estimator'] ?? null) !== 'utf8-bytes-conservative-v1' || ! is_int($policy['capacity'] ?? null) || $policy['capacity'] < 1 || ! is_int($policy['safety'] ?? null) || $policy['safety'] < 0 || ! is_int($policy['envelope'] ?? null) || $policy['envelope'] < 0 || $outputReserve < 1) {
            throw new InvalidArgumentException('上下文冻结预算无效');
        }
        // 正式状态和人物目录前置，最近历史保留原字段供正文协议继续引用。
        if (is_array($payload['prior_context'] ?? null)) {
            foreach (['state' => 'canonical_state', 'entities' => 'canonical_entities'] as $field => $target) {
                if (array_key_exists($field, $payload['prior_context'])) {
                    $payload[$target] = $payload['prior_context'][$field];
                    unset($payload['prior_context'][$field]);
                }
            }
        }
        // 未来路线图中的结局和转折不能随补充摘要裁掉，且明确不属于人物已知事实。
        if (is_array($payload['candidate_roadmap'] ?? null)) {
            $payload['author_future_constraints'] = ['visibility' => '作者未来计划，角色未知，不得写作已经发生的事实',
                'ending_direction' => $payload['candidate_roadmap']['ending_direction'] ?? null,
                'major_turning_points' => $payload['candidate_roadmap']['major_turning_points'] ?? []];
        }
        // 长篇事件常重复引用同一整段正文；仅对完全相同的完整值建立字典，保留所有事实与来源。
        if ($this->estimate(PlanningJson::encode($payload)) > $policy['capacity'] / 2) {
            $payload = $this->deduplicateEvidence($payload);
        }
        $entries = [];
        $ordered = [];
        $keys = array_keys($payload);
        usort($keys, fn (string $a, string $b): int => [$this->category($a)[0], $a] <=> [$this->category($b)[0], $b]);
        $entries[] = $this->entry('system', $system, 1, '系统任务与输出契约', true, $source);
        $entries[] = $this->entry('response_schema', $schema, 1, '系统任务与输出契约', true, $source);
        foreach ($keys as $key) {
            [$priority, $category] = $this->category($key);
            $protected = ! in_array($key, ['candidate_roadmap', 'long_term_memory', 'style_examples'], true);
            $entries[] = $this->entry($key, $payload[$key], $priority, $category, $protected, $source);
            $ordered[$key] = $payload[$key];
        }
        $fixed = $this->estimate($system) + $this->estimate(PlanningJson::encode($schema)) + $policy['envelope'];
        $encode = fn (array $data): string => $prefix.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $total = fn (array $data): int => $fixed + $this->estimate($encode($data)) + $outputReserve + $policy['safety'];
        // 只从最低优先级软上下文开始删除；不得截取硬条目的字符串或数组。
        for ($index = count($entries) - 1; $index >= 2 && $total($ordered) > $policy['capacity']; $index--) {
            if ($entries[$index]['protected']) {
                continue;
            }
            unset($ordered[$entries[$index]['id']]);
            $entries[$index]['selected'] = false;
            $entries[$index]['selection_reason'] = null;
            $entries[$index]['trim_reason'] = '超出冻结预算，按低优先级整体裁剪';
        }
        $order = 0;
        foreach ($entries as &$entry) {
            $entry['final_order'] = $entry['selected'] ? ++$order : null;
        }
        unset($entry);
        $prompt = $encode($ordered);
        $required = $total($ordered);

        return ['version' => 'c04-context-v1', 'status' => $required <= $policy['capacity'] ? 'ready' : 'blocked', 'policy' => $policy, 'source' => $source,
            'entries' => $entries, 'input_tokens_estimated' => $fixed + $this->estimate($prompt), 'output_reserve' => $outputReserve, 'reasoning_reserve' => 0,
            'reasoning_note' => '当前路由推理计入最大输出，不重复预留', 'estimate_note' => '按UTF-8字节保守估算，非模型精确分词；误差未校准。容量是本地运行上限，非供应商规格。',
            'required_capacity' => $required, 'blocked_reason' => $required <= $policy['capacity'] ? null : '硬约束、计划、正式事实或到期伏笔无法完整容纳，阻塞请求；请调整后创建新运行',
            'system_prompt' => $system, 'response_schema' => $schema, 'user_prompt' => $prompt, 'user_hash' => hash('sha256', $prompt), 'payload' => $ordered];
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
    private function entry(string $id, mixed $value, int $priority, string $category, bool $protected, array $source): array
    {
        $text = is_string($value) ? $value : PlanningJson::encode($value);

        return ['id' => $id, 'source_version' => $source, 'text_hash' => hash('sha256', $text), 'category' => $category, 'priority' => $priority, 'retrieval_score' => null,
            'summary' => $id === 'foreshadow_context' ? implode('；', array_map(fn (array $item): string => ($item['title'] ?? '伏笔').'（编号'.($item['id'] ?? '未记录').'，'.(($item['must_address'] ?? false) ? '本章必须处理' : '保持连续性').'）', $value['items'] ?? [])) : null,
            'tokens_estimated' => $this->estimate($text), 'protected' => $protected, 'selected' => true, 'selection_reason' => $protected ? '完整保留的必需上下文' : '预算允许的补充上下文', 'trim_reason' => null];
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
            'style_examples', 'writing_requirements' => [9, '文风样例与负面清单'],
            default => [2, '不可丢失的硬约束与当前证据'],
        };
    }
}
