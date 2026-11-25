<?php

namespace App\Services\Pipeline;

use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Jobs\FormalRevisionStepJob;
use App\Models\CanonicalBaseline;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Models\FormalRevision;
use App\Models\FormalRevisionChapter;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\StoryEvent;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** 正式稿修订：在候选基线中只改文字、事实不变。规则信号 → 模型逐条建议 → 作者取舍 → 程序替换 → 事实等价核对 → 提交候选基线 → 作者切换。 */
final class FormalRevisionService
{
    /** 发起修订：校验范围与请求上限，建立候选基线，逐章计算规则信号（不发请求）。 */
    public function start(Novel $novel, User $actor, int $from, int $to, string $intent, int $requestLimit, ?string $model = null): FormalRevision
    {
        $novel->refresh();
        $chapters = $to - $from + 1;
        if ($from < 1 || $to < $from || $to > $novel->chapters_done || trim($intent) === '' || $requestLimit < 1 || $requestLimit > $chapters * 4) {
            throw new InvalidArgumentException('修订范围须在已提交章节内，填写修订意图，请求上限为1至'.max(4, $chapters * 4).'次');
        }
        $model = trim($model ?? ($novel->generation_config['model'] ?? app(GeneralSettings::class)->default_model));

        return DB::transaction(function () use ($novel, $actor, $from, $to, $intent, $requestLimit, $model): FormalRevision {
            $baselines = app(CanonicalBaselines::class);
            $active = $baselines->current($novel) ?? throw new InvalidArgumentException('尚无正式提交');
            $candidate = $baselines->createCandidate($novel, $from, 'formal_revision', '正式稿修订第'.$from.'～'.$to.'章：'.$intent, $actor);
            $revision = FormalRevision::create(['novel_id' => $novel->id, 'baseline_id' => $candidate->id, 'from_chapter' => $from, 'to_chapter' => $to, 'intent' => $intent,
                'status' => 'open', 'model' => $model, 'request_limit' => $requestLimit, 'requests_used' => 0, 'created_by' => $actor->id]);
            foreach (range($from, $to) as $number) {
                $commit = $baselines->commitFor($active, $number);
                FormalRevisionChapter::create(['formal_revision_id' => $revision->id, 'chapter_number' => $number, 'source_commit_id' => $commit->id, 'source_version_id' => $commit->version_id,
                    'signals' => app(StyleSignals::class)->forCommitted($novel, $number), 'status' => 'pending', 'cost_log_ids' => []]);
            }

            return $revision;
        });
    }

    /** 为指定章节排队生成建议或事实核对，每章一个任务、一次请求。
     * @param  list<int>  $chapterIds
     */
    public function dispatch(array $chapterIds, string $step): void
    {
        foreach ($chapterIds as $id) {
            Bus::dispatch(new FormalRevisionStepJob($id, $step));
        }
    }

    /** 生成本章逐条润色建议：一次请求，结果逐条校验后保存，等待作者取舍。 */
    public function suggest(int $chapterId, AiTextGenerationService $ai): FormalRevisionChapter
    {
        [$record, $stage] = $this->claim($chapterId, ['pending', 'suggested', 'unchanged'], 'formal_suggest');
        $body = ChapterVersion::findOrFail($record->source_version_id)->body;
        $novel = Novel::findOrFail($record->revision->novel_id);
        $payload = ['chapter_number' => $record->chapter_number, 'intent' => $record->revision->intent, 'style' => $novel->creative_brief['style'] ?? null,
            'paragraphs' => $this->paragraphs($body), 'signals' => $record->signals];
        $options = TextRequestOptions::formalRevision('suggest', ['revision_id' => $record->formal_revision_id, 'chapter_number' => $record->chapter_number, 'paragraph_count' => count(explode("\n", $body))]);

        return $this->request($record, $stage, $ai, '正式稿修订建议；冻结输入：'.PlanningJson::encode($payload), $options, function (array $response) use ($record, $body): void {
            $record->update(['suggestions' => FormalRevisionContract::suggestions($response, $body), 'decisions' => null, 'candidate_version_id' => null, 'equivalence' => null, 'status' => 'suggested', 'error' => null]);
        });
    }

