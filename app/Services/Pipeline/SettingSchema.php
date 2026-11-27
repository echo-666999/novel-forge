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

    /** 人物卡扩展协议：建书时冻结到 generation_config.character_cards；未冻结的书沿用七项人物卡。 */
    public const CARD_VERSION = 'c-character-cards-v2';

    /** 原有七项人物卡字段。 */
    public const CHARACTER_FIELDS = ['name', 'role', 'goal', 'personality', 'speech_style', 'abilities', 'limitations'];

    /** 扩展人物卡新增的文本字段。 */
    public const CARD_TEXT_FIELDS = ['desire', 'fear', 'flaw', 'secret', 'relation_to_protagonist', 'arc'];

    /** 扩展人物卡新增的列表字段：口头禅与习惯动作、代表台词，各1至3条。 */
    public const CARD_LIST_FIELDS = ['quirks', 'voice_samples'];

    public const TIERS = ['protagonist' => '主角', 'core' => '核心配角', 'antagonist' => '反派', 'supporting' => '功能配角'];

    /** 为 SDK 构造严格字段结构，不把仅能解析 JSON 等同于契约通过。
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema, bool $cards = false): array
    {
        $character = self::strings($schema, self::CHARACTER_FIELDS);
        // 冻结了扩展人物卡的书：每张卡另有层级、欲望、恐惧、毛病、秘密、与主角关系、人物弧线、小习惯和代表台词。
        if ($cards) {
            $character += self::strings($schema, self::CARD_TEXT_FIELDS) + ['tier' => $schema->string()->enum(array_keys(self::TIERS))->required()];
            foreach (self::CARD_LIST_FIELDS as $field) {
                $character[$field] = $schema->array()->items($schema->string())->min(1)->max(3)->required();
            }
        }

        return [
            'world' => $schema->object(self::strings($schema, ['era', 'background']) + [
                'rules' => $schema->array()->items($schema->string())->min(1)->required(),
            ])->withoutAdditionalProperties()->required(),
            'characters' => $schema->array()->items($schema->object($character)->withoutAdditionalProperties())->min(1)->required(),
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
            // 带层级的是扩展人物卡，字段必须完整；其余按原七项校验，作者确认时两种卡都能保存。
            if (is_array($character) && array_key_exists('tier', $character)) {
                self::stringObject($character, [...self::CHARACTER_FIELDS, ...self::CARD_TEXT_FIELDS], ['tier', ...self::CARD_LIST_FIELDS]);
                if (! array_key_exists($character['tier'], self::TIERS)) {
                    throw new InvalidArgumentException('人物卡层级只能是主角、核心配角、反派或功能配角');
                }
                foreach (self::CARD_LIST_FIELDS as $field) {
                    self::stringList($character[$field]);
                    if (count($character[$field]) > 3) {
                        throw new InvalidArgumentException('人物卡的小习惯和代表台词各最多3条');
                    }
                }

                continue;
            }
            self::stringObject($character, self::CHARACTER_FIELDS);
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

    /** 本章出场人物的声纹：只取扩展人物卡，按名字出现在本章计划里判断出场；计划里一个都没提到时取主角。
     * @param  array<string, mixed>  $setting
     * @return list<array{name: string, speech_style: string, quirks: list<string>, voice_samples: list<string>, desire: string}>
     */
    public static function voiceCards(array $setting, string $planText): array
    {
        $cards = array_values(array_filter($setting['characters'] ?? [], fn (mixed $character): bool => is_array($character) && isset($character['voice_samples'])));
        $present = array_values(array_filter($cards, fn (array $character): bool => str_contains($planText, $character['name'])));
        if ($present === []) {
            $present = array_values(array_filter($cards, fn (array $character): bool => $character['tier'] === 'protagonist'));
        }

        return array_map(fn (array $character): array => ['name' => $character['name'], 'speech_style' => $character['speech_style'], 'quirks' => $character['quirks'],
            'voice_samples' => $character['voice_samples'], 'desire' => $character['desire']], $present);
    }

    /** 正文首稿与修订附加的声纹要求。 */
    public static function voiceInstructions(): string
    {
        return "\n人物声纹：voice_cards列出本章出场人物的说话方式、小习惯、代表台词和欲望。每个人开口都要带着自己的腔调和想要的东西，遮住名字也能认出是谁在说话；小习惯在合适的时候自然带出，不要每次出场都重复同一个动作。voice_samples只是示范，不要原句照搬。";
    }

    /** 冻结了扩展人物卡的设定与设定决策请求附加的字段说明。 */
    public static function cardInstructions(): string
    {
        return "\n扩展人物卡：每张卡另填tier（protagonist主角、core核心配角、antagonist反派、supporting功能配角）；desire是具体、可能失去的欲望；fear是最怕的事；flaw是会让他吃亏的毛病；secret是别人不知道的事（作者可见，没有就写“暂无”）；relation_to_protagonist写与主角的利害关系以及这种关系怎么变；arc写从起点到终点的变化；"
            .'quirks写一至三个辨识度细节（口头禅、习惯动作、穿着）；voice_samples写两三句最能代表他的原创台词，不同人物的台词遮住名字也能分辨。功能配角也要有名字、小习惯和台词。';
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
