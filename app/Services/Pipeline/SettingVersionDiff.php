<?php

namespace App\Services\Pipeline;

/**
 * 两个设定确认版本的字段级对照：按含义而非行号匹配。
 *
 * 人物按姓名、作者决定与待决定问题按问题文本匹配，规则与保留细节按集合比较，
 * 因此插入一条规则不会让后面所有条目都显示为“修改”。未识别的字段按路径逐项比较，不丢失内容。
 */
final class SettingVersionDiff
{
    /** 结构化字段的中文名，设定展示与对照共用。 */
    public const LABELS = ['world' => '世界', 'era' => '时代', 'background' => '背景', 'rules' => '规则', 'characters' => '人物', 'name' => '姓名', 'role' => '角色', 'goal' => '目标', 'personality' => '性格', 'speech_style' => '说话方式', 'abilities' => '能力', 'limitations' => '限制', 'tone' => '基调', 'narrative_style' => '文风', 'pov' => '视角', 'pacing' => '节奏', 'core_conflict' => '核心矛盾', 'preserved_details' => '保留细节', 'open_questions' => '待决定问题', 'question' => '问题', 'impact' => '影响', 'blocking' => '阻塞', 'answer' => '作者决定'];

    /** 人物卡片字段的展示顺序。 */
    private const CHARACTER_FIELDS = ['role', 'goal', 'personality', 'speech_style', 'abilities', 'limitations'];

    /** 已分区处理的设定键，其余归入“其他”。 */
    private const KNOWN = ['core_conflict', 'open_questions', 'characters', 'world', 'tone', 'preserved_details'];

    /** 对照两个版本；旧版在前、新版在后。
     * @param  array<string, mixed>  $oldSetting
     * @param  array<int, mixed>  $oldDecisions
     * @param  array<string, mixed>  $newSetting
     * @param  array<int, mixed>  $newDecisions
     * @return array{identical: bool, counts: array{modified: int, added: int, removed: int, unchanged: int}, sections: list<array{title: string, changes: list<array{label: string, kind: string, old: ?string, new: ?string}>, unchanged: list<array{label: string, value: string}>}>}
     */
    public static function compare(array $oldSetting, array $oldDecisions, array $newSetting, array $newDecisions): array
    {
        $world = fn (array $setting): array => is_array($setting['world'] ?? null) ? $setting['world'] : [];
        $tone = fn (array $setting): array => is_array($setting['tone'] ?? null) ? $setting['tone'] : [];
        $sections = [
            FieldDiff::section('核心矛盾', [FieldDiff::scalar('核心矛盾', $oldSetting['core_conflict'] ?? null, $newSetting['core_conflict'] ?? null)]),
            FieldDiff::section('作者决定', self::questions($oldDecisions, $newDecisions, true)),
            FieldDiff::section('待决定问题', self::questions(FieldDiff::listOf($oldSetting['open_questions'] ?? null), FieldDiff::listOf($newSetting['open_questions'] ?? null), false)),
            FieldDiff::section('人物', self::characters(FieldDiff::listOf($oldSetting['characters'] ?? null), FieldDiff::listOf($newSetting['characters'] ?? null))),
            FieldDiff::section('世界与规则', [
                FieldDiff::scalar('时代', $world($oldSetting)['era'] ?? null, $world($newSetting)['era'] ?? null),
                FieldDiff::scalar('背景', $world($oldSetting)['background'] ?? null, $world($newSetting)['background'] ?? null),
                ...FieldDiff::set('规则', $world($oldSetting)['rules'] ?? null, $world($newSetting)['rules'] ?? null),
                ...self::paths(array_diff_key($world($oldSetting), array_flip(['era', 'background', 'rules'])), array_diff_key($world($newSetting), array_flip(['era', 'background', 'rules']))),
            ]),
            FieldDiff::section('基调', [
                ...array_map(fn (string $key): array => FieldDiff::scalar(self::LABELS[$key], $tone($oldSetting)[$key] ?? null, $tone($newSetting)[$key] ?? null), ['pov', 'pacing', 'narrative_style']),
                ...self::paths(array_diff_key($tone($oldSetting), array_flip(['pov', 'pacing', 'narrative_style'])), array_diff_key($tone($newSetting), array_flip(['pov', 'pacing', 'narrative_style']))),
            ]),
            FieldDiff::section('保留细节', FieldDiff::set('保留细节', $oldSetting['preserved_details'] ?? null, $newSetting['preserved_details'] ?? null)),
            FieldDiff::section('其他', self::paths(array_diff_key($oldSetting, array_flip(self::KNOWN)), array_diff_key($newSetting, array_flip(self::KNOWN)))),
        ];

        return FieldDiff::summarize($sections);
    }

