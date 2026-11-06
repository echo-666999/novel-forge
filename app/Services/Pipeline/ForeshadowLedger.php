<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** 作者维护的伏笔账本；事实只能引用已正式提交的正文，不能改写正式故事。 */
final class ForeshadowLedger
{
    /** 登记不可覆盖的计划，同一请求重放不会重复创建。 */
    public function plan(Novel $reference, User $author, string $title, string $description, int $plantChapter, int $targetChapter, string $key): Foreshadow
    {
        $hash = PlanningJson::hash([$reference->id, $author->id, $title, $description, $plantChapter, $targetChapter]);

        return DB::transaction(function () use ($reference, $author, $title, $description, $plantChapter, $targetChapter, $key, $hash): Foreshadow {
            $novel = Novel::lockForUpdate()->findOrFail($reference->id);
            $this->validateKey($key);
            if ($existing = Foreshadow::where('request_key', $key)->first()) {
                $this->require($existing->request_hash === $hash, '请求编号对应不同计划');

                return $existing;
            }
            $this->idle($novel);
            $this->require(trim($title) !== '' && mb_strlen($title) <= 255 && trim($description) !== '', '伏笔名称或说明无效');
            $this->require($plantChapter >= 1 && $targetChapter >= $plantChapter && $targetChapter <= $novel->target_chapters, '伏笔章节范围无效');

            return Foreshadow::create(['novel_id' => $novel->id, 'title' => $title, 'description' => $description, 'plant_chapter' => $plantChapter, 'target_chapter' => $targetChapter, 'status' => 'planned', 'revision' => 0, 'created_by' => $author->id, 'request_key' => $key, 'request_hash' => $hash]);
        });
    }

