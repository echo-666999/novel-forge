<?php

namespace App\Console\Commands;

use App\Models\FirstChapterRun;
use App\Models\User;
use App\Services\Pipeline\FirstChapterService;
use Illuminate\Console\Command;

/** 与后台共用的首章自动生成入口，支持显式恢复及同步验收。 */
class RunFirstChapter extends Command
{
    /** 新建必须提供创意文件及明确授权；恢复复用原小说。 */
    protected $signature = 'app:run-first-chapter {--input= : 创意JSON文件} {--author= : 授权用户编号} {--authorize-automatic-commit : 授权设定决策、实体采纳和首章提交} {--run= : 已有自动流程编号} {--retry : 恢复失败断点} {--confirm-unknown : 接受历史未知请求费用风险} {--model= : 新流程或正文/提交恢复使用的模型，不改全局设置} {--rewrite : 正文恢复时授权新版本整章重写，保留旧正文} {--sync : 当前进程顺序执行直到完成或失败}';

    /** Artisan中文说明。 */
    protected $description = '自动生成并正式提交新小说第一章，保留授权、断点及请求次数';

    /** CLI不补写故事，所有产物均通过应用服务与正式门禁生成。 */
    public function handle(FirstChapterService $service): int
    {
        $author = User::findOrFail((int) $this->option('author'));
        if ($this->option('run')) {
            $run = FirstChapterRun::findOrFail((int) $this->option('run'));
            $run->assertDelegation($run->novel_id, $author->id);
            if ($this->option('retry')) {
                $run = $service->retry($run->id, $author, (bool) $this->option('confirm-unknown'), (bool) $this->option('rewrite'), $this->option('model'));
            }
        } else {
            $path = $this->option('input');
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                $this->error('新建自动流程必须提供可读的 --input 创意JSON文件');

                return self::FAILURE;
            }
            $input = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $run = $service->start($input, $author, (bool) $this->option('authorize-automatic-commit'), false, $this->option('model'));
        }
        $this->line('自动流程 '.$run->id.' / 小说 '.$run->novel_id);
        if ($this->option('sync')) {
            while ($run->status === 'pending') {
                $run = $service->advance($run->id);
                $this->line($run->step.' / '.$run->status.' / AI请求 '.$service->requestCount($run).($run->error ? ' / '.$run->error : ''));
            }
        } elseif ($run->status === 'pending') {
            $service->dispatch($run);
            $run->refresh();
        }

        return in_array($run->status, ['failed', 'running'], true) ? self::FAILURE : self::SUCCESS;
    }
}
