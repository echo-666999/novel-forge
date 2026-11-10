<?php

namespace App\Services\Pipeline;

use App\Models\AcceptanceBatch;
use App\Models\AcceptanceEvidence;
use App\Models\CanonicalCommit;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\NovelCostSummary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** 验收证据工作流，不生成正文，不替作者评分，不改变正式提交。 */
final class AcceptanceWorkbench
{
    /** 七项评分维度及固定中文标签。 */
    public const DIMENSIONS = ['plot' => '情节推进', 'character' => '人物一致性', 'causality' => '因果连贯', 'pace' => '节奏', 'dialogue' => '对白与声音', 'naturalness' => '自然度', 'appeal' => '章节吸引力'];

    /** 必须人工核验的证据，导入记录明确属于操作者声明。 */
    public const CASES = ['pre_generation_contract' => '生成前契约及冻结时间', 'requirements' => '原始要求覆盖及硬约束', 'foreshadows' => '伏笔埋设和来源', 'boundaries' => '知情边界、因果与重复拼接'];

    /** PRD AC03 评分锚点，评分界面逐项展示，不改变通过阈值。 */
    public const SCORE_ANCHORS = [1 => '无法作为连续正文阅读', 2 => '大量明显问题', 3 => '可读但需要局部修订', 4 => '连续流畅且主要选择有说服力', 5 => '表达鲜明、节奏和人物反应突出'];

    /** 外部证据的取证说明，只提示作者如何核验，不代表系统已执行。 */
    public const CASE_GUIDANCE = [
        'pre_generation_contract' => '打开生成前冻结的契约文件或运行快照，核对创意、字数政策、终局与评分政策的冻结时间早于第1章正文生成。',
        'requirements' => '在“原始要求与覆盖”页逐条核对硬约束均有承接计划或正文证据，没有被静默豁免。',
        'foreshadows' => '在“伏笔账本”核对至少3条主伏笔在前5章有正式原文埋设证据，且来源指向本批次冻结版本。',
        'boundaries' => '通读时留意人物是否知道了不该知道的信息、因果是否断裂、段落是否重复拼接；记录检查范围与结论。',
        'long_foreshadow' => '核对百章规划中的长程伏笔在生成前已冻结，并有第100章回收的正式原文证据。',
        'recovery_drill' => '在隔离环境完成一次恢复，抽查章节正文与事件和恢复前一致，附恢复记录。',
        'security_controls' => '附上鉴权隔离、密钥不落日志等专项测试的执行结果。',
    ];

    /** 专项类别增加对应证据，不能用通用正文检查冒充安全或恢复通过。 */
    public static function casesFor(string $kind): array
    {
        return [...self::CASES, ...match ($kind) {
            'hundred' => ['long_foreshadow' => '百章回收及生成前长程契约'],
            'recovery' => ['recovery_drill' => '隔离恢复演练及结果校验'],
            'security' => ['security_controls' => '鉴权隔离与安全专项测试'],
            default => [],
        }];
    }

    /** 创建事后验收批次，冻结正式来源及原运行字数政策，不能事后放宽。 */
    public function create(int $novelId, User $actor, string $kind, int $end, string $reason): AcceptanceBatch
    {
        Validator::make(compact('kind', 'end', 'reason'), ['kind' => 'required|in:ten,hundred,recovery,security', 'end' => 'required|integer|min:1|max:1000', 'reason' => 'required|string|max:10000'])->validate();
        if (($kind === 'ten' && $end !== 10) || ($kind === 'hundred' && $end !== 100) || ! $actor->exists) {
            throw ValidationException::withMessages(['batch' => '十章与百章验收必须使用对应完整范围，并由有效账号创建。']);
        }

        return DB::transaction(function () use ($novelId, $actor, $kind, $end, $reason): AcceptanceBatch {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $export = app(FormalChapterExport::class)->build($novelId, $end, 'txt');
            $policies = [];
            foreach ($export['manifest'] as $entry) {
                $version = ChapterVersion::findOrFail($entry['version_id']);
                $run = ChapterGenerationRun::findOrFail($version->run_id);
                $policies[$entry['number']] = data_get($run->snapshot, 'novel.chapter_length_policy');
            }
            $snapshot = ['manifest' => $export['manifest'], 'policies' => $policies, 'premise' => $novel->premise_raw, 'title' => $novel->title,
                'rating_policy' => ['dimensions' => self::DIMENSIONS, 'minimum' => 3, 'minimum_sum' => 27, 'reason' => '七项每项至少3且均分至少3.8'],
                'manual_cases' => self::casesFor($kind), 'reason' => $reason, 'scope' => '事后验收来源冻结，不是生成前契约；外部证据由操作者明确核验'];

            return AcceptanceBatch::create(['novel_id' => $novelId, 'actor_id' => $actor->id, 'kind' => $kind, 'end_chapter' => $end,
                'snapshot' => $snapshot, 'snapshot_hash' => PlanningJson::hash($snapshot), 'status' => 'not_run', 'created_at' => now()]);
        });
    }

