<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/** 首章固定步骤契约；结构验证与模型语义判断分别保存。 */
final class ContentSchema
{
    public const VERSION = 'b04-content-v2';

    public const BOUNDED_VERSION = 'b04-content-v3';

    public const ANCHORED_VERSION = 'b04-content-v4';

    public const COUNT_VERSION = 'visible-nonspace-plain-v1';

    /** 非空原段使用稳定ID；空行仍留在正文中且不改变原始段号。
     * @return array<string, array{paragraph: int, text: string, body_hash: string}>
     */
    public static function anchors(string $body): array
    {
        $anchors = [];
        foreach (self::paragraphs($body) as $paragraph) {
            if (self::count($paragraph['text']) > 0) {
                $anchors['p'.$paragraph['paragraph']] = $paragraph + ['body_hash' => hash('sha256', $body)];
            }
        }

        return $anchors;
    }

    /** 新协议仅允许选择冻结的非空段ID，不让模型重抄原文。
     * @return array<string, mixed>
     */
    public static function anchoredSchema(JsonSchema $schema, array $budget, array $anchors): array
    {
        self::validateBudget($budget);
        if (count($anchors) < $budget['count']) {
            throw new InvalidArgumentException('可用非空锚点少于扩写数量');
        }

        return ['insertions' => $schema->array()->min($budget['count'])->max($budget['count'])->items($schema->object([
            'anchor_id' => $schema->string()->enum(array_keys($anchors))->required(),
            'text' => $schema->string()->pattern(self::paragraphPattern($budget))->required(),
            'reason' => $schema->string()->required(),
        ])->withoutAdditionalProperties())->required()];
    }

    /** 先复验整份锚点清单，再将ID确定性映射为原段插入补丁。 */
    public static function anchoredExpand(array $data, string $body, array $budget, array $anchors): string
    {
        if (PlanningJson::hash($anchors) !== PlanningJson::hash(self::anchors($body))) {
            throw new InvalidArgumentException('冻结锚点或源正文摘要变化');
        }
        self::keys($data, ['insertions']);
        self::listing($data['insertions']);
        $patches = [];
        $seen = [];
        foreach ($data['insertions'] as $patch) {
            self::keys($patch, ['anchor_id', 'text', 'reason']);
            $id = $patch['anchor_id'];
            if (! is_string($id) || ! isset($anchors[$id]) || isset($seen[$id])) {
                throw new InvalidArgumentException('未知或重复锚点ID');
            }
            $seen[$id] = true;
            $patches[] = ['paragraph' => $anchors[$id]['paragraph'], 'anchor' => $anchors[$id]['text'], 'text' => $patch['text'], 'reason' => $patch['reason']];
        }

        return self::boundedExpand(['insertions' => $patches], $body, $budget);
    }

    /** 从冻结篇幅与源正文计算段落预算，数量优先3处，无法分配时收敛到2或1处。
     * @return array{count: int, min: int, max: int, source_count: int, final_min: int, final_max: int}
     */
    public static function paragraphBudget(int $sourceCount, int $minimum, int $maximum): array
    {
        if ($sourceCount < 0 || $minimum <= $sourceCount || $maximum < $minimum) {
            throw new InvalidArgumentException('段落预算仅适用于合法的篇幅不足正文');
        }
        $target = intdiv($minimum + $maximum, 2);
        for ($count = 3; $count >= 1; $count--) {
            $lower = max(1, intdiv($minimum - $sourceCount + $count - 1, $count), intdiv($target - $sourceCount, 50 * $count) * 50);
            $upper = min(intdiv($maximum - $sourceCount, $count), intdiv($target - $sourceCount + 50 * $count - 1, 50 * $count) * 50);
            if ($lower <= $upper) {
                return ['count' => $count, 'min' => $lower, 'max' => $upper, 'source_count' => $sourceCount,
                    'final_min' => $sourceCount + $count * $lower, 'final_max' => $sourceCount + $count * $upper];
            }
        }
        throw new InvalidArgumentException('无法在冻结篇幅范围内分配段落预算');
    }

