<?php

namespace App\Services\Pipeline;

use App\Enums\FactCategory;
use App\Models\Novel;
use App\Models\StoryEntity;
use InvalidArgumentException;

/** 角色视角仅来自经正式回放核验的认知记录，不从事件旁观或作者计划推断知情。 */
final class CharacterKnowledge
{
    /** 截止章过滤角色及其认知证据，未来人物和其他小说均不可进入结果。
     * @return array{characters: array<int, string>, rows: list<array>, events: list<array>}
     */
    public function atChapter(Novel $novel, int $chapter, ?int $actorId): array
    {
        $replay = app(StoryStateReplay::class)->atChapter($novel, $chapter);
        $characters = collect($replay['entities'])->where('type', 'character')->pluck('name', 'id')->all();
        if ($actorId !== null && ! array_key_exists($actorId, $characters)) {
            throw new InvalidArgumentException('所选人物不在本书该章的正式来源中');
        }
        $events = collect($replay['events'])->keyBy('id');
        $rows = [];
        if ($actorId !== null) {
            $properties = $replay['state']['entities']['entity-'.$actorId] ?? [];
            foreach (['knowledge' => '认知记录', 'belief' => '信念记录'] as $property => $label) {
                foreach ($properties[$property] ?? [] as $value) {
                    // 隐藏真相不因出现在作者数据里就成为角色可知内容。
                    if ($value['fact_category'] === FactCategory::AuthorHiddenTruth->value) {
                        continue;
                    }
                    $event = $events->get($value['source_event_id']);
                    if ($event === null) {
                        throw new InvalidArgumentException('角色认知缺少已核验的正式来源');
                    }
                    $text = $value['value_text'];
                    if ($value['value_ref'] !== '') {
                        $id = (int) substr($value['value_ref'], strlen('entity-'));
                        $text = StoryEntity::where('novel_id', $novel->id)->findOrFail($id)->name;
                    }
                    $rows[] = ['kind' => $label, 'text' => $text, 'category' => FactCategory::from($value['fact_category'])->label(),
                        'chapter' => $event['chapter'], 'event_id' => $event['id'], 'version_id' => $event['version_id'], 'evidence' => $event['evidence']];
                }
            }
        }

        return ['characters' => $characters, 'rows' => $rows,
            'events' => $events->filter(fn (array $event): bool => $actorId === null || $event['actor_id'] === $actorId)->values()->all()];
    }
}
