<?php

namespace App\Services\Pipeline;

use App\Models\Novel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 书架归档事务：只调整分类并追加审计，不隐式暂停或恢复生成。 */
final class NovelArchiveService
{
    /** 锁定小说并幂等处理，拒绝空原因及未持久化操作账号。 */
    public function setArchived(int $novelId, User $actor, bool $archived, string $reason): void
    {
        if (! $actor->exists || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => '请由已登录账号填写1至1000字的操作原因。']);
        }
        DB::transaction(function () use ($novelId, $actor, $archived, $reason): void {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            if (($novel->archived_at !== null) === $archived) {
                return;
            }
            $novel->archived_at = $archived ? now() : null;
            $novel->archive_history = [...($novel->archive_history ?? []), [
                'action' => $archived ? 'archive' : 'restore', 'actor_id' => $actor->id,
                'reason' => trim($reason), 'at' => now()->toIso8601String(),
            ]];
            $novel->save();
        });
    }
}
