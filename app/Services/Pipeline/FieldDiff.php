<?php

namespace App\Services\Pipeline;

/**
 * 字段级对照的通用积木：单值比较、列表按集合比较、分区汇总。
 *
 * 结果统一为 {identical, counts, sections}，由 field-diff 视图展示；各业务对照（设定、实体定义等）只负责把字段映射成分区。
 */
final class FieldDiff
{
    /** 汇总各分区，去掉没有内容的分区并统计变化数量。
     * @param  list<array{title: string, changes: list<array{label: string, kind: string, old: ?string, new: ?string}>, unchanged: list<array{label: string, value: string}>}>  $sections
     * @return array{identical: bool, counts: array{modified: int, added: int, removed: int, unchanged: int}, sections: list<array{title: string, changes: list<array{label: string, kind: string, old: ?string, new: ?string}>, unchanged: list<array{label: string, value: string}>}>}
     */
    public static function summarize(array $sections): array
    {
        $sections = array_values(array_filter($sections, fn (array $section): bool => $section['changes'] !== [] || $section['unchanged'] !== []));
        $counts = ['modified' => 0, 'added' => 0, 'removed' => 0, 'unchanged' => 0];
        foreach ($sections as $section) {
            foreach ($section['changes'] as $change) {
                $counts[$change['kind']]++;
            }
            $counts['unchanged'] += count($section['unchanged']);
        }

        return ['identical' => $counts['modified'] + $counts['added'] + $counts['removed'] === 0, 'counts' => $counts, 'sections' => $sections];
    }

    /** 把一组比较结果拆成变化与未变化两部分；两边都没有的字段不展示。
     * @param  list<array{label: string, kind: string, old: ?string, new: ?string}>  $items
     * @return array{title: string, changes: list<array{label: string, kind: string, old: ?string, new: ?string}>, unchanged: list<array{label: string, value: string}>}
     */
    public static function section(string $title, array $items): array
    {
        $changes = [];
        $unchanged = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'unchanged') {
                $unchanged[] = ['label' => $item['label'], 'value' => (string) $item['new']];
            } elseif ($item['kind'] !== 'absent') {
                $changes[] = $item;
            }
        }

        return ['title' => $title, 'changes' => $changes, 'unchanged' => $unchanged];
    }

    /** 单值字段：两边都没有时标记为 absent，不计入任何统计。
     * @return array{label: string, kind: string, old: ?string, new: ?string}
     */
    public static function scalar(string $label, mixed $old, mixed $new): array
    {
        $old = self::text($old);
        $new = self::text($new);
        $kind = match (true) {
            $old === null && $new === null => 'absent',
            $old === null => 'added',
            $new === null => 'removed',
            $old === $new => 'unchanged',
            default => 'modified',
        };

        return ['label' => $label, 'kind' => $kind, 'old' => $old, 'new' => $new];
    }

    /** 字符串列表按集合比较：顺序变化不算修改，只报告新增与删除的条目。
     * @return list<array{label: string, kind: string, old: ?string, new: ?string}>
     */
    public static function set(string $label, mixed $old, mixed $new): array
    {
        $old = array_values(array_unique(array_filter(array_map(self::text(...), self::listOf($old)))));
        $new = array_values(array_unique(array_filter(array_map(self::text(...), self::listOf($new)))));
        $items = [];
        foreach ($new as $item) {
            $kept = in_array($item, $old, true);
            $items[] = ['label' => $label, 'kind' => $kept ? 'unchanged' : 'added', 'old' => $kept ? $item : null, 'new' => $item];
        }
        foreach (array_diff($old, $new) as $item) {
            $items[] = ['label' => $label, 'kind' => 'removed', 'old' => $item, 'new' => null];
        }

        return $items;
    }

    /** 只接受非空标量，统一去除首尾空白；布尔值转为是/否。 */
    public static function text(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /** 非数组按空列表处理。
     * @return list<mixed>
     */
    public static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