    /** 保存作者逐条取舍：只替换被接受的合格建议生成候选正文，没有接受项则本章无改动。字数仍须在冻结范围内。
     * @param  array<string, string>  $decisions  建议编号 => accept|reject|exception
     */
    public function decide(int $chapterId, User $actor, array $decisions): FormalRevisionChapter
    {
        return DB::transaction(function () use ($chapterId, $actor, $decisions): FormalRevisionChapter {
            $record = FormalRevisionChapter::lockForUpdate()->findOrFail($chapterId);
            $revision = FormalRevision::lockForUpdate()->findOrFail($record->formal_revision_id);
            if ($revision->status !== 'open' || ! in_array($record->status, ['suggested', 'decided', 'failed', 'unchanged', 'verified'], true) || $record->suggestions === null) {
                throw new InvalidArgumentException('本章当前不能取舍建议');
            }
            $normalized = [];
            foreach ($record->suggestions as $suggestion) {
                $choice = $decisions[$suggestion['id']] ?? 'reject';
                if (! in_array($choice, ['accept', 'reject', 'exception'], true) || ($choice === 'accept' && ! $suggestion['valid'])) {
                    throw new InvalidArgumentException('建议 '.$suggestion['id'].' 的取舍无效');
                }
                $normalized[$suggestion['id']] = $choice;
            }
            $accepted = array_values(array_filter($record->suggestions, fn (array $suggestion): bool => $normalized[$suggestion['id']] === 'accept'));
            if ($accepted === []) {
                $record->update(['decisions' => $normalized, 'candidate_version_id' => null, 'equivalence' => null, 'status' => 'unchanged', 'error' => null]);

                return $record;
            }
            $source = ChapterVersion::findOrFail($record->source_version_id);
            $body = FormalRevisionContract::apply($source->body, $accepted);
            $policy = Novel::findOrFail($revision->novel_id)->chapter_length_policy;
            $count = ContentSchema::count($body);
            if ($count < $policy['min_characters'] || $count > $policy['max_characters']) {
                throw new InvalidArgumentException('接受这些建议后正文'.$count.'字，超出'.$policy['min_characters'].'～'.$policy['max_characters'].'字范围');
            }
            // 候选正文按版本身份独立留档：来源阶段记录本次取舍，正文版本挂在原正式版本之后。
            $stage = NovelStage::create(['novel_id' => $revision->novel_id, 'stage' => Stage::Content, 'status' => StageStatus::Completed,
                'execution_count' => (int) NovelStage::where('novel_id', $revision->novel_id)->where('stage', Stage::Content->value)->max('execution_count') + 1,
                'input' => ['formal_revision_id' => $revision->id, 'chapter_number' => $record->chapter_number, 'step' => 'formal_apply', 'source_version_id' => $source->id],
                'output' => ['simulation' => false, 'stage' => 'content', 'data' => ['step' => 'formal_apply', 'accepted' => array_column($accepted, 'id'), 'actor_id' => $actor->id]],
                'started_at' => now(), 'finished_at' => now()]);
            $version = ChapterVersion::create(['chapter_id' => $source->chapter_id, 'run_id' => $source->run_id, 'source_stage_id' => $stage->id, 'parent_version_id' => $source->id,
                'version' => (int) ChapterVersion::where('chapter_id', $source->chapter_id)->max('version') + 1, 'title' => $source->title, 'body' => $body,
                'body_hash' => hash('sha256', $body), 'character_count' => $count, 'count_version' => ContentSchema::COUNT_VERSION, 'created_at' => now()]);
            $record->update(['decisions' => $normalized, 'candidate_version_id' => $version->id, 'equivalence' => null, 'status' => 'decided', 'error' => null]);

            return $record;
        });
    }