    /** 原文定位、状态转换、并发修订及幂等请求在同一事务中验证。 */
    public function record(Foreshadow $reference, User $author, int $revision, string $status, int $versionId, int $paragraph, string $quote, string $reason, string $key): ForeshadowEvidence
    {
        $hash = PlanningJson::hash([$reference->id, $author->id, $revision, $status, $versionId, $paragraph, $quote, $reason]);

        return DB::transaction(function () use ($reference, $author, $revision, $status, $versionId, $paragraph, $quote, $reason, $key, $hash): ForeshadowEvidence {
            $novel = Novel::lockForUpdate()->findOrFail($reference->novel_id);
            $ledger = Foreshadow::lockForUpdate()->findOrFail($reference->id);
            $this->validateKey($key);
            if ($existing = ForeshadowEvidence::where('request_key', $key)->first()) {
                $this->require($existing->request_hash === $hash, '请求编号对应不同证据');

                return $existing;
            }
            $this->idle($novel);
            $this->require($ledger->revision === $revision, '伏笔已变更，请刷新后重试');
            $allowed = match ($ledger->status) {
                'planned' => ['planted'], 'planted', 'reinforced' => ['reinforced', 'resolved'], default => []
            };
            $this->require(in_array($status, $allowed, true), '伏笔状态转换无效');
            $version = ChapterVersion::findOrFail($versionId);
            $commit = CanonicalCommit::where('novel_id', $novel->id)->where('version_id', $versionId)->firstOrFail();
            $chapter = $this->formal($commit, $version);
            $this->require($chapter->number >= ($ledger->evidence()->max('chapter_number') ?? 1), '证据章节不能倒退');
            $parts = explode("\n", $version->body);
            $text = $parts[$paragraph - 1] ?? '';
            $offset = $quote === '' ? false : mb_strpos($text, $quote);
            $this->require($paragraph > 0 && $offset !== false && trim($reason) !== '', '证据必须逐字匹配指定正文段落，并填写理由');
            $start = mb_strlen(implode("\n", array_slice($parts, 0, $paragraph - 1))) + ($paragraph > 1 ? 1 : 0) + $offset;
            $evidence = ForeshadowEvidence::create(['foreshadow_id' => $ledger->id, 'novel_id' => $novel->id, 'revision' => $revision + 1, 'from_status' => $ledger->status, 'to_status' => $status, 'commit_id' => $commit->id, 'chapter_id' => $chapter->id, 'version_id' => $version->id, 'chapter_number' => $chapter->number, 'body_hash' => $version->body_hash, 'evidence' => ['paragraph' => $paragraph, 'start' => $start, 'end' => $start + mb_strlen($quote), 'quote' => $quote], 'reason' => $reason, 'recorded_by' => $author->id, 'request_key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
            $ledger->update(['status' => $status, 'revision' => $revision + 1]);

            return $evidence;
        });
    }

    /** 按章前边界重放证据；到期及逾期项全部保留，调用方将结果冻结进请求。
     * @return array<string, mixed>
     */
    public function context(Novel $novel, int $chapterNumber): array
    {
        $items = [];
        foreach (Foreshadow::where('novel_id', $novel->id)->with('evidence')->orderBy('id')->get() as $ledger) {
            $status = 'planned';
            $sources = [];
            $this->require($ledger->revision === $ledger->evidence->count() && $ledger->status === ($ledger->evidence->last()?->to_status ?? 'planned'), '伏笔当前状态与证据链不一致');
            foreach ($ledger->evidence as $entry) {
                if ($entry->chapter_number >= $chapterNumber) {
                    continue;
                }
                $version = ChapterVersion::findOrFail($entry->version_id);
                $chapter = $this->formal(CanonicalCommit::findOrFail($entry->commit_id), $version);
                $this->require($entry->chapter_number === $chapter->number && $entry->chapter_id === $chapter->id && $entry->novel_id === $novel->id && $entry->evidence['start'] >= 0 && $entry->evidence['end'] > $entry->evidence['start'], '伏笔证据来源不一致');
                $this->require($entry->body_hash === $version->body_hash && mb_substr($version->body, $entry->evidence['start'], $entry->evidence['end'] - $entry->evidence['start']) === $entry->evidence['quote'], '伏笔证据已损坏');
                $this->require($entry->from_status === $status, '伏笔证据链断裂');
                $status = $entry->to_status;
                $sources[] = $entry->only(['id', 'commit_id', 'version_id', 'chapter_number', 'body_hash', 'evidence', 'to_status']);
            }
            if ($status === 'resolved' || ($status === 'planned' && $ledger->plant_chapter > $chapterNumber)) {
                continue;
            }
            $items[] = [...$ledger->only(['id', 'title', 'description', 'plant_chapter', 'target_chapter']), 'status' => $status, 'must_address' => $status === 'planned' || $ledger->target_chapter <= $chapterNumber, 'obligation' => $status === 'planned' ? ($ledger->target_chapter <= $chapterNumber ? 'plant_and_resolve' : 'plant') : ($ledger->target_chapter <= $chapterNumber ? 'resolve' : 'preserve'), 'sources' => $sources];
        }

        return ['version' => 'c03-foreshadow-v1', 'chapter_number' => $chapterNumber, 'instruction' => '必须处理 must_address=true 的埋设或回收要求；不得裁剪到期及逾期项。其余伏笔保持连续性。只能依据正文证据判断是否完成，计划不是已发生事实。', 'items' => $items];
    }

    /** 核验正式提交身份与正文摘要，候选版本不能成为事实来源。 */
    private function formal(CanonicalCommit $commit, ChapterVersion $version): Chapter
    {
        $chapter = Chapter::findOrFail($commit->chapter_id);
        $this->require($commit->version_id === $version->id && $chapter->novel_id === $commit->novel_id && $chapter->formal_version_id === $version->id && $chapter->canonical_commit_id === $commit->id && hash('sha256', $version->body) === $version->body_hash && ($commit->result['body_hash'] ?? null) === $version->body_hash, '正式正文来源不一致');

        return $chapter;
    }

    /** 活跃章节已有冻结输入时禁止改账，小说行锁与启动入口共用。 */
    private function idle(Novel $novel): void
    {
        $this->require(! $novel->chapters()->whereNull('formal_version_id')->exists(), '存在未正式提交章节，请完成当前章节后维护伏笔');
    }

    /** 请求标识必须可审计且可安全幂等重放。 */
    private function validateKey(string $key): void
    {
        $this->require(Str::isUuid($key), '请求编号必须为 UUID');
    }

    /** 业务拒绝统一以明确中文原因返回。 */
    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
