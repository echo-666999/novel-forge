<?php

namespace App\Services\Pipeline;

use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** 问题驱动的候选修订工作台，复用范围修订与独立检查，不修改正式历史。 */
final class RevisionWorkbench
{
    /** 同章工作台默认两轮，每轮修订（格式不合格时可补发一次）和复评各一次；超出须作者逐轮明确确认追加。 */
    public const ROUND_LIMIT = 2;

    /** 追加轮次的硬上限，到达后只能人工编辑，防止反复付费修订。 */
    public const HARD_ROUND_LIMIT = 4;

    /** 作者单章放宽时允许的最高轮次，避免无限付费修订。 */
    public const MAX_ROUND_LIMIT = 10;

    /** 本章 AI 修订轮次上限：单章放宽过时用单章值，否则用全局上限。 */
    public function hardLimit(int $chapterId): int
    {
        return (int) (Chapter::whereKey($chapterId)->value('revision_round_limit') ?? self::HARD_ROUND_LIMIT);
    }

    /** 作者放宽本章 AI 修订轮次上限：只能调高、不超过 10 轮，并在本章最新运行的控制记录中留档。正式稿和运行中的章节不能调整。 */
    public function raiseLimit(Chapter $chapter, User $author, int $limit, string $reason): void
    {
        ContentSchema::text($reason);
        DB::transaction(function () use ($chapter, $author, $limit, $reason): void {
            Novel::lockForUpdate()->findOrFail($chapter->novel_id);
            $chapter = Chapter::lockForUpdate()->findOrFail($chapter->id);
            $run = ChapterGenerationRun::where('chapter_id', $chapter->id)->latest('id')->first();
            if ($run === null || ! app(BookAuthorship::class)->allows($chapter->novel_id, $author, $run->config['author_id'] ?? null)) {
                throw new InvalidArgumentException('只有本书作者可以调整本章 AI 修订上限');
            }
            if ($chapter->formal_version_id !== null || ! in_array($run->status, ['paused', 'completed', 'error'], true)) {
                throw new InvalidArgumentException('本章已正式提交或正在运行，不能调整 AI 修订上限');
            }
            $old = $this->hardLimit($chapter->id);
            if ($limit <= $old || $limit > self::MAX_ROUND_LIMIT) {
                throw new InvalidArgumentException('新上限须大于当前的 '.$old.' 轮，且不超过 '.self::MAX_ROUND_LIMIT.' 轮');
            }
            $chapter->update(['revision_round_limit' => $limit]);
            $run->update(['control_history' => [...$run->control_history, ['action' => 'revision_round_limit', 'old' => $old, 'new' => $limit,
                'author_id' => $author->id, 'reason' => $reason, 'at' => now()->toIso8601String()]]]);
        });
    }

    /** 仅统计已保存的工作台授权，失败请求也占用该轮，不能靠恢复重置。 */
    public function rounds(int $chapterId): int
    {
        return ChapterGenerationRun::where('chapter_id', $chapterId)->whereNotNull('config->workbench')->count();
    }

    /** 在小说锁内冻结问题与当前版本；隐藏按钮不能代替服务端校验。
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    public function freeze(ChapterGenerationRun $parent, User $author, int $versionId, array $issues, bool $extraRound = false): array
    {
        $chapter = $parent->chapter;
        if (! app(BookAuthorship::class)->allows($chapter->novel_id, $author, $parent->config['author_id'] ?? null) || $chapter->formal_version_id !== null
            || ! app(CanonicalBaselines::class)->writable($chapter->novel, $chapter->number)
            || $chapter->current_candidate_version_id !== $versionId
            || ChapterGenerationRun::where('chapter_id', $chapter->id)->latest('id')->value('id') !== $parent->id
            || ProductionRun::coveringChapter($chapter->novel_id, $chapter->number, true) !== null
            || ChapterSubmissionRun::where('chapter_id', $chapter->id)->whereIn('status', ['pending', 'running'])->exists()) {
            throw new InvalidArgumentException('当前正文、作者或运行边界已变化，不能启动工作台修订');
        }
        $round = $this->rounds($chapter->id) + 1;
        if ($round > $this->hardLimit($chapter->id)) {
            throw new InvalidArgumentException('修订已达到'.$this->hardLimit($chapter->id).'轮上限，请人工编辑正文；不能继续模型修订');
        }
        if ($round > self::ROUND_LIMIT && ! $extraRound) {
            throw new InvalidArgumentException('已用完'.self::ROUND_LIMIT.'轮默认修订，追加第'.$round.'轮须明确确认');
        }
        if (! array_is_list($issues) || count($issues) < 1 || count($issues) > 10) {
            throw new InvalidArgumentException('请指定1至10条修订问题');
        }
        foreach ($issues as $issue) {
            ContentSchema::text($issue);
            if (mb_strlen($issue) > 1000) {
                throw new InvalidArgumentException('每条问题最多1000字');
            }
        }

        return ['round' => $round, 'round_limit' => self::ROUND_LIMIT, 'version_id' => $versionId, 'issues' => $issues, ...($round > self::ROUND_LIMIT ? ['extra_round' => true] : [])];
    }

    /** 工作台本轮修订与复评都已执行，且当前候选已切换为修订结果。 */
    public function reviewCompleted(ChapterGenerationRun $run): bool
    {
        return isset($run->config['workbench']) && $run->requests_used >= 2 && $run->chapter->progression_result !== null
            && $run->chapter->current_candidate_version_id !== $run->source_version_id;
    }

    /** 将当前版本的逐项复评转换为作者可读结论，旧版检查不冒充本轮结果。 */
    public function reviewSummary(ChapterGenerationRun $run): string
    {
        $result = $run->chapter->progression_result;
        if (! $this->reviewCompleted($run)) {
            return '本轮独立复评尚未完成';
        }
        $lines = ['正文版本 #'.$result['version_id'].' · '.match ($result['verdict']) {
            'pass' => '复评通过，等待全文核对', 'blocked' => '存在阻塞问题', default => '仍有待解决问题',
        }];
        foreach ($result['reading_reviews'] ?? [] as $review) {
            $finding = $run->config['recovery_findings'][$review['id']] ?? null;
            $lines[] = ($finding['reason'] ?? $review['id']).'：'.match ($review['disposition'] ?? null) {
                'resolved' => '已解决', 'benign' => '复核为合理表达', default => '未解决',
            }.'；'.$review['reason'];
            foreach ($review['evidence'] ?? [] as $evidence) {
                $lines[] = '原文证据：'.$evidence['quote'];
            }
        }

        return implode("\n", $lines);
    }

    /** 以只读状态明确显示剩余轮次和人工处理边界。 */
    public function status(ChapterGenerationRun $run): string
    {
        $count = $this->rounds($run->chapter_id);
        if ($run->chapter->formal_version_id !== null) {
            return '正式版本只读，不在候选修订范围';
        }
        if ($count >= $this->hardLimit($run->chapter_id)) {
            return '已授权'.$count.'轮，达到上限；剩余问题请人工编辑正文';
        }
        if ($count >= self::ROUND_LIMIT) {
            return '已用完'.self::ROUND_LIMIT.'轮默认修订（已授权'.$count.'轮）；可明确确认追加，最多'.$this->hardLimit($run->chapter_id).'轮，或人工编辑正文';
        }

        return '已授权'.$count.' / '.self::ROUND_LIMIT.'轮；每轮修订及独立复评各一次（修订格式不合格时补发一次），不自动正式提交';
    }
}
