<?php

namespace App\Console\Commands;

use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\NovelSettingConfirmation;
use App\Models\User;
use App\Services\Pipeline\ChapterSubmissionService;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** 有界提交准备入口，失败不能通过重复命令重置请求预算。 */
#[Signature('app:prepare-chapter-submission {version} {--expected-state-version=} {--max-requests=2} {--max-execution-count=} {--reason=} {--sync}')]
#[Description('冻结正文后进行一次评审及一次事件提取，不执行正式提交')]
class PrepareChapterSubmission extends Command
{
    /** 同步方式也复用单次领用服务，外部请求期间不持数据库事务。 */
    public function handle(ChapterSubmissionService $service): int
    {
        $run = null;
        try {
            if ((string) $this->option('max-requests') !== '2' || ! ctype_digit((string) $this->option('expected-state-version'))
                || ($this->option('max-execution-count') !== null && ! ctype_digit((string) $this->option('max-execution-count')))) {
                throw new \InvalidArgumentException('必须明确预期状态版本，请求上限固定2');
            }
            $version = ChapterVersion::findOrFail((int) $this->argument('version'));
            $source = ChapterGenerationRun::findOrFail($version->run_id);
            $confirmation = NovelSettingConfirmation::findOrFail($source->snapshot['confirmation_id']);
            $operator = User::findOrFail($confirmation->confirmed_by);
            $run = $service->prepare($version->id, $operator, (int) $this->option('expected-state-version'), (string) $this->option('reason'),
                $this->option('max-execution-count') === null ? null : (int) $this->option('max-execution-count'), ! $this->option('sync'));
            if ($this->option('sync')) {
                for ($i = 0; $i < 2 && $run->refresh()->status === 'pending'; $i++) {
                    $service->execute($run->id, $run->invocation_id, false);
                }
            }
            $this->line(PlanningJson::encode($run->refresh()->toArray()));

            return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            if ($run !== null) {
                $service->interrupted($run->id, $run->refresh()->invocation_id);
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
