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
}