    /** 事实等价核对：证据能由程序平移的直接平移，其余交模型重新定位；所有事件成立、无新增事实与丢失约束才通过。 */
    public function verify(int $chapterId, AiTextGenerationService $ai): FormalRevisionChapter
    {
        [$record, $stage] = $this->claim($chapterId, ['decided', 'failed'], 'formal_equivalence');
        $old = ChapterVersion::findOrFail($record->source_version_id)->body;
        $new = ChapterVersion::findOrFail($record->candidate_version_id ?? throw new InvalidArgumentException('本章没有候选正文'))->body;
        $parent = CanonicalBaseline::findOrFail(CanonicalBaseline::findOrFail($record->revision->baseline_id)->parent_id);
        $events = StoryEvent::where('commit_id', $record->source_commit_id)->orderBy('narrative_order')->get();
        $foreshadows = ForeshadowEvidence::where('commit_id', $record->source_commit_id)->where('baseline_id', $parent->id)->orderBy('id')->get();
        [$eventEvidence, $foreshadowEvidence, $unresolved] = $this->relocateAll($events->all(), $foreshadows->all(), $old, $new);
        $oldParagraphs = explode("\n", $old);
        $newParagraphs = explode("\n", $new);
        $changed = [];
        foreach ($oldParagraphs as $index => $text) {
            if ($text !== $newParagraphs[$index]) {
                $changed[] = ['paragraph' => $index + 1, 'before' => $text, 'after' => $newParagraphs[$index]];
            }
        }
        // 证据全部落在未改动段落的事件，正文逐字相同，由程序判定成立；只请模型核对涉及改动段落的事件。
        $changedNumbers = array_column($changed, 'paragraph');
        $affected = $events->filter(fn (StoryEvent $event): bool => array_intersect(array_column($event->evidence, 'paragraph'), $changedNumbers) !== [])->values();
        $autoHeld = $events->count() - $affected->count();
        $events = $affected;
        $payload = ['chapter_number' => $record->chapter_number, 'changed_paragraphs' => $changed,
            'events' => $events->map(fn (StoryEvent $event): array => ['event_key' => $event->event_key, 'type' => $event->event_type, 'fact_category' => $event->fact_category, 'evidence_before' => array_column($event->evidence, 'quote')])->all(),
            'foreshadow_evidence' => $foreshadows->map(fn (ForeshadowEvidence $entry): array => ['title' => Foreshadow::whereKey($entry->foreshadow_id)->value('title'), 'status' => $entry->to_status, 'quote' => $entry->evidence['quote']])->all(),
            'unresolved' => array_map(fn (array $item): array => ['ref' => $item['ref'], 'original_quote' => $item['quote']], $unresolved)];
        $options = TextRequestOptions::formalRevision('equivalence', ['revision_id' => $record->formal_revision_id, 'chapter_number' => $record->chapter_number,
            'paragraph_count' => count($newParagraphs), 'event_keys' => $events->pluck('event_key')->all(), 'unresolved_refs' => array_column($unresolved, 'ref')]);

        return $this->request($record, $stage, $ai, '正式稿修订事实核对；冻结输入：'.PlanningJson::encode($payload), $options, function (array $response) use ($record, $events, $new, $eventEvidence, $foreshadowEvidence, $unresolved, $autoHeld): void {
            $checks = collect($response['event_checks'] ?? [])->keyBy('event_key');
            foreach ($response['relocations'] ?? [] as $relocation) {
                $item = collect($unresolved)->firstWhere('ref', $relocation['ref'] ?? null);
                if ($item === null) {
                    continue;
                }
                $located = FormalRevisionContract::locate($new, (int) $relocation['paragraph'], (string) $relocation['quote']);
                if ($item['kind'] === 'event') {
                    $eventEvidence[$item['key']][$item['index']] = [...$item['evidence'], ...$located];
                } else {
                    $foreshadowEvidence[$item['key']] = $this->placeForeshadow($foreshadowEvidence[$item['key']], $item, $located);
                }
            }
            $missing = array_values(array_filter($unresolved, fn (array $item): bool => $item['kind'] === 'event' ? ! isset($eventEvidence[$item['key']][$item['index']]) || $eventEvidence[$item['key']][$item['index']] === null : $this->foreshadowMissing($foreshadowEvidence[$item['key']], $item)));
            $failed = $events->filter(fn (StoryEvent $event): bool => ! ($checks->get($event->event_key)['holds'] ?? false))->pluck('event_key')->all();
            $passed = ($response['verdict'] ?? null) === 'equivalent' && $failed === [] && ($response['new_facts'] ?? []) === [] && ($response['lost_constraints'] ?? []) === [] && $missing === [];
            // 事件全部成立、证据全部定位时，剩下的新增事实或改变的约束可能正是作者要的修改：保留证据，交作者逐条确认。
            $factChanges = ! $passed && $failed === [] && $missing === [] && (($response['new_facts'] ?? []) !== [] || ($response['lost_constraints'] ?? []) !== []);
            $keepEvidence = $passed || $factChanges;
            $record->update(['status' => $passed ? 'verified' : 'failed', 'error' => $passed ? null : ($factChanges ? '核对发现事实变化，确认属有意修改后可由作者确认通过' : '事实等价核对未通过，请调整取舍后重新核对'),
                'equivalence' => ['verdict' => $response['verdict'] ?? null, 'summary' => $response['summary'] ?? '', 'failed_events' => $failed, 'auto_held_events' => $autoHeld,
                    'new_facts' => $response['new_facts'] ?? [], 'lost_constraints' => $response['lost_constraints'] ?? [], 'literary_issues' => $response['literary_issues'] ?? [],
                    'unrelocated' => array_column($missing, 'ref'), 'event_evidence' => $keepEvidence ? $eventEvidence : null, 'foreshadow_evidence' => $keepEvidence ? $foreshadowEvidence : null]]);
        });
    }

