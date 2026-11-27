<?php

namespace App\Services\Pipeline;

use App\Enums\ReviewStrictness;
use App\Models\ChapterSubmissionRun;
use App\Models\Novel;

/**
 * 提交评审分级：评审把阻塞问题归入一个硬伤类别，不属于本书阻断类别的问题由程序降为瑕疵。
 * 分级在准备运行时冻结到 config.review_tier；未冻结的运行（严格度为严格或旧运行）保持原有逐项阻断。
 */
final class ReviewTiers
{
    public const VERSION = 'c-review-tier-v1';

    /** 不属于任何硬伤类别时填写的值。 */
    public const NONE = 'none';

    /** 硬伤类别：键写入评审结果，值进入提示词与后台展示。 */
    public const HARD_DEFECTS = [
        'truncation' => '正文截断、占位、格式损坏',
        'pov' => '叙述视角或人称错乱',
        'dead_character' => '已死或明确不在场的人物开口、行动',
        'key_item' => '关键物件无故易手或凭空出现，并影响后续情节',
        'causality' => '时间线倒错导致因果颠倒',
        'world_rule' => '违反作者确认的世界规则或设定决策',
        'repeated_reveal' => '把前文已揭示的关键事实当作第一次发现重新演示',
        'open_item' => '把待核事项当作已确认事实，或改写已核实的结论',
    ];

    /** 宽松时降为瑕疵的类别：待核台账与已揭示事实只提示。 */
    private const LEDGER_DEFECTS = ['repeated_reveal', 'open_item'];

    /** 准备运行冻结的分级配置；严格度为严格时不冻结，评审沿用原有口径。
     * @return array{version: string, strictness: string}|null
     */
    public static function config(Novel $novel): ?array
    {
        $strictness = $novel->review_strictness ?? ReviewStrictness::Strict;

        return $strictness === ReviewStrictness::Strict ? null : ['version' => self::VERSION, 'strictness' => $strictness->value];
    }

    /** 运行冻结的分级；版本不符或未冻结时返回 null。
     * @return array{version: string, strictness: string}|null
     */
    public static function frozen(ChapterSubmissionRun $run): ?array
    {
        $tier = $run->config['review_tier'] ?? null;

        return is_array($tier) && ($tier['version'] ?? null) === self::VERSION && ReviewStrictness::tryFrom($tier['strictness'] ?? '') !== null ? $tier : null;
    }

    /** 本书严格度下会阻断提交的硬伤类别。
     * @param  array{version: string, strictness: string}  $tier
     * @return list<string>
     */
    public static function blockingDefects(array $tier): array
    {
        $defects = array_keys(self::HARD_DEFECTS);

        return $tier['strictness'] === ReviewStrictness::Loose->value ? array_values(array_diff($defects, self::LEDGER_DEFECTS)) : $defects;
    }

    /** 附加在评审程序约束之后的分级说明。
     * @param  array{version: string, strictness: string}  $tier
     */
    public static function instructions(array $tier): string
    {
        $blocking = array_intersect_key(self::HARD_DEFECTS, array_flip(self::blockingDefects($tier)));
        $listing = implode('；', array_map(fn (string $key, string $label): string => $key.'：'.$label, array_keys($blocking), $blocking));
        $ledger = $tier['strictness'] === ReviewStrictness::Loose->value
            ? '待核事项写成已确认、已揭示事实被重新发现，本书只记为advisory，defect填对应类别（open_item、repeated_reveal），不要因此判needs_revision。' : '';

        return "\n评审分级（本书为网络小说，好看优先、逻辑兜底）：每条finding都填写defect。只有下列硬伤可以标为blocking，defect填对应类别：".$listing.'。'
            .$ledger
            .'其他问题一律是瑕疵，severity填advisory、defect填none，例如小动作或道具细节前后不一致、称谓偶尔不统一、同一信息重复交代、配角出入没有交代、文笔与节奏建议。文学表达类问题不能blocking。'
            .'判断标准：这个问题如果不改，读者会不会读不懂后面的情节、或者明显觉得故事出错？不会就是瑕疵。没有blocking问题时verdict必须是pass。';
    }

    /** 程序降级：不属于本书阻断类别的阻塞问题改为瑕疵，全部降级后评审改为通过并保留原结论。
     * @param  array<string, mixed>  $review
     * @param  array{version: string, strictness: string}  $tier
     * @return array<string, mixed>
     */
    public static function apply(array $review, array $tier): array
    {
        $blocking = self::blockingDefects($tier);
        $remaining = 0;
        foreach ($review['findings'] as &$finding) {
            if ($finding['severity'] !== 'blocking') {
                continue;
            }
            if ($finding['kind'] === 'literary' || ! in_array($finding['defect'], $blocking, true)) {
                $finding['severity'] = 'advisory';
                $finding['downgraded'] = true;

                continue;
            }
            $remaining++;
        }
        unset($finding);
        if ($remaining === 0 && $review['verdict'] !== 'pass') {
            $review['tier_adjusted_from'] = $review['verdict'];
            $review['verdict'] = 'pass';
        }

        return $review;
    }
}
