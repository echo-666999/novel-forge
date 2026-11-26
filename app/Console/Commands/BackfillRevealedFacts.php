<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\RevealBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** 一次性补录已揭示事实；已补录的章节跳过，失败后重跑从未补录的章节继续。 */
#[Signature('app:backfill-revealed-facts {novel : 小说编号} {--through= : 补录到第几章（含）} {--author= : 执行人用户编号} {--model=deepseek-flash : 使用的模型}')]
#[Description('对已正式提交的章节逐章补录已揭示事实，供台账防止前文发现被重演')]
class BackfillRevealedFacts extends Command
{
    /** 逐章执行并输出每章条数，任一章失败即停止，已完成的章节保留。 */
    public function handle(RevealBackfill $backfill): int
    {
        if (! ctype_digit((string) $this->argument('novel')) || ! ctype_digit((string) $this->option('through')) || ! ctype_digit((string) $this->option('author'))) {
            $this->error('必须提供小说编号、--through 章号与 --author 执行人');

            return self::FAILURE;
        }
        try {
            $done = $backfill->run(Novel::findOrFail((int) $this->argument('novel')), (int) $this->option('through'), User::findOrFail((int) $this->option('author')), (string) $this->option('model'),
                fn (int $chapter, int $count) => $this->line('第'.$chapter.'章补录 '.$count.' 条'));
        } catch (Throwable $exception) {
            $this->error('补录停止：'.$exception->getMessage());

            return self::FAILURE;
        }
        $this->info('本次补录 '.count($done).' 章，共 '.array_sum($done).' 条');

        return self::SUCCESS;
    }
}
