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
            if ($kind === 'issue') {
                if (trim($note ?? '') === '' || mb_strlen($note ?? '') > 2000) {
                    throw ValidationException::withMessages(['note' => '问题说明须填写1至2000字。']);
                }
                $quote = $this->sourceQuotes($version->body, $quote ?? '');
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

    /** 空行分隔独立摘录；忽略复制产生的空白，但文字与标点必须逐字匹配，保存正文原值。 */
    public function sourceQuotes(string $body, string $input, int $maxLength = 5000): string
    {
        if ($this->withoutCopyWhitespace($input) === '' || mb_strlen($input) > $maxLength) {
            throw ValidationException::withMessages(['quote' => '请填写原文摘录，所有摘录合计不得超过'.$maxLength.'字。']);
        }
        // 建立规范化字符到原文字符的映射，匹配成功后还原原文空白，不把粘贴变体当作正文保存。
        $positions = [];
        $normalized = '';
        foreach (mb_str_split($body) as $offset => $character) {
            if ($this->withoutCopyWhitespace($character) !== '') {
                $positions[] = $offset;
                $normalized .= $character;
            }
        }
        $parts = preg_split('/\n[\p{Z}\t]*\n+/u', str_replace(["\r\n", "\r"], "\n", $input));
        $quotes = [];
        foreach ($parts as $part) {
            $needle = $this->withoutCopyWhitespace($part);
            if ($needle === '') {
                continue;
            }
            $start = mb_strpos($normalized, $needle);
            if ($start === false) {
                $number = count($quotes) + 1;
                throw ValidationException::withMessages(['quote' => '第'.$number.'处摘录未匹配当前正文：空格和换行已忽略，请核对文字、标点及版本；不连续的摘录请用空行分隔。']);
            }
            $from = $positions[$start];
            $to = $positions[$start + mb_strlen($needle) - 1];
            $quotes[] = mb_substr($body, $from, $to - $from + 1);
        }

        return implode("\n\n", $quotes);
    }

    /** 仅忽略排版空白、复制附带的零宽空格及字节序标记；不替换文字或标点。 */
    private function withoutCopyWhitespace(string $text): string
    {
        return preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', '', $text);
    }
}
