<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;

/**
 * 开篇交代清单：设定阶段从创意中列出全书立足的设定钩子（重生、穿越、出身处境等）以及要在第1至3章交代的内容，
 * 大纲首批据此安排前三章，正文前三章逐项写出，检查只记录覆盖情况。随故事根基一起冻结（generation_config.story_foundation，见 StoryFoundation）；
 * 设定里有 opening_brief 的书才会在大纲、正文和检查中使用，旧书请求不变。
 */
final class OpeningBrief
{
    /** 清单最多覆盖到第几章。 */
    public const LAST_CHAPTER = 3;

    public const MIN_ITEMS = 3;

    public const MAX_ITEMS = 8;

    /** 覆盖情况：已交代、一笔带过、未交代、未检查。 */
    public const COVERAGE = ['covered', 'brief', 'missing'];

    public const COVERAGE_LABELS = ['covered' => '已交代', 'brief' => '一笔带过', 'missing' => '未交代', 'unchecked' => '未检查'];

    /** 设定结构中的开篇交代清单。 */
    public static function schema(JsonSchema $schema): Type
    {
        $item = $schema->object([
            'topic' => $schema->string()->required(),
            'content' => $schema->string()->required(),
            'technique' => $schema->string()->required(),
            'chapter' => $schema->integer()->min(1)->max(self::LAST_CHAPTER)->required(),
        ])->withoutAdditionalProperties();

        return $schema->object([
            'premise_hook' => $schema->string()->required(),
            'items' => $schema->array()->items($item)->min(self::MIN_ITEMS)->max(self::MAX_ITEMS)->required(),
        ])->withoutAdditionalProperties()->required();
    }

    /** 严格校验：三至八项，章号在1至3之间，第1章至少一项。
     * @return array{premise_hook: string, items: list<array{topic: string, content: string, technique: string, chapter: int}>}
     */
    public static function parse(mixed $brief): array
    {
        if (! is_array($brief) || array_is_list($brief) || count($brief) !== 2 || ! array_key_exists('premise_hook', $brief) || ! array_key_exists('items', $brief)) {
            throw new InvalidArgumentException('开篇交代清单结构不正确');
        }
        self::text($brief['premise_hook']);
        if (! is_array($brief['items']) || ! array_is_list($brief['items']) || count($brief['items']) < self::MIN_ITEMS || count($brief['items']) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('开篇交代清单须有'.self::MIN_ITEMS.'至'.self::MAX_ITEMS.'项');
        }
        $items = [];
        foreach ($brief['items'] as $item) {
            if (! is_array($item) || array_is_list($item) || count($item) !== 4 || array_diff(['topic', 'content', 'technique', 'chapter'], array_keys($item)) !== []) {
                throw new InvalidArgumentException('开篇交代项结构不正确');
            }
            foreach (['topic', 'content', 'technique'] as $field) {
                self::text($item[$field]);
            }
            // 作者确认表单提交的章号是数字字符串，按整数接受。
            if (is_string($item['chapter']) && preg_match('/^\d$/', $item['chapter']) === 1) {
                $item['chapter'] = (int) $item['chapter'];
            }
            if (! is_int($item['chapter']) || $item['chapter'] < 1 || $item['chapter'] > self::LAST_CHAPTER) {
                throw new InvalidArgumentException('开篇交代项只能安排在第1至'.self::LAST_CHAPTER.'章');
            }
            $items[] = ['topic' => $item['topic'], 'content' => $item['content'], 'technique' => $item['technique'], 'chapter' => $item['chapter']];
        }
        if (! in_array(1, array_column($items, 'chapter'), true)) {
            throw new InvalidArgumentException('第1章至少要交代一项开篇背景');
        }

        return ['premise_hook' => $brief['premise_hook'], 'items' => $items];
    }

    /** 冻结了开篇交代的设定与设定决策请求附加的说明；题材包与设定元素给出的必备项一并列出。
     * @param  list<string>  $checklist
     */
    public static function settingInstructions(array $checklist = []): string
    {
        return "\n开篇交代清单：opening_brief.premise_hook用一句话写清本书立足的特殊设定（例如重生回到哪一年、从哪里穿越成谁；没有特殊设定就写主角的出身处境）。"
            .'items列出读者在前三章必须知道的背景，每项写topic（交代什么）、content（具体内容，取自作者创意，创意没写的按创意合理补全并保持一致）、technique（建议写法：借当下场景触发回忆、内心独白、前后两世或古今对比、旁人一句话点破等）、chapter（安排在第1至3章哪一章）。'
            .'重生类必须交代：前世怎么走到结局、最大的遗憾和吃过的亏、重生到哪个时间节点、带回来的经验手艺、这一世要做什么和不做什么。'
            .'穿越类必须交代：穿越前是谁、怎么穿来的、原身是谁和留下什么烂摊子、主角手里的优势（金手指或现代知识）及其局限、穿来后的第一个危机、想要什么、现代观念与当地观念的第一个冲突。'
            .'其他题材交代主角的出身处境、核心欲望和本书的特殊设定。第1章必须交代“主角是谁、怎么来到这个处境、眼下遭遇什么”。'
            .($checklist === [] ? '' : '本书题材要求的必备项：'.implode('；', $checklist).'。');
    }

