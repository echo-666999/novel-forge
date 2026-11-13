<?php

namespace App\Services\Pipeline;

/** 完整正文区段对照，保留所有原文，不把相同文字误标为新增。 */
final class BodyVersionDiff
{
    /** 按行寻找共同前后文，中间作为修改区段展示；不承诺最小字符编辑距离。
     * @return list<array{kind:string,text:string}>
     */
    public static function compare(string $before, string $after): array
    {
        if ($before === $after) {
            return [['kind' => '相同全文', 'text' => $before]];
        }
        // 保留每行换行符，输出各区段可无损还原比较两侧。
        $old = preg_split('/(?<=\n)/u', $before, -1, PREG_SPLIT_NO_EMPTY);
        $new = preg_split('/(?<=\n)/u', $after, -1, PREG_SPLIT_NO_EMPTY);
        $prefix = 0;
        while ($prefix < min(count($old), count($new)) && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < min(count($old), count($new)) - $prefix && $old[count($old) - 1 - $suffix] === $new[count($new) - 1 - $suffix]) {
            $suffix++;
        }
        $rows = [
            ['kind' => '相同前文', 'text' => implode('', array_slice($old, 0, $prefix))],
            ['kind' => '旧版区段（删除 / 替换）', 'text' => implode('', array_slice($old, $prefix, count($old) - $prefix - $suffix))],
            ['kind' => '新版区段（新增 / 替换）', 'text' => implode('', array_slice($new, $prefix, count($new) - $prefix - $suffix))],
            ['kind' => '相同后文', 'text' => $suffix === 0 ? '' : implode('', array_slice($old, -$suffix))],
        ];

        return array_values(array_filter($rows, fn (array $row): bool => $row['text'] !== ''));
    }

    /** 按段落对照：最长公共子序列对齐段落，相邻的删除与新增配成“修改”，并标出段内变化的文字；连续未变化的段落合并为一组。
     * @return array{identical: bool, counts: array{modified: int, added: int, removed: int, unchanged: int}, hunks: list<array<string, mixed>>}
     */
    public static function paragraphs(string $before, string $after): array
    {
        $old = self::split($before);
        $new = self::split($after);
        $n = count($old);
        $m = count($new);
        // 自底向上计算公共子序列长度，再顺序回放得到逐段操作。
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $old[$i] === $new[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $ops = [];
        [$i, $j] = [0, 0];
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                $ops[] = ['same', $i++, $j++];
            } elseif ($j < $m && ($i === $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $ops[] = ['add', null, $j++];
            } else {
                $ops[] = ['del', $i++, null];
            }
        }
        $hunks = [];
        $counts = ['modified' => 0, 'added' => 0, 'removed' => 0, 'unchanged' => 0];
        $removed = [];
        $added = [];
        $flush = function () use (&$removed, &$added, &$hunks, &$counts, $old, $new): void {
            foreach (array_keys(array_slice($removed, 0, min(count($removed), count($added)), true)) as $k) {
                [$oldText, $newText] = [$old[$removed[$k]], $new[$added[$k]]];
                $hunks[] = ['type' => 'change', 'kind' => 'modified', 'old_no' => $removed[$k] + 1, 'new_no' => $added[$k] + 1, ...self::highlight($oldText, $newText)];
                $counts['modified']++;
            }
            foreach (array_slice($removed, count($added)) as $index) {
                $hunks[] = ['type' => 'change', 'kind' => 'removed', 'old_no' => $index + 1, 'new_no' => null, 'old' => [['', $old[$index], '']], 'new' => null];
                $counts['removed']++;
            }
            foreach (array_slice($added, count($removed)) as $index) {
                $hunks[] = ['type' => 'change', 'kind' => 'added', 'old_no' => null, 'new_no' => $index + 1, 'old' => null, 'new' => [['', $new[$index], '']]];
                $counts['added']++;
            }
            [$removed, $added] = [[], []];
        };
        foreach ($ops as [$op, $oi, $nj]) {
            if ($op === 'same') {
                $flush();
                $last = array_key_last($hunks);
                if ($last !== null && $hunks[$last]['type'] === 'same') {
                    $hunks[$last]['paragraphs'][] = ['no' => $nj + 1, 'text' => $new[$nj]];
                } else {
                    $hunks[] = ['type' => 'same', 'paragraphs' => [['no' => $nj + 1, 'text' => $new[$nj]]]];
                }
                $counts['unchanged']++;
            } elseif ($op === 'del') {
                $removed[] = $oi;
            } else {
                $added[] = $nj;
            }
        }
        $flush();

        return ['identical' => $counts['modified'] + $counts['added'] + $counts['removed'] === 0, 'counts' => $counts, 'hunks' => $hunks];
    }

    /** 按行切成段落，忽略空行与首尾空白。
     * @return list<string>
     */
    private static function split(string $body): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $body) ?: []), fn (string $line): bool => $line !== ''));
    }

    /** 段内只标出中间变化的部分：去掉两段共同的开头与结尾。
     * @return array{old: list<array{0: string, 1: string, 2: string}>, new: list<array{0: string, 1: string, 2: string}>}
     */
    private static function highlight(string $old, string $new): array
    {
        $a = mb_str_split($old);
        $b = mb_str_split($new);
        $prefix = 0;
        while ($prefix < min(count($a), count($b)) && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < min(count($a), count($b)) - $prefix && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            $suffix++;
        }
        $part = fn (array $chars): array => [implode('', array_slice($chars, 0, $prefix)), implode('', array_slice($chars, $prefix, count($chars) - $prefix - $suffix)), $suffix === 0 ? '' : implode('', array_slice($chars, -$suffix))];

        return ['old' => [$part($a)], 'new' => [$part($b)]];
    }
}
