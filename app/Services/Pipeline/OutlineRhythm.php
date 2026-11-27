<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/**
 * 大纲爽点节奏：每章标出兑现点、情绪最高点与钩子类型，程序本地检查节奏，只记录提醒、不让大纲停下。
 * 在大纲计划启动时冻结到 config.payoff_rhythm；未冻结的旧计划结构与提示词保持不变。
 */
final class OutlineRhythm
{
    public const VERSION = 'c-payoff-rhythm-v1';

    /** 没有题材包时的爽点类型。 */
    public const DEFAULT_TYPES = ['打脸', '逆袭', '揭秘', '获得', '身份揭露', '智斗胜出', '护短', '甜', '笑点', '危机解除'];

    public const NONE = 'none';

    public const SIZES = ['small' => '小', 'medium' => '中', 'large' => '大'];

    public const HOOK_TYPES = ['suspense' => '悬念', 'crisis' => '危机', 'twist' => '反转', 'emotion' => '情感', 'anticipation' => '期待'];

    /** 连续多少章内至少要有一次中兑现或大兑现。 */
    public const MEDIUM_WINDOW = 5;

    /** 钩子类型连续相同的上限。 */
    public const SAME_HOOK_LIMIT = 2;

    /** 一批里没有兑现点的章节最多占比。 */
    public const NONE_SHARE = 0.3;

    /** 计划启动时冻结的节奏配置：爽点类型取本书题材包，没有题材包时用默认列表。
     * @param  array<string, mixed>  $planningInput
     * @return array{version: string, payoff_types: list<string>}
     */
    public static function config(array $planningInput): array
    {
        $names = array_column($planningInput['story_profile']['genre']['content']['payoff_types'] ?? [], 'name');

        return ['version' => self::VERSION, 'payoff_types' => $names === [] ? self::DEFAULT_TYPES : array_values(array_unique([...$names, '笑点', '危机解除']))];
    }

    /** 冻结的节奏配置；版本不符或未冻结时返回 null。
     * @param  array<string, mixed>|null  $config
     * @return array{version: string, payoff_types: list<string>}|null
     */
    public static function frozen(?array $config): ?array
    {
        $rhythm = $config['payoff_rhythm'] ?? null;

        return is_array($rhythm) && ($rhythm['version'] ?? null) === self::VERSION && is_array($rhythm['payoff_types'] ?? null) ? $rhythm : null;
    }

