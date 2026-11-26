<?php

namespace App\Services\Pipeline;

/** 去模板化文风规则：新运行冻结版本号后，细纲与正文按章轮换开场方式、章首不以时间起句、避开高频套话，人物不念写作规则。旧运行没有该冻结值时完全保持原协议。
 * 只有章首以时间起句会送一次修复，修不掉也不让运行停下；套话超量只作观察，章首章末与近章雷同仍由跨章文风提示展示。 */
final class ContentStyleRules
{
    public const VERSION = 'c-style-rules-v1';

    /** 场景数下限：允许一场大戏写满半章，不必为凑场景数频繁转场。 */
    public const MIN_SCENES = 2;

    /** 同一套话在一个场景中出现超过此次数即列入观察。 */
    public const SCENE_CLICHE_LIMIT = 1;

    /** 同一套话在整章中出现超过此次数即列入观察。 */
    public const CHAPTER_CLICHE_LIMIT = 2;

    /** 开场方式按章号轮换，相邻三章必然不同。 */
    public const OPENING_MODES = [
        'dialogue' => '对白开场：第一段就是人物说出口的一句话，带着情绪或冲突',
        'action' => '动作中途：从人物正在做的一件具体的事中间切入',
        'conflict' => '冲突进行时：从争执、对峙或麻烦已经发生的那一刻切入',
        'suspense' => '悬念抛出：第一句抛出一个让读者想知道答案的事实或异常',
        'oddity' => '反常细节：从一个不对劲的细节切入，引出本章事件',
        'inner' => '人物内心：从人物此刻一个强烈的念头或情绪切入，随即进入场面',
    ];

    /** 模型写作中被过度使用的动作、神态与收束；键为提示用名称，值为匹配式。 */
    public const CLICHES = [
        '指尖' => '指尖',
        '指腹' => '指腹',
        '喉咙动了一下' => '喉咙动了|喉结(?:上下)?滚',
        '顿了顿' => '顿了顿',
        '没接话' => '没(?:有)?接话',
        '压低声音' => '压低(?:了)?声音|声音压(?:得)?(?:很|更)?低',
        '掂量' => '掂量',
        '悬着没落下' => '悬着没(?:有)?落下|悬在半空',
        '屏幕暗下来' => '屏幕(?:暗了下去|暗下来|暗下去|黑了下去)',
        '没有回音' => '没(?:有)?回音',
        '膝盖响了一下' => '膝盖响了一下',
        '往前推了推' => '(?:往前|往里|往回)推了(?:推|半寸)',
        '按了按' => '按了按',
        '深吸一口气' => '深吸(?:了)?一口气',
        '空气凝固' => '空气(?:仿佛|像是)?(?:凝固|凝住)',
        '嘴角上扬' => '嘴角(?:微微)?(?:上扬|勾起)',
        '眼底闪过' => '眼底闪过|眼中闪过一丝',
        '心里一沉' => '心(?:里|头|中)一(?:沉|紧)',
        '还答不上来' => '还答不上来|一时说不出',
    ];

    /** 以时间、钟点或日期起句的章首；对白开头不受限。 */
    private const TIME_OPENING = '/^(?:次日|翌日|隔天|第二天|第[二三四五六七八九十]天|当晚|当天|那天|这天|傍晚|黄昏|清晨|凌晨|早上|早晨|上午|中午|正午|午后|下午|晚上|夜里|深夜|半夜|入夜|天刚亮|天亮时|天黑|[零〇一二两三四五六七八九十\d]+点|[一二三四五六七八九十\d]+月|周[一二三四五六日天]|星期)/u';

    /** 本章指定的开场方式，按章号循环。 */
    public static function openingMode(int $chapterNumber): string
    {
        $keys = array_keys(self::OPENING_MODES);

        return $keys[(max(1, $chapterNumber) - 1) % count($keys)];
    }

    /** 章首以时间起句：第22本书五十章里有三十三章这样开头，是最先被读者察觉的模板化痕迹，判定精确，由场景修复或修订处理一次。
     * @return list<array{type: string, paragraph: int, anchor_id: string, quotes: list<string>, reason: string}>
     */
    public static function openingIssues(string $body): array
    {
        $first = self::firstParagraph($body);
        if ($first === null) {
            return [];
        }
        $sentence = self::firstSentence($first['text']);
        if (preg_match(self::TIME_OPENING, $sentence) !== 1) {
            return [];
        }

        return [['type' => 'time_opening', 'paragraph' => $first['paragraph'], 'anchor_id' => 'p'.$first['paragraph'], 'quotes' => [$sentence],
            'reason' => '章首「'.$sentence.'」以时间起句，近章大多这样开头：换成本章指定的开场方式，时间变化融进后文叙述']];
    }