    /** 机器检查只覆盖可验证来源、导出、回放、规则检查和评审引用，绝不直接认可成品。 */
    public function machine(int $novelId, int $batchId, User $actor): AcceptanceEvidence
    {
        return $this->write($novelId, $batchId, $actor, function (AcceptanceBatch $batch) use ($actor): AcceptanceEvidence {
            $checks = [];
            $novel = Novel::findOrFail($batch->novel_id);
            foreach (['txt', 'md'] as $format) {
                $file = app(FormalChapterExport::class)->build($novel->id, $batch->end_chapter, $format);
                $checks['export_'.$format] = ['pass' => true, 'sha256' => hash('sha256', $file['content'])];
            }
            try {
                $replay = app(StoryStateReplay::class)->atChapter($novel, $batch->end_chapter);
                $checks['replay'] = ['pass' => true, 'state_hash' => PlanningJson::hash($replay['state'])];
            } catch (InvalidArgumentException|ModelNotFoundException $error) {
                $checks['replay'] = ['pass' => false, 'reason' => $error->getMessage()];
            }
            $titles = [];
            foreach ($batch->snapshot['manifest'] as $entry) {
                $version = ChapterVersion::findOrFail($entry['version_id']);
                $policy = $batch->snapshot['policies'][$entry['number']] ?? null;
                $issues = is_array($policy) && isset($policy['min_characters'], $policy['max_characters']) ? ContentQualityContract::issues($version->body, $policy) : [['reason' => '原运行缺少冻结字数政策']];
                $title = trim($version->title);
                if ($title === '' || in_array($title, $titles, true)) {
                    $issues[] = ['reason' => '章名为空或重复'];
                }
                $titles[] = $title;
                $commit = CanonicalCommit::findOrFail($entry['commit_id']);
                $validation = ChapterSubmissionValidation::find($commit->validation_id);
                $review = $validation === null ? null : ChapterReview::find($validation->review_id);
                if ($validation?->verdict !== 'pass' || $review?->verdict !== 'pass' || $review?->version_id !== $version->id
                    || $review?->body_hash !== $version->body_hash || $review?->submission_run_id !== $commit->submission_run_id
                    || $validation?->submission_run_id !== $commit->submission_run_id || PlanningJson::hash($review?->result ?? []) !== $review?->result_hash) {
                    $issues[] = ['reason' => '正式评审引用或摘要不一致'];
                }
                $checks['chapter_'.$entry['number']] = ['pass' => $issues === [], 'issues' => $issues, 'version_id' => $version->id];
            }
            $pass = collect($checks)->every(fn (array $check): bool => $check['pass']);
            $evidence = $this->append($batch, $actor, 'machine', 'system', ['pass' => $pass, 'checks' => $checks, 'scope' => '仅确定性检查，不代表语义覆盖或人工文学认可']);
            $this->refreshStatus($batch);

            return $evidence;
        });
    }