    /** 固定预算结构，防止调用方伪造段长或预计整章范围。 */
    public static function validateBudget(array $budget): void
    {
        self::keys($budget, ['count', 'min', 'max', 'source_count', 'final_min', 'final_max']);
        foreach ($budget as $value) {
            if (! is_int($value) || $value < 0 || $value > 2147483647) {
                throw new InvalidArgumentException('段落预算必须使用有效整数');
            }
        }
        if ($budget['count'] < 1 || $budget['count'] > 3 || $budget['min'] < 1 || $budget['max'] < $budget['min']
            || $budget['final_min'] !== $budget['source_count'] + $budget['count'] * $budget['min']
            || $budget['final_max'] !== $budget['source_count'] + $budget['count'] * $budget['max']) {
            throw new InvalidArgumentException('段落预算范围或数量不一致');
        }
    }

    /** 使用OpenAI支持的pattern约束单段长度；本地仍以原计数算法复核。 */
    public static function paragraphPattern(array $budget): string
    {
        self::validateBudget($budget);

        return '^[^\\s#*_`~>]{'.$budget['min'].','.$budget['max'].'}$';
    }

    /** 把数量与逐段长度直接写入最终供应商Schema，而非仅写在提示词里。
     * @return array<string, mixed>
     */
    public static function boundedSchema(JsonSchema $schema, array $budget): array
    {
        self::validateBudget($budget);

        return ['insertions' => $schema->array()->min($budget['count'])->max($budget['count'])->items($schema->object([
            'paragraph' => $schema->integer()->min(1)->required(), 'anchor' => $schema->string()->required(),
            'text' => $schema->string()->pattern(self::paragraphPattern($budget))->required(), 'reason' => $schema->string()->required(),
        ])->withoutAdditionalProperties())->required()];
    }

    /** 本地逐段核验数量、字符及格式，再复用只插入的原文保护逻辑。 */
    public static function boundedExpand(array $data, string $body, array $budget): string
    {
        self::validateBudget($budget);
        self::keys($data, ['insertions']);
        self::listing($data['insertions']);
        if (self::count($body) !== $budget['source_count'] || count($data['insertions']) !== $budget['count']) {
            throw new InvalidArgumentException('源字数或补丁数量与冻结预算不一致');
        }
        foreach ($data['insertions'] as $patch) {
            self::keys($patch, ['paragraph', 'anchor', 'text', 'reason']);
            self::text($patch['text']);
            if (preg_match('/[\s\p{Z}\p{Cf}\p{Cc}#*_`~>]/u', $patch['text']) === 1
                || self::count($patch['text']) < $budget['min'] || self::count($patch['text']) > $budget['max']) {
                throw new InvalidArgumentException('插入段落长度或格式不符合冻结预算');
            }
        }
        $expanded = self::expand($data, $body);
        $actual = self::count($expanded);
        if ($actual < $budget['final_min'] || $actual > $budget['final_max']) {
            throw new InvalidArgumentException('整章字数与段落预算不一致');
        }

        return $expanded;
    }

    /** 按固定步骤构造严格输出，不接受调用方自定义 Schema。
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema, string $step): array
    {
        $texts = fn (array $keys): array => array_combine($keys, array_map(fn () => $schema->string()->required(), $keys));
        if ($step === 'fine') {
            return ['scenes' => $schema->array()->min(3)->max(5)->items($schema->object($texts(['time', 'place', 'action', 'change']) + [
                'point_ids' => $schema->array()->items($schema->string())->required(),
            ])->withoutAdditionalProperties())->required(), 'hook' => $schema->string()->required(),
                'adjustments' => $schema->array()->items($schema->string())->required(),
                'hook_transition' => $schema->object($texts(['completed_state', 'trigger', 'next_question']) + ['point_ids' => $schema->array()->items($schema->string())->min(1)->required()])->withoutAdditionalProperties()->required()];
        }
        if ($step === 'length_repair') {
            return ['insertions' => $schema->array()->min(1)->max(5)->items($schema->object($texts(['anchor', 'text', 'reason']) + [
                'paragraph' => $schema->integer()->min(1)->required(),
            ])->withoutAdditionalProperties())->required()];
        }
        if ($step === 'revise') {
            return ['edits' => $schema->array()->min(1)->max(5)->items($schema->object($texts(['before', 'after', 'reason']) + [
                'paragraph' => $schema->integer()->min(1)->required(),
            ])->withoutAdditionalProperties())->required()];
        }
        if ($step === 'draft') {
            return $texts(['title', 'body']);
        }
        $evidence = $schema->array()->items($schema->object(['paragraph' => $schema->integer()->min(1)->required(), 'quote' => $schema->string()->required()])->withoutAdditionalProperties())->required();
        $result = $texts(['reason']) + ['met' => $schema->boolean()->required(), 'evidence' => $evidence];

        return ['points' => $schema->array()->min(1)->max(3)->items($schema->object(['id' => $schema->string()->required()] + $result)->withoutAdditionalProperties())->required(),
            'hook' => $schema->object($result)->withoutAdditionalProperties()->required(),
            'conflicts' => $schema->array()->items($schema->object($texts(['reason']) + ['evidence' => $evidence])->withoutAdditionalProperties())->required()];
    }

    /** 严格解码对象；响应原文由 CostLog 保留，解析失败不能请求自动修补。 */
    public static function decode(string $text): array
    {
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('首章响应必须是对象');
        }

