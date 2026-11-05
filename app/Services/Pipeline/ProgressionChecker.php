<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 校验独立检查模型的逐点判断及原文证据，不信任模型自报的整体通过。 */
final class ProgressionChecker
{
    /** 本地核对正文摘要、全部推进点和钩子，计算最终结论。
     * @param  list<string>  $pointIds
     * @return array{verdict: string, points: array, hook: array, conflicts: array}
     */
    public function validate(array $data, string $body, array $pointIds): array
    {
        ContentSchema::keys($data, ['points', 'hook', 'conflicts']);
        ContentSchema::listing($data['points']);
        ContentSchema::listing($data['conflicts']);
        $seen = [];
        $pass = true;
        foreach ($data['points'] as &$point) {
            ContentSchema::keys($point, ['id', 'met', 'reason', 'evidence']);
            if (! is_string($point['id']) || ! in_array($point['id'], $pointIds, true) || in_array($point['id'], $seen, true)) {
                throw new InvalidArgumentException('检查推进点缺失、重复或越界');
            }
            $seen[] = $point['id'];
            $this->item($point, $body);
            $pass = $pass && $point['met'];
        }
        unset($point);
        if (array_diff($pointIds, $seen) !== []) {
            throw new InvalidArgumentException('检查遗漏强制推进点');
        }
        ContentSchema::keys($data['hook'], ['met', 'reason', 'evidence']);
        $this->item($data['hook'], $body);
        foreach ($data['conflicts'] as &$conflict) {
            ContentSchema::keys($conflict, ['reason', 'evidence']);
            ContentSchema::text($conflict['reason']);
            $this->evidence($conflict['evidence'], $body, true);
        }
        unset($conflict);

        return ['verdict' => $data['conflicts'] !== [] ? 'blocked' : ($pass && $data['hook']['met'] ? 'pass' : 'insufficient'), ...$data];
    }

    /** 每个肯定判断必须提供可定位原文，否定判断允许无证据但必须解释。 */
    private function item(array &$item, string $body): void
    {
        ContentSchema::text($item['reason']);
        if (! is_bool($item['met'])) {
            throw new InvalidArgumentException('完成判断必须是布尔值');
        }
        $this->evidence($item['evidence'], $body, $item['met']);
    }

    /** 引文在指定段落中须唯一匹配；位置为零基Unicode字符区间，终点不含。
     * @param  list<array<string, mixed>>  $evidence
     */
    private function evidence(array &$evidence, string $body, bool $required): void
    {
        ContentSchema::listing($evidence);
        if ($required && $evidence === []) {
            throw new InvalidArgumentException('肯定判断缺少原文证据');
        }
        $paragraphs = explode("\n", $body);
        foreach ($evidence as &$quote) {
            ContentSchema::keys($quote, ['paragraph', 'quote']);
            ContentSchema::text($quote['quote']);
            $number = $quote['paragraph'];
            $text = is_int($number) && $number >= 1 ? ($paragraphs[$number - 1] ?? null) : null;
            if ($text === null || mb_substr_count($text, $quote['quote']) !== 1) {
                throw new InvalidArgumentException('检查引用不存在或无法唯一定位');
            }
            $prefix = implode("\n", array_slice($paragraphs, 0, $number - 1));
            $quote['start'] = ($number === 1 ? 0 : mb_strlen($prefix) + 1) + mb_strpos($text, $quote['quote']);
            $quote['end'] = $quote['start'] + mb_strlen($quote['quote']);
        }
    }
}
