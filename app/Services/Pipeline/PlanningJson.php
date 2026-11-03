<?php

namespace App\Services\Pipeline;

/** JSONB 不保留对象键序，摘要必须递归排序对象且保持列表顺序和类型。 */
final class PlanningJson
{
    /** 计算可跨 PostgreSQL JSONB 往返复验的内容摘要。 */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /** 生成稳定的中文 JSON，拒绝非法数值或无法编码的数据。 */
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** 对象按键递归排序，不重排章序、人物列表或推进点。 */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