    /** 人工评分必须绑定本批次原文摘录；每次修订追加，不覆盖失败分数。 */
    public function rate(int $novelId, int $batchId, User $actor, array $data): AcceptanceEvidence
    {
        $rules = ['chapter' => 'required|integer|min:1', 'scores' => 'required|array:'.implode(',', array_keys(self::DIMENSIONS)),
            'quote' => 'required|string|max:10000', 'reason' => 'required|string|max:10000', 'repair' => 'nullable|string|max:10000',
            'blocking' => 'required|boolean', 'read_confirmed' => 'accepted'];
        foreach (array_keys(self::DIMENSIONS) as $key) {
            $rules['scores.'.$key] = 'required|integer|min:1|max:5';
        }
        Validator::make($data, $rules)->validate();

        return $this->write($novelId, $batchId, $actor, function (AcceptanceBatch $batch) use ($actor, $data): AcceptanceEvidence {
            $entry = collect($batch->snapshot['manifest'])->firstWhere('number', (int) $data['chapter']);
            if ($entry === null) {
                throw ValidationException::withMessages(['chapter' => '所选章号不属于本批次冻结正式正文。']);
            }
            // 与阅读问题共用原文匹配规则，支持空行分隔摘录；只匹配本批次冻结版本，不替换评分。
            $quote = app(ReadingRecordService::class)->sourceQuotes(ChapterVersion::findOrFail($entry['version_id'])->body, $data['quote'], 10000);
            $scores = array_map('intval', $data['scores']);
            if (min($scores) < 3 && trim($data['repair'] ?? '') === '') {
                throw ValidationException::withMessages(['repair' => '低于3分必须填写修复期待。']);
            }
            $pass = min($scores) >= 3 && array_sum($scores) >= 27 && ! $data['blocking'];

            $evidence = $this->append($batch, $actor, 'human', 'chapter_'.$data['chapter'], ['scores' => $scores, 'quote' => $quote, 'reason' => $data['reason'],
                'repair' => $data['repair'] ?? '', 'blocking' => (bool) $data['blocking'], 'read_confirmed' => true, 'pass' => $pass, 'version_id' => $entry['version_id']]);
            $this->refreshStatus($batch);

            return $evidence;
        });
    }

    /** 导入粘贴的外部证据，保留原文摘要；不访问用户输入路径或网络，不冒充程序执行。 */
    public function import(int $novelId, int $batchId, User $actor, array $data): AcceptanceEvidence
    {
        Validator::make($data, ['case_key' => 'required|string|max:64', 'result' => 'required|in:pass,fail',
            'source' => 'required|string|max:2000', 'report' => 'required|string|max:100000', 'reason' => 'required|string|max:10000', 'verified' => 'accepted'])->validate();

        return $this->write($novelId, $batchId, $actor, function (AcceptanceBatch $batch) use ($actor, $data): AcceptanceEvidence {
            if (! array_key_exists($data['case_key'], $batch->snapshot['manual_cases'])) {
                throw ValidationException::withMessages(['case_key' => '用例不属于本批次冻结验收范围。']);
            }
            $evidence = $this->append($batch, $actor, 'external', $data['case_key'], [
                'pass' => $data['result'] === 'pass', 'source' => $data['source'], 'report' => $data['report'], 'report_hash' => hash('sha256', $data['report']),
                'reason' => $data['reason'], 'verification' => '操作者已明确核验的外部证据，不是后台自动执行结果',
            ]);
            $this->refreshStatus($batch);

            return $evidence;
        });
    }

    /** 只有全部必需证据和逐章阅读达标后，作者才能明确关闭通过批次。 */
    public function close(int $novelId, int $batchId, User $actor, string $reason, bool $acknowledged): AcceptanceEvidence
    {
        Validator::make(['reason' => $reason, 'acknowledged' => $acknowledged], ['reason' => 'required|string|max:10000', 'acknowledged' => 'accepted'])->validate();

        return $this->write($novelId, $batchId, $actor, function (AcceptanceBatch $batch) use ($actor, $reason): AcceptanceEvidence {
            $gaps = $this->gaps($batch);
            if ($gaps !== []) {
                throw ValidationException::withMessages(['acceptance' => implode('；', $gaps)]);
            }
            $evidence = $this->append($batch, $actor, 'close', 'accepted', ['reason' => $reason, 'pass' => true, 'mode' => 'explicit_human_acceptance']);
            $batch->update(['status' => 'accepted']);

            return $evidence;
        });
    }

