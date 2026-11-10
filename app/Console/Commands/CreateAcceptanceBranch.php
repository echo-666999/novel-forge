<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Pipeline\AcceptanceBranch;
use Illuminate\Console\Command;

/** 将已认可首章接入独立十章验收，不覆盖原书或自动伪造人工评分。 */
class CreateAcceptanceBranch extends Command
{
    /** 必须指定来源、证据、操作者和本次分支授权。 */
    protected $signature = 'app:create-acceptance-branch {novel} {--author=} {--evidence=} {--model=} {--authorize-automatic-commit}';

    /** 只创建分支，后续通过已有逐章运行命令推进。 */
    protected $description = '从已认可对照稿创建独立验收分支，保留原书正式历史';

    /** 创建成功返回可恢复运行编号；不在创建事务内发送AI请求。 */
    public function handle(AcceptanceBranch $service): int
    {
        $run = $service->create((int) $this->argument('novel'), User::findOrFail((int) $this->option('author')),
            (string) $this->option('evidence'), (string) $this->option('model'), (bool) $this->option('authorize-automatic-commit'));
        $this->info('新验收分支小说 '.$run->novel_id.' / 自动流程 '.$run->id.'；原书未修改');

        return self::SUCCESS;
    }
}
