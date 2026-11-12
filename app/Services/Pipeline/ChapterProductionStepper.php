<?php

namespace App\Services\Pipeline;

use App\Models\ChapterEventExtraction;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use InvalidArgumentException;

/** 单章有限步骤机：正文 → 评审与提取 → 正式提交。每次推进至多发送一次模型请求，产物先入库。 */
final class ChapterProductionStepper
{
    /** 本步骤机负责的固定步骤。 */
    public const STEPS = ['content', 'submission', 'commit'];

    /** 推进当前章的一个步骤，返回更新后的产物；章节全部提交后把运行推进到下一章或完成。
     * @param  array<string, mixed>  $artifacts
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function perform(ProductionRun $run, Novel $novel, User $author, array $artifacts): array
    {
        $number = $artifacts['chapter_number'] ?? $run->start_chapter;
        switch ($run->step) {
            case 'content':
                $body = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', $number))->latest('id')->first()
                    ?? app(ContentGenerationService::class)->start($novel, $author, reason: '第'.$number.'章自动授权生成', modelOverride: $run->frozenModel() ?? $novel->generation_config['model'], chapterNumber: $number);
                $artifacts['body_run_id'] = $body->id;
                if ($body->status === 'pending') {
                    app(ContentExecutionService::class)->runStep($body->id, $body->invocation_id, app(AiTextGenerationService::class));
                    $body->refresh();
                }
                if ($body->stop_reason === 'awaiting_review') {
                    // 任何句中截断都必须先修复；独立模型通过不能豁免确定性完整性检查。
                    $version = ChapterVersion::findOrFail($body->chapter->current_candidate_version_id);
                    $incomplete = ContentExecutionContract::incompleteParagraphs($version->body);
                    if ($incomplete !== []) {
                        app(ContentExecutionService::class)->recordReading($body->id, $author, false,
                            ['自动段落完整性检查未通过：第'.implode('、', array_column($incomplete, 'paragraph')).'段在句中截断。必须由模型补全或合理合并，不能删除残句或仅补标点冒充完整；不是人工阅读结论。'], $run->id);
                        throw new InvalidArgumentException('CONTENT_PARAGRAPH_INCOMPLETE');
                    }
                    app(ContentExecutionService::class)->recordReading($body->id, $author, true, ['自动授权策略通过当前推进检查；后续独立评审通过后才提交。此记录不是人工全文阅读。'], $run->id);
                    $body->refresh();
                }
                if ($body->stop_reason === 'b04_accepted') {
                    $artifacts['version_id'] = $body->chapter->current_candidate_version_id;
                    $run->step = 'submission';
                } elseif ($body->status !== 'pending') {
                    throw new InvalidArgumentException('CONTENT_STOPPED:'.$body->stop_reason);
                }
                break;
            case 'submission':
                $submission = ChapterSubmissionRun::where('novel_id', $novel->id)->where('version_id', $artifacts['version_id'])->whereHas('chapter', fn ($query) => $query->where('number', $number))->latest('id')->first()
                    ?? app(ChapterSubmissionService::class)->prepare($artifacts['version_id'], $author, app(CanonicalBaselines::class)->activeStateBefore($novel, $number), '第'.$number.'章自动授权评审与提取', dispatch: false, modelOverride: ChapterGenerationRun::findOrFail($artifacts['body_run_id'])->config['model']);
                $artifacts['submission_run_id'] = $submission->id;
                if ($submission->status === 'pending') {
                    app(ChapterSubmissionService::class)->execute($submission->id, $submission->invocation_id, false);
                    $submission->refresh();
                }
                if (in_array($submission->status, ['ready', 'awaiting_approval', 'committed'], true)) {
                    $run->step = 'commit';
                } elseif ($submission->status !== 'pending') {
                    throw new InvalidArgumentException('SUBMISSION_STOPPED:'.$submission->stop_reason);
                }
                break;
            case 'commit':
                $submission = ChapterSubmissionRun::findOrFail($artifacts['submission_run_id']);
                $review = ChapterReview::where('submission_run_id', $submission->id)->firstOrFail();
                $extraction = ChapterEventExtraction::where('submission_run_id', $submission->id)->firstOrFail();
                $validation = ChapterSubmissionValidation::where('submission_run_id', $submission->id)->orderBy('id')->firstOrFail();
                $commit = app(CanonicalCommitService::class)->commit($submission->version_id, $review->id, $extraction->id, $validation->id, app(CanonicalBaselines::class)->activeStateBefore($novel, $number),
                    $run->chapterCommitKey($number), $author, $number === 1 ? '首章自动授权正式提交' : '第'.$number.'章自动授权正式提交', array_column($extraction->result['proposed_entities'], 'key'), true, $run->id);
                $artifacts['commit_id'] = $commit->id;
                // 正式提交完成才推进章号；提交回执与每章产物保留在编排历史中。
                $artifacts['chapters'][(string) $number] = ['number' => $number, 'body_run_id' => $artifacts['body_run_id'], 'version_id' => $artifacts['version_id'], 'submission_run_id' => $submission->id, 'commit_id' => $commit->id];
                if ($number < $run->chapter_limit) {
                    $artifacts['chapter_number'] = $number + 1;
                    unset($artifacts['body_run_id'], $artifacts['version_id'], $artifacts['submission_run_id'], $artifacts['commit_id']);
                    $run->step = 'content';
                } else {
                    $run->step = 'completed';
                }
                break;
            default:
                throw new InvalidArgumentException('UNKNOWN_AUTOMATION_STEP');
        }

        return $artifacts;
    }

    /** 领取超时后结算当前章仍在运行的子任务，不发送任何模型请求。 */
    public function interrupt(ProductionRun $run, Novel $novel): void
    {
        $number = $run->artifacts['chapter_number'] ?? $run->start_chapter;
        if ($run->step === 'content') {
            $body = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', $number))->latest('id')->first();
            if ($body?->status === 'running') {
                app(ContentExecutionService::class)->interrupted($body->id, $body->invocation_id);
            }
        } elseif ($run->step === 'submission') {
            $submission = ChapterSubmissionRun::where('novel_id', $novel->id)->whereHas('chapter', fn ($query) => $query->where('number', $number))->latest('id')->first();
            if ($submission?->status === 'running') {
                app(ChapterSubmissionService::class)->interrupted($submission->id, $submission->invocation_id);
            }
        }
    }