    /** 最新证据决定是否还有缺口，旧失败始终保留在导出历史中。 */
    public function gaps(AcceptanceBatch $batch): array
    {
        $this->integrity($batch);
        $latest = AcceptanceEvidence::where('batch_id', $batch->id)->orderBy('id')->get()->keyBy(fn ($row): string => $row->kind.':'.$row->case_key);
        $required = ['machine:system' => '机器核验'];
        foreach ($batch->snapshot['manual_cases'] as $key => $label) {
            $required['external:'.$key] = $label;
        }
        foreach ($batch->snapshot['manifest'] as $entry) {
            $required['human:chapter_'.$entry['number']] = '第'.$entry['number'].'章人工评分';
        }
        $gaps = [];
        foreach ($required as $key => $label) {
            if (! ($latest->get($key)?->payload['pass'] ?? false)) {
                $gaps[] = $label.'未通过或缺少证据';
            }
        }

        return $gaps;
    }

    /** 只读汇总批次进度：每章及每项外部证据取最新记录，供评分页与进度矩阵展示，不写入任何证据。
     * @return array{machine: ?bool, chapters: list<array{number: int, title: string, version_id: int, scores: ?array<string, int>, sum: ?int, pass: ?bool, blocking: ?bool, rated_at: ?string}>, cases: list<array{key: string, label: string, guidance: string, pass: ?bool}>, rated: int, passed: int, total: int}
     */
    public function progress(AcceptanceBatch $batch): array
    {
        $latest = AcceptanceEvidence::where('batch_id', $batch->id)->orderBy('id')->get()->keyBy(fn ($row): string => $row->kind.':'.$row->case_key);
        $chapters = [];
        foreach ($batch->snapshot['manifest'] as $entry) {
            $evidence = $latest->get('human:chapter_'.$entry['number']);
            $scores = $evidence?->payload['scores'] ?? null;
            $chapters[] = ['number' => (int) $entry['number'], 'title' => (string) ($entry['title'] ?? ''), 'version_id' => (int) $entry['version_id'],
                'scores' => $scores, 'sum' => $scores === null ? null : array_sum($scores), 'pass' => $evidence?->payload['pass'] ?? null,
                'blocking' => $evidence?->payload['blocking'] ?? null, 'rated_at' => $evidence?->created_at?->format('Y-m-d H:i')];
        }
        $cases = [];
        foreach ($batch->snapshot['manual_cases'] as $key => $label) {
            $cases[] = ['key' => $key, 'label' => $label, 'guidance' => self::CASE_GUIDANCE[$key] ?? '按用例名称核验并附上证据。', 'pass' => $latest->get('external:'.$key)?->payload['pass'] ?? null];
        }

        return ['machine' => $latest->get('machine:system')?->payload['pass'] ?? null, 'chapters' => $chapters, 'cases' => $cases,
            'rated' => count(array_filter($chapters, fn (array $chapter): bool => $chapter['pass'] !== null)),
            'passed' => count(array_filter($chapters, fn (array $chapter): bool => $chapter['pass'] === true)), 'total' => count($chapters)];
    }

    /** 从指定章之后寻找下一章未评分或未通过的章节；都已通过时返回空。 */
    public function nextChapterToRate(AcceptanceBatch $batch, int $after): ?int
    {
        $pending = array_values(array_filter($this->progress($batch)['chapters'], fn (array $chapter): bool => $chapter['pass'] !== true));
        foreach ($pending as $chapter) {
            if ($chapter['number'] > $after) {
                return $chapter['number'];
            }
        }

        return $pending[0]['number'] ?? null;
    }

