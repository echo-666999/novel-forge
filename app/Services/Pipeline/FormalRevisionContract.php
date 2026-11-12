<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/** 正式稿修订协议：只改文字、事实不变。建议逐条由作者取舍，程序只替换被接受的原文片段；事实等价与证据重新定位逐条核对。 */
final class FormalRevisionContract
{
    /** v2：事实核对只问证据落在改动段落的事件，未改动段落的事件由程序判定成立；近义措辞替换不算事实改变。v3：事件摘要可能含提取时的推断，只以 before 原文为准。v4：事件不再附摘要，改附原文证据。 */
    public const VERSION = 'f01-formal-revision-v4';

    /** 每章最多给出的建议条数。 */
    public const SUGGESTION_LIMIT = 12;

    /** 单条替换后文字相对原片段允许的最大长度倍数。 */
    private const GROWTH_LIMIT = 2;

    /** 两种请求的固定指令。 */
    public static function instructions(string $purpose): string
    {
        return match ($purpose) {
            'suggest' => '你是中文长篇小说的文字编辑，只做措辞润色：去掉模板化句式、跨章重复的表达和生硬重复，让语句自然。只返回约定JSON，不重写整章。'
                .'严禁改变任何事实：人物、时间、地点、动作及其结果、数字、物证与文件名称、谁知道什么、因果顺序、限制条件和线索都必须保持不变；不新增情节、对白内容或人物心理结论。'
                .'每条建议只改一个连续原文片段：paragraph 是段号，quote 必须逐字摘自该段且在该段只出现一次，不跨段、不含换行；replacement 是替换后的文字，信息量与原片段相同，不使用Markdown。'
                .'优先处理输入 signals 中标出的跨章重复短语、章内重复和与相邻章雷同的开头结尾；没有值得改的地方就少给或不给，不要为改而改。最多'.self::SUGGESTION_LIMIT.'条，按价值从高到低排列。problem 用一句话说明问题。',
            'equivalence' => '你是事实核对员。输入只包含同一章润色前后发生改动的段落（before/after），未改动的段落逐字不变、故意不提供，不要因为看不到而判定事件不成立或认为内容缺失。events 只列出证据落在改动段落的事件。只返回约定JSON，不改写正文。'
                .'每个事件附有 before 中支持它的原文证据（evidence_before）。逐个事件比较 before 与 after，判断 after 是否仍然完整保留 evidence_before 写出的事实（holds）；只以原文实际写出的内容为准，原文没写的内容 after 没有也不算丢失。近义措辞替换不算事实改变，例如“没有/没”“写下/记下”“把/将”、语序调整、删去不改变意思的虚词；只有动作、对象、时间、地点、数量、结果、人物态度强弱、对白内容或知情范围的实质变化才算不成立。'
                .'new_facts 只列出 after 相对 before 新增的事实或暗示；lost_constraints 只列出 after 相对 before 删去或改变的关键限制、线索、因果步骤、人物知情范围；只比较改动段落，不要引用输入里不存在的句子。literary_issues 列出断句、语病、口吻不一致等文字问题。没有就给空列表。'
                .'若输入 unresolved 列出了需要重新定位的证据，为每一项在润色后正文中给出 paragraph 和逐字摘自该段的 quote，表达与原证据相同的事实。'
                .'只有所有事件成立、new_facts 与 lost_constraints 都为空时 verdict 才能为 equivalent。',
            default => throw new InvalidArgumentException('未知正式稿修订用途'),
        };
    }

    /** 严格结构化输出；段号、事件键和待定位证据编号都限制在冻结目录内，引用文本仍由程序逐字复验。
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema, string $purpose, array $context): array
    {
        $paragraph = fn () => $schema->integer()->min(1)->max($context['paragraph_count'])->required();
        if ($purpose === 'suggest') {
            return ['suggestions' => $schema->array()->max(self::SUGGESTION_LIMIT)->items($schema->object([
                'paragraph' => $paragraph(), 'quote' => $schema->string()->required(), 'replacement' => $schema->string()->required(), 'problem' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required()];
        }
        $events = $context['event_keys'] === [] ? ['none'] : $context['event_keys'];
        $refs = $context['unresolved_refs'] === [] ? ['none'] : $context['unresolved_refs'];

        return ['verdict' => $schema->string()->enum(['equivalent', 'not_equivalent'])->required(), 'summary' => $schema->string()->required(),
            'event_checks' => $schema->array()->items($schema->object(['event_key' => $schema->string()->enum($events)->required(), 'holds' => $schema->boolean()->required(),
                'reason' => $schema->string()->required()])->withoutAdditionalProperties())->required(),
            'new_facts' => $schema->array()->items($schema->string())->required(), 'lost_constraints' => $schema->array()->items($schema->string())->required(),
            'literary_issues' => $schema->array()->items($schema->string())->required(),
            'relocations' => $schema->array()->items($schema->object(['ref' => $schema->string()->enum($refs)->required(), 'paragraph' => $paragraph(),
                'quote' => $schema->string()->required()])->withoutAdditionalProperties())->required()];
    }

    /** 逐条校验建议；不合格的保留并标明原因，不能被接受。
     * @param  array<string, mixed>  $response
     * @return list<array{id: string, paragraph: int, quote: string, replacement: string, problem: string, valid: bool, invalid_reason: ?string}>
     */
    public static function suggestions(array $response, string $body): array
    {
        $items = $response['suggestions'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::SUGGESTION_LIMIT) {
            throw new InvalidArgumentException('润色建议格式无效');
        }
        $paragraphs = explode("\n", $body);
        $result = [];
        foreach ($items as $index => $item) {
            $paragraph = $item['paragraph'] ?? null;
            $quote = is_string($item['quote'] ?? null) ? $item['quote'] : '';
            $replacement = is_string($item['replacement'] ?? null) ? trim($item['replacement']) : '';
            $text = is_int($paragraph) ? ($paragraphs[$paragraph - 1] ?? null) : null;
            $reason = match (true) {
                $text === null => '段号不存在',
                $quote === '' || substr_count($text, $quote) !== 1 => '原句不在该段或在该段出现多次',
                $replacement === '' || $replacement === $quote => '改法为空或与原句相同',
                str_contains($replacement, "\n") || preg_match('/[#*`>|]/u', $replacement) === 1 => '改法含换行或Markdown',
                mb_strlen($replacement) > self::GROWTH_LIMIT * mb_strlen($quote) + 20 => '改法明显扩写，超出润色范围',
                default => null,
            };
            $result[] = ['id' => 's'.($index + 1), 'paragraph' => is_int($paragraph) ? $paragraph : 0, 'quote' => $quote, 'replacement' => $replacement,
                'problem' => is_string($item['problem'] ?? null) ? $item['problem'] : '', 'valid' => $reason === null, 'invalid_reason' => $reason];
        }

        return $result;
    }

