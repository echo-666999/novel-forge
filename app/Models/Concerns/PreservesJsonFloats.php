<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Json;

/**
 * JSON 字段写库时保留小数的零位（1.0 不写成 1），使整数值与小数值经 PostgreSQL JSONB 往返后类型不变；
 * 冻结快照、运行配置等按内容摘要复验的字段依赖这一点，否则温度 1.0、检索得分 1.0 之类的值会让摘要不一致。
 */
trait PreservesJsonFloats
{
    /** 在框架默认编码上追加保留零小数位。 */
    protected function asJson($value, $flags = 0)
    {
        return Json::encode($value, $flags | JSON_PRESERVE_ZERO_FRACTION);
    }
}
