<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalCommit;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterOpenItemExtraction;
use App\Models\ChapterSubmissionValidation;
use App\Models\Novel;
use InvalidArgumentException;

/** 待核事项台账：沿活动基线的正式提交链重放各章提取中的 open_items，不另存可变状态，基线切换与重挂后自然一致。 */
final class OpenItemLedger
{
    public const VERSION = 'open-items-v1';

    /** 提取动作：open 新开待核事项，confirm、refute 以本章证据确认或推翻既有事项。 */
    public const ACTIONS = ['open', 'confirm', 'refute'];

    /** 单章最多报告的变化条数，与供应商Schema一致。 */
    public const MAX_CHANGES = 10;

    /** 注入上下文的条数上限：待核取最近开立的，已核取最近结论的。 */
    private const PENDING_LIMIT = 60;

    private const RESOLVED_LIMIT = 30;

    /** 本章开始前的待核与已核事项；首章及没有任何记录时返回空列表。
     * @return array{version: string, chapter_number: int, instruction: string, pending: list<array<string, mixed>>, resolved: list<array<string, mixed>>, omitted_pending: int}
     */
    public function context(Novel $novel, int $chapterNumber): array
    {
        $items = [];
        $baselines = app(CanonicalBaselines::class);
        $baseline = $chapterNumber > 1 ? $baselines->current($novel) : null;
        $chain = $baseline === null ? [] : $baselines->chain($baseline, $chapterNumber - 1);
        foreach ($chain as $commit) {
            // 重挂或修订提交沿来源链回到原始提交，台账编号与该提交的提取结果绑定。
            $lineage = $baselines->lineage($commit);
            $root = CanonicalCommit::findOrFail($lineage[array_key_last($lineage)]);
            $opened = 0;
            foreach ($this->changes($root) as $change) {
                if ($change['action'] === 'open') {
                    $id = 'open-'.$root->id.'-'.(++$opened);
                    $items[$id] = ['id' => $id, 'claim' => $change['claim'], 'opened_chapter' => $commit->chapter_number, 'status' => 'pending',
                        'quote' => $change['evidence'][0]['quote'] ?? null];

                    continue;
                }
                $item = $items[$change['item_id']] ?? null;
                if ($item === null || $item['status'] !== 'pending') {
                    throw new InvalidArgumentException('待核台账引用不一致');
                }
                $items[$change['item_id']] = [...$item, 'status' => $change['action'] === 'confirm' ? 'confirmed' : 'refuted', 'conclusion' => $change['claim'], 'resolved_chapter' => $commit->chapter_number];
            }
        }
        $pending = array_values(array_filter($items, fn (array $item): bool => $item['status'] === 'pending'));
        $resolved = array_values(array_filter($items, fn (array $item): bool => $item['status'] !== 'pending'));
        usort($resolved, fn (array $a, array $b): int => $a['resolved_chapter'] <=> $b['resolved_chapter']);

        return ['version' => self::VERSION, 'chapter_number' => $chapterNumber,
            'instruction' => 'pending是前文尚未核实的事项，正文只能写成待核、怀疑或推测；要核实或推翻必须写出核实的动作、依据和结果。resolved是已核实的结论，后文不能改写。',
            'pending' => array_slice($pending, -self::PENDING_LIMIT), 'resolved' => array_slice($resolved, -self::RESOLVED_LIMIT), 'omitted_pending' => max(0, count($pending) - self::PENDING_LIMIT)];
    }

    /** 本章待核事项编号目录，供提取引用；只含待核项，已核事项不能再次结论。
     * @param  array<string, mixed>|null  $context
     * @return list<string>
     */
    public static function pendingIds(?array $context): array
    {
        return array_values(array_map(fn (array $item): string => $item['id'], $context['pending'] ?? []));
    }

    /** 原始提交在提交时通过验证的台账变化：独立台账请求的结果优先，早期运行取事件提取中的字段；都没有时为空。
     * @return list<array<string, mixed>>
     */
    private function changes(CanonicalCommit $root): array
    {
        if ($root->validation_id === null) {
            return [];
        }
        $validation = ChapterSubmissionValidation::findOrFail($root->validation_id);
        $ledger = ChapterOpenItemExtraction::where('submission_run_id', $validation->submission_run_id)->first();
        if ($ledger !== null) {
            if ($ledger->result_hash !== PlanningJson::hash($ledger->result)) {
                throw new InvalidArgumentException('待核台账来源提取已变化');
            }

            return $ledger->result['open_items'];
        }
        $extraction = ChapterEventExtraction::findOrFail($validation->extraction_id);
        if ($extraction->result_hash !== PlanningJson::hash($extraction->result)) {
            throw new InvalidArgumentException('待核台账来源提取已变化');
        }

        return $extraction->result['open_items'] ?? [];
    }
}