    /** 冻结正文、原始输入、审计记录及成本摘要组成私有证据包，不包含凭据或原始API载荷。 */
    public function export(int $novelId, int $batchId): array
    {
        $batch = AcceptanceBatch::where('novel_id', $novelId)->findOrFail($batchId);
        $this->integrity($batch);
        $texts = [];
        foreach (['txt', 'md'] as $format) {
            $file = app(FormalChapterExport::class)->build($novelId, $batch->end_chapter, $format);
            $texts[$format] = ['content' => $file['content'], 'sha256' => hash('sha256', $file['content'])];
        }

        return ['batch' => $batch->toArray(), 'outstanding' => $this->gaps($batch), 'texts' => $texts,
            'evidence' => AcceptanceEvidence::where('batch_id', $batchId)->orderBy('id')->get()->toArray(), 'cost_summary' => app(NovelCostSummary::class)->forNovel($novelId)];
    }

    /** 锁顺序与正式提交一致，防止并发关闭与写入遗漏；只有批次负责人能写。 */
    private function write(int $novelId, int $batchId, User $actor, callable $callback): AcceptanceEvidence
    {
        return DB::transaction(function () use ($novelId, $batchId, $actor, $callback): AcceptanceEvidence {
            Novel::lockForUpdate()->findOrFail($novelId);
            $batch = AcceptanceBatch::where('novel_id', $novelId)->lockForUpdate()->findOrFail($batchId);
            if (! $actor->exists || $batch->actor_id !== $actor->id || AcceptanceEvidence::where('batch_id', $batchId)->where('kind', 'close')->exists()) {
                throw ValidationException::withMessages(['acceptance' => '仅批次负责人可记录证据，已关闭批次不可改写。']);
            }
            $this->integrity($batch);

            return $callback($batch);
        });
    }

    /** 每次操作都重新核验当前正式来源与冻结摘要，过期批次不能继续获认可。 */
    private function integrity(AcceptanceBatch $batch): void
    {
        try {
            $manifest = app(FormalChapterExport::class)->build($batch->novel_id, $batch->end_chapter, 'txt')['manifest'];
        } catch (InvalidArgumentException|ModelNotFoundException $error) {
            throw ValidationException::withMessages(['acceptance' => '冻结正式来源不可用，请检查章节回执与正文摘要。']);
        }
        if (Novel::findOrFail($batch->novel_id)->title !== $batch->snapshot['title'] || $batch->snapshot_hash !== PlanningJson::hash($batch->snapshot) || PlanningJson::hash($manifest) !== PlanningJson::hash($batch->snapshot['manifest'])) {
            throw ValidationException::withMessages(['acceptance' => '冻结验收来源已变化或损坏，请另建批次。']);
        }
        foreach (AcceptanceEvidence::where('batch_id', $batch->id)->cursor() as $entry) {
            if ($entry->payload_hash !== PlanningJson::hash($entry->payload)) {
                throw ValidationException::withMessages(['acceptance' => '验收历史证据摘要不一致。']);
            }
        }
    }

    /** 人工失败同样显式显示，证据齐全也仍需另行关闭认可。 */
    private function refreshStatus(AcceptanceBatch $batch): void
    {
        $latest = AcceptanceEvidence::where('batch_id', $batch->id)->orderBy('id')->get()->keyBy(fn ($row): string => $row->kind.':'.$row->case_key);
        $failed = $latest->contains(fn ($entry): bool => ($entry->payload['pass'] ?? false) === false);
        $status = $failed ? 'rejected' : ($latest->has('machine:system') ? ($this->gaps($batch) === [] ? 'system_pass' : 'human_pending') : 'not_run');
        $batch->update(['status' => $status]);
    }

    /** 追加一条带摘要的操作记录，不修改旧分数或旧失败。 */
    private function append(AcceptanceBatch $batch, User $actor, string $kind, string $key, array $payload): AcceptanceEvidence
    {
        return AcceptanceEvidence::create(['batch_id' => $batch->id, 'actor_id' => $actor->id, 'kind' => $kind, 'case_key' => $key,
            'payload' => $payload, 'payload_hash' => PlanningJson::hash($payload), 'created_at' => now()]);
    }
}
