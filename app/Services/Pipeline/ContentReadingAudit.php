<?php

namespace App\Services\Pipeline;

/** 正文阅读辅助审计；只提示可复核的重复与时序风险，不覆盖模型历史结论。 */
final class ContentReadingAudit
{
    public const VERSION = 'b04-reading-audit-v1';

    /** 重复片段只是复核线索，不能把修辞复沓直接裁定为剧情错误。
     * @return array{version: string, body_hash: string, requires_review: bool, issues: list<array{type: string, reason: string, evidence: list<array{paragraph: int, quote: string, start: int, end: int}>}>}
     */
    public static function inspect(string $body): array
    {
        $paragraphs = ContentSchema::anchors($body);
        $index = [];
        $pairs = [];
        foreach ($paragraphs as $paragraph) {
            $text = $paragraph['text'];
            $number = $paragraph['paragraph'];
            // 十二个连续原文字符完全一致才提示；按段对合并，避免同一片段刷屏。
            for ($offset = 0; $offset <= mb_strlen($text) - 12; $offset++) {
                $quote = mb_substr($text, $offset, 12);
                foreach ($index[$quote] ?? [] as $priorNumber => $priorOffset) {
                    if ($priorNumber === $number) {
                        continue;
                    }
                    $key = $priorNumber.':'.$number;
                    if (! isset($pairs[$key])) {
                        $pairs[$key] = ['type' => 'repeated_text', 'reason' => '不同段落出现连续相同原文；需区分有意复沓和重复动作。',
                            'evidence' => [self::quote($body, $priorNumber, $priorOffset, $quote), self::quote($body, $number, $offset, $quote)]];
                    }
                }
                $index[$quote][$number] ??= $offset;
            }
        }
        $issues = array_values($pairs);
        $departure = null;
        foreach ($paragraphs as $paragraph) {
            $text = $paragraph['text'];
            if (preg_match('/(?:第二天|次日|翌日|清晨|黎明).{0,30}(?:踏上|出门|出发)/u', $text) === 1) {
                $departure = $paragraph;
            } elseif ($departure !== null && preg_match('/(?:决定|打算).{0,12}(?:第二天|次日|明日|明天).{0,16}(?:验证|调查|出发)/u', $text) === 1
                && preg_match('/回想|回忆|昨夜|此前|当时/u', $text) !== 1) {
                $issues[] = ['type' => 'possible_time_order', 'reason' => '已有次日出发叙述，后文又决定次日行动；请核实是否有明确回忆衔接。',
                    'evidence' => [self::quote($body, $departure['paragraph'], 0, $departure['text']), self::quote($body, $paragraph['paragraph'], 0, $text)]];
            }
        }

        return ['version' => self::VERSION, 'body_hash' => hash('sha256', $body), 'requires_review' => $issues !== [], 'issues' => $issues];
    }

    /** 按原文字符计算零基区间，重复文本分别绑定各自段落，不猜测语义。
     * @return array{paragraph: int, quote: string, start: int, end: int}
     */
    private static function quote(string $body, int $paragraph, int $offset, string $quote): array
    {
        $prefix = implode("\n", array_slice(explode("\n", $body), 0, $paragraph - 1));
        $start = ($paragraph === 1 ? 0 : mb_strlen($prefix) + 1) + $offset;

        return ['paragraph' => $paragraph, 'quote' => $quote, 'start' => $start, 'end' => $start + mb_strlen($quote)];
    }
}