        return $data;
    }

    /** 确认对象恰好包含约定字段。
     * @param  list<string>  $keys
     */
    public static function keys(mixed $data, array $keys): void
    {
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('对象结构不合法');
        }
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('字段缺失或包含额外字段');
        }
    }

    /** 所有业务文本必须包含非空白可见内容。 */
    public static function text(mixed $value): void
    {
        if (! is_string($value) || preg_match('/[^\s\p{Z}\p{Cf}]/u', $value) !== 1) {
            throw new InvalidArgumentException('必填文本为空');
        }
    }

    /** 列表类型不能被对象或稀疏数组替代。 */
    public static function listing(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('应提供连续列表');
        }
    }

    /** 细纲必须完整映射原推进点，不能新增或替换作者契约。
     * @param  list<string>  $pointIds
     */
    public static function fine(array $data, array $pointIds, ?string $invalidHook = null): array
    {
        self::keys($data, ['scenes', 'hook', 'adjustments', 'hook_transition']);
        self::keys($data['hook_transition'], ['completed_state', 'trigger', 'next_question', 'point_ids']);
        foreach (['completed_state', 'trigger', 'next_question'] as $field) {
            self::text($data['hook_transition'][$field]);
        }
        self::listing($data['hook_transition']['point_ids']);
        if ($data['hook_transition']['point_ids'] === [] || array_diff($data['hook_transition']['point_ids'], $pointIds) !== []) {
            throw new InvalidArgumentException('钩子必须承接已有推进点完成后的状态');
        }
        if ($invalidHook !== null && preg_replace('/[\s\p{Z}\p{Cf}]/u', '', $data['hook']) === preg_replace('/[\s\p{Z}\p{Cf}]/u', '', $invalidHook)) {
            throw new InvalidArgumentException('本次已知失效钩子不能原样复用');
        }
        self::text($data['hook']);
        self::listing($data['scenes']);
        self::listing($data['adjustments']);
        if (count($data['scenes']) < 3 || count($data['scenes']) > 5) {
            throw new InvalidArgumentException('首章应包含3至5个场景');
        }
        $covered = [];
        foreach ($data['scenes'] as $scene) {
            // v15场景额外携带写戏字段；v14细纲只有基础字段，复用时仍可核验。
            $fields = ['time', 'place', 'action', 'change'];
            if (is_array($scene) && array_key_exists('goal', $scene)) {
                $fields = [...$fields, ...ContentExecutionContract::DRAMA_FIELDS];
            }
            self::keys($scene, [...$fields, 'point_ids']);
            foreach ($fields as $key) {
                self::text($scene[$key]);
            }
            self::listing($scene['point_ids']);
            foreach ($scene['point_ids'] as $id) {
                if (! is_string($id) || ! in_array($id, $pointIds, true)) {
                    throw new InvalidArgumentException('细纲引用了不存在的推进点');
                }
                $covered[] = $id;
            }
        }
        foreach ($data['adjustments'] as $adjustment) {
            self::text($adjustment);
        }
        if (array_diff($pointIds, $covered) !== []) {
            throw new InvalidArgumentException('细纲遗漏推进点');
        }

        return $data;
    }

    /** 正文只统一换行；原供应商响应保持不变，不截断或人工补齐正文。 */
    public static function body(array $data): array
    {
        self::keys($data, ['title', 'body']);
        self::text($data['title']);
        self::text($data['body']);
        if (mb_strlen($data['title']) > 255 || preg_match('/^第\s*[0-9一二三四五六七八九十百]+\s*章\s*$/u', trim($data['title'])) === 1) {
            throw new InvalidArgumentException('章名为空泛占位或超长');
        }
        $data['body'] = str_replace(["\r\n", "\r"], "\n", $data['body']);

        return $data;
    }

    /** 字数只计算正文可见非空白字符，排除约定 Markdown 格式标记。 */
    public static function count(string $body): int
    {
        return mb_strlen(preg_replace('/[\s\p{Z}\p{Cf}#*_`~>]/u', '', $body));
    }

    /** 提供稳定的一基行段号，空行也占编号，证据不能跨段猜测匹配。
     * @return list<array{paragraph: int, text: string}>
     */
    public static function paragraphs(string $body): array
    {
        return array_map(fn (string $text, int $index): array => ['paragraph' => $index + 1, 'text' => $text], explode("\n", $body), array_keys(explode("\n", $body)));
    }

    /** 扩写只允许在精确原段之后插入，程序逐字保留原文，拒绝重复或空补丁。 */
    public static function expand(array $data, string $body): string
    {
        self::keys($data, ['insertions']);
        self::listing($data['insertions']);
        if (count($data['insertions']) < 1 || count($data['insertions']) > 5) {
            throw new InvalidArgumentException('扩写只允许1至5处插入');
        }
        $paragraphs = explode("\n", $body);
        $insertions = [];
        $knownParagraphs = array_fill_keys(array_filter(array_map('trim', $paragraphs)), true);
        foreach ($data['insertions'] as $patch) {
            self::keys($patch, ['paragraph', 'anchor', 'text', 'reason']);
            foreach (['anchor', 'text', 'reason'] as $field) {
                self::text($patch[$field]);
            }
            $number = $patch['paragraph'];
            if (! is_int($number) || $number < 1 || isset($insertions[$number]) || ($paragraphs[$number - 1] ?? null) !== $patch['anchor'] || str_contains($patch['text'], "\r") || trim($patch['text']) === trim($patch['anchor'])) {
                throw new InvalidArgumentException('扩写锚点不匹配、重复或内容重复');
            }
            foreach (explode("\n", $patch['text']) as $line) {
                $line = trim($line);
                if ($line !== '' && isset($knownParagraphs[$line])) {
                    throw new InvalidArgumentException('扩写不能复制已有段落凑字');
                }
                if ($line !== '') {
                    $knownParagraphs[$line] = true;
                }
            }
            $insertions[$number] = $patch['text'];
        }
        $result = [];
        foreach ($paragraphs as $index => $paragraph) {
            $result[] = $paragraph;
            if (isset($insertions[$index + 1])) {
                $result[] = $insertions[$index + 1];
            }
        }
        $expanded = implode("\n", $result);
        if (self::count($expanded) <= self::count($body)) {
            throw new InvalidArgumentException('扩写没有增加可见字符');
        }

        return $expanded;
    }

    /** 精确段落替换产生新版本，未指定段落逐字保留，不允许整章隐式重写。 */
    public static function revise(array $data, string $body): string
    {
        self::keys($data, ['edits']);
        self::listing($data['edits']);
        if (count($data['edits']) < 1 || count($data['edits']) > 5) {
            throw new InvalidArgumentException('修订仅允许1至5处段落替换');
        }
        $paragraphs = explode("\n", $body);
        $used = [];
        foreach ($data['edits'] as $edit) {
            self::keys($edit, ['paragraph', 'before', 'after', 'reason']);
            foreach (['before', 'after', 'reason'] as $key) {
                self::text($edit[$key]);
            }
            $number = $edit['paragraph'];
            if (! is_int($number) || isset($used[$number]) || ($paragraphs[$number - 1] ?? null) !== $edit['before'] || $edit['before'] === $edit['after'] || str_contains($edit['after'], "\r")) {
                throw new InvalidArgumentException('修订锚点不匹配、重复或未产生变化');
            }
            $used[$number] = true;
            $paragraphs[$number - 1] = $edit['after'];
        }
        // 至少保留一段非空原文，防止以段落替换之名默认整章重写。
        $preserved = false;
        foreach (explode("\n", $body) as $index => $paragraph) {
            if (! isset($used[$index + 1]) && preg_match('/[^\s\p{Z}\p{Cf}]/u', $paragraph) === 1) {
                $preserved = true;
            }
        }
        if (! $preserved) {
            throw new InvalidArgumentException('修订必须保留未涉及的原文段落，不能整章替换');
        }

        return implode("\n", $paragraphs);
    }
}
