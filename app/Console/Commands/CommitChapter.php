<?php

namespace App\Console\Commands;

use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\User;
use App\Services\Pipeline\CanonicalCommitService;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** 作者正式提交入口，所有门禁仍由同一事务服务复验。 */
#[Signature('app:commit-chapter {version} {--review=} {--extraction=} {--validation=} {--expected-state-version=} {--idempotency-key=} {--reason=} {--approve-entity=*} {--acknowledge}')]
#[Description('提交已评审和验证的冻结章节，显式批准实体后原子推进故事状态')]
class CommitChapter extends Command
{
    /** 必须给出产物编号及幂等键，不隐式选择最新评审或替作者批准实体。 */
    public function handle(CanonicalCommitService $service): int
    {
        try {
            foreach (['review', 'extraction', 'validation', 'expected-state-version'] as $option) {
                if (! ctype_digit((string) $this->option($option))) {
                    throw new \InvalidArgumentException('必须明确所有产物编号及预期状态版本');
                }
            }
            $review = ChapterReview::findOrFail((int) $this->option('review'));
            $run = ChapterSubmissionRun::findOrFail($review->submission_run_id);
            $result = $service->commit((int) $this->argument('version'), $review->id, (int) $this->option('extraction'), (int) $this->option('validation'),
                (int) $this->option('expected-state-version'), (string) $this->option('idempotency-key'), User::findOrFail($run->config['operator_id']),
                (string) $this->option('reason'), $this->option('approve-entity'), (bool) $this->option('acknowledge'));
            $this->line(PlanningJson::encode($result->toArray()));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
