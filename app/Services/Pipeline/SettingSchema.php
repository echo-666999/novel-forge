<?php

namespace App\Services\Pipeline;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use JsonException;

/** B02 设定候选契约；模型约束与本地严格校验共同防止错误结构进入成功结果。 */
final class SettingSchema
{
    public const VERSION = 'b02-setting-v1';

    /** 为 SDK 构造严格字段结构，不把仅能解析 JSON 等同于契约通过。
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'world' => $schema->object(self::strings($schema, ['era', 'background']) + [
                'rules' => $schema->array()->items($schema->string())->min(1)->required(),
            ])->withoutAdditionalProperties()->required(),
            'characters' => $schema->array()->items($schema->object(self::strings($schema, ['name', 'role', 'goal', 'personality', 'speech_style', 'abilities', 'limitations']))->withoutAdditionalProperties())->min(1)->required(),
            'tone' => $schema->object(self::strings($schema, ['narrative_style', 'pov', 'pacing']))->withoutAdditionalProperties()->required(),
            'core_conflict' => $schema->string()->required(),
            'preserved_details' => $schema->array()->items($schema->string())->min(1)->required(),
            'open_questions' => $schema->array()->items($schema->object(self::strings($schema, ['question', 'impact']) + [
                'blocking' => $schema->boolean()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    /** 只接受供应商原文中的完整 JSON，不使用 SDK 的代码围栏或引号修补结果。
     * @return array<string, mixed>
     */
    public static function parse(string $text): array
    {
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('设定响应不是完整有效的 JSON');
        }
        self::keys($data, ['world', 'characters', 'tone', 'core_conflict', 'preserved_details', 'open_questions']);
        self::stringObject($data['world'], ['era', 'background'], ['rules']);
        self::stringList($data['world']['rules']);
        self::list($data['characters'], 1);
        foreach ($data['characters'] as $character) {
            self::stringObject($character, ['name', 'role', 'goal', 'personality', 'speech_style', 'abilities', 'limitations']);
        }
        self::stringObject($data['tone'], ['narrative_style', 'pov', 'pacing']);
        self::text($data['core_conflict']);
        self::stringList($data['preserved_details']);
        self::list($data['open_questions'], 0);
        foreach ($data['open_questions'] as $question) {
            self::stringObject($question, ['question', 'impact'], ['blocking']);
            if (! is_bool($question['blocking'])) {
                throw new InvalidArgumentException('待确认问题的阻塞标记必须为布尔值');
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
        $fields = [];
        foreach ($names as $name) {
            $fields[$name] = $schema->string()->required();
        }

        return $fields;
    }

    /** 字段必须完全匹配，禁止遗漏、未知字段及数组冒充对象。
     * @param  list<string>  $names
     */
    private static function keys(mixed $value, array $names): void
    {
        if (! is_array($value) || array_is_list($value) || count($value) !== count($names) || array_diff($names, array_keys($value)) !== []) {
            throw new InvalidArgumentException('设定字段不符合约定结构');
        }
    }

    /** 校验对象中的非空白文本字段。
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

    /** 只接受顺序列表，避免 JSON 对象混入列表字段。 */
    private static function list(mixed $value, int $minimum): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $minimum) {
            throw new InvalidArgumentException('设定列表为空或类型不正确');
        }
    }

    /** 校验有内容的文本列表。 */
    private static function stringList(mixed $value): void
    {
        self::list($value, 1);
        foreach ($value as $item) {
            self::text($item);
        }
    }

    /** Unicode 空白不能冒充完整中文设定。 */
    private static function text(mixed $value): void
    {
        if (! is_string($value) || preg_match('/(*UCP)\S/u', $value) !== 1) {
            throw new InvalidArgumentException('设定文本缺失或只包含空白');
        }
    }
}
