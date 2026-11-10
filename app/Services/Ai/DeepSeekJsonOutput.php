<?php

namespace App\Services\Ai;

/** 只移除字符串之外的末尾逗号；不补正文、引号、括号或截断响应。 */
final class DeepSeekJsonOutput
{
    /** 原始文本另存于供应商响应；返回可逐字复核的字节偏移，不允许无痕修补。
     * @return array{text: string, removed_comma_offsets: list<int>}
     */
    public static function normalize(string $text): array
    {
        if (json_validate($text)) {
            return ['text' => $text, 'removed_comma_offsets' => []];
        }
        $inside = false;
        $escaped = false;
        $previous = null;
        $normalized = '';
        $removed = [];
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $character = $text[$i];
            if ($inside) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inside = false;
                }
            } elseif ($character === '"') {
                $inside = true;
            } elseif ($character === ',' && $previous !== null && ! in_array($previous, ['[', '{', ',', ':'], true)) {
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $text[$next])) {
                    $next++;
                }
                if ($next < $length && in_array($text[$next], [']', '}'], true)) {
                    $removed[] = $i;

                    continue;
                }
            }
            $normalized .= $character;
            if (! str_contains(" \t\r\n", $character)) {
                $previous = $character;
            }
        }

        // 只接受完整有效的结果；仍有语法错误时回退原文并由供应商适配器拒绝。
        return $removed !== [] && json_validate($normalized)
            ? ['text' => $normalized, 'removed_comma_offsets' => $removed]
            : ['text' => $text, 'removed_comma_offsets' => []];
    }
}
