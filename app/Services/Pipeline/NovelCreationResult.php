<?php

namespace App\Services\Pipeline;

use App\Models\Novel;

/** 区分小说已保存与启动投递已确认，避免队列故障时误报启动成功。 */
final readonly class NovelCreationResult
{
    /** 保存本次创建的小说及投递确认结果，不携带内部异常或凭据。 */
    public function __construct(public Novel $novel, public bool $startupConfirmed) {}
}
