<?php

namespace App\Services\Pipeline;

use App\Models\ChapterVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 作者阅读证据服务，只追加针对指定版本的记录。 */
final class ReadingRecordService
{
    /** 校验小说归属、原文摘要与摘录来源，拒绝跨书伪造问题。 */
    public function record(int $novelId, int $versionId, User $actor, string $kind, ?string $quote = null, ?string $note = null): void
    {
        if (! $actor->exists || ! in_array($kind, ['read', 'issue'], true)) {
            throw ValidationException::withMessages(['kind' => '阅读记录类型或账号无效。']);
        }
        DB::transaction(function () use ($novelId, $versionId, $actor, $kind, $quote, $note): void {
            $version = ChapterVersion::whereHas('chapter', fn ($q) => $q->where('novel_id', $novelId))->lockForUpdate()->findOrFail($versionId);
            if (! hash_equals($version->body_hash, hash('sha256', $version->body))) {
                throw ValidationException::withMessages(['version' => '正文摘要不一致，请先检查来源。']);
            }
            if ($kind === 'issue' && (trim($quote ?? '') === '' || ! str_contains($version->body, $quote)
                || mb_strlen($quote) > 5000 || trim($note ?? '') === '' || mb_strlen($note) > 2000)) {
                throw ValidationException::withMessages(['quote' => '摘录必须来自当前正文且不超过5000字，问题说明须为1至2000字。']);
            }
            // 同一版本的已读操作幂等；新版本必须重新阅读全文后标记。
            if ($kind === 'read' && DB::table('reading_records')->where(['version_id' => $versionId, 'actor_id' => $actor->id, 'kind' => 'read'])->exists()) {
                return;
            }
            DB::table('reading_records')->insert([
                'version_id' => $versionId, 'actor_id' => $actor->id, 'kind' => $kind,
                'quote' => $kind === 'issue' ? $quote : null, 'note' => $kind === 'issue' ? trim($note) : null, 'created_at' => now(),
            ]);
        });
    }
}
