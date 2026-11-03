<?php

namespace App\Services\Ai;

use RuntimeException;

/** 只携带安全诊断及可保留响应，避免将含认证信息的供应商异常链写入日志。 */
final class AiGenerationException extends RuntimeException
{
    /** 将业务可读错误、计费不确定性和已有证据一起传递给记账服务。 */
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly string $status = 'failed',
        public readonly ?TextGenerationResult $result = null,
        public readonly ?int $costLogId = null,
    ) {
        parent::__construct($message);
    }
}
