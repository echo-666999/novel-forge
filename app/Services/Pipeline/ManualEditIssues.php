<?php

namespace App\Services\Pipeline;

use App\Models\ChapterEventExtraction;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\Foreshadow;
use App\Models\StoryEntity;

/**
 * 人工编辑页要列出的问题：按版本走到的最后一关取问题来源。
 * 一键提交失败时列评审、确定性验证或事件提取被驳回的问题；只做过推进检查时列推进检查问题。只读，不调用模型。
 */
final class ManualEditIssues
{
    /** 问题来源及其说明。 */
    public const ORIGINS = ['review' => '提交评审', 'validation' => '确定性验证', 'reading' => '事件提取核对', 'system' => '一键提交', 'check' => '推进检查', 'none' => ''];

    /** 改正文能否解决：text 改正文；rerun 是提取结果本身不合规，通常重新一键提交即可；approval 需在提交页处理。 */
    public const SCOPES = ['text' => '改正文', 'rerun' => '重新一键提交即可', 'approval' => '需在提交页处理'];

    /** 确定性验证错误码的中文说明、修改建议与处理方式。
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const VALIDATION_ERRORS = [
        'FORESHADOW_TRANSITION_INVALID' => ['伏笔的埋设或强化与它当前的状态、章节窗口不符', '检查正文是否提前写到了这条伏笔，或在尚未埋设时就写成了强化；按大纲窗口删改相关句子', 'text'],
        'FORESHADOW_WINDOW_VIOLATION' => ['伏笔在回收窗口之外被回收', '删去或弱化提前揭晓的句子，只保留暗示', 'text'],
        'FORESHADOW_PLANT_MISSING' => ['本章应埋设的伏笔正文没有写到', '按大纲补写一处可被读者注意到的细节', 'text'],
        'FORESHADOW_PAYOFF_MISSING' => ['本章是这条伏笔回收窗口的最后一章，正文没有回收', '补写回收这条伏笔的情节', 'text'],
        'FORESHADOW_PROGRESS_INVALID' => ['伏笔的推进方式不合规', '核对正文对这条伏笔的写法是否与大纲一致', 'text'],
        'FORESHADOW_UNKNOWN' => ['提取到了未登记的伏笔', '这是事件提取的问题，通常重新一键提交即可', 'rerun'],
        'FORESHADOW_DUPLICATE' => ['同一条伏笔在本章被重复标记', '这是事件提取的问题，通常重新一键提交即可', 'rerun'],
        'DEAD_ACTOR' => ['已死亡的人物在之后仍有当下的行动', '改为回忆，或删去该人物的行动', 'text'],
        'LIFE_STATE_CONFLICT' => ['已死亡的人物在本章又被写成活着', '核对与前文的存亡是否矛盾', 'text'],
        'OWNERSHIP_CONFLICT' => ['物品已有主人，却没经过转交就换了主人', '补写转交过程，或改回原主人', 'text'],
        'OWNERSHIP_EVENT_REQUIRED' => ['物品归属变化缺少获得或转交的动作', '写明人物如何拿到这件物品', 'text'],
        'LOCATION_EVENT_REQUIRED' => ['位置变化缺少移动的动作', '写明人物或物品如何到达新位置', 'text'],
        'PATCH_PRECONDITION_FAILED' => ['状态变化与前文对不上（例如物品之前并不在此人手里）', '核对这段与前几章的状态是否矛盾', 'text'],
        'AMBIGUOUS_ENTITY' => ['这个称呼可以指多个人物或事物', '在正文里改用全名或更明确的称呼', 'text'],
        'DUPLICATE_ENTITY' => ['新实体与已有实体重复', '在提交页处理新实体', 'approval'],
        'ENTITY_ALIAS_COLLISION' => ['新实体的别名与已有实体冲突', '在提交页处理新实体', 'approval'],
        'ENTITY_DEFINITION_INVALID' => ['新实体的定义不完整', '在提交页处理新实体', 'approval'],
        'ENTITY_ALIASES_INVALID' => ['新实体的别名不合规', '在提交页处理新实体', 'approval'],
        'APPROVAL_INVALID' => ['新实体批准记录无效', '在提交页重新批准新实体', 'approval'],
    ];

    /** 未单列的错误码多为事件提取结果自身不合规。 */
    private const RERUN = ['事件提取结果不合规', '这是事件提取的问题，通常重新一键提交即可', 'rerun'];

