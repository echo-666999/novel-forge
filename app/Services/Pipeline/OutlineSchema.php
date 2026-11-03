<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use JsonException;

/** 分批大纲契约；结构门禁不能替代作者对推进点语义的审阅。 */
final class OutlineSchema
{
    public const VERSION = 'b03-outline-v1';

    public const CHANGE_TYPES = ['information', 'decision', 'relationship', 'resource', 'position', 'risk'];

    /** 首批带全书走向，后续批次只返回章节和延续摘要，不能悄悄重写走向。
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema, int $start, int $end, int $total): array
    {
        $point = $schema->object(self::strings($schema, ['subject', 'before', 'action', 'after', 'verification']) + [
            'change_type' => $schema->string()->enum(self::CHANGE_TYPES)->required(),
        ])->withoutAdditionalProperties();
        $chapter = $schema->object(self::strings($schema, ['title', 'summary', 'objective', 'conflict', 'hook']) + [
            'chapter_number' => $schema->integer()->min($start)->max($end)->required(),
            'progression_points' => $schema->array()->items($point)->min(1)->max(3)->required(),
        ])->withoutAdditionalProperties();
        $fields = [
            'chapters' => $schema->array()->items($chapter)->min($end - $start + 1)->max($end - $start + 1)->required(),
            'handoff' => $schema->object(self::strings($schema, ['continuity_summary']) + [
                'open_threads' => $schema->array()->items($schema->string())->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
        if ($start === 1) {
            $fields['roadmap'] = $schema->object(self::strings($schema, ['whole_story_summary', 'ending_direction']) + [
                'major_turning_points' => $schema->array()->items($schema->object([
                    'chapter_number' => $schema->integer()->min(1)->max($total)->required(),
                    'change' => $schema->string()->required(),
                ])->withoutAdditionalProperties())->min(1)->required(),
            ])->withoutAdditionalProperties()->required();
        }

        return $fields;
    }

    /** 严格解析整批；任何章节不合格时整批不接纳，不做截断或自动补章。
     * @return array<string, mixed>
     */
    public static function parse(string $text, int $start, int $end, int $total): array
    {
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('大纲不是完整有效的 JSON');
        }
        self::keys($data, $start === 1 ? ['chapters', 'handoff', 'roadmap'] : ['chapters', 'handoff']);
        self::list($data['chapters']);
        if (count($data['chapters']) !== $end - $start + 1) {
            throw new InvalidArgumentException('本批章节数与冻结范围不一致');
        }
        foreach ($data['chapters'] as $i => &$chapter) {
            self::stringObject($chapter, ['title', 'summary', 'objective', 'conflict', 'hook'], ['chapter_number', 'progression_points']);
            if (! is_int($chapter['chapter_number']) || $chapter['chapter_number'] !== $start + $i || mb_strlen($chapter['title']) > 255 || preg_match('/^第\s*[0-9一二三四五六七八九十百千万零〇]+\s*章[\s：:]*$/u', trim($chapter['title'])) === 1) {
                throw new InvalidArgumentException('章序不连续或章名只是占位符');
            }
            self::list($chapter['progression_points']);
            if (count($chapter['progression_points']) < 1 || count($chapter['progression_points']) > 3) {
                throw new InvalidArgumentException('每章必须有1至3条推进点');
            }
            foreach ($chapter['progression_points'] as $j => &$point) {
                self::stringObject($point, ['subject', 'before', 'action', 'after', 'verification', 'change_type']);
                if (! in_array($point['change_type'], self::CHANGE_TYPES, true) || self::compact($point['before']) === self::compact($point['after'])) {
                    throw new InvalidArgumentException('推进点缺少可辨认的前后变化');
                }
                $point['id'] = 'chapter-'.$chapter['chapter_number'].'-point-'.($j + 1);
            }
            unset($point);
        }
        unset($chapter);
        self::stringObject($data['handoff'], ['continuity_summary'], ['open_threads']);
        self::texts($data['handoff']['open_threads']);
        if ($start === 1) {
            self::stringObject($data['roadmap'], ['whole_story_summary', 'ending_direction'], ['major_turning_points']);
            self::list($data['roadmap']['major_turning_points']);
            if ($data['roadmap']['major_turning_points'] === []) {
                throw new InvalidArgumentException('全书走向缺少主要转折');
            }
            foreach ($data['roadmap']['major_turning_points'] as $turn) {
                self::stringObject($turn, ['change'], ['chapter_number']);
                if (! is_int($turn['chapter_number']) || $turn['chapter_number'] < 1 || $turn['chapter_number'] > $total) {
                    throw new InvalidArgumentException('全书转折章序越界');
                }
            }
        }

        return $data;
    }

    /** 构造必填文本属性。
     * @param  list<string>  $names
     * @return array<string, Type>
     */
    private static function strings(JsonSchema $schema, array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $result[$name] = $schema->string()->required();
        }

        return $result;
    }

    /** 只接受约定对象字段。
     * @param  list<string>  $names
     */
    private static function keys(mixed $value, array $names): void
    {
        if (! is_array($value) || array_is_list($value) || count($value) !== count($names) || array_diff($names, array_keys($value)) !== []) {
            throw new InvalidArgumentException('大纲字段结构不正确');
        }
    }

    /** 校验非空白文本字段。
     * @param  list<string>  $names
     * @param  list<string>  $extra
     */
    private static function stringObject(mixed $value, array $names, array $extra = []): void
    {
        self::keys($value, [...$names, ...$extra]);
        foreach ($names as $name) {
            self::text($value[$name]);
        }
    }

    /** 数组必须是 JSON 顺序列表。 */
    private static function list(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('大纲列表类型不正确');
        }
    }

    /** 延续线索允许为空，但每条线索必须有内容。 */
    private static function texts(mixed $value): void
    {
        self::list($value);
        foreach ($value as $text) {
            self::text($text);
        }
    }

    /** Unicode 空白不算有效内容。 */
    private static function text(mixed $value): void
    {
        if (! is_string($value) || preg_match('/(*UCP)\S/u', $value) !== 1) {
            throw new InvalidArgumentException('大纲文本为空或类型不正确');
        }
    }

    /** 比较前后状态时忽略空白差异，不把排版变化冒充情节推进。 */
    private static function compact(string $value): string
    {
        return preg_replace('/(*UCP)\s/u', '', $value);
    }
}
