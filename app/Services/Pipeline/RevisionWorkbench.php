<?php

namespace App\Services\Pipeline;

use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\FirstChapterRun;
use App\Models\User;
use InvalidArgumentException;

/** 问题驱动的候选修订工作台，复用范围修订与独立检查，不修改正式历史。 */
final class RevisionWorkbench
{
    /** 同章工作台最多两轮，每轮修订和复评各一次，耗尽后转人工。 */
    public const ROUND_LIMIT = 2;

    /** 仅统计已保存的工作台授权，失败请求也占用该轮，不能靠恢复重置。 */
    public function rounds(int $chapterId): int
    {
        return ChapterGenerationRun::where('chapter_id', $chapterId)->whereNotNull('config->workbench')->count();
    }

    /** 在小说锁内冻结问题与当前版本；隐藏按钮不能代替服务端校验。
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    public function freeze(ChapterGenerationRun $parent, User $author, int $versionId, array $issues): array
    {
        $chapter = $parent->chapter;
        if (($parent->config['author_id'] ?? null) !== $author->id || $chapter->formal_version_id !== null
            || $chapter->number !== $chapter->novel->chapters_done + 1
            || $chapter->novel->state_version !== $chapter->number - 1
            || $chapter->current_candidate_version_id !== $versionId
            || ChapterGenerationRun::where('chapter_id', $chapter->id)->latest('id')->value('id') !== $parent->id
            || FirstChapterRun::where('novel_id', $chapter->novel_id)->exists()
            || ChapterSubmissionRun::where('chapter_id', $chapter->id)->whereIn('status', ['pending', 'running'])->exists()) {
            throw new InvalidArgumentException('当前正文、作者或运行边界已变化，不能启动工作台修订');
        }
        $round = $this->rounds($chapter->id) + 1;
        if ($round > self::ROUND_LIMIT) {
            throw new InvalidArgumentException('修订已达到两轮上限，请转人工处理；不能继续模型修订');
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

        return ['round' => $round, 'round_limit' => self::ROUND_LIMIT, 'version_id' => $versionId, 'issues' => $issues];
    }

    /** 将当前版本的逐项复评转换为作者可读结论，旧版检查不冒充本轮结果。 */
    public function reviewSummary(ChapterGenerationRun $run): string
    {
        $result = $run->chapter->progression_result;
        if (! isset($run->config['workbench']) || $run->requests_used < 2 || $result === null
            || $run->chapter->current_candidate_version_id === $run->source_version_id) {
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
        if ($count >= self::ROUND_LIMIT) {
            return '已授权2 / 2轮；当前轮执行结束后不再开放模型修订，剩余问题转人工处理';
        }

        return '已授权'.$count.' / '.self::ROUND_LIMIT.'轮；每轮修订及独立复评各一次，不自动正式提交';
    }
}
