<?php

namespace App\Services\Pipeline;

use App\Enums\NovelStatus;
use App\Models\CanonicalBaseline;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\StoryEvent;
use App\Models\StoryOutboxEvent;
use App\Models\StoryStateProjection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/** 正式基线链解析：章位由基线链决定，状态序号全书单调递增，不再假定“状态序号 = 章号”。 */
final class CanonicalBaselines
{
    /** 活动基线；新书首次提交时在调用方持有的小说锁内惰性建立初始基线。 */
    public function active(Novel $novel): CanonicalBaseline
    {
        $current = $this->current($novel);
        if ($current !== null) {
            return $current;
        }
        $baseline = CanonicalBaseline::create(['novel_id' => $novel->id, 'parent_id' => null, 'fork_chapter' => 1, 'status' => 'active', 'purpose' => 'initial',
            'base_head_state' => 0, 'reason' => '首次正式提交建立初始基线', 'activated_at' => now()]);
        $novel->forceFill(['active_baseline_id' => $baseline->id])->save();

        return $baseline;
    }

    /** 只读取活动基线；尚无正式提交的书返回空。 */
    public function current(Novel $novel): ?CanonicalBaseline
    {
        if ($novel->active_baseline_id !== null) {
            return CanonicalBaseline::where('novel_id', $novel->id)->findOrFail($novel->active_baseline_id);
        }

        return CanonicalBaseline::where('novel_id', $novel->id)->where('status', 'active')->first();
    }

    /** 某章在该基线中的正式提交；起始章之前沿父基线解析。 */
    public function commitFor(CanonicalBaseline $baseline, int $number): ?CanonicalCommit
    {
        return $this->resolved($baseline)[$number] ?? null;
    }

    /** 从第1章起连续的提交链，逐章校验章位与状态序号衔接；指定上限时必须完整覆盖。
     * @return list<CanonicalCommit>
     */
    public function chain(CanonicalBaseline $baseline, ?int $upTo = null): array
    {
        $resolved = $this->resolved($baseline);
        $chain = [];
        $previous = 0;
        for ($number = 1; $upTo === null ? isset($resolved[$number]) : $number <= $upTo; $number++) {
            $commit = $resolved[$number] ?? throw new InvalidArgumentException('基线第'.$number.'章没有正式提交');
            if ($commit->chapter_number !== $number || $commit->previous_state_version !== $previous || $commit->state_version <= $previous) {
                throw new InvalidArgumentException('基线第'.$number.'章提交链衔接不一致');
            }
            $previous = $commit->state_version;
            $chain[] = $commit;
        }

        return $chain;
    }

    /** 基线中连续正式章数。 */
    public function length(CanonicalBaseline $baseline): int
    {
        return count($this->chain($baseline));
    }

    /** 写作或提交第 n 章应基于的状态序号：上一章提交的状态序号，首章为0。 */
    public function stateBefore(?CanonicalBaseline $baseline, int $number): int
    {
        if ($number === 1) {
            return 0;
        }
        $previous = $baseline === null ? null : $this->commitFor($baseline, $number - 1);

        return $previous?->state_version ?? throw new InvalidArgumentException('第'.($number - 1).'章尚未正式提交');
    }

    /** 活动基线下第 n 章应基于的状态序号；无提交的新书只允许首章。 */
    public function activeStateBefore(Novel $novel, int $number): int
    {
        return $this->stateBefore($this->current($novel), $number);
    }