    /** 递归展开为“路径：值”行；布尔值转为是/否。
     * @return list<string>
     */
    public static function lines(array $value, string $prefix = ''): array
    {
        return array_map(fn (array $entry): string => $entry[0].'：'.$entry[1], self::entries($value, $prefix));
    }

    /** 递归展开为路径与值。
     * @return list<array{0: string, 1: string}>
     */
    private static function entries(array $value, string $prefix = ''): array
    {
        $entries = [];
        foreach ($value as $key => $item) {
            $label = $prefix.(is_int($key) ? (string) ($key + 1) : (self::LABELS[$key] ?? (string) $key));
            if (is_array($item)) {
                $entries = [...$entries, ...self::entries($item, $label.' / ')];
            } else {
                $entries[] = [$label, is_bool($item) ? ($item ? '是' : '否') : (string) $item];
            }
        }

        return $entries;
    }

    /** 作者决定与待决定问题按问题文本匹配，比较回答、影响与是否阻塞。
     * @param  list<mixed>  $old
     * @param  list<mixed>  $new
     * @return list<array{label: string, kind: string, old: ?string, new: ?string}>
     */
    private static function questions(array $old, array $new, bool $withAnswer): array
    {
        $describe = function (array $item) use ($withAnswer): string {
            $parts = $withAnswer ? [FieldDiff::text($item['answer'] ?? null) ?? '未作答'] : [];
            if (($impact = FieldDiff::text($item['impact'] ?? null)) !== null) {
                $parts[] = '影响：'.$impact;
            }
            if ((bool) ($item['blocking'] ?? false)) {
                $parts[] = '阻塞';
            }

            return implode("\n", $parts) ?: '（无补充说明）';
        };
        $keyed = function (array $items): array {
            $result = [];
            foreach ($items as $index => $item) {
                $item = is_array($item) ? $item : ['question' => $item];
                $result[FieldDiff::text($item['question'] ?? null) ?? '第'.($index + 1).'项'] = $item;
            }

            return $result;
        };
        $old = $keyed($old);
        $new = $keyed($new);
        $items = [];
        foreach ($new as $question => $item) {
            $items[] = FieldDiff::scalar($question, isset($old[$question]) ? $describe($old[$question]) : null, $describe($item));
        }
        foreach (array_diff_key($old, $new) as $question => $item) {
            $items[] = FieldDiff::scalar($question, $describe($item), null);
        }

        return $items;
    }

    /** 人物按姓名匹配：同名人物逐字段比较，新增或删除的人物整体列出。
     * @param  list<mixed>  $old
     * @param  list<mixed>  $new
     * @return list<array{label: string, kind: string, old: ?string, new: ?string}>
     */
    private static function characters(array $old, array $new): array
    {
        $keyed = function (array $items): array {
            $result = [];
            foreach ($items as $index => $item) {
                $item = is_array($item) ? $item : [];
                $result[FieldDiff::text($item['name'] ?? null) ?? '未命名人物'.($index + 1)] = $item;
            }

            return $result;
        };
        $summary = fn (array $character): string => implode('；', array_map(fn (array $entry): string => $entry[0].'：'.$entry[1],
            self::entries(array_diff_key($character, ['name' => true]))));
        $old = $keyed($old);
        $new = $keyed($new);
        $items = [];
        foreach ($new as $name => $character) {
            if (! isset($old[$name])) {
                $items[] = ['label' => $name, 'kind' => 'added', 'old' => null, 'new' => $summary($character)];

                continue;
            }
            $keys = array_values(array_unique([...self::CHARACTER_FIELDS, ...array_keys($old[$name]), ...array_keys($character)]));
            foreach (array_diff($keys, ['name']) as $key) {
                $items[] = FieldDiff::scalar($name.' · '.(self::LABELS[$key] ?? $key), self::flatten($old[$name][$key] ?? null), self::flatten($character[$key] ?? null));
            }
        }
        foreach (array_diff_key($old, $new) as $name => $character) {
            $items[] = ['label' => $name, 'kind' => 'removed', 'old' => $summary($character), 'new' => null];
        }

        return $items;
    }

    /** 未识别的字段按路径逐项比较。
     * @return list<array{label: string, kind: string, old: ?string, new: ?string}>
     */
    private static function paths(array $old, array $new): array
    {
        $old = array_column(self::entries($old), 1, 0);
        $new = array_column(self::entries($new), 1, 0);
        $items = [];
        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $path) {
            $items[] = FieldDiff::scalar((string) $path, $old[$path] ?? null, $new[$path] ?? null);
        }

        return $items;
    }

    /** 嵌套值展开为一段文字，便于并排比较。 */
    private static function flatten(mixed $value): ?string
    {
        return is_array($value) ? (implode('；', self::lines($value)) ?: null) : FieldDiff::text($value);
    }
}