    /** 为当前章的失败步骤建立后继子运行，旧证据全部保留；评审驳回转为绑定原评审的定点修订。 */
    public function prepareRetry(ProductionRun $run, Novel $novel, User $author, int $recoveryLimit, bool $confirmUnknown, bool $rewrite, ?string $model): void
    {
        $number = $run->artifacts['chapter_number'] ?? $run->start_chapter;
        if ($run->step === 'content') {
            $body = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', $number))->latest('id')->first();
            if ($body !== null && $body->stop_reason !== 'b04_accepted' && $body->status !== 'pending') {
                app(ContentGenerationService::class)->recover($body->id, $author, '恢复自动生成，保留前序失败证据', executionLimit: $recoveryLimit, confirmUnknown: $confirmUnknown, rewrite: $rewrite, model: $model);
            }
        } elseif ($run->step === 'submission') {
            $submission = ChapterSubmissionRun::where('novel_id', $novel->id)->whereHas('chapter', fn ($query) => $query->where('number', $number))->latest('id')->first();
            if ($submission?->status === 'failed') {
                if ($submission->stop_reason === 'REVIEW_NOT_PASSED') {
                    // 拒绝评审不能靠再次抽样变通过，必须绑定原评审另建修订；作者授权整章重写时按最新章纲重写，评审意见仍随运行冻结。
                    $review = ChapterReview::where('submission_run_id', $submission->id)->firstOrFail();
                    $body = ChapterGenerationRun::where('chapter_id', $submission->chapter_id)->latest('id')->firstOrFail();
                    $revised = app(ContentGenerationService::class)->recover($body->id, $author, $rewrite ? '根据独立提交评审整章重写当前候选' : '根据独立提交评审修订当前候选', executionLimit: $recoveryLimit, rewrite: $rewrite, model: $model, submissionReviewId: $review->id);
                    $run->step = 'content';
                    $artifacts = $run->artifacts;
                    $artifacts['body_run_id'] = $revised->id;
                    unset($artifacts['version_id'], $artifacts['submission_run_id']);
                    $run->artifacts = $artifacts;
                } elseif ($rewrite) {
                    // 提交准备失败而作者授权整章重写（如冻结前文超出上下文预算）：另建正文运行，按当前协议重新冻结。
                    $body = ChapterGenerationRun::where('chapter_id', $submission->chapter_id)->latest('id')->firstOrFail();
                    $rewritten = app(ContentGenerationService::class)->recover($body->id, $author, '提交准备失败，作者授权按当前协议整章重写', executionLimit: $recoveryLimit, confirmUnknown: $confirmUnknown, rewrite: true, model: $model);
                    $run->step = 'content';
                    $artifacts = $run->artifacts;
                    $artifacts['body_run_id'] = $rewritten->id;
                    unset($artifacts['version_id'], $artifacts['submission_run_id']);
                    $run->artifacts = $artifacts;
                } else {
                    app(ChapterSubmissionService::class)->prepare($submission->version_id, $author, app(CanonicalBaselines::class)->activeStateBefore($novel, $number), '恢复自动生成提交准备', $recoveryLimit, false, $submission->id, $model ?? $submission->config['model']);
                }
            }
        }
    }
}
