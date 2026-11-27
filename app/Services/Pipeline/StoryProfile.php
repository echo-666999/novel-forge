<?php

namespace App\Services\Pipeline;

use App\Models\StoryPreset;
use InvalidArgumentException;

/**
 * 本书的题材包与文风预设：建书时从后台列表整份复制进 novels.story_profile，随作者输入冻结进设定、大纲和正文。
 * 设定与大纲直接读取冻结输入里的整份副本；正文另附渲染好的写法要求与示范段落。
 */
final class StoryProfile
{
    public const VERSION = 'c-story-profile-v1';

    public const HUMOR_LEVELS = ['none' => '无', 'low' => '低', 'mid' => '中', 'high' => '高'];

    /** 题材包字段：键为字段名，值为是否列表。 */
    public const GENRE_FIELDS = ['core_promise' => false, 'payoff_types' => true, 'beat_template' => false, 'world_rules_hints' => false, 'archetypes' => true, 'taboos' => true, 'default_style' => false];

    /** 文风预设字段：键为字段名，值为是否列表。 */
    public const STYLE_FIELDS = ['narrator_attitude' => false, 'sentence_rhythm' => false, 'dialogue_share' => false, 'humor_level' => false, 'humor_devices' => true,
        'emotion_expression' => false, 'vocabulary' => false, 'banned_words' => true, 'samples' => true, 'draft_fragment' => false];

    /** 建书时冻结所选题材包与文风预设；两者都没选时返回 null，旧书输入保持不变。
     * @return array<string, mixed>|null
     */
    public static function freeze(?string $genreKey, ?string $styleKey): ?array
    {
        $profile = [];
        foreach (['genre' => $genreKey, 'style' => $styleKey] as $kind => $key) {
            if (blank($key)) {
                continue;
            }
            $preset = StoryPreset::query()->enabled()->where('kind', $kind)->where('key', $key)->first();
            if ($preset === null) {
                throw new InvalidArgumentException(StoryPreset::KINDS[$kind].'「'.$key.'」不存在或已停用');
            }
            $profile[$kind] = ['key' => $preset->key, 'name' => $preset->name, 'version' => $preset->version, 'content' => self::normalize($kind, $preset->content)];
        }

        return $profile === [] ? null : ['version' => self::VERSION, ...$profile];
    }

    /** 校验并整理一份内容：只保留已知字段，文本去空白，列表去空项；爽点权重为0至100的整数。
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function normalize(string $kind, array $content): array
    {
        $fields = match ($kind) {
            'genre' => self::GENRE_FIELDS,
            'style' => self::STYLE_FIELDS,
            default => throw new InvalidArgumentException('未知的预设类别'),
        };
        $normalized = [];
        foreach ($fields as $field => $isList) {
            $value = $content[$field] ?? ($isList ? [] : '');
            if ($field === 'payoff_types') {
                $normalized[$field] = array_values(array_filter(array_map(fn (mixed $item): array => ['name' => trim((string) ($item['name'] ?? '')), 'weight' => max(0, min(100, (int) ($item['weight'] ?? 0)))],
                    is_array($value) ? $value : []), fn (array $item): bool => $item['name'] !== ''));

                continue;
            }
            $normalized[$field] = $isList
                ? array_values(array_filter(array_map(fn (mixed $item): string => trim((string) $item), is_array($value) ? $value : []), fn (string $item): bool => $item !== ''))
                : trim((string) $value);
        }
        if ($kind === 'style' && ! array_key_exists($normalized['humor_level'], self::HUMOR_LEVELS)) {
            $normalized['humor_level'] = 'none';
        }

        return $normalized;
    }

    /** 正文写法要求：作者手改过的 draft_fragment 优先，否则按各字段渲染。
     * @param  array<string, mixed>  $style
     */
    public static function fragment(array $style): string
    {
        if (filled($style['draft_fragment'] ?? null)) {
            return $style['draft_fragment'];
        }
        $parts = array_filter([
            filled($style['narrator_attitude'] ?? null) ? '叙述口吻：'.$style['narrator_attitude'] : null,
            filled($style['sentence_rhythm'] ?? null) ? '句子节奏：'.$style['sentence_rhythm'] : null,
            filled($style['dialogue_share'] ?? null) ? '对白约占全章'.$style['dialogue_share'] : null,
            ($style['humor_level'] ?? 'none') === 'none' ? null : '幽默程度'.self::HUMOR_LEVELS[$style['humor_level']].($style['humor_devices'] === [] ? '' : '，手法以'.implode('、', $style['humor_devices']).'为主'),
            filled($style['emotion_expression'] ?? null) ? '情绪表达：'.$style['emotion_expression'] : null,
            filled($style['vocabulary'] ?? null) ? '用词：'.$style['vocabulary'] : null,
            ($style['banned_words'] ?? []) === [] ? null : '不用这些词：'.implode('、', $style['banned_words']),
        ]);

        return implode('；', $parts).'。';
    }

    /** 正文请求附带的题材与文风资料：题材取读者承诺、爽点与禁忌，文风取示范段落。
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function bodyPayload(array $profile): array
    {
        $payload = [];
        if (isset($profile['genre'])) {
            $genre = $profile['genre']['content'];
            $payload['genre'] = ['name' => $profile['genre']['name'], 'core_promise' => $genre['core_promise'], 'payoff_types' => $genre['payoff_types'], 'taboos' => $genre['taboos']];
        }
        if (isset($profile['style'])) {
            $payload['style'] = ['name' => $profile['style']['name'], 'samples' => $profile['style']['content']['samples']];
        }

        return $payload;
    }

    /** 首稿、文字修复与修订附加的写法要求。
     * @param  array<string, mixed>  $profile
     */
    public static function instructions(array $profile): string
    {
        $text = '';
        if (isset($profile['style'])) {
            $text .= "\n本书文风「".$profile['style']['name'].'」：'.self::fragment($profile['style']['content'])
                .($profile['style']['content']['samples'] === [] ? '' : 'story_profile.style.samples是这种文风的示范段落，只学语气、节奏和用词，不照搬其中的人物、情节和句子。');
        }
        if (isset($profile['genre'])) {
            $text .= "\n本书题材「".$profile['genre']['name'].'」：给读者的承诺是'.rtrim($profile['genre']['content']['core_promise'], '。').'。本章要让读者感到这个承诺在兑现或在蓄力。'
                .($profile['genre']['content']['taboos'] === [] ? '' : '题材禁忌：'.implode('；', $profile['genre']['content']['taboos']).'。');
        }

        return $text;
    }
}
