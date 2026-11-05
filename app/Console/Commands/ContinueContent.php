<?php

namespace App\Console\Commands;

use App\Models\ChapterGenerationRun;
use App\Models\User;
use App\Services\Pipeline\ContentGenerationService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/** 后台与CLI共用有限恢复服务，历史不限次数选项不再执行。 */
class ContinueContent extends Command
{
    protected $signature = 'app:continue-content {run : 首章运行编号} {--recover : 明确授权新建v14有限恢复运行} {--continuous : 已弃用，不会发送请求} {--rewrite : 仅新恢复运行整章重写} {--model= : 仅新恢复运行模型} {--reject-reading : 仅保存全文未通过意见，不发送请求} {--confirm-unknown : 已核查未知结果及额外费用风险} {--max-requests= : 本次请求总上限} {--max-execution-count= : 明确确认小说总执行上限} {--reason= : 操作原因或全文核对意见}';

    protected $description = '保留首章历史，通过统一入口有限恢复或在原预算内继续';

    /** 参数先严格校验，弃用选项不会退化为另一种付费动作。 */
    public function handle(ContentGenerationService $service): int
    {
        try {
            if ($this->option('continuous')) {
                throw new InvalidArgumentException('--continuous 已停用；请使用 --recover 明确确认有限预算');
            }
            foreach ([$this->argument('run'), $this->option('max-requests'), $this->option('max-execution-count')] as $value) {
                if ($value !== null && (! ctype_digit((string) $value) || (float) $value < 1 || (float) $value > 2147483647)) {
                    throw new InvalidArgumentException('编号及额度必须为有效正整数');
                }
            }
            $parent = ChapterGenerationRun::findOrFail((int) $this->argument('run'));
            $operator = $parent->config['author_id'] ?? data_get($parent->config, 'continuation_grant.operator_id') ?? data_get($parent->control_history, '0.author_id');
            $author = User::findOrFail($operator);
            $reason = $this->option('reason') ?? '';
            $requests = $this->option('max-requests') === null ? null : (int) $this->option('max-requests');
            $executions = $this->option('max-execution-count') === null ? null : (int) $this->option('max-execution-count');
            if ($this->option('reject-reading')) {
                if ($this->option('recover') || $this->option('rewrite') || $this->option('model') !== null || $requests !== null || $executions !== null) {
                    throw new InvalidArgumentException('全文意见记录不能混用新请求参数');
                }
                $run = $service->recordReading($parent->id, $author, false, [$reason]);
            } elseif ($this->option('recover')) {
                $run = $service->recover($parent->id, $author, $reason, $requests, $executions, (bool) $this->option('confirm-unknown'), (bool) $this->option('rewrite'), $this->option('model'));
            } else {
                if ($this->option('rewrite') || $this->option('model') !== null || $this->option('confirm-unknown')) {
                    throw new InvalidArgumentException('新模型、重写及未知结果确认必须使用 --recover');
                }
                $run = $service->continue($parent->id, requestLimit: $requests, executionLimit: $executions, reason: $reason, authorId: $author->id);
            }
            $this->info('首章运行'.$run->id.'：'.$run->status.'；请求预算'.$run->requests_used.'/'.$run->request_limit.'，历史已保留。');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('操作未完成，请核查运行身份及来源；没有自动重发。');

            return self::FAILURE;
        }
    }
}