    /** 一个版本需要列出的问题；notice 用于不需要改正文的情况。
     * @return array{version: int, origin: string, title: string, notice: ?string, issues: list<array{severity: string, category: string, reason: string, hint: ?string, scope: string, quotes: list<string>}>}
     */
    public function forVersion(ChapterVersion $version): array
    {
        $result = ['version' => $version->version, 'origin' => 'none', 'title' => '', 'notice' => null, 'issues' => []];
        $submission = ChapterSubmissionRun::where('version_id', $version->id)->latest('id')->first();
        if ($submission?->status === 'failed') {
            return [...$result, ...$this->submissionIssues($submission, $version)];
        }
        $check = ChapterProgressionCheck::where('version_id', $version->id)->latest('id')->first();
        if ($check !== null) {
            $issues = self::checkIssues(is_array($check->result) ? $check->result : []);
            if ($issues !== []) {
                return [...$result, 'origin' => 'check', 'title' => '版本 '.$version->version.' 的推进检查', 'issues' => $issues];
            }
        }

        return $result;
    }

    /** 当前正文里仍能找到的问题引文数，用于给修改中的草稿找最接近的版本。
     * @param  list<array{quotes: list<string>}>  $issues
     */
    public static function score(array $issues, string $body): int
    {
        return collect($issues)->sum(fn (array $issue): int => count(array_filter($issue['quotes'], fn (string $quote): bool => str_contains($body, $quote))));
    }

    /** 一键提交失败的问题：评审未通过、确定性验证阻塞、作者驳回事件提取，其余为系统原因。
     * @return array{origin: string, title: string, notice: ?string, issues: list<array<string, mixed>>}
     */
    private function submissionIssues(ChapterSubmissionRun $submission, ChapterVersion $version): array
    {
        $label = '版本 '.$version->version.' 的';
        $review = ChapterReview::where('submission_run_id', $submission->id)->first();
        $reviewIssues = self::reviewIssues(is_array($review?->result) ? $review->result : []);

        return match ($submission->stop_reason) {
            'REVIEW_NOT_PASSED' => ['origin' => 'review', 'title' => $label.'提交评审 #'.$review?->id, 'notice' => null, 'issues' => $reviewIssues],
            'EVENT_VALIDATION_BLOCKED' => ['origin' => 'validation', 'title' => $label.'确定性验证', 'notice' => null,
                'issues' => [...$this->validationIssues($submission), ...array_values(array_filter($reviewIssues, fn (array $issue): bool => $issue['severity'] === 'advisory'))]],
            'EVENT_READING_REJECTED' => ['origin' => 'reading', 'title' => $label.'事件提取核对', 'notice' => null, 'issues' => self::readingIssues($submission)],
            default => ['origin' => 'system', 'title' => $label.'一键提交', 'notice' => '一键提交因系统或供应商原因中断（'.$submission->stop_reason.'），不是正文问题，不需要修改正文，回工作台重新一键提交即可。', 'issues' => []],
        };
    }

    /** 评审问题：阻塞在前，建议在后。
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function reviewIssues(array $result): array
    {
        $issues = [];
        foreach ($result['findings'] ?? [] as $finding) {
            $issues[] = ['severity' => ($finding['severity'] ?? null) === 'blocking' ? 'blocking' : 'advisory',
                'category' => SubmissionPresenter::FINDING_KINDS[$finding['kind'] ?? ''] ?? '其他', 'reason' => (string) ($finding['reason'] ?? ''),
                'hint' => null, 'scope' => 'text', 'quotes' => self::quotes($finding['evidence'] ?? [])];
        }
        usort($issues, fn (array $a, array $b): int => ($a['severity'] === 'blocking' ? 0 : 1) <=> ($b['severity'] === 'blocking' ? 0 : 1));

        return $issues;
    }

    /** 确定性验证错误翻成中文，并借事件提取中的证据定位到原文。
     * @return list<array<string, mixed>>
     */
    private function validationIssues(ChapterSubmissionRun $submission): array
    {
        $validation = ChapterSubmissionValidation::where('submission_run_id', $submission->id)->latest('id')->first()?->result;
        $extraction = ChapterEventExtraction::where('submission_run_id', $submission->id)->first()?->result;
        $extraction = is_array($extraction) ? $extraction : [];
        $names = StoryEntity::where('novel_id', $submission->novel_id)->pluck('name', 'id')->all();
        $titles = Foreshadow::where('novel_id', $submission->novel_id)->pluck('title', 'id')->all();
        $issues = [];
        foreach (is_array($validation) ? ($validation['errors'] ?? []) : [] as $error) {
            $issues[] = self::validationIssue(is_array($error) ? $error : ['code' => (string) $error], $extraction, $names, $titles);
        }

        return $issues;
    }

