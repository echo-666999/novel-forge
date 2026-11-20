<?php

namespace App\Services\Pipeline;

use InvalidArgumentException;

/** 提交准备校验失败：消息仍是停止原因代码，另附作者可读的具体原因，供失败记录与重跑弹窗回填。 */
final class SubmissionRejection extends InvalidArgumentException
{
    public function __construct(string $code, public readonly string $detail)
    {
        parent::__construct($code);
    }
}