    /** 整章超过上限的高频套话及次数；只供作者阅读，不触发修复。
     * @return array<string, int>
     */
    public static function overusedCliches(string $body): array
    {
        return array_filter(self::clicheCounts($body), fn (int $count): bool => $count > self::CHAPTER_CLICHE_LIMIT);
    }

    /** 各套话在文本中的出现次数，未出现的不列出。
     * @return array<string, int>
     */
    public static function clicheCounts(string $text): array
    {
        $counts = [];
        foreach (self::CLICHES as $label => $pattern) {
            $count = preg_match_all('/'.$pattern.'/u', $text);
            if ($count > 0) {
                $counts[$label] = $count;
            }
        }

        return $counts;
    }

    /** 细纲附加要求：场景数可少至两个，time只写时间变化，第一个场景按指定方式开场。 */
    public static function fineInstructions(string $openingMode): string
    {
        return '场景数量按情节需要安排，一场大戏可以写满半章，不必为凑场景数转场。time只写与上一场相比的时间变化（如“当晚”“三天后”），时间与上一场连续时写“紧接上场”，不写具体钟点。'
            .'本章开场方式：'.self::OPENING_MODES[$openingMode].'；第一个场景的action第一个节拍就按这种方式设计。';
    }

    /** 正文首稿附加要求：开场方式、不以时间起句、不沿用近章开头结尾、避开套话、人物不念写作规则。 */
    public static function draftInstructions(string $openingMode): string
    {
        return '本章开场方式：'.self::OPENING_MODES[$openingMode].'。全章第一句不得以时间、钟点、日期或天气起句，也不要用“某人在某地做某事”的环境交代开头；各场景的时间变化融进叙述带出，不以钟点起句。'
            .'开头和章末都不要沿用style_signals中recent_openings、recent_endings的句式。'
            .self::clicheInstruction().self::firewall();
    }

    /** 文字问题修复附加要求：章首问题按指定开场方式重写第一段。 */
    public static function repairInstructions(string $openingMode): string
    {
        return '问题指出章首以时间起句时，按本章开场方式重写第一段：'.self::OPENING_MODES[$openingMode].'；原有时间信息融进后文叙述，不增删情节。'.self::firewall();
    }

    /** 修订附加要求：改写时同样避开套话，人物不念写作规则。 */
    public static function reviseInstructions(): string
    {
        return self::clicheInstruction().self::firewall();
    }

    /** 套话清单提示。 */
    private static function clicheInstruction(): string
    {
        return '下列表达已被过度使用，每个场景最多出现一次，能不用就不用，换成本场景独有的具体反应：'.implode('、', array_keys(self::CLICHES)).'。';
    }

    /** 规则防火墙：系统约束是写给作者的，不能变成人物的台词。人物反复念“核一遍”“写清来源”“按程序”是流程化观感的主要来源之一。 */
    private static function firewall(): string
    {
        return '待核事项、已揭示事实、推进点、场景自检和规则目录都是写给作者的约束，人物并不知道这些规则：不要让人物在对白或心里念出这些规则，例如反复说要再核一遍、写清来源、按程序来、等签收、先登记；除非这件事本身就是本场冲突，程序性动作最多一句带过，把篇幅留给人物的情绪、交锋和后果。';
    }

    /** 第一个非空段。
     * @return array{paragraph: int, text: string}|null
     */
    private static function firstParagraph(string $body): ?array
    {
        foreach (ContentSchema::paragraphs($body) as $paragraph) {
            if (trim($paragraph['text']) !== '') {
                return ['paragraph' => $paragraph['paragraph'], 'text' => trim($paragraph['text'])];
            }
        }

        return null;
    }

    /** 段落的第一个分句到第一个句末标点为止。 */
    private static function firstSentence(string $text): string
    {
        $end = preg_match('/[。！？]/u', $text, $match, PREG_OFFSET_CAPTURE) === 1 ? mb_strlen(substr($text, 0, $match[0][1])) + 1 : mb_strlen($text);

        return mb_substr($text, 0, min($end, 60));
    }
}