    /** 单条验证错误：说明、涉及对象、建议与原文证据。
     * @param  array<string, mixed>  $error
     * @param  array<string, mixed>  $extraction
     * @param  array<int|string, string>  $names
     * @param  array<int|string, string>  $titles
     * @return array<string, mixed>
     */
    public static function validationIssue(array $error, array $extraction, array $names, array $titles): array
    {
        $code = (string) ($error['code'] ?? '');
        $reference = (string) ($error['reference'] ?? '');
        [$reason, $hint, $scope] = self::VALIDATION_ERRORS[$code] ?? self::RERUN;
        $quotes = [];
        $subject = null;
        if (preg_match('/^foreshadow-(\d+)$/', $reference, $match) === 1) {
            $subject = '伏笔「'.($titles[$match[1]] ?? '#'.$match[1]).'」';
            foreach ($extraction['foreshadows'] ?? [] as $item) {
                if ((string) ($item['foreshadow_id'] ?? '') === $match[1]) {
                    $quotes = [...$quotes, ...self::quotes($item['evidence'] ?? [])];
                }
            }
        } else {
            $event = preg_match('/^event-\d+/', $reference, $match) === 1 ? collect($extraction['events'] ?? [])->firstWhere('key', $match[0]) : null;
            $quotes = is_array($event) ? self::quotes($event['evidence'] ?? []) : [];
            if (preg_match('/entity-(\d+)/', $reference, $match) === 1) {
                $subject = $names[$match[1]] ?? null;
            } elseif (! str_starts_with($reference, 'event-') && $reference !== '' && $reference !== 'approved_keys') {
                $proposal = collect($extraction['proposed_entities'] ?? [])->firstWhere('key', $reference);
                $subject = is_array($proposal) ? ($proposal['name'] ?? $reference) : $reference;
                $quotes = is_array($proposal) ? self::quotes($proposal['evidence'] ?? []) : $quotes;
            }
            if (is_array($event) && filled($event['summary'] ?? null)) {
                $reason .= '；相关事件：'.$event['summary'];
            }
        }

        return ['severity' => 'blocking', 'category' => '确定性验证', 'reason' => ($subject === null ? '' : $subject.'：').$reason.'（'.$code.'）',
            'hint' => $hint, 'scope' => $scope, 'quotes' => array_values(array_unique($quotes))];
    }

    /** 作者驳回事件提取时写下的理由。
     * @return list<array<string, mixed>>
     */
    private static function readingIssues(ChapterSubmissionRun $submission): array
    {
        $receipt = collect($submission->control_history ?? [])->last(fn (array $item): bool => ($item['action'] ?? null) === 'event_reading' && ($item['accepted'] ?? true) === false);

        return array_map(fn (string $finding): array => ['severity' => 'blocking', 'category' => '事件提取核对', 'reason' => $finding, 'hint' => null, 'scope' => 'text', 'quotes' => []],
            array_values(array_filter($receipt['findings'] ?? [], 'is_string')));
    }

    /** 推进检查中需要修改的问题：前后矛盾、设定冲突、未解决复核项、未完成推进点与钩子。
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public static function checkIssues(array $result): array
    {
        $issues = [];
        $add = function (string $category, mixed $reason, mixed $evidence) use (&$issues): void {
            $issues[] = ['severity' => 'blocking', 'category' => $category, 'reason' => (string) $reason, 'hint' => null, 'scope' => 'text', 'quotes' => self::quotes($evidence)];
        };
        foreach ($result['continuity_issues'] ?? [] as $issue) {
            $add('前后重复或时序矛盾', $issue['reason'] ?? '', $issue['evidence'] ?? []);
        }
        foreach ($result['setting_violations'] ?? [] as $issue) {
            $add('设定冲突', $issue['reason'] ?? '', $issue['evidence'] ?? []);
        }
        foreach ($result['reading_reviews'] ?? [] as $review) {
            if (($review['disposition'] ?? null) === 'unresolved') {
                $add('未解决', $review['reason'] ?? '', $review['evidence'] ?? []);
            }
        }
        foreach ($result['points'] ?? [] as $point) {
            if (($point['met'] ?? true) === false) {
                $add('推进点未完成', $point['reason'] ?? '', $point['evidence'] ?? []);
            }
        }
        if (($result['hook']['met'] ?? true) === false) {
            $add('章末钩子不足', $result['hook']['reason'] ?? '', $result['hook']['evidence'] ?? []);
        }

        return $issues;
    }

    /** 证据里的原文引文；过短的不用于定位。
     * @return list<string>
     */
    private static function quotes(mixed $evidence): array
    {
        $quotes = [];
        foreach (is_array($evidence) ? $evidence : [] as $item) {
            $quote = trim((string) (is_array($item) ? ($item['quote'] ?? '') : ''));
            if (mb_strlen($quote) >= 2) {
                $quotes[] = $quote;
            }
        }

        return $quotes;
    }
}
