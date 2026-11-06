<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Services\Search\ChapterSearchIndex;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** 处理正式提交outbox对应的检索投影；外部嵌入必须显式启用。 */
#[Signature('app:build-search-index {novel? : 小说编号，省略则处理全部正式提交} {--embeddings : 发送真实嵌入请求并保留费用流水}')]
class BuildSearchIndex extends Command
{
    /** 后台命令说明。 */
    protected $description = '构建正式正文中文检索索引，默认不调用外部模型';

    /** 已成功块幂等复用，失败批次可在检查流水后再次执行。 */
    public function handle(ChapterSearchIndex $index): int
    {
        // 定时任务仅构建本地词法索引，真实嵌入必须指定小说并显式启用。
        if ($this->option('embeddings') && ! $this->argument('novel')) {
            $this->error('真实嵌入必须指定小说');

            return self::FAILURE;
        }
        $novels = $this->argument('novel') ? [Novel::findOrFail($this->argument('novel'))] : Novel::whereHas('chapters', fn ($query) => $query->whereNotNull('canonical_commit_id'))->get();
        foreach ($novels as $novel) {
            $this->line(json_encode(['novel_id' => $novel->id, ...$index->build($novel, (bool) $this->option('embeddings'))], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