    /** 只替换被接受的建议，同段多条按位置从后往前替换，片段互相重叠时拒绝。
     * @param  list<array<string, mixed>>  $accepted
     */
    public static function apply(string $body, array $accepted): string
    {
        $paragraphs = explode("\n", $body);
        $byParagraph = [];
        foreach ($accepted as $suggestion) {
            if (! ($suggestion['valid'] ?? false)) {
                throw new InvalidArgumentException('不能接受不合格的建议');
            }
            $text = $paragraphs[$suggestion['paragraph'] - 1];
            $start = mb_strpos($text, $suggestion['quote']);
            $byParagraph[$suggestion['paragraph']][] = [$start, $start + mb_strlen($suggestion['quote']), $suggestion['replacement']];
        }
        foreach ($byParagraph as $number => $edits) {
            usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
            $text = $paragraphs[$number - 1];
            $floor = PHP_INT_MAX;
            foreach ($edits as [$start, $end, $replacement]) {
                if ($end > $floor) {
                    throw new InvalidArgumentException('第'.$number.'段被接受的建议互相重叠，请只保留其中一条');
                }
                $text = mb_substr($text, 0, $start).$replacement.mb_substr($text, $end);
                $floor = $start;
            }
            $paragraphs[$number - 1] = $text;
        }

        return implode("\n", $paragraphs);
    }

    /** 段落数不变时，按段把旧证据移到新正文：段落未改则平移；整段引文取整个新段；片段仍在则取离原位置最近的出现处；否则留给模型重新定位。
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>|null
     */
    public static function relocate(array $evidence, string $oldBody, string $newBody): ?array
    {
        $old = explode("\n", $oldBody);
        $new = explode("\n", $newBody);
        $number = $evidence['paragraph'] ?? 0;
        if (count($old) !== count($new) || ! isset($old[$number - 1])) {
            return null;
        }
        $oldStart = self::paragraphStart($old, $number);
        $newStart = self::paragraphStart($new, $number);
        $within = $evidence['start'] - $oldStart;
        $quote = $evidence['quote'];
        if ($old[$number - 1] === $new[$number - 1]) {
            $offset = $within;
        } elseif ($quote === $old[$number - 1]) {
            $quote = $new[$number - 1];
            $offset = 0;
        } else {
            $offset = self::nearest($new[$number - 1], $quote, $within);
            if ($offset === null) {
                return null;
            }
        }

        return [...$evidence, 'start' => $newStart + $offset, 'end' => $newStart + $offset + mb_strlen($quote), 'quote' => $quote];
    }

    /** 模型给出的段号与引文转换为新正文绝对位置；引文须逐字出现在该段。
     * @return array{paragraph: int, start: int, end: int, quote: string}
     */
    public static function locate(string $body, int $paragraph, string $quote): array
    {
        $paragraphs = explode("\n", $body);
        $text = $paragraphs[$paragraph - 1] ?? null;
        $offset = $text === null || $quote === '' ? false : mb_strpos($text, $quote);
        if ($offset === false) {
            throw new InvalidArgumentException('重新定位的证据不在润色后正文指定段落');
        }
        $start = self::paragraphStart($paragraphs, $paragraph) + $offset;

        return ['paragraph' => $paragraph, 'start' => $start, 'end' => $start + mb_strlen($quote), 'quote' => $quote];
    }

    /** 段首在全文中的字符位置。
     * @param  list<string>  $paragraphs
     */
    private static function paragraphStart(array $paragraphs, int $number): int
    {
        return $number === 1 ? 0 : mb_strlen(implode("\n", array_slice($paragraphs, 0, $number - 1))) + 1;
    }

    /** 片段在段内离原位置最近的出现处。 */
    private static function nearest(string $text, string $quote, int $near): ?int
    {
        $best = null;
        for ($offset = mb_strpos($text, $quote); $offset !== false; $offset = mb_strpos($text, $quote, $offset + 1)) {
            if ($best === null || abs($offset - $near) < abs($best - $near)) {
                $best = $offset;
            }
        }

        return $best;
    }
}
