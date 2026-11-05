<?php

namespace App\Services\Pipeline;

use App\Models\ChapterGenerationRun;
use App\Models\User;
use InvalidArgumentException;

/** 旧持续验收接口仅提供明确弃用提示及历史阅读兼容，不再创建无限授权。 */
final class ContentContinuationService
{
    /** 不把历史不限次数授权偷偷变为新运行。 */
    public function advance(int $parentId, User $operator, string $reason, bool $forceRewrite = false, ?string $modelOverride = null): never
    {
        throw new InvalidArgumentException('持续验收接口已停用，请使用有限的首章恢复入口');
    }

    /** 历史全文核对仍可保存，但未通过不会隐式产生后继或付费。 */
    public function recordReading(int $id, User $operator, bool $accepted, array $notes, bool $forceRewrite = false, ?string $modelOverride = null): ChapterGenerationRun
    {
        if ($forceRewrite || $modelOverride !== null) {
            throw new InvalidArgumentException('阅读记录不能同时请求重写或切换模型，请另行明确授权恢复');
        }

        return app(ContentGenerationService::class)->recordReading($id, $operator, $accepted, $notes);
    }
}
