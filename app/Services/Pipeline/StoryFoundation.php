<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;

/**
 * 故事根基：新书冻结 generation_config.story_foundation 后，设定另列开篇交代清单（OpeningBrief）、
 * 主角当前处境和主线目标阶梯，并把创意里互相打架的目标列入待确认问题，由作者或自动决策取舍。
 * 设定里有这些字段的书，大纲据此安排阶段目标的达成章节，正文请求随确认设定一并带上；旧书不变。
 */
final class StoryFoundation
{
    public const VERSION = 'c-story-foundation-v1';

    public const MIN_GOALS = 2;

    public const MAX_GOALS = 5;

    /** 设定结构新增的字段。
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'opening_brief' => OpeningBrief::schema($schema),
            'protagonist_situation' => $schema->string()->required(),
            'goal_ladder' => $schema->array()->items($schema->object([
                'goal' => $schema->string()->required(),
                'done_when' => $schema->string()->required(),
                'tradeoff' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->min(self::MIN_GOALS)->max(self::MAX_GOALS)->required(),
        ];
    }

    /** 校验目标阶梯：二至五级，每级三项都有内容。
     * @return list<array{goal: string, done_when: string, tradeoff: string}>
     */
    public static function parseLadder(mixed $ladder): array
    {
        if (! is_array($ladder) || ! array_is_list($ladder) || count($ladder) < self::MIN_GOALS || count($ladder) > self::MAX_GOALS) {
            throw new InvalidArgumentException('主线目标阶梯须有'.self::MIN_GOALS.'至'.self::MAX_GOALS.'级');
        }
        $result = [];
        foreach ($ladder as $step) {
            if (! is_array($step) || array_is_list($step) || count($step) !== 3) {
                throw new InvalidArgumentException('主线目标阶梯结构不正确');
            }
            foreach (['goal', 'done_when', 'tradeoff'] as $field) {
                if (! is_string($step[$field] ?? null) || preg_match('/(*UCP)\S/u', $step[$field]) !== 1) {
                    throw new InvalidArgumentException('主线目标阶梯文本缺失');
                }
            }
            $result[] = ['goal' => $step['goal'], 'done_when' => $step['done_when'], 'tradeoff' => $step['tradeoff']];
        }

        return $result;
    }

    /** 设定附加：处境、目标阶梯，以及把互相打架的目标列入待确认问题；设定决策不新增问题，直接在处境和目标阶梯里定下取舍。 */
    public static function settingInstructions(bool $decision = false): string
    {
        if ($decision) {
            return "\n主角处境与目标：修订后的设定里protagonist_situation写主角开篇时的身份、营生、住处、日常时间安排、有什么缺什么；goal_ladder按先后写主线阶段目标（goal做成什么、done_when读者怎么看出做成了、tradeoff放弃或付出什么）。"
                .'作者创意里并行的目标或处境互相打架时（例如既要保住厂里的名额又要天天摆摊），直接在protagonist_situation和goal_ladder里定下取舍并写清先后，不要新增待答问题；answers只回答候选设定里已有的问题，条数与问题数完全一致。';
        }

        return "\n主角处境与目标：protagonist_situation写主角开篇时的身份、工作或营生、住处、日常时间怎么安排、手里有什么、缺什么，后文行为必须与之相符（例如还在厂里上班，就要写清什么时候摆摊）。"
            .'goal_ladder按先后列出主线的阶段目标：goal写这一阶段要做成什么，done_when写读者怎么看出它做成了，tradeoff写为此放弃或付出什么。'
            .'检查作者创意里并行的目标和主角处境是否互相打架（例如既要保住厂里的名额又要天天摆摊、既要低调又要出风头），每个冲突列入open_questions：question写清冲突，impact用一段文字（字符串，不要写成列表）写出两三种可选的取舍方案并标出推荐；不影响结局和世界规则的设为blocking=false。';
    }

    /** 大纲首批附加：阶段目标要有达成或放弃的章节，延续摘要写处境变化。 */
    public static function outlineInstructions(): string
    {
        return "\n主线目标：confirmed_setting.goal_ladder列出了主线的阶段目标，全书走向和章纲要让每一级目标有明确的达成或放弃章节，前一级没做成或没放弃之前，不让主角同时追两个互相打架的目标；protagonist_situation是主角开篇时的处境，章纲里主角的行动要与之相符。handoff.continuity_summary写清主角当前的处境变化（身份、营生、住处、手里的资源）。";
    }
}
