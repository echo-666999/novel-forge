<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalCommit;
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
    /** 聚合前文各章摘要及上一章结尾；传闻、信念与客观事实保留原类别。
     * @return array<string, mixed>
     */
    public function build(Novel $novel, int $number): array
    {
        $chapters = [];
        $ending = '';
        for ($i = 1; $i < $number; $i++) {
            $chapter = Chapter::where('novel_id', $novel->id)->where('number', $i)->firstOrFail();
            $commit = CanonicalCommit::findOrFail($chapter->canonical_commit_id);
            $version = ChapterVersion::findOrFail($chapter->formal_version_id);
            if ($commit->novel_id !== $novel->id || $commit->chapter_id !== $chapter->id || $commit->version_id !== $version->id || $version->chapter_id !== $chapter->id || $commit->previous_state_version !== $i - 1
                || $commit->state_version !== $i || hash('sha256', $version->body) !== $version->body_hash) {
                throw new InvalidArgumentException('前文正式版本或提交链不一致');
            }
            $events = StoryEvent::where('commit_id', $commit->id)->orderBy('narrative_order')->get()->map(fn (StoryEvent $event): array => [
                'event_id' => $event->id, 'type' => $event->event_type, 'fact_category' => $event->fact_category,
                'time_scope' => $event->payload['time_scope'], 'summary' => $event->payload['summary'],
                'evidence' => $event->evidence,
            ])->all();
            $chapters[] = ['number' => $i, 'title' => $chapter->title, 'commit_id' => $commit->id, 'version_id' => $version->id,
                'body_hash' => $version->body_hash, 'events' => $events];
            $ending = implode("\n", array_slice(explode("\n", $version->body), -2));
        }
        $projection = $number === 1 ? null : StoryStateProjection::where('novel_id', $novel->id)->where('state_version', $number - 1)->firstOrFail();
        if ($projection !== null && ($projection->state_hash !== PlanningJson::hash($projection->state) || $projection->commit_id !== $chapters[array_key_last($chapters)]['commit_id'])) {
            throw new InvalidArgumentException('前序状态投影摘要不一致');
        }

        // 只纳入前序提交已批准的实体，历史摘要不能混入后续章的新实体。
        $commitIds = array_column($chapters, 'commit_id');
        $entities = StoryEntity::where('novel_id', $novel->id)->orderBy('id')->get()
            ->filter(fn (StoryEntity $entity): bool => ($entity->origin['kind'] ?? null) === 'confirmed_setting'
                || in_array($entity->origin['commit_id'] ?? null, $commitIds, true))
            ->mapWithKeys(fn (StoryEntity $entity): array => ['entity-'.$entity->id => $entity->definition])->all();

        return ['version' => 'c01-canonical-context-v1', 'prior_state_version' => $number - 1, 'chapters' => $chapters,
            'previous_ending' => $ending, 'entities' => $entities, 'state' => $projection?->state ?? ['entities' => []]];
    }
}
