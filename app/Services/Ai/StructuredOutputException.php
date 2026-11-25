<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/** 统一结构校验未通过：供应商已完整响应且已计费，调用记录保持“成功”，由业务层按“响应结构无效”处理（与本地解析失败同一路径，可走既有修复）。 */
final class StructuredOutputException extends InvalidArgumentException
{
    /** 携带调用记录编号与结构问题清单，调用方据此定位证据。
     * @param  list<string>  $violations
     */
    public function __construct(public readonly int $costLogId, public readonly array $violations)
    {
        parent::__construct('响应结构不符合约定：'.implode('；', array_slice($violations, 0, 3)).(count($violations) > 3 ? ' 等'.count($violations).'处' : '').'。原始响应及费用证据已保留，未自动重发。');
    }
}