    /** 大纲批次包含前三章时附加：按清单安排前三章。 */
    public static function outlineInstructions(): string
    {
        return "\n开篇交代：confirmed_setting.opening_brief列出了前三章必须交代的背景，每项标了章号。第1至3章的summary和objective要为这些交代安排具体的场面（谁在什么情境下想起或说起什么），不能只写“确认重生处境”这类一句话概括；交代要嵌在本章冲突里推进，不要另起一章专讲背景。";
    }

    /** 本章要交代的清单项；不在前三章或设定没有清单时为空。
     * @param  array<string, mixed>  $setting
     * @return list<array{topic: string, content: string, technique: string}>
     */
    public static function chapterItems(array $setting, int $chapter): array
    {
        if ($chapter > self::LAST_CHAPTER || ! isset($setting['opening_brief']['items'])) {
            return [];
        }

        return array_values(array_map(fn (array $item): array => ['topic' => $item['topic'], 'content' => $item['content'], 'technique' => $item['technique']],
            array_filter($setting['opening_brief']['items'], fn (array $item): bool => $item['chapter'] === $chapter)));
    }

    /** 细纲与正文首稿附加的交代要求；放开前三章的交代预算。
     * @param  list<array{topic: string, content: string, technique: string}>  $items
     */
    public static function chapterInstructions(array $items, string $step): string
    {
        $list = implode('；', array_map(fn (array $item): string => '「'.$item['topic'].'」'.preg_replace('/。+$/u', '', $item['content']).'（写法：'.preg_replace('/。+$/u', '', $item['technique']).'）', $items));
        $text = "\n本章必须交代的开篇背景：".$list.'。';

        return $text.($step === 'fine'
            ? '细纲要把每一项落到具体场景里：由当下的人、物或冲突触发，写清在哪个场景、借什么交代。'
            : '每一项都要让读者读完就知道，不能一个词带过；可以用一到两段集中回忆或内心独白，前后两世、古今观念的对比要写出具体画面和情绪。交代嵌在当下的冲突里，不要大段旁白科普；本章对“少说明”的要求不适用于这些交代项。');
    }

    /** 检查附加：逐项判断是否交代清楚，只记录。
     * @param  list<array{topic: string, content: string, technique: string}>  $items
     */
    public static function reviewInstructions(array $items): string
    {
        return "\n开篇交代检查：opening_review逐项对照本章必须交代的背景（".implode('、', array_map(fn (array $item): string => '「'.$item['topic'].'」', $items)).'），'
            .'coverage为covered（读者读完能说清这件事）、brief（只有一个词或一句带过）、missing（没写）；reason一句话说明，evidence_ids引用对应段落（missing可为空）。只记录，不影响检查结论。';
    }

    /** 检查结构中的 opening_review：每个清单项一条。
     * @param  list<string>  $anchors
     */
    public static function reviewSchema(JsonSchema $schema, int $count, array $anchors): Type
    {
        $evidence = $schema->array()->items($anchors === [] ? $schema->string() : $schema->string()->enum($anchors));

        return $schema->array()->items($schema->object([
            'topic' => $schema->string()->required(),
            'coverage' => $schema->string()->enum(self::COVERAGE)->required(),
            'reason' => $schema->string()->required(),
            'evidence_ids' => $evidence->required(),
        ])->withoutAdditionalProperties())->min($count)->max($count)->required();
    }

    /** 校验检查返回的覆盖情况：条数与清单一致，证据来自当前正文；不合格由调用方记为未检查。
     * @param  list<array{topic: string, content: string, technique: string}>  $items
     * @param  array<string, mixed>  $anchors
     * @return list<array{topic: string, coverage: string, reason: string, evidence: list<string>}>
     */
    public static function parseReview(mixed $review, array $items, array $anchors): array
    {
        if (! is_array($review) || ! array_is_list($review) || count($review) !== count($items)) {
            throw new InvalidArgumentException('开篇交代检查条数与清单不一致');
        }
        $result = [];
        foreach ($review as $index => $entry) {
            if (! is_array($entry) || ! in_array($entry['coverage'] ?? null, self::COVERAGE, true) || ! is_string($entry['reason'] ?? null) || ! is_array($entry['evidence_ids'] ?? null)) {
                throw new InvalidArgumentException('开篇交代检查结构不正确');
            }
            $evidence = array_values(array_filter($entry['evidence_ids'], fn (mixed $id): bool => is_string($id) && array_key_exists($id, $anchors)));
            $result[] = ['topic' => $items[$index]['topic'], 'coverage' => $entry['coverage'], 'reason' => trim($entry['reason']), 'evidence' => $evidence];
        }

        return $result;
    }

    /** 不合格或缺失时的记录：每项记为未检查。
     * @param  list<array{topic: string, content: string, technique: string}>  $items
     * @return list<array{topic: string, coverage: string, reason: string, evidence: list<string>}>
     */
    public static function unchecked(array $items, string $reason): array
    {
        return array_map(fn (array $item): array => ['topic' => $item['topic'], 'coverage' => 'unchecked', 'reason' => $reason, 'evidence' => []], $items);
    }

    /** 非空白文本。 */
    private static function text(mixed $value): void
    {
        if (! is_string($value) || preg_match('/(*UCP)\S/u', $value) !== 1) {
            throw new InvalidArgumentException('开篇交代清单文本缺失或只包含空白');
        }
    }
}