    /** 第 n 章当前能否开始写作或提交：必须紧接活动基线的已提交章、前章提交可解析，且没有进行中的候选基线（正式稿修订期间不写新章）。 */
    public function writable(Novel $novel, int $number): bool
    {
        if ($number !== $novel->chapters_done + 1 || $this->hasOpenCandidate($novel)) {
            return false;
        }
        try {
            $this->activeStateBefore($novel, $number);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /** 是否有尚未切换或放弃的候选基线。 */
    public function hasOpenCandidate(Novel $novel): bool
    {
        return CanonicalBaseline::where('novel_id', $novel->id)->whereIn('status', ['candidate', 'verified'])->exists();
    }

    /** 提交及其修订、重挂来源的编号；实体来源指向原提交，复制后的提交仍可见这些实体。
     * @return list<int>
     */
    public function lineage(CanonicalCommit $commit): array
    {
        $ids = [$commit->id];
        while ($commit->source_commit_id !== null) {
            $commit = CanonicalCommit::findOrFail($commit->source_commit_id);
            $ids[] = $commit->id;
        }

        return $ids;
    }

    /** 建立候选基线：小说须空闲、无其他候选，起始章之前的提交与伏笔证据沿用活动基线。 */
    public function createCandidate(Novel $reference, int $forkChapter, string $purpose, string $reason, User $actor): CanonicalBaseline
    {
        return DB::transaction(function () use ($reference, $forkChapter, $purpose, $reason, $actor): CanonicalBaseline {
            $novel = Novel::lockForUpdate()->findOrFail($reference->id);
            $this->assertIdle($novel);
            $active = $this->current($novel) ?? throw new InvalidArgumentException('尚无正式提交，不能建立候选基线');
            $chain = $this->chain($active);
            if (count($chain) !== $novel->chapters_done || $forkChapter < 1 || $forkChapter > $novel->chapters_done || trim($reason) === '') {
                throw new InvalidArgumentException('候选基线起始章必须在已正式提交的章节内，并填写原因');
            }
            $candidate = CanonicalBaseline::create(['novel_id' => $novel->id, 'parent_id' => $active->id, 'fork_chapter' => $forkChapter, 'status' => 'candidate', 'purpose' => $purpose,
                'base_head_state' => $chain[array_key_last($chain)]->state_version, 'reason' => $reason, 'created_by' => $actor->id]);
            // 起始章之前的伏笔证据原样归入候选基线，切换后账本链完整。
            foreach (ForeshadowEvidence::where('novel_id', $novel->id)->where('baseline_id', $active->id)->where('chapter_number', '<', $forkChapter)->orderBy('id')->get() as $entry) {
                $this->copyEvidence($entry, $candidate, $entry->commit_id, $entry->version_id, $entry->body_hash, $entry->evidence, $actor);
            }

            return $candidate;
        });
    }

    /** 在候选基线提交某章的修订正文：事件与伏笔证据逐条重新定位到新正文，事实字段不变。
     * @param  array<string, list<array{paragraph: int, start: int, end: int, quote: string}>>  $eventEvidence  事件键 => 新证据
     * @param  array<int, array<string, mixed>>  $foreshadowEvidence  原伏笔证据编号 => 新证据
     */
    public function commitRevision(CanonicalBaseline $candidate, int $number, ChapterVersion $version, array $eventEvidence, array $foreshadowEvidence, User $actor, string $reason): CanonicalCommit
    {
        return DB::transaction(function () use ($candidate, $number, $version, $eventEvidence, $foreshadowEvidence, $actor, $reason): CanonicalCommit {
            $novel = Novel::lockForUpdate()->findOrFail($candidate->novel_id);
            $candidate = CanonicalBaseline::lockForUpdate()->findOrFail($candidate->id);
            $source = $this->sourceFor($candidate, $number);
            $sourceEvents = StoryEvent::where('commit_id', $source->id)->orderBy('narrative_order')->get();
            if ($version->chapter_id !== $source->chapter_id || hash('sha256', $version->body) !== $version->body_hash
                || array_diff(array_keys($eventEvidence), $sourceEvents->pluck('event_key')->all()) !== [] || count($eventEvidence) !== $sourceEvents->count()) {
                throw new InvalidArgumentException('修订正文或事件证据与原提交不对应');
            }
            foreach ($eventEvidence as $list) {
                $this->assertQuotes($list, $version->body);
            }
            $sourceForeshadow = ForeshadowEvidence::where('commit_id', $source->id)->where('baseline_id', $this->parent($candidate)->id)->pluck('id')->all();
            sort($sourceForeshadow);
            $relocated = array_keys($foreshadowEvidence);
            sort($relocated);
            if ($sourceForeshadow !== $relocated) {
                throw new InvalidArgumentException('伏笔证据必须逐条重新定位');
            }
            foreach ($foreshadowEvidence as $evidence) {
                $this->assertQuotes([$evidence], $version->body);
            }

            return $this->copyCommit($novel, $candidate, $source, $version, 'revision', $eventEvidence, $foreshadowEvidence, $actor, $reason);
        });
    }

    /** 把候选基线中尚未提交的某一章原样挂上（正式稿修订范围内无改动的章节），不调用模型。 */
    public function carry(CanonicalBaseline $candidate, int $number, User $actor): CanonicalCommit
    {
        return DB::transaction(function () use ($candidate, $number, $actor): CanonicalCommit {
            $novel = Novel::lockForUpdate()->findOrFail($candidate->novel_id);
            $candidate = CanonicalBaseline::lockForUpdate()->findOrFail($candidate->id);
            $source = $this->sourceFor($candidate, $number);

            return $this->copyCommit($novel, $candidate, $source, ChapterVersion::findOrFail($source->version_id), 'rebase', null, null, $actor, '正式稿修订范围内无改动，原样挂入');
        });
    }

    /** 把修订之后到链头的各章原样挂到候选基线（同一正文与证据，事件与状态编号重映射），不调用模型。 */
    public function rebase(CanonicalBaseline $candidate, User $actor): int
    {
        return DB::transaction(function () use ($candidate, $actor): int {
            $novel = Novel::lockForUpdate()->findOrFail($candidate->novel_id);
            $candidate = CanonicalBaseline::lockForUpdate()->findOrFail($candidate->id);
            $count = 0;
            for ($number = $candidate->fork_chapter; $number <= $novel->chapters_done; $number++) {
                if (CanonicalCommit::where('baseline_id', $candidate->id)->where('chapter_number', $number)->exists()) {
                    continue;
                }
                $source = $this->sourceFor($candidate, $number);
                $this->copyCommit($novel, $candidate, $source, ChapterVersion::findOrFail($source->version_id), 'rebase', null, null, $actor, '正式稿修订后下游章节原样重挂');
                $count++;
            }

            return $count;
        });
    }

    /** 回放候选链并逐条核对伏笔证据；章数、伏笔状态须与活动基线一致（只改文字、事实不变）。
     * @return array<string, mixed>
     */
    public function verify(CanonicalBaseline $reference): array
    {
        return DB::transaction(function () use ($reference): array {
            $candidate = CanonicalBaseline::lockForUpdate()->findOrFail($reference->id);
            $novel = Novel::findOrFail($candidate->novel_id);
            if (! in_array($candidate->status, ['candidate', 'verified'], true)) {
                throw new InvalidArgumentException('只能验证候选基线');
            }
            $active = $this->current($novel);
            $chain = $this->chain($candidate);
            if ($active === null || $candidate->parent_id !== $active->id || count($chain) !== $novel->chapters_done) {
                throw new InvalidArgumentException('候选基线章数与活动基线不一致，请先完成修订与下游重挂');
            }
            $replay = app(StoryStateReplay::class)->atChapter($novel, count($chain), $candidate);
            $activeReplay = app(StoryStateReplay::class)->atChapter($novel, $novel->chapters_done, $active);
            if (count($replay['events']) !== count($activeReplay['events'])) {
                throw new InvalidArgumentException('候选基线事件数量与活动基线不一致');
            }
            $ledgers = [];
            foreach (Foreshadow::where('novel_id', $novel->id)->orderBy('id')->get() as $ledger) {
                $status = 'planned';
                $entries = ForeshadowEvidence::where('foreshadow_id', $ledger->id)->where('baseline_id', $candidate->id)->orderBy('revision')->get();
                foreach ($entries->values() as $index => $entry) {
                    $commit = $this->commitFor($candidate, $entry->chapter_number);
                    $version = ChapterVersion::findOrFail($entry->version_id);
                    if ($entry->revision !== $index + 1 || $entry->from_status !== $status || $commit?->id !== $entry->commit_id || $commit->version_id !== $version->id
                        || $entry->body_hash !== $version->body_hash || mb_substr($version->body, $entry->evidence['start'], $entry->evidence['end'] - $entry->evidence['start']) !== $entry->evidence['quote']) {
                        throw new InvalidArgumentException('候选基线伏笔证据不一致：'.$ledger->title);
                    }
                    $status = $entry->to_status;
                }
                if ($status !== $ledger->status || $entries->count() !== $ledger->revision) {
                    throw new InvalidArgumentException('候选基线伏笔状态与活动基线不一致：'.$ledger->title);
                }
                $ledgers[] = ['id' => $ledger->id, 'status' => $status, 'revision' => $entries->count()];
            }
            $verification = ['verified_at' => now()->toIso8601String(), 'chapters' => count($chain), 'head_state' => $chain[array_key_last($chain)]->state_version,
                'state_hash' => PlanningJson::hash($replay['state']), 'events' => count($replay['events']), 'foreshadows' => $ledgers,
                'changed_chapters' => array_values(array_map(fn (CanonicalCommit $commit): int => $commit->chapter_number, array_filter($chain, fn (CanonicalCommit $commit): bool => $commit->origin === 'revision' && $commit->baseline_id === $candidate->id)))];
            $candidate->update(['status' => 'verified', 'verification' => $verification]);

            return $verification;
        });
    }

    /** 切换到已验证的候选基线：活动基线未前进时，原子更新章节正式指针和伏笔账本；旧基线只读保留。 */
    public function switchTo(CanonicalBaseline $reference, User $actor, string $reason): CanonicalBaseline
    {
        $this->verify($reference);

        return DB::transaction(function () use ($reference, $actor, $reason): CanonicalBaseline {
            $novel = Novel::lockForUpdate()->findOrFail($reference->novel_id);
            $candidate = CanonicalBaseline::lockForUpdate()->findOrFail($reference->id);
            $active = $this->current($novel);
            $activeChain = $active === null ? [] : $this->chain($active);
            if ($candidate->status !== 'verified' || $candidate->parent_id !== $active?->id || $activeChain === [] || $activeChain[array_key_last($activeChain)]->state_version !== $candidate->base_head_state) {
                throw new InvalidArgumentException('STALE_BASELINE');
            }
            if (trim($reason) === '' || $novel->status !== NovelStatus::Paused || ProductionRun::activeFor($novel->id) !== null || $novel->chapters()->whereNull('formal_version_id')->exists()) {
                throw new InvalidArgumentException('小说不在可切换基线的空闲状态，或未填写原因');
            }
            foreach ($this->chain($candidate) as $commit) {
                if ($commit->chapter_number >= $candidate->fork_chapter) {
                    $version = ChapterVersion::findOrFail($commit->version_id);
                    Chapter::whereKey($commit->chapter_id)->update(['formal_version_id' => $version->id, 'canonical_commit_id' => $commit->id, 'title' => $version->title]);
                }
            }
            $active->update(['status' => 'superseded']);
            $candidate->update(['status' => 'active', 'activated_by' => $actor->id, 'activated_at' => now(),
                'verification' => [...$candidate->verification, 'switch_reason' => $reason, 'previous_baseline_id' => $active->id]]);
            $novel->update(['active_baseline_id' => $candidate->id]);
            foreach (Foreshadow::where('novel_id', $novel->id)->get() as $ledger) {
                $entries = ForeshadowEvidence::where('foreshadow_id', $ledger->id)->where('baseline_id', $candidate->id)->orderBy('revision')->get();
                $ledger->update(['status' => $entries->last()?->to_status ?? 'planned', 'revision' => $entries->count()]);
            }

            return $candidate->refresh();
        });
    }

    /** 放弃候选基线；已写入的修订提交作为历史保留，不再参与任何链。 */
    public function abandon(CanonicalBaseline $reference, string $reason): CanonicalBaseline
    {
        return DB::transaction(function () use ($reference, $reason): CanonicalBaseline {
            $candidate = CanonicalBaseline::lockForUpdate()->findOrFail($reference->id);
            if (! in_array($candidate->status, ['candidate', 'verified'], true)) {
                throw new InvalidArgumentException('只能放弃候选基线');
            }
            $candidate->update(['status' => 'abandoned', 'verification' => [...($candidate->verification ?? []), 'abandoned_reason' => $reason, 'abandoned_at' => now()->toIso8601String()]]);

            return $candidate;
        });
    }

    /** 小说空闲：暂停中、无在途生产运行、无未提交章节、无其他候选基线。 */
    private function assertIdle(Novel $novel): void
    {
        if ($novel->status !== NovelStatus::Paused || ProductionRun::activeFor($novel->id) !== null || $novel->chapters()->whereNull('formal_version_id')->exists() || $this->hasOpenCandidate($novel)) {
            throw new InvalidArgumentException('小说正在生成、有未提交章节或已有候选基线，暂不能建立新基线');
        }
    }

    /** 候选基线的父基线。 */
    private function parent(CanonicalBaseline $candidate): CanonicalBaseline
    {
        return CanonicalBaseline::findOrFail($candidate->parent_id);
    }

    /** 候选基线中第 n 章应复制的原提交：父基线链上的该章提交；候选须仍可写且该章尚未提交、前一章已就位。 */
    private function sourceFor(CanonicalBaseline $candidate, int $number): CanonicalCommit
    {
        if ($candidate->status !== 'candidate' || $number < $candidate->fork_chapter
            || CanonicalCommit::where('baseline_id', $candidate->id)->where('chapter_number', $number)->exists()
            || ($number > $candidate->fork_chapter && ! CanonicalCommit::where('baseline_id', $candidate->id)->where('chapter_number', $number - 1)->exists())) {
            throw new InvalidArgumentException('候选基线须从起始章起逐章提交，且每章只提交一次');
        }

        return $this->commitFor($this->parent($candidate), $number) ?? throw new InvalidArgumentException('父基线缺少第'.$number.'章提交');
    }

    /** 复制提交：事件事实字段不变，证据可换为重新定位后的位置；状态投影按事件编号重映射，伏笔证据随提交复制。
     * @param  array<string, list<array<string, mixed>>>|null  $eventEvidence
     * @param  array<int, array<string, mixed>>|null  $foreshadowEvidence
     */
    private function copyCommit(Novel $novel, CanonicalBaseline $candidate, CanonicalCommit $source, ChapterVersion $version, string $origin, ?array $eventEvidence, ?array $foreshadowEvidence, User $actor, string $reason): CanonicalCommit
    {
        // 旧事件编号 => 新事件编号：候选基线中此前复制的各章，本章复制后追加。
        $mapping = $this->eventMapping($candidate);
        $previous = $this->stateBefore($candidate, $source->chapter_number);
        $next = $novel->state_version + 1;
        $receipt = [...$source->result, 'version_id' => $version->id, 'body_hash' => $version->body_hash, 'previous_state_version' => $previous, 'state_version' => $next,
            'baseline_id' => $candidate->id, 'origin' => $origin, 'source_commit_id' => $source->id];
        $commit = CanonicalCommit::create(['submission_run_id' => null, 'validation_id' => null, 'novel_id' => $novel->id, 'chapter_id' => $source->chapter_id, 'version_id' => $version->id,
            'previous_state_version' => $previous, 'state_version' => $next, 'idempotency_key' => (string) Uuid::uuid5($source->idempotency_key, 'baseline-'.$candidate->id),
            'request_hash' => PlanningJson::hash([$candidate->id, $source->id, $version->id, $eventEvidence, $foreshadowEvidence]), 'committed_by' => $actor->id, 'reason' => $reason,
            'approvals' => [...$source->approvals, 'origin' => $origin], 'result' => $receipt, 'created_at' => now(),
            'baseline_id' => $candidate->id, 'chapter_number' => $source->chapter_number, 'origin' => $origin, 'source_commit_id' => $source->id]);
        foreach (StoryEvent::where('commit_id', $source->id)->orderBy('narrative_order')->get() as $event) {
            $copy = StoryEvent::create(['commit_id' => $commit->id, 'novel_id' => $novel->id, 'chapter_id' => $event->chapter_id, 'version_id' => $version->id,
                'actor_entity_id' => $event->actor_entity_id, 'event_key' => $event->event_key, 'narrative_order' => $event->narrative_order, 'event_type' => $event->event_type,
                'fact_category' => $event->fact_category, 'payload' => $event->payload, 'evidence' => $eventEvidence[$event->event_key] ?? $event->evidence, 'created_at' => now()]);
            $mapping[$event->id] = $copy->id;
        }
        $state = $this->remapEvents(StoryStateProjection::where('commit_id', $source->id)->firstOrFail()->state, $mapping);
        StoryStateProjection::create(['commit_id' => $commit->id, 'novel_id' => $novel->id, 'state_version' => $next, 'state' => $state, 'state_hash' => PlanningJson::hash($state), 'created_at' => now()]);
        StoryOutboxEvent::create(['commit_id' => $commit->id, 'event_type' => 'chapter.committed', 'payload' => [...$receipt, 'commit_id' => $commit->id], 'created_at' => now()]);
        foreach (ForeshadowEvidence::where('commit_id', $source->id)->where('baseline_id', $this->parent($candidate)->id)->orderBy('id')->get() as $entry) {
            $this->copyEvidence($entry, $candidate, $commit->id, $version->id, $version->body_hash, $foreshadowEvidence[$entry->id] ?? $entry->evidence, $actor);
        }
        $novel->update(['state_version' => $next]);

        return $commit;
    }

    /** 候选基线已复制提交的事件编号映射（原事件 => 复制事件）。
     * @return array<int, int>
     */
    private function eventMapping(CanonicalBaseline $candidate): array
    {
        $mapping = [];
        foreach (CanonicalCommit::where('baseline_id', $candidate->id)->whereNotNull('source_commit_id')->get() as $commit) {
            $copies = StoryEvent::where('commit_id', $commit->id)->pluck('id', 'event_key');
            foreach (StoryEvent::where('commit_id', $commit->source_commit_id)->get() as $original) {
                $mapping[$original->id] = $copies[$original->event_key];
            }
        }

        return $mapping;
    }

    /** 只替换状态中的事件来源编号，其余字段原样保留。
     * @param  array<int, int>  $mapping
     * @return array<string|int, mixed>
     */
    private function remapEvents(array $data, array $mapping): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->remapEvents($value, $mapping);
            } elseif ($key === 'source_event_id' && is_int($value) && isset($mapping[$value])) {
                $data[$key] = $mapping[$value];
            }
        }