    /** 每章的节奏字段结构。
     * @param  array{version: string, payoff_types: list<string>}  $rhythm
     */
    public static function schema(JsonSchema $schema, array $rhythm): mixed
    {
        return $schema->object([
            'payoff' => $schema->object([
                'type' => $schema->string()->enum([...$rhythm['payoff_types'], self::NONE])->required(),
                'size' => $schema->string()->enum(array_keys(self::SIZES))->required(),
                'description' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
            'emotional_peak' => $schema->string()->required(),
            'hook_type' => $schema->string()->enum(array_keys(self::HOOK_TYPES))->required(),
        ])->withoutAdditionalProperties()->required();
    }

    /** 校验并整理一章的节奏字段；类型不在冻结列表里时整批不合格，与其他结构问题一致。
     * @param  array{version: string, payoff_types: list<string>}  $rhythm
     * @return array{payoff: array{type: string, size: string, description: string}, emotional_peak: string, hook_type: string}
     */
    public static function parse(mixed $value, array $rhythm, int $chapterNumber): array
    {
        $payoff = is_array($value) ? ($value['payoff'] ?? null) : null;
        // 节奏只是提醒：模型多给的无用字段直接丢掉，只校验需要的字段。
        if (! is_array($value) || ! is_array($payoff)
            || ! in_array($payoff['type'] ?? null, [...$rhythm['payoff_types'], self::NONE], true)
            || ! array_key_exists($payoff['size'] ?? '', self::SIZES) || ! is_string($payoff['description'] ?? null)
            || ! is_string($value['emotional_peak'] ?? null) || ! array_key_exists($value['hook_type'] ?? '', self::HOOK_TYPES)) {
            throw new InvalidArgumentException('第'.$chapterNumber.'章的rhythm字段不完整或取值不在允许范围内');
        }

        return ['payoff' => ['type' => $payoff['type'], 'size' => $payoff['size'], 'description' => trim($payoff['description'])],
            'emotional_peak' => trim($value['emotional_peak']), 'hook_type' => $value['hook_type']];
    }

    /** 附加在大纲指令后的节奏要求。
     * @param  array{version: string, payoff_types: list<string>}  $rhythm
     */
    public static function instructions(array $rhythm): string
    {
        return "\n爽点节奏（本书是网络小说，读者追更靠兑现）：每章填写rhythm。payoff是本章给读者的兑现：type从".implode('、', $rhythm['payoff_types']).'中选，size为small（小兑现：一次小胜、一个笑点、一次怼回去）、medium（中兑现：一次完整的先压后扬）或large（大兑现：一段剧情的高潮收尾）；description一句话写清谁对谁、怎么兑现、旁人什么反应。'
            .'每章都要有兑现，实在没有才填none，一批里none不超过三成；每连续'.self::MEDIUM_WINDOW.'章至少一次medium或large。'
            .'emotional_peak写本章情绪最高点：谁、什么情绪、因为什么。hook_type为章末钩子类型：'.implode('、', array_map(fn (string $key, string $label): string => $key.'（'.$label.'）', array_keys(self::HOOK_TYPES), self::HOOK_TYPES)).'，不要连续三章相同。'
            .'宏观目标不能一拖几十章：每个大段落内主角的阶段目标要达成或明确改变。';
    }

    /** 本地节奏检查：只返回提醒，不让大纲停下。章节按章序排列，可包含前批章节以检查跨批窗口。
     * @param  list<array<string, mixed>>  $chapters
     * @param  int  $from  只报告从该章起的问题，前批已报告过的不重复。
     * @return list<string>
     */
    public static function issues(array $chapters, int $from): array
    {
        $issues = [];
        $rated = array_values(array_filter($chapters, fn (array $chapter): bool => isset($chapter['rhythm'])));
        $batch = array_values(array_filter($rated, fn (array $chapter): bool => $chapter['chapter_number'] >= $from));
        $none = array_filter($batch, fn (array $chapter): bool => $chapter['rhythm']['payoff']['type'] === self::NONE);
        foreach ($none as $chapter) {
            if ($chapter['rhythm']['emotional_peak'] === '') {
                $issues[] = '第'.$chapter['chapter_number'].'章既没有兑现点也没有情绪最高点';
            }
        }
        if ($batch !== [] && count($none) / count($batch) > self::NONE_SHARE) {
            $issues[] = '本批'.count($batch).'章里有'.count($none).'章没有兑现点，超过三成';
        }
        $sinceMedium = 0;
        $sameHook = 0;
        $previousHook = null;
        foreach ($rated as $chapter) {
            $rhythm = $chapter['rhythm'];
            $sinceMedium = $rhythm['payoff']['type'] !== self::NONE && $rhythm['payoff']['size'] !== 'small' ? 0 : $sinceMedium + 1;
            if ($sinceMedium === self::MEDIUM_WINDOW && $chapter['chapter_number'] >= $from) {
                $issues[] = '第'.($chapter['chapter_number'] - self::MEDIUM_WINDOW + 1).'至'.$chapter['chapter_number'].'章连续'.self::MEDIUM_WINDOW.'章没有中兑现或大兑现';
                $sinceMedium = 0;
            }
            $sameHook = $rhythm['hook_type'] === $previousHook ? $sameHook + 1 : 1;
            $previousHook = $rhythm['hook_type'];
            if ($sameHook === self::SAME_HOOK_LIMIT + 1 && $chapter['chapter_number'] >= $from) {
                $issues[] = '第'.($chapter['chapter_number'] - self::SAME_HOOK_LIMIT).'至'.$chapter['chapter_number'].'章的章末钩子都是'.self::HOOK_TYPES[$rhythm['hook_type']];
            }
        }

        return $issues;
    }

    /** 兑现点检查的结论：只记录，不影响检查是否通过。 */
    public const DELIVERY = ['delivered' => '已兑现', 'partial' => '部分兑现', 'missing' => '未兑现'];

    /** 结论的后台显示名，含模型没给出有效结论时的未检查。 */
    public const DELIVERY_LABELS = [...self::DELIVERY, 'unchecked' => '未检查'];

    /** 本章是否需要兑现点检查：章纲带节奏且有兑现点。
     * @param  array<string, mixed>|null  $rhythm
     */
    public static function reviewable(?array $rhythm): bool
    {
        return $rhythm !== null && ($rhythm['payoff']['type'] ?? self::NONE) !== self::NONE;
    }

    /** 独立检查附带的兑现点检查结构。
     * @param  list<string>  $anchorIds
     */
    public static function reviewSchema(JsonSchema $schema, array $anchorIds): mixed
    {
        return $schema->object([
            'delivery' => $schema->string()->enum(array_keys(self::DELIVERY))->required(),
            'reason' => $schema->string()->required(),
            'evidence_ids' => $schema->array()->items($schema->string()->enum($anchorIds))->required(),
        ])->withoutAdditionalProperties()->required();
    }

    /** 独立检查附加的兑现点检查要求。
     * @param  array{payoff: array{type: string, size: string, description: string}, emotional_peak: string, hook_type: string}  $rhythm
     */
    public static function reviewInstructions(array $rhythm): string
    {
        return '
兑现点检查（只记录，不影响points与hook的判断）：章纲计划的兑现点是「'.self::SIZES[$rhythm['payoff']['size']].'兑现·'.$rhythm['payoff']['type'].'：'.rtrim($rhythm['payoff']['description'], '。').'」。'
            .'在payoff_review里判断正文是否写出了这次兑现：delivered为写成了完整场面，有压力、有出手、有对手或旁人当场的反应；partial为写到了但被一笔带过，或兑现的方向与计划不同、爽感明显打了折扣；missing为没有写到。reason一句话说明差在哪里，evidence_ids引用兑现所在的段落，没有写到时可以为空。';
    }

    /** 校验并整理兑现点检查结果。
     * @param  array<string, array{paragraph: int, text: string}>  $anchors
     * @return array{delivery: string, reason: string, evidence: list<array{paragraph: int, quote: string}>}
     */
    public static function parseReview(mixed $value, array $anchors): array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['delivery', 'reason', 'evidence_ids']) !== [] || ! array_key_exists($value['delivery'] ?? '', self::DELIVERY)
            || ! is_string($value['reason'] ?? null) || ! is_array($value['evidence_ids'] ?? null)) {
            throw new InvalidArgumentException('payoff_review字段不完整或取值不在允许范围内');
        }
        $evidence = [];
        foreach (array_unique($value['evidence_ids']) as $id) {
            if (! is_string($id) || ! isset($anchors[$id])) {
                throw new InvalidArgumentException('payoff_review引用了未知段落');
            }
            $evidence[] = ['paragraph' => $anchors[$id]['paragraph'], 'quote' => $anchors[$id]['text']];
        }

        return ['delivery' => $value['delivery'], 'reason' => trim($value['reason']), 'evidence' => $evidence];
    }

    /** 正文细纲与首稿附加的本章兑现要求。
     * @param  array{payoff: array{type: string, size: string, description: string}, emotional_peak: string, hook_type: string}  $rhythm
     */
    public static function chapterInstructions(array $rhythm): string
    {
        $payoff = $rhythm['payoff'];
        $text = $payoff['type'] === self::NONE ? '' : "\n本章兑现点（".self::SIZES[$payoff['size']].'兑现·'.$payoff['type'].'）：'.rtrim($payoff['description'], '。')
            .'。这是读者读这一章的理由，要写成完整的场面：先让压力或对手的气焰立起来，再让主角出手，写出对手和旁人当场的反应，不要一句话交代结果。';

        return $text.($rhythm['emotional_peak'] === '' ? '' : "\n本章情绪最高点：".rtrim($rhythm['emotional_peak'], '。').'。用人物的言行和身体反应写出来，不要只用旁白点明。')
            ."\n章末钩子类型：".self::HOOK_TYPES[$rhythm['hook_type']].'。';
    }
}
