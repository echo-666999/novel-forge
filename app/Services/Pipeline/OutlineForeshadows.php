<?php

namespace App\Services\Pipeline;

use App\Models\Foreshadow;
use App\Models\Novel;
use App\Models\NovelOutlinePlan;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * 大纲自动登记伏笔：新计划冻结 config.outline_foreshadows，首批 roadmap 另列三至八条长程伏笔（表面线索、隐藏含义、埋设章、回收窗口），
 * 首批接纳时登记进伏笔账本，之后沿用账本的正文义务与正式提交自动流转。只是建议，不合格的条目丢弃并记录，不让大纲批次失败。
 */
final class OutlineForeshadows
{
    public const VERSION = 'c-outline-foreshadow-v1';

    public const MIN = 3;

    public const MAX = 8;

    /** 自动登记的伏笔在说明前加的标记，伏笔页据此显示来源。 */
    public const MARK = '（大纲自动登记）';

    /** 计划是否冻结了自动登记。
     * @param  array<string, mixed>|null  $config
     */
    public static function frozen(?array $config): bool
    {
        return ($config['outline_foreshadows'] ?? null) === self::VERSION;
    }

    /** 首批 roadmap 中的伏笔结构。 */
    public static function schema(JsonSchema $schema, int $total): Type
    {
        $chapter = fn () => $schema->integer()->min(1)->max($total)->required();

        return $schema->array()->items($schema->object([
            'title' => $schema->string()->required(),
            'surface_clue' => $schema->string()->required(),
            'hidden_meaning' => $schema->string()->required(),
            'payoff_effect' => $schema->string()->required(),
            'plant_chapter' => $chapter(),
            'payoff_from' => $chapter(),
            'payoff_to' => $chapter(),
        ])->withoutAdditionalProperties())->min(self::MIN)->max(self::MAX)->required();
    }

    /** 宽松整理：只保留字段齐全、章号满足"埋设章 < 回收起始章 ≤ 回收截止章 ≤ 全书章数"的条目，最多八条；其余丢弃并计数。
     * @return array{items: list<array<string, mixed>>, dropped: int}
     */
    public static function parse(mixed $items, int $total): array
    {
        $kept = [];
        $dropped = 0;
        foreach (is_array($items) && array_is_list($items) ? $items : [] as $item) {
            $text = fn (string $field): ?string => is_array($item) && is_string($item[$field] ?? null) && preg_match('/(*UCP)\S/u', $item[$field]) === 1 ? trim($item[$field]) : null;
            $number = fn (string $field): ?int => is_array($item) && is_int($item[$field] ?? null) ? $item[$field] : null;
            $entry = ['title' => $text('title'), 'surface_clue' => $text('surface_clue'), 'hidden_meaning' => $text('hidden_meaning'), 'payoff_effect' => $text('payoff_effect'),
                'plant_chapter' => $number('plant_chapter'), 'payoff_from' => $number('payoff_from'), 'payoff_to' => $number('payoff_to')];
            if (in_array(null, $entry, true) || mb_strlen($entry['title']) > 255 || $entry['plant_chapter'] < 1 || $entry['payoff_from'] <= $entry['plant_chapter'] || $entry['payoff_to'] < $entry['payoff_from'] || $entry['payoff_to'] > $total || count($kept) >= self::MAX) {
                $dropped++;

                continue;
            }
            $kept[] = $entry;
        }
        if (! is_array($items)) {
            $dropped++;
        }

        return ['items' => $kept, 'dropped' => $dropped];
    }

    /** 首批大纲附加的伏笔要求。 */
    public static function instructions(): string
    {
        return "\n长程伏笔：roadmap.foreshadows列出三至八条贯穿全书的伏笔，服务于全书走向和主要转折。title是简短名称；surface_clue是读者最先看到的表面线索（一件东西、一句话、一个反常的细节）；hidden_meaning是它背后的真相（作者可见，回收前人物不能无途径得知）；payoff_effect是回收时让主角做出什么改变主线的选择或带来什么翻盘；plant_chapter是埋设章，payoff_from至payoff_to是回收窗口，必须晚于埋设章。前十章至少埋下两条，回收分散在全书不同阶段，不要都挤在结尾。";
    }

    /** 首批接纳时登记进伏笔账本；本书已有伏笔或已有章节时不登记。逐条登记，单条失败不影响其余条目和大纲批次。
     * @param  list<array<string, mixed>>  $items
     * @return array{registered: int, failed: list<string>}
     */
    public static function register(NovelOutlinePlan $plan, array $items): array
    {
        $novel = Novel::findOrFail($plan->novel_id);
        if ($items === [] || Foreshadow::where('novel_id', $novel->id)->exists() || $novel->chapters()->exists()) {
            return ['registered' => 0, 'failed' => []];
        }
        $author = User::findOrFail($plan->confirmation->confirmed_by);
        $registered = 0;
        $failed = [];
        foreach ($items as $index => $item) {
            try {
                app(ForeshadowLedger::class)->plan($novel, $author, $item['title'], self::MARK.$item['surface_clue'], $item['plant_chapter'], $item['payoff_to'],
                    (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'outline-plan-'.$plan->id.'-foreshadow-'.$index),
                    ['surface_clue' => $item['surface_clue'], 'hidden_meaning' => $item['hidden_meaning'], 'payoff_effect' => $item['payoff_effect'], 'payoff_from' => $item['payoff_from'], 'payoff_to' => $item['payoff_to']]);
                $registered++;
            } catch (Throwable $exception) {
                $failed[] = $item['title'].'：'.$exception->getMessage();
            }
        }

        return ['registered' => $registered, 'failed' => $failed];
    }
}