        return $data;
    }

    /** 复制一条伏笔证据到候选基线，修订号与流转不变。
     * @param  array<string, mixed>  $evidence
     */
    private function copyEvidence(ForeshadowEvidence $entry, CanonicalBaseline $candidate, int $commitId, int $versionId, string $bodyHash, array $evidence, User $actor): void
    {
        ForeshadowEvidence::create(['source' => $entry->source, 'foreshadow_id' => $entry->foreshadow_id, 'novel_id' => $entry->novel_id, 'revision' => $entry->revision,
            'from_status' => $entry->from_status, 'to_status' => $entry->to_status, 'commit_id' => $commitId, 'baseline_id' => $candidate->id, 'chapter_id' => $entry->chapter_id,
            'version_id' => $versionId, 'chapter_number' => $entry->chapter_number, 'body_hash' => $bodyHash, 'evidence' => $evidence, 'reason' => $entry->reason,
            'recorded_by' => $actor->id, 'request_key' => (string) Uuid::uuid5($entry->request_key, 'baseline-'.$candidate->id), 'request_hash' => PlanningJson::hash([$entry->id, $candidate->id, $evidence]), 'created_at' => now()]);
    }

    /** 证据引文必须是新正文指定位置的精确子串，且落在声明的段落内。
     * @param  list<array<string, mixed>>  $list
     */
    private function assertQuotes(array $list, string $body): void
    {
        $paragraphs = explode("\n", $body);
        foreach ($list as $evidence) {
            $start = $evidence['start'] ?? -1;
            $end = $evidence['end'] ?? -1;
            $quote = (string) ($evidence['quote'] ?? '');
            $paragraph = $evidence['paragraph'] ?? 0;
            if (! is_int($start) || ! is_int($end) || $start < 0 || $end <= $start || $quote === '' || mb_substr($body, $start, $end - $start) !== $quote
                || ! is_int($paragraph) || ! str_contains($paragraphs[$paragraph - 1] ?? '', $quote)) {
                throw new InvalidArgumentException('证据引文与修订正文位置不一致');
            }
        }
    }

    /** 基线及全部祖先的提交，按章取最近一个拥有该章的基线。
     * @return array<int, CanonicalCommit>
     */
    private function resolved(CanonicalBaseline $baseline): array
    {
        $ancestry = [];
        for ($node = $baseline; $node !== null; $node = $node->parent_id === null ? null : CanonicalBaseline::findOrFail($node->parent_id)) {
            $ancestry[] = $node;
        }
        $commits = CanonicalCommit::whereIn('baseline_id', array_map(fn (CanonicalBaseline $node): int => $node->id, $ancestry))->get()->groupBy('baseline_id');
        $resolved = [];
        // 从根到当前基线覆盖：子基线只拥有起始章及之后的章位。
        $floor = PHP_INT_MAX;
        foreach ($ancestry as $node) {
            foreach ($commits->get($node->id, collect()) as $commit) {
                if ($commit->chapter_number >= $node->fork_chapter && $commit->chapter_number < $floor) {
                    $resolved[$commit->chapter_number] = $commit;
                }
            }
            $floor = min($floor, $node->fork_chapter);
        }
        ksort($resolved);

        return $resolved;
    }
}
