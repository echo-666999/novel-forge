<?php

namespace App\Services\Pipeline;

use App\Enums\FactCategory;
use App\Models\CanonicalBaseline;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\StoryEvent;
use App\Models\StoryStateProjection;
use InvalidArgumentException;

/** 只读回放正式事件，逐章核对快照；不写入历史、不调用模型。 */
final class StoryStateReplay
{
    /** 作者可选择的正式章节，不包含候选章节。
     * @return array<int, string>
     */
    public function chapters(Novel $novel): array
    {
        return $novel->chapters()->whereNotNull('canonical_commit_id')->orderBy('number')->get()
            ->mapWithKeys(fn (Chapter $chapter): array => [$chapter->number => '第'.$chapter->number.'章 · '.$chapter->title])->all();
    }

    /** 从首个正式事件重放至指定章；任何断链、证据或摘要不一致均拒绝展示可信状态。默认回放活动基线，也可回放候选或已被替换的基线。
     * @return array{chapter: int, state_version: int, state: array, entities: array, events: array, body: string}
     */
    public function atChapter(Novel $novel, int $number, ?CanonicalBaseline $baseline = null): array
    {
        $baselines = app(CanonicalBaselines::class);
        $baseline ??= $baselines->current($novel);
        if ($baseline === null || $baseline->novel_id !== $novel->id || $number < 1 || $baselines->commitFor($baseline, $number) === null) {
            throw new InvalidArgumentException('所选章节尚未正式提交');
        }
        $commits = $baselines->chain($baseline, $number);
        // 活动基线还须与章节正式指针一致；其他基线只按自身提交链回放。
        $active = $baseline->status === 'active';
        $state = ['entities' => []];
        $history = [];
        $entityIds = [];
        $body = '';
        $expected = 0;
        foreach ($commits as $commit) {
            $chapter = Chapter::where('novel_id', $novel->id)->findOrFail($commit->chapter_id);
            $version = ChapterVersion::findOrFail($commit->version_id);
            $expected = $commit->state_version;
            if ($commit->novel_id !== $novel->id || $chapter->number !== $commit->chapter_number
                || ($active && ($chapter->canonical_commit_id !== $commit->id || $chapter->formal_version_id !== $version->id)) || $version->chapter_id !== $chapter->id
                || hash('sha256', $version->body) !== $version->body_hash || ($commit->result['body_hash'] ?? null) !== $version->body_hash) {
                throw new InvalidArgumentException('正式提交链或正文摘要不一致');
            }
            $body = $version->body;
            $events = StoryEvent::where('commit_id', $commit->id)->orderBy('narrative_order')->get();
            if ($events->count() !== ($commit->result['event_count'] ?? null)) {
                throw new InvalidArgumentException('正式事件数量不一致');
            }
            $lastOrder = 0;
            foreach ($events as $event) {
                if ($event->novel_id !== $novel->id || $event->chapter_id !== $chapter->id || $event->version_id !== $version->id || $event->narrative_order <= $lastOrder) {
                    throw new InvalidArgumentException('正式事件来源或顺序不一致');
                }
                $lastOrder = $event->narrative_order;
                foreach ($event->evidence as $evidence) {
                    if (mb_substr($body, $evidence['start'], $evidence['end'] - $evidence['start']) !== $evidence['quote']) {
                        throw new InvalidArgumentException('事件证据与正式正文不一致');
                    }
                }
                $entityIds[] = $event->actor_entity_id;
                foreach ($event->payload['target_refs'] as $ref) {
                    $entityIds[] = $this->entityId($ref);
                }
                // 正式补丁只重放已提交的语义；主观认知追加，客观状态覆盖，保留全局事件来源。
                foreach ($event->payload['patch'] as $patch) {
                    $property = $patch['property'];
                    if (! in_array($property, SubmissionContract::PROPERTIES, true) || $patch['source_event_key'] !== $event->event_key || $event->payload['time_scope'] !== 'present') {
                        throw new InvalidArgumentException('状态补丁来源不合法');
                    }
                    $entityIds[] = $this->entityId($patch['entity_ref']);
                    if ($patch['value_ref'] !== '') {
                        $entityIds[] = $this->entityId($patch['value_ref']);
                    }
                    $value = ['value_ref' => $patch['value_ref'], 'value_text' => $patch['value_text'], 'fact_category' => $patch['fact_category'], 'source_event_key' => $event->event_key, 'source_event_id' => $event->id];
                    if (in_array($property, ['belief', 'knowledge'], true)) {
                        $state['entities'][$patch['entity_ref']][$property][] = $value;
                    } else {
                        $state['entities'][$patch['entity_ref']][$property] = $value;
                    }
                }
                $history[] = ['id' => $event->id, 'chapter' => $chapter->number, 'version_id' => $version->id, 'actor_id' => $event->actor_entity_id,
                    'summary' => $event->payload['summary'], 'category' => FactCategory::from($event->fact_category)->label(),
                    'time_scope' => match ($event->payload['time_scope']) {
                        'present' => '当前', 'memory' => '回忆', default => '预测'
                    },
                    'evidence' => $event->evidence];
            }
            $projection = StoryStateProjection::where('novel_id', $novel->id)->where('commit_id', $commit->id)->first();
            if ($projection === null || $projection->state_version !== $expected || PlanningJson::hash($projection->state) !== $projection->state_hash || PlanningJson::hash($state) !== $projection->state_hash) {
                throw new InvalidArgumentException('事件回放与正式状态快照不一致');
            }
        }
        if (count($commits) !== $number) {
            throw new InvalidArgumentException('正式章节链不连续');
        }
        // 实体目录只取截至所选章出现的引用，防止未来章节新人物泄漏到旧状态。
        $entities = StoryEntity::where('novel_id', $novel->id)->whereIn('id', array_unique($entityIds))->orderBy('id')->get();
        if ($entities->count() !== count(array_unique($entityIds))) {
            throw new InvalidArgumentException('历史实体引用不属于当前小说');
        }
        $names = $entities->mapWithKeys(fn (StoryEntity $entity): array => ['entity-'.$entity->id => $entity->name])->all();
        $display = [];
        foreach ($entities as $entity) {
            $properties = $state['entities']['entity-'.$entity->id] ?? [];
            $facts = [];
            foreach (['location' => '位置', 'life_status' => '存亡', 'item_owner' => '归属', 'belief' => '信念', 'knowledge' => '认知'] as $property => $label) {
                $values = isset($properties[$property]) ? (in_array($property, ['belief', 'knowledge'], true) ? $properties[$property] : [$properties[$property]]) : [];
                $facts[] = $label.'：'.($values === [] ? '未记录' : implode('；', array_map(fn (array $value): string => ($names[$value['value_ref']] ?? match ($value['value_text']) {
                    'alive' => '存活', 'dead' => '死亡', default => $value['value_text']
                }).'（'.FactCategory::from($value['fact_category'])->label().'，事件 #'.$value['source_event_id'].'）', $values)));
            }
            $display[] = ['id' => $entity->id, 'type' => $entity->type, 'name' => $entity->name, 'facts' => implode("\n", $facts)];
        }

        return ['chapter' => $number, 'state_version' => $expected, 'state' => $state, 'entities' => $display, 'events' => $history, 'body' => $body];
    }

    /** 解析正式实体编号，拒绝候选编号及任意字符串。 */
    private function entityId(string $reference): int
    {
        if (preg_match('/^entity-([1-9][0-9]*)$/D', $reference, $matches) !== 1) {
            throw new InvalidArgumentException('正式实体编号不合法');
        }

        return (int) $matches[1];
    }
}
