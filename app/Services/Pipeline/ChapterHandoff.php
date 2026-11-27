<?php

namespace App\Services\Pipeline;

use App\Models\Chapter;
use App\Models\ChapterProgressionCheck;
use App\Models\Novel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;

/**
 * 章末交接与故事时钟：独立检查顺带记下本章结束时的故事时间、人物所在和新留下的约定（只记录）；
 * 下一章细纲与首稿先接住上一章到期的约定，章末只按章纲钩子收束、不另起新约定。
 * 新运行冻结 config.chapter_handoff；上一章的交接在运行建立时从其正式版本的检查结果冻结进快照。
 */
final class ChapterHandoff
{
    public const VERSION = 'c-chapter-handoff-v1';

    /** 每章最多记录的约定数。 */
    public const MAX_PROMISES = 5;

    /** 运行是否冻结了章末交接。
     * @param  array<string, mixed>  $config
     */
    public static function frozen(array $config): bool
    {
        return ($config['chapter_handoff'] ?? null) === self::VERSION;
    }

    /** 检查结构中的 handoff。 */
    public static function schema(JsonSchema $schema): Type
    {
        $promise = $schema->object([
            'who' => $schema->string()->required(),
            'when' => $schema->string()->required(),
            'what' => $schema->string()->required(),
            'planned' => $schema->boolean()->required(),
        ])->withoutAdditionalProperties();

        return $schema->object([
            'story_time' => $schema->string()->required(),
            'location' => $schema->string()->required(),
            'open_promises' => $schema->array()->items($promise)->max(self::MAX_PROMISES)->required(),
        ])->withoutAdditionalProperties()->required();
    }

    /** 校验检查返回的交接；不合格由调用方记为未检查。
     * @return array{story_time: string, location: string, open_promises: list<array{who: string, when: string, what: string, planned: bool}>}
     */
    public static function parse(mixed $handoff): array
    {
        if (! is_array($handoff) || ! self::filled($handoff['story_time'] ?? null) || ! self::filled($handoff['location'] ?? null) || ! is_array($handoff['open_promises'] ?? null) || ! array_is_list($handoff['open_promises'])) {
            throw new InvalidArgumentException('章末交接结构不正确');
        }
        $promises = [];
        foreach (array_slice($handoff['open_promises'], 0, self::MAX_PROMISES) as $promise) {
            if (! is_array($promise) || ! self::filled($promise['who'] ?? null) || ! self::filled($promise['when'] ?? null) || ! self::filled($promise['what'] ?? null) || ! is_bool($promise['planned'] ?? null)) {
                throw new InvalidArgumentException('章末约定结构不正确');
            }
            $promises[] = ['who' => trim($promise['who']), 'when' => trim($promise['when']), 'what' => trim($promise['what']), 'planned' => $promise['planned']];
        }

        return ['story_time' => trim($handoff['story_time']), 'location' => trim($handoff['location']), 'open_promises' => $promises];
    }

    /** 上一章正式版本检查时记下的交接；第1章、上一章未正式提交或检查没有交接时为 null。
     * @return array{chapter_number: int, story_time: string, location: string, open_promises: list<array<string, mixed>>}|null
     */
    public static function previous(Novel $novel, int $chapterNumber): ?array
    {
        if ($chapterNumber < 2) {
            return null;
        }
        $chapter = Chapter::where('novel_id', $novel->id)->where('number', $chapterNumber - 1)->first();
        if ($chapter?->formal_version_id === null) {
            return null;
        }
        $handoff = ChapterProgressionCheck::where('chapter_id', $chapter->id)->where('version_id', $chapter->formal_version_id)->latest('id')->first()?->result['handoff'] ?? null;

        return is_array($handoff) && isset($handoff['story_time']) ? ['chapter_number' => $chapterNumber - 1, ...$handoff] : null;
    }

    /** 细纲与首稿：先接住上一章的约定，章末只按章纲钩子收束。
     * @param  array<string, mixed>|null  $previous
     */
    public static function planInstructions(?array $previous, string $step): string
    {
        $text = "\n章间衔接：本章结尾只按章纲hook收束，不要在章末另外安排新的会面、传话或“明天去做某事”这类章纲里没有的约定；已经在前文发生过的约定不要再约一次。故事时间只能向前，不能倒回已经写过的时段。";
        if ($previous !== null) {
            $promises = array_map(fn (array $promise): string => $promise['who'].'：'.$promise['when'].'，'.$promise['what'], $previous['open_promises']);
            $text .= '上一章（第'.$previous['chapter_number'].'章）结束时：故事时间'.$previous['story_time'].'，人物在'.$previous['location'].'。'
                .($promises === [] ? '上一章没有留下待办的约定。' : '上一章留下的约定：'.implode('；', $promises).'。时间已经到了的约定，本章开头先交代结果（去了、推迟了或没去及原因），不能当作没发生；本章章纲没有安排的，用一个短场景或几句话交代，不必重排全章。');
        }

        return $text.($step === 'fine' ? '细纲第一个场景的time写清与上一章结尾的时间关系。' : '');
    }

    /** 检查附加：记下章末交接，只记录。 */
    public static function reviewInstructions(): string
    {
        return "\n章末交接（只记录，不影响检查结论）：handoff.story_time写本章结束时的故事时间（第几天、什么时段，能推断就写具体，例如“重生后第三天傍晚”）；location写主角此时所在；open_promises列出本章新留下、还没兑现的约定或约见（谁、什么时候、去做什么），planned表示它是不是章纲hook本身要求的悬念。没有就给空列表。";
    }

    /** 非空白文本。 */
    private static function filled(mixed $value): bool
    {
        return is_string($value) && preg_match('/(*UCP)\S/u', $value) === 1;
    }
}
