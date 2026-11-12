<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalBaseline;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\StoryEvent;
use App\Models\StoryStateProjection;
use InvalidArgumentException;

/** 仅从已正式提交的前文生成带来源的事件摘要，不生成新的故事事实。 */
final class CanonicalChapterContext
{
    /** 原协议：全部前文章节都附完整原文引用。 */
    public const LEGACY_VERSION = 'c01-canonical-context-v1';

    /** 滑动窗口：最近若干章附完整原文引用，更早章节保留全部事件与段落位置，省略引文。 */
    public const VERSION = 'c01-canonical-context-v2';

    /** 滑动窗口内保留完整原文引用的章数。 */
    public const FULL_EVIDENCE_CHAPTERS = 10;

    /** 聚合前文各章摘要及上一章结尾；传闻、信念与客观事实保留原类别。按冻结版本重建，旧运行沿用原协议。
     * @return array<string, mixed>
     */
    public function build(Novel $novel, int $number, string $protocol = self::VERSION, ?CanonicalBaseline $baseline = null): array
    {
        if (! in_array($protocol, [self::LEGACY_VERSION, self::VERSION], true)) {
            throw new InvalidArgumentException('未知前文上下文版本');
        }
        $fullEvidenceFrom = $protocol === self::VERSION ? max(1, $number - self::FULL_EVIDENCE_CHAPTERS) : 1;
        $chapters = [];
        $ending = '';
        // 前文按基线提交链取（默认活动基线），链内已校验章位与状态序号衔接。
        $baselines = app(CanonicalBaselines::class);
        $baseline ??= $baselines->current($novel);
        $chain = $number === 1 ? [] : $baselines->chain($baseline ?? throw new InvalidArgumentException('前文正式版本或提交链不一致'), $number - 1);
        $lineage = [];
        for ($i = 1; $i < $number; $i++) {
            $commit = $chain[$i - 1];
            $chapter = Chapter::where('novel_id', $novel->id)->where('number', $i)->firstOrFail();
            $version = ChapterVersion::findOrFail($commit->version_id);
            if ($commit->novel_id !== $novel->id || $commit->chapter_id !== $chapter->id || $version->chapter_id !== $chapter->id || hash('sha256', $version->body) !== $version->body_hash) {
                throw new InvalidArgumentException('前文正式版本或提交链不一致');
            }
            array_push($lineage, ...$baselines->lineage($commit));
            $events = StoryEvent::where('commit_id', $commit->id)->orderBy('narrative_order')->get()->map(fn (StoryEvent $event): array => [
                'event_id' => $event->id, 'type' => $event->event_type, 'fact_category' => $event->fact_category,
                'time_scope' => $event->payload['time_scope'], 'summary' => $event->payload['summary'],
                // 窗口外章节只保留证据的段落与位置，事件本身和来源编号不删减。
                'evidence' => $i >= $fullEvidenceFrom ? $event->evidence : array_map(fn (array $evidence): array => array_diff_key($evidence, ['quote' => true]), $event->evidence),
            ])->all();
            $chapters[] = ['number' => $i, 'title' => $chapter->title, 'commit_id' => $commit->id, 'version_id' => $version->id,
                'body_hash' => $version->body_hash, 'events' => $events];
            $ending = implode("\n", array_slice(explode("\n", $version->body), -2));
        }
        $projection = $number === 1 ? null : StoryStateProjection::where('novel_id', $novel->id)->where('commit_id', $chain[$number - 2]->id)->firstOrFail();
        if ($projection !== null && ($projection->state_hash !== PlanningJson::hash($projection->state) || $projection->commit_id !== $chapters[array_key_last($chapters)]['commit_id'])) {
            throw new InvalidArgumentException('前序状态投影摘要不一致');
        }

        // 只纳入前序提交已批准的实体，历史摘要不能混入后续章的新实体。
        // 修订或重挂提交复制自原提交，原提交批准的实体仍属于这条前文。
        $commitIds = $lineage;
        $entities = StoryEntity::where('novel_id', $novel->id)->orderBy('id')->get()
            ->filter(fn (StoryEntity $entity): bool => ($entity->origin['kind'] ?? null) === 'confirmed_setting'
                || (($entity->origin['kind'] ?? null) === 'author_definition' && ($entity->origin['effective_from'] ?? PHP_INT_MAX) < $number)
                || in_array($entity->origin['commit_id'] ?? null, $commitIds, true))
            ->mapWithKeys(fn (StoryEntity $entity): array => ['entity-'.$entity->id => $entity->definition])->all();

        $context = ['version' => $protocol, 'prior_state_version' => $number === 1 ? 0 : $chain[$number - 2]->state_version, 'chapters' => $chapters,
            'previous_ending' => $ending, 'entities' => $entities, 'state' => $projection?->state ?? ['entities' => []]];
        if ($protocol === self::VERSION) {
            $context['evidence_window'] = ['full_evidence_from_chapter' => $fullEvidenceFrom,
                'note' => '第'.$fullEvidenceFrom.'章之前的章节保留全部事件、类别和段落位置，省略原文引用；需要原文时以正式正文为准。'];
        }

        return $context;
    }
}
