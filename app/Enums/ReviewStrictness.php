<?php

namespace App\Enums;

/** 本书提交评审的严格度：宽松只拦硬伤，标准再拦待核与已揭示事实，严格沿用逐项阻断。 */
enum ReviewStrictness: string
{
    /** 网文默认：只有影响情节推进的硬伤阻断，其余记为瑕疵。 */
    case Loose = 'loose';

    /** 硬伤之外，待核事项与已揭示事实的问题也阻断。 */
    case Standard = 'standard';

    /** 原有口径：评审认定的任何阻塞问题都阻断（悬疑推理）。 */
    case Strict = 'strict';

    /** 后台选项名称。 */
    public function label(): string
    {
        return match ($this) {
            self::Loose => '宽松（网文默认）',
            self::Standard => '标准',
            self::Strict => '严格（悬疑推理）',
        };
    }

    /** 选项说明。 */
    public function description(): string
    {
        return match ($this) {
            self::Loose => '只拦截硬伤：正文截断、视角错乱、已死人物开口、关键物件无故易手、因果颠倒、违反确认的设定。小动作不一致、称谓不统一等细节只记为瑕疵，不阻断提交。',
            self::Standard => '在宽松的基础上，待核事项被写成已确认、已揭示的事实被重新发现也会拦截。',
            self::Strict => '评审认定的任何阻塞问题都拦截，与改造前一致，适合悬疑推理。',
        };
    }

    /** 后台下拉选项。
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
