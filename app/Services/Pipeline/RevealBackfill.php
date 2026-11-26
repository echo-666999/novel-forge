<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalCommit;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\OpenItemBackfill;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use InvalidArgumentException;

/** 已揭示事实的前文补录：对已正式提交的章节逐章复用台账请求，只保留 reveal，按原始提交保存；已补录的章节跳过，可中断后续跑。 */
final class RevealBackfill
{
    public const PROMPT_VERSION = 'backfill-reveal-v1';

    /** 每章最多保留的揭示条数，与台账请求的指令一致。 */
    private const PER_CHAPTER = 3;

    /** 补录第1章到 $through 章；返回每章新补录的条数，已补录的章节不出现在结果中。
     * @param  callable(int, int): void|null  $progress  每补完一章回调章号与条数
     * @return array<int, int>
     */
    public function run(Novel $novel, int $through, User $operator, string $model, ?callable $progress = null): array
    {
        $baselines = app(CanonicalBaselines::class);
        $baseline = $baselines->current($novel) ?? throw new InvalidArgumentException('尚无正式提交，无需补录');
        if ($through < 1 || $through > $novel->chapters_done) {
            throw new InvalidArgumentException('补录范围必须在已正式提交的章节内');
        }
        $done = [];
        foreach ($baselines->chain($baseline, $through) as $commit) {
            $lineage = $baselines->lineage($commit);
            $root = CanonicalCommit::findOrFail($lineage[array_key_last($lineage)]);
            if (OpenItemBackfill::where('commit_id', $root->id)->exists()) {
                continue;
            }
            $count = $this->chapter($novel, $commit, $root, $operator, $model);
            $done[$commit->chapter_number] = $count;
            if ($progress !== null) {
                $progress($commit->chapter_number, $count);
            }
        }

        return $done;
    }

    /** 单章补录：输入本章正式正文的段落目录与此前已揭示的事实，模型只报告本章首次揭示的关键事实。 */
    private function chapter(Novel $novel, CanonicalCommit $commit, CanonicalCommit $root, User $operator, string $model): int
    {
        $version = ChapterVersion::findOrFail($commit->version_id);
        if (hash('sha256', $version->body) !== $version->body_hash) {
            throw new InvalidArgumentException('第'.$commit->chapter_number.'章正式正文摘要不一致');
        }
        $anchors = ContentSchema::anchors($version->body);
        $known = app(OpenItemLedger::class)->context($novel, $commit->chapter_number)['revealed'];
        $payload = ['chapter_number' => $commit->chapter_number,
            'backfill' => '本次是前文补录：只在open_items中输出action为reveal的条目，记录本章首次揭示、之后人物必须当作已知的关键事实，不输出open、confirm、refute。已在open_items.revealed中的不要重复。',
            'evidence_catalog' => $anchors, 'open_items' => ['revealed' => $known]];
        $context = ['anchors' => $anchors, 'open_items' => true, 'open_item_ids' => [], 'run_id' => 0, 'version_id' => $version->id, 'body_hash' => $version->body_hash];
        $log = app(AiTextGenerationService::class)->generate('冻结提交输入：'.PlanningJson::encode($payload), 'real', $model, $novel->id, null, self::PROMPT_VERSION, TextRequestOptions::submission('ledger', $context));
        if ($log->status !== 'succeeded') {
            throw new InvalidArgumentException('第'.$commit->chapter_number.'章补录请求未成功：'.($log->error_message ?? $log->status));
        }
        $result = app(SubmissionValidator::class)->normalizeOpenItems(ContentSchema::decode($log->response_text), $version->body);
        $entries = array_slice(array_values(array_filter($result['open_items'], fn (array $entry): bool => $entry['action'] === 'reveal')), 0, self::PER_CHAPTER);
        OpenItemBackfill::create(['novel_id' => $novel->id, 'commit_id' => $root->id, 'chapter_number' => $commit->chapter_number, 'entries' => $entries,
            'entries_hash' => PlanningJson::hash($entries), 'cost_log_id' => $log->id, 'created_by' => $operator->id, 'created_at' => now()]);

        return count($entries);
    }
}
