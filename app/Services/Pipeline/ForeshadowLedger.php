<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/** 作者维护的伏笔账本；事实只能引用已正式提交的正文，不能改写正式故事。 */
final class ForeshadowLedger
{
    /** 长程契约字段。 */
    public const CONTRACT_FIELDS = ['surface_clue', 'hidden_meaning', 'payoff_effect', 'knowledge_constraint', 'payoff_from', 'payoff_to', 'must_payoff'];

    /** 自动流转动作与目标状态。 */
    public const ACTIONS = ['plant' => 'planted', 'reinforce' => 'reinforced', 'payoff' => 'resolved'];

    /** 登记不可覆盖的计划，同一请求重放不会重复创建。长程契约可选：给出回收窗口后，回收只能在窗口内由正式正文触发。
     * @param  array<string, mixed>  $contract  surface_clue、hidden_meaning、payoff_effect、knowledge_constraint、payoff_from、payoff_to、must_payoff
     */
    public function plan(Novel $reference, User $author, string $title, string $description, int $plantChapter, int $targetChapter, string $key, array $contract = []): Foreshadow
    {
        $contract = array_intersect_key($contract, array_flip(self::CONTRACT_FIELDS));
        // 无契约时保持原请求摘要，旧调用的幂等重放不受影响。
        $hash = PlanningJson::hash($contract === [] ? [$reference->id, $author->id, $title, $description, $plantChapter, $targetChapter] : [$reference->id, $author->id, $title, $description, $plantChapter, $targetChapter, $contract]);

        return DB::transaction(function () use ($reference, $author, $title, $description, $plantChapter, $targetChapter, $key, $hash, $contract): Foreshadow {
            $novel = Novel::lockForUpdate()->findOrFail($reference->id);
            $this->validateKey($key);
            if ($existing = Foreshadow::where('request_key', $key)->first()) {
                $this->require($existing->request_hash === $hash, '请求编号对应不同计划');

                return $existing;
            }
            $this->idle($novel);
            $this->require(trim($title) !== '' && mb_strlen($title) <= 255 && trim($description) !== '', '伏笔名称或说明无效');
            $this->require($plantChapter >= 1 && $targetChapter >= $plantChapter && $targetChapter <= $novel->target_chapters, '伏笔章节范围无效');
            $from = isset($contract['payoff_from']) ? (int) $contract['payoff_from'] : null;
            $to = isset($contract['payoff_to']) ? (int) $contract['payoff_to'] : null;
            $this->require(($from === null) === ($to === null), '回收窗口须同时填写起止章');
            if ($to !== null) {
                $this->require($from > $plantChapter && $to >= $from && $to === $targetChapter, '回收窗口须晚于埋设章，且窗口末章等于最迟回收章');
            }
            $must = (bool) ($contract['must_payoff'] ?? false);
            $this->require(! $must || $to !== null, '必回收伏笔须指定回收窗口');
            foreach (['surface_clue', 'hidden_meaning', 'payoff_effect', 'knowledge_constraint'] as $field) {
                $this->require(! isset($contract[$field]) || (is_string($contract[$field]) && mb_strlen($contract[$field]) <= 2000), '伏笔契约文字过长');
            }
            // 必回收伏笔属于长程契约，只能在本章及之后都尚未生成前冻结，不能事后补登记冒充。
            $this->require(! $must || ! $novel->chapters()->where('number', '>=', $plantChapter)->exists(), '必回收伏笔须在埋设章生成前登记');

            return Foreshadow::create(['novel_id' => $novel->id, 'title' => $title, 'description' => $description, 'plant_chapter' => $plantChapter, 'target_chapter' => $targetChapter, 'status' => 'planned', 'revision' => 0, 'created_by' => $author->id, 'request_key' => $key, 'request_hash' => $hash,
                ...array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, array_intersect_key($contract, array_flip(['surface_clue', 'hidden_meaning', 'payoff_effect', 'knowledge_constraint']))),
                'payoff_from' => $from, 'payoff_to' => $to, 'must_payoff' => $must, 'planned_before_generation' => ! $novel->chapters()->exists()]);
        });
    }

    /** 原文定位、状态转换、并发修订及幂等请求在同一事务中验证。 */
    public function record(Foreshadow $reference, User $author, int $revision, string $status, int $versionId, int $paragraph, string $quote, string $reason, string $key): ForeshadowEvidence
    {
        $hash = PlanningJson::hash([$reference->id, $author->id, $revision, $status, $versionId, $paragraph, $quote, $reason]);

        return DB::transaction(function () use ($reference, $author, $revision, $status, $versionId, $paragraph, $quote, $reason, $key, $hash): ForeshadowEvidence {
            $novel = Novel::lockForUpdate()->findOrFail($reference->novel_id);
            $ledger = Foreshadow::lockForUpdate()->findOrFail($reference->id);
            $this->validateKey($key);
            if ($existing = ForeshadowEvidence::where('request_key', $key)->first()) {
                $this->require($existing->request_hash === $hash, '请求编号对应不同证据');

                return $existing;
            }
            // 必回收伏笔的回收只能由正式提交时的正文证据触发，不提供手工勾选。
            $this->require(! ($ledger->must_payoff && $status === 'resolved'), '必回收伏笔只能由正式提交的正文自动回收');
            $this->idle($novel);
            // 候选基线复制的是建立时的证据链；修订期间登记的新证据不会进入候选基线，因此暂停登记。
            $this->require(! app(CanonicalBaselines::class)->hasOpenCandidate($novel), '正式稿修订进行中，请切换或放弃候选基线后再登记伏笔证据');
            $this->require($ledger->revision === $revision, '伏笔已变更，请刷新后重试');
            $allowed = match ($ledger->status) {
                'planned' => ['planted'], 'planted', 'reinforced' => ['reinforced', 'resolved'], default => []
            };
            $this->require(in_array($status, $allowed, true), '伏笔状态转换无效');
            $version = ChapterVersion::findOrFail($versionId);
            // 同一正文版本可能在多个基线中被提交，只认活动基线的正式指针。
            $commit = CanonicalCommit::where('novel_id', $novel->id)->findOrFail(Chapter::where('novel_id', $novel->id)->findOrFail($version->chapter_id)->canonical_commit_id);
            $chapter = $this->formal($commit, $version);
            $this->require($chapter->number >= ($ledger->evidence()->max('chapter_number') ?? 1), '证据章节不能倒退');
            $parts = explode("\n", $version->body);
            $text = $parts[$paragraph - 1] ?? '';
            $offset = $quote === '' ? false : mb_strpos($text, $quote);
            $this->require($paragraph > 0 && $offset !== false && trim($reason) !== '', '证据必须逐字匹配指定正文段落，并填写理由');
            $start = mb_strlen(implode("\n", array_slice($parts, 0, $paragraph - 1))) + ($paragraph > 1 ? 1 : 0) + $offset;
            $evidence = ForeshadowEvidence::create(['source' => 'manual', 'foreshadow_id' => $ledger->id, 'novel_id' => $novel->id, 'revision' => $revision + 1, 'from_status' => $ledger->status, 'to_status' => $status, 'commit_id' => $commit->id, 'baseline_id' => $commit->baseline_id, 'chapter_id' => $chapter->id, 'version_id' => $version->id, 'chapter_number' => $chapter->number, 'body_hash' => $version->body_hash, 'evidence' => ['paragraph' => $paragraph, 'start' => $start, 'end' => $start + mb_strlen($quote), 'quote' => $quote], 'reason' => $reason, 'recorded_by' => $author->id, 'request_key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
            $ledger->update(['status' => $status, 'revision' => $revision + 1]);

            return $evidence;
        });
    }

    /** 按章前边界重放证据；到期及逾期项全部保留，调用方将结果冻结进请求。
     * @return array<string, mixed>
     */
    public function context(Novel $novel, int $chapterNumber): array
    {
        $items = [];
        foreach (Foreshadow::where('novel_id', $novel->id)->with('evidence')->orderBy('id')->get() as $ledger) {
            $status = 'planned';
            $sources = [];
            $this->require($ledger->revision === $ledger->evidence->count() && $ledger->status === ($ledger->evidence->last()?->to_status ?? 'planned'), '伏笔当前状态与证据链不一致');
            foreach ($ledger->evidence as $entry) {
                if ($entry->chapter_number >= $chapterNumber) {
                    continue;
                }
                $version = ChapterVersion::findOrFail($entry->version_id);
                $chapter = $this->formal(CanonicalCommit::findOrFail($entry->commit_id), $version);
                $this->require($entry->chapter_number === $chapter->number && $entry->chapter_id === $chapter->id && $entry->novel_id === $novel->id && $entry->evidence['start'] >= 0 && $entry->evidence['end'] > $entry->evidence['start'], '伏笔证据来源不一致');
                $this->require($entry->body_hash === $version->body_hash && mb_substr($version->body, $entry->evidence['start'], $entry->evidence['end'] - $entry->evidence['start']) === $entry->evidence['quote'], '伏笔证据已损坏');
                $this->require($entry->from_status === $status, '伏笔证据链断裂');
                $status = $entry->to_status;
                $sources[] = $entry->only(['id', 'commit_id', 'version_id', 'chapter_number', 'body_hash', 'evidence', 'to_status']);
            }
            if ($status === 'resolved' || ($status === 'planned' && $ledger->plant_chapter > $chapterNumber)) {
                continue;
            }
            if (! $ledger->hasWindow()) {
                // 旧伏笔沿用原规则与原字段，历史请求快照保持一致。
                $items[] = [...$ledger->only(['id', 'title', 'description', 'plant_chapter', 'target_chapter']), 'status' => $status, 'must_address' => $status === 'planned' || $ledger->target_chapter <= $chapterNumber, 'obligation' => $status === 'planned' ? ($ledger->target_chapter <= $chapterNumber ? 'plant_and_resolve' : 'plant') : ($ledger->target_chapter <= $chapterNumber ? 'resolve' : 'preserve'), 'sources' => $sources];

                continue;
            }
            $obligation = $this->obligation($ledger, $status, $chapterNumber);
            $items[] = [...$ledger->only(['id', 'title', 'description', 'plant_chapter', 'target_chapter', 'surface_clue', 'hidden_meaning', 'payoff_effect', 'knowledge_constraint', 'payoff_from', 'payoff_to', 'must_payoff']),
                'status' => $status, 'must_address' => in_array($obligation, ['plant', 'resolve', 'overdue'], true), 'obligation' => $obligation, 'sources' => $sources];
        }

        $instruction = '必须处理 must_address=true 的埋设或回收要求；不得裁剪到期及逾期项。其余伏笔保持连续性。只能依据正文证据判断是否完成，计划不是已发生事实。';
        if (collect($items)->contains(fn (array $item): bool => isset($item['payoff_to']))) {
            $instruction .= 'plant：本章以表面线索自然埋设，不揭示隐藏含义；reinforce/prepare：引入新信息或影响人物选择，不重复旧句子，不提前揭示；resolve：本章用已发生的证据把表面线索与隐藏含义连接起来，并让人物据此作出改变主线的选择，单纯再提道具名称不算回收；hidden_meaning 是作者计划，回收前人物不能无途径获知。';
        }

        return ['version' => 'c03-foreshadow-v1', 'chapter_number' => $chapterNumber, 'instruction' => $instruction, 'items' => $items];
    }

    /** 长程契约在某章的义务：埋设章前后、回收前20章准备、窗口内回收、窗口后逾期。 */
    private function obligation(Foreshadow $ledger, string $status, int $chapter): string
    {
        return match (true) {
            $status === 'planned' => $chapter >= $ledger->plant_chapter ? 'plant' : 'pending',
            $chapter > $ledger->payoff_to => 'overdue',
            $chapter >= $ledger->payoff_from => 'resolve',
            $chapter >= $ledger->payoff_from - 20 => 'prepare',
            default => 'preserve',
        };
    }

    /** 在正式提交事务内写入自动流转证据：只接受已通过确定性校验的提取结果，来源绑定本次提交、正式版本与段落。
     * @param  array{foreshadow_id: int, action: string, evidence: list<array<string, mixed>>, explanation: string}  $entry
     */
    public function applyFromCommit(array $entry, CanonicalCommit $commit, Chapter $chapter, ChapterVersion $version, User $operator, string $commitKey): ForeshadowEvidence
    {
        $ledger = Foreshadow::lockForUpdate()->where('novel_id', $commit->novel_id)->findOrFail($entry['foreshadow_id']);
        $to = self::ACTIONS[$entry['action']] ?? null;
        $allowed = match ($ledger->status) {
            'planned' => ['planted'], 'planted', 'reinforced' => ['reinforced', 'resolved'], default => []
        };
        $this->require($to !== null && in_array($to, $allowed, true), '伏笔状态转换无效');
        $evidence = $entry['evidence'][0];
        $key = (string) Uuid::uuid5($commitKey, 'foreshadow-'.$ledger->id);
        $record = ForeshadowEvidence::create(['source' => 'commit_extraction', 'foreshadow_id' => $ledger->id, 'novel_id' => $commit->novel_id, 'revision' => $ledger->revision + 1,
            'from_status' => $ledger->status, 'to_status' => $to, 'commit_id' => $commit->id, 'baseline_id' => $commit->baseline_id, 'chapter_id' => $chapter->id, 'version_id' => $version->id, 'chapter_number' => $chapter->number,
            'body_hash' => $version->body_hash, 'evidence' => [...$evidence, 'all_evidence' => $entry['evidence']], 'reason' => '正式提交时自动提取：'.$entry['explanation'],
            'recorded_by' => $operator->id, 'request_key' => $key, 'request_hash' => PlanningJson::hash([$commit->id, $entry]), 'created_at' => now()]);
        $ledger->update(['status' => $to, 'revision' => $ledger->revision + 1]);

        return $record;
    }

    /** 回收准备检查：每条伏笔的当前状态、最近证据、强化次数、距回收窗口的章数与提醒；只读。
     * @return list<array<string, mixed>>
     */
    public function readiness(Novel $novel): array
    {
        $done = $novel->chapters_done;

        return Foreshadow::where('novel_id', $novel->id)->with('evidence')->orderBy('id')->get()->map(function (Foreshadow $ledger) use ($done): array {
            [$from, $to] = $ledger->window();
            $reinforced = $ledger->evidence->where('to_status', 'reinforced')->count();
            $last = $ledger->evidence->last()?->chapter_number;
            $warnings = [];
            if ($ledger->status === 'planned' && $done >= $ledger->plant_chapter) {
                $warnings[] = '已过计划埋设章仍未埋设';
            }
            if (in_array($ledger->status, ['planted', 'reinforced'], true)) {
                if ($ledger->hasWindow() && $done + 1 >= $from - 20 && $reinforced === 0) {
                    $warnings[] = '进入回收前20章仍没有强化，铺垫可能不足';
                }
                if ($last !== null && $done - $last > 30) {
                    $warnings[] = '已有'.($done - $last).'章没有新的铺垫';
                }
                if ($ledger->hasWindow() && $done >= $to) {
                    $warnings[] = '已超过回收窗口仍未回收';
                }
            }

            return ['id' => $ledger->id, 'title' => $ledger->title, 'status' => Foreshadow::statusLabel($ledger->displayStatus($done)), 'window' => $ledger->hasWindow() ? '第'.$from.'～'.$to.'章' : '最迟第'.$to.'章',
                'must' => $ledger->must_payoff, 'reinforced' => $reinforced, 'last' => $last, 'remaining' => max(0, $from - $done - 1), 'warnings' => $warnings];
        })->all();
    }

    /** 核验正式提交身份与正文摘要，候选版本不能成为事实来源。 */
    private function formal(CanonicalCommit $commit, ChapterVersion $version): Chapter
    {
        $chapter = Chapter::findOrFail($commit->chapter_id);
        $this->require($commit->version_id === $version->id && $chapter->novel_id === $commit->novel_id && $chapter->formal_version_id === $version->id && $chapter->canonical_commit_id === $commit->id && hash('sha256', $version->body) === $version->body_hash && ($commit->result['body_hash'] ?? null) === $version->body_hash, '正式正文来源不一致');

        return $chapter;
    }

    /** 活跃章节已有冻结输入时禁止改账，小说行锁与启动入口共用。 */
    private function idle(Novel $novel): void
    {
        $this->require(! $novel->chapters()->whereNull('formal_version_id')->exists(), '存在未正式提交章节，请完成当前章节后维护伏笔');
    }

    /** 请求标识必须可审计且可安全幂等重放。 */
    private function validateKey(string $key): void
    {
        $this->require(Str::isUuid($key), '请求编号必须为 UUID');
    }

    /** 业务拒绝统一以明确中文原因返回。 */
    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
