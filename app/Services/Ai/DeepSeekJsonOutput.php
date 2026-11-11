<?php

namespace App\Services\Ai;

/** 只移除字符串之外的末尾逗号，以及完整根对象之后多余的闭合括号；不补正文、引号、括号或截断响应。 */
final class DeepSeekJsonOutput
{
    /** 原始文本另存于供应商响应；返回可逐字复核的字节偏移，不允许无痕修补。
     * @return array{text: string, removed_comma_offsets: list<int>, removed_trailing_offsets: list<int>}
     */
    public static function normalize(string $text): array
    {
        if (json_validate($text)) {
            return ['text' => $text, 'removed_comma_offsets' => [], 'removed_trailing_offsets' => []];
        }
        $commas = self::withoutTrailingCommas($text);
        $candidate = $commas['text'];
        $trailing = [];
        if (! json_validate($candidate)) {
            // 根对象已完整闭合、其后只有空白与多余闭合括号时，移除这些括号并记录偏移；根对象内部不作任何改动。
            $end = self::rootEnd($candidate);
            if ($end !== null && preg_match('/\A[\s\]}]+\z/', substr($candidate, $end + 1)) === 1 && json_validate(substr($candidate, 0, $end + 1))) {
                for ($i = $end + 1; $i < strlen($candidate); $i++) {
                    if (in_array($candidate[$i], [']', '}'], true)) {
                        $trailing[] = $i;
                    }
                }
                $candidate = substr($candidate, 0, $end + 1);
            }
        }

        // 只接受完整有效的结果；仍有语法错误时回退原文并由供应商适配器拒绝。
        return ($commas['removed'] !== [] || $trailing !== []) && json_validate($candidate)
            ? ['text' => $candidate, 'removed_comma_offsets' => $commas['removed'], 'removed_trailing_offsets' => $trailing]
            : ['text' => $text, 'removed_comma_offsets' => [], 'removed_trailing_offsets' => []];
    }

    /** 根对象或根数组的结束字节位置；字符串内的括号不计入，未闭合时返回空。 */
    private static function rootEnd(string $text): ?int
    {
        $depth = 0;
        $inside = false;
        $escaped = false;
        $started = false;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $character = $text[$i];
            if ($inside) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inside = false;
                }

                continue;
            }
            if ($character === '"') {
                $inside = true;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
                $started = true;
            } elseif (($character === '}' || $character === ']') && --$depth === 0 && $started) {
                return $i;
            }
        }

        return null;
    }

    /** 移除字符串之外、紧接闭合括号前的逗号。
     * @return array{text: string, removed: list<int>}
     */
    private static function withoutTrailingCommas(string $text): array
    {
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

        return ['text' => $normalized, 'removed' => $removed];
    }
}
