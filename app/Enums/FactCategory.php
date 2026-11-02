<?php

namespace App\Enums;

/** 故事事实的认知类别，信念和传闻不能直接当作客观事实。 */
enum FactCategory: string
{
    /** 客观已确认。 */
    case ObjectiveConfirmed = 'objective_confirmed';

    /** 人物相信。 */
    case CharacterBelief = 'character_belief';

    /** 传闻。 */
    case Rumor = 'rumor';

    /** 推测。 */
    case Inference = 'inference';

    /** 谎言。 */
    case Lie = 'lie';

    /** 作者隐藏真相。 */
    case AuthorHiddenTruth = 'author_hidden_truth';

    /** 返回面向作者的中文名称。 */
    public function label(): string
    {
        return match ($this) {
            self::ObjectiveConfirmed => '客观已确认',
            self::CharacterBelief => '人物相信',
            self::Rumor => '传闻',
            self::Inference => '推测',
            self::Lie => '谎言',
            self::AuthorHiddenTruth => '作者隐藏真相',
        };
    }
}
