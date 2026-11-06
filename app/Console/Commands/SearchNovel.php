<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Services\Search\HybridSearch;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** 与后台实验室共用检索路径，支持保存可复核JSON证据。 */
#[Signature('app:search-novel {novel : 小说编号} {query : 查询词} {--through= : 最大正式章号} {--semantic : 允许真实查询嵌入，成功缓存复用}')]
class SearchNovel extends Command
{
    /** 中文命令说明。 */
    protected $description = '仅检索当前小说正式正文，显示四路召回来源与降级状态';

    /** 缺省上限取当前正式进度，不读取未来候选。 */
    public function handle(HybridSearch $search): int
    {
        $novel = Novel::findOrFail($this->argument('novel'));
        $result = $search->search($novel, (string) $this->argument('query'), (int) ($this->option('through') ?? $novel->chapters_done), (bool) $this->option('semantic'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
