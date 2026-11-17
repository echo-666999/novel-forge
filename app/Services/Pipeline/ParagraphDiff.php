<?php

namespace App\Services\Pipeline;

/** 按段对比两份正文（每段一行），供人工编辑页查看改动；同一位置被改写的段再标出段内不同的部分。只读计算，不涉及任何存储。 */
final class ParagraphDiff
{
    /** 对比结果：unchanged 为连续未改动的段数，changed 为改写段（前后缀相同部分不高亮），removed/added 为整段删除或新增。
     * @return list<array{type: string, count?: int, old?: array{0: string, 1: string, 2: string}, new?: array{0: string, 1: string, 2: string}, text?: string}>
     */
    public static function compare(string $old, string $new): array
    {
        $before = self::paragraphs($old);
        $after = self::paragraphs($new);
        $operations = self::operations($before, $after);
        $rows = [];
        $removed = [];
        $added = [];
        $flush = function () use (&$rows, &$removed, &$added): void {
            foreach (array_keys($removed + $added) as $index) {
                if (isset($removed[$index], $added[$index])) {
                    [$oldParts, $newParts] = self::inline($removed[$index], $added[$index]);
                    $rows[] = ['type' => 'changed', 'old' => $oldParts, 'new' => $newParts];
                } elseif (isset($removed[$index])) {
                    $rows[] = ['type' => 'removed', 'text' => $removed[$index]];
                } else {
                    $rows[] = ['type' => 'added', 'text' => $added[$index]];
                }
            }
            $removed = [];
            $added = [];
        };
        foreach ($operations as [$type, $text]) {
            if ($type === 'same') {
                $flush();
                $last = array_key_last($rows);
                if ($last !== null && $rows[$last]['type'] === 'unchanged') {
                    $rows[$last]['count']++;
                } else {
                    $rows[] = ['type' => 'unchanged', 'count' => 1];
                }
            } elseif ($type === 'removed') {
                $removed[] = $text;
            } else {
                $added[] = $text;
            }
        }
        $flush();

        return $rows;
    }

    /** 改动段数（改写、删除、新增各算一段）。
     * @param  list<array{type: string}>  $rows
     */
    public static function changes(array $rows): int
    {
        return count(array_filter($rows, fn (array $row): bool => $row['type'] !== 'unchanged'));
    }

    /** @return list<string> */
    private static function paragraphs(string $body): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $body) ?: []), fn (string $line): bool => $line !== ''));
    }

    /** 最长公共子序列回溯出逐段操作。
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{0: string, 1: string}>
     */
    private static function operations(array $before, array $after): array
    {
        $rows = count($before);
        $columns = count($after);
        $lengths = array_fill(0, $rows + 1, array_fill(0, $columns + 1, 0));
        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $before[$i] === $after[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $operations = [];
        [$i, $j] = [0, 0];
        while ($i < $rows || $j < $columns) {
            if ($i < $rows && $j < $columns && $before[$i] === $after[$j]) {
                $operations[] = ['same', $before[$i]];
                $i++;
                $j++;
            } elseif ($j < $columns && ($i === $rows || $lengths[$i][$j + 1] >= $lengths[$i + 1][$j])) {
                $operations[] = ['added', $after[$j++]];
            } else {
                $operations[] = ['removed', $before[$i++]];
            }
        }

        return $operations;
    }

    /** 段内只高亮去掉相同前后缀后的中间部分。
     * @return array{0: array{0: string, 1: string, 2: string}, 1: array{0: string, 1: string, 2: string}}
     */
    private static function inline(string $old, string $new): array
    {
        $a = mb_str_split($old);
        $b = mb_str_split($new);
        $prefix = 0;
        while ($prefix < count($a) && $prefix < count($b) && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < count($a) - $prefix && $suffix < count($b) - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            $suffix++;
        }
        $split = fn (array $chars): array => [implode('', array_slice($chars, 0, $prefix)), implode('', array_slice($chars, $prefix, count($chars) - $prefix - $suffix)), implode('', array_slice($chars, count($chars) - $suffix))];

        return [$split($a), $split($b)];
    }
}