    /** 作者确认核对列出的新增事实与改变的约束属有意修改：仅当事件全部成立、证据全部定位时可用；不重新抽取事件，确认人、理由和逐条原文随核对结果留档。 */
    public function confirmFactChanges(int $chapterId, User $actor, string $reason): FormalRevisionChapter
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('请写明确认原因');
        }

        return DB::transaction(function () use ($chapterId, $actor, $reason): FormalRevisionChapter {
            $record = FormalRevisionChapter::lockForUpdate()->findOrFail($chapterId);
            $revision = FormalRevision::lockForUpdate()->findOrFail($record->formal_revision_id);
            $equivalence = $record->equivalence;
            $changes = [...($equivalence['new_facts'] ?? []), ...($equivalence['lost_constraints'] ?? [])];
            if ($revision->status !== 'open' || $record->status !== 'failed' || ! is_array($equivalence) || $changes === []
                || ($equivalence['failed_events'] ?? []) !== [] || ($equivalence['unrelocated'] ?? []) !== []) {
                throw new InvalidArgumentException('只有事件全部成立、证据全部定位且仅有新增事实或约束变化的核对结果可以由作者确认');
            }
            // 早于本功能的核对未保留证据时，只在全部证据可由程序确定性平移的情况下重新计算，需模型定位的不能补算。
            if (! is_array($equivalence['event_evidence'] ?? null)) {
                [$eventEvidence, $foreshadowEvidence, $unresolved] = $this->relocateAll(StoryEvent::where('commit_id', $record->source_commit_id)->orderBy('narrative_order')->get()->all(),
                    ForeshadowEvidence::where('commit_id', $record->source_commit_id)->where('baseline_id', CanonicalBaseline::findOrFail(CanonicalBaseline::findOrFail($revision->baseline_id)->parent_id)->id)->orderBy('id')->get()->all(),
                    ChapterVersion::findOrFail($record->source_version_id)->body, ChapterVersion::findOrFail($record->candidate_version_id)->body);
                if ($unresolved !== []) {
                    throw new InvalidArgumentException('本次核对未保留需模型定位的证据，请重新核对后再确认');
                }
                $equivalence['event_evidence'] = $eventEvidence;
                $equivalence['foreshadow_evidence'] = $foreshadowEvidence;
            }
            $equivalence['author_confirmed_changes'] = ['new_facts' => $equivalence['new_facts'] ?? [], 'lost_constraints' => $equivalence['lost_constraints'] ?? [],
                'actor_id' => $actor->id, 'reason' => trim($reason), 'at' => now()->toIso8601String()];
            $record->update(['status' => 'verified', 'error' => null, 'equivalence' => $equivalence]);

            return $record;
        });
    }

    /** 范围内各章都已核对或无改动时，逐章提交到候选基线、原样重挂下游并验证整条候选链。 */
    public function commitCandidate(FormalRevision $reference, User $actor): CanonicalBaseline
    {
        $revision = FormalRevision::findOrFail($reference->id);
        $records = $revision->chapters()->get();
        if ($revision->status !== 'open' || $records->contains(fn (FormalRevisionChapter $record): bool => ! in_array($record->status, ['verified', 'unchanged'], true))) {
            throw new InvalidArgumentException('还有章节未取舍或未通过核对');
        }
        if (! $records->contains(fn (FormalRevisionChapter $record): bool => $record->status === 'verified')) {
            throw new InvalidArgumentException('没有任何章节有改动，无需提交候选基线');
        }
        $baselines = app(CanonicalBaselines::class);
        $candidate = CanonicalBaseline::findOrFail($revision->baseline_id);
        DB::transaction(function () use ($records, $baselines, $candidate, $actor, $revision): void {
            foreach ($records as $record) {
                if ($record->status === 'verified') {
                    $baselines->commitRevision($candidate, $record->chapter_number, ChapterVersion::findOrFail($record->candidate_version_id),
                        $record->equivalence['event_evidence'], $this->foreshadowKeys($record->equivalence['foreshadow_evidence']), $actor, '正式稿修订：'.$revision->intent);
                } else {
                    $baselines->carry($candidate, $record->chapter_number, $actor);
                }
                $record->update(['status' => $record->status === 'verified' ? 'committed' : 'unchanged']);
            }
            $baselines->rebase($candidate, $actor);
        });
        $baselines->verify($candidate);
        $revision->update(['status' => 'ready']);

        return $candidate->refresh();
    }

    /** 切换到已验证的候选基线。 */
    public function switchTo(FormalRevision $revision, User $actor, string $reason): void
    {
        if ($revision->status !== 'ready') {
            throw new InvalidArgumentException('候选基线尚未验证');
        }
        app(CanonicalBaselines::class)->switchTo(CanonicalBaseline::findOrFail($revision->baseline_id), $actor, $reason);
        $revision->update(['status' => 'switched']);
    }

    /** 放弃修订与候选基线；已生成的候选正文与请求记录保留。 */
    public function abandon(FormalRevision $revision, string $reason): void
    {
        if (! in_array($revision->status, ['open', 'ready'], true)) {
            throw new InvalidArgumentException('该修订已结束');
        }
        app(CanonicalBaselines::class)->abandon(CanonicalBaseline::findOrFail($revision->baseline_id), $reason);
        $revision->update(['status' => 'abandoned']);
    }

    /** 小说锁内占用一次请求额度并建立运行中的阶段记录；超过上限在请求前拒绝。
     * @param  list<string>  $allowed
     * @return array{0: FormalRevisionChapter, 1: NovelStage}
     */
    private function claim(int $chapterId, array $allowed, string $step): array
    {
        return DB::transaction(function () use ($chapterId, $allowed, $step): array {
            $record = FormalRevisionChapter::lockForUpdate()->findOrFail($chapterId);
            $revision = FormalRevision::lockForUpdate()->findOrFail($record->formal_revision_id);
            if ($revision->status !== 'open' || ! in_array($record->status, $allowed, true)) {
                throw new InvalidArgumentException('本章当前不能执行该步骤');
            }
            if ($revision->requests_used >= $revision->request_limit) {
                throw new InvalidArgumentException('已用完本次修订授权的'.$revision->request_limit.'次请求');
            }
            $revision->update(['requests_used' => $revision->requests_used + 1]);
            $stage = NovelStage::create(['novel_id' => $revision->novel_id, 'stage' => Stage::Content, 'status' => StageStatus::Running,
                'execution_count' => (int) NovelStage::where('novel_id', $revision->novel_id)->where('stage', Stage::Content->value)->max('execution_count') + 1,
                'input' => ['formal_revision_id' => $revision->id, 'chapter_number' => $record->chapter_number, 'step' => $step, 'request_ordinal' => $revision->requests_used],
                'started_at' => now()]);

            return [$record->fresh('revision'), $stage];
        });
    }

    /** 发送一次请求并结算：成功时交由处理函数保存结果；失败只记录原因，不自动重试。 */
    private function request(FormalRevisionChapter $record, NovelStage $stage, AiTextGenerationService $ai, string $prompt, TextRequestOptions $options, callable $handle): FormalRevisionChapter
    {
        $log = null;
        try {
            $log = $ai->generate($prompt, 'real', $record->revision->model, $record->revision->novel_id, $stage->id, FormalRevisionContract::VERSION, $options);
            $response = ContentSchema::decode($log->response_text);
            DB::transaction(fn () => $handle($response));
            $stage->update(['status' => StageStatus::Completed, 'output' => ['simulation' => false, 'stage' => 'content', 'data' => ['step' => $stage->input['step'], 'cost_log_id' => $log->id]], 'finished_at' => now()]);
        } catch (Throwable $exception) {
            $log ??= $exception instanceof AiGenerationException && $exception->costLogId !== null ? CostLog::find($exception->costLogId) : null;
            $message = $exception instanceof InvalidArgumentException || $exception instanceof AiGenerationException ? $exception->getMessage() : '本地处理异常，结果已保留';
            $stage->update(['status' => StageStatus::Failed, 'error_message' => $message, 'output' => ['simulation' => false, 'stage' => 'content', 'data' => ['step' => $stage->input['step'], 'cost_log_id' => $log?->id]], 'finished_at' => now()]);
            $record->refresh()->update(['error' => $message]);
        }
        $record->refresh();
        if ($log !== null) {
            $record->update(['cost_log_ids' => [...$record->cost_log_ids, $log->id]]);
        }

        return $record->refresh();
    }

    /** 带段号的正文段落。
     * @return list<array{paragraph: int, text: string}>
     */
    private function paragraphs(string $body): array
    {
        return array_map(fn (string $text, int $index): array => ['paragraph' => $index + 1, 'text' => $text], explode("\n", $body), array_keys(explode("\n", $body)));
    }

    /** 程序先尽量平移全部证据，记录需要模型重新定位的项目。
     * @param  list<StoryEvent>  $events
     * @param  list<ForeshadowEvidence>  $foreshadows
     * @return array{0: array<string, list<array<string, mixed>|null>>, 1: array<string, array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function relocateAll(array $events, array $foreshadows, string $old, string $new): array
    {
        $eventEvidence = [];
        $foreshadowEvidence = [];
        $unresolved = [];
        foreach ($events as $event) {
            foreach ($event->evidence as $index => $evidence) {
                $moved = FormalRevisionContract::relocate($evidence, $old, $new);
                $eventEvidence[$event->event_key][$index] = $moved;
                if ($moved === null) {
                    $unresolved[] = ['ref' => 'event:'.$event->event_key.':'.$index, 'kind' => 'event', 'key' => $event->event_key, 'index' => $index, 'evidence' => $evidence, 'quote' => $evidence['quote']];
                }
            }
        }
        foreach ($foreshadows as $entry) {
            $main = FormalRevisionContract::relocate($entry->evidence, $old, $new);
            $all = array_map(fn (array $evidence): ?array => FormalRevisionContract::relocate($evidence, $old, $new), $entry->evidence['all_evidence'] ?? []);
            $foreshadowEvidence['f'.$entry->id] = ['main' => $main ?? null, 'all' => $all, 'source' => $entry->evidence];
            if ($main === null) {
                $unresolved[] = ['ref' => 'foreshadow:'.$entry->id, 'kind' => 'foreshadow', 'key' => 'f'.$entry->id, 'index' => null, 'evidence' => $entry->evidence, 'quote' => $entry->evidence['quote']];
            }
            foreach ($all as $index => $moved) {
                if ($moved === null) {
                    $unresolved[] = ['ref' => 'foreshadow:'.$entry->id.':'.$index, 'kind' => 'foreshadow', 'key' => 'f'.$entry->id, 'index' => $index, 'evidence' => $entry->evidence['all_evidence'][$index], 'quote' => $entry->evidence['all_evidence'][$index]['quote']];
                }
            }
        }

        return [$eventEvidence, $foreshadowEvidence, $unresolved];
    }

    /** 把模型重新定位的伏笔证据放回主证据或附带证据。
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $located
     * @return array<string, mixed>
     */
    private function placeForeshadow(array $entry, array $item, array $located): array
    {
        if ($item['index'] === null) {
            $entry['main'] = [...$item['evidence'], ...$located];
        } else {
            $entry['all'][$item['index']] = [...$item['evidence'], ...$located];
        }

        return $entry;
    }

    /** 伏笔证据是否仍有未定位项。
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $item
     */
    private function foreshadowMissing(array $entry, array $item): bool
    {
        return $item['index'] === null ? $entry['main'] === null : $entry['all'][$item['index']] === null;
    }

    /** 核对结果中的伏笔证据转为“原证据编号 => 新证据”（附带证据并回 all_evidence）。
     * @param  array<string, array<string, mixed>>  $relocated
     * @return array<int, array<string, mixed>>
     */
    private function foreshadowKeys(array $relocated): array
    {
        $result = [];
        foreach ($relocated as $key => $entry) {
            $evidence = $entry['main'];
            if (isset($entry['source']['all_evidence'])) {
                $evidence['all_evidence'] = $entry['all'];
            }
            $result[(int) substr($key, 1)] = $evidence;
        }

        return $result;
    }
}
