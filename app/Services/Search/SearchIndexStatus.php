<?php

namespace App\Services\Search;

use App\Models\Chapter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** 索引覆盖观测只读派生表，不用outbox全局投递标记冒充订阅者进度。 */
final class SearchIndexStatus
{
    /** 报告没有任何当前代词法块的正式章位，不将有一块称为全文完整。 */
    public function summary(): string
    {
        $missing = Chapter::whereNotNull('canonical_commit_id')->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('search_documents')
                ->whereColumn('search_documents.commit_id', 'chapters.canonical_commit_id')
                ->whereColumn('search_documents.version_id', 'chapters.formal_version_id')
                ->where('profile', EmbeddingService::PROFILE)->whereNotNull('search_vector');
        });
        $count = (clone $missing)->count();
        $oldest = (clone $missing)->min('committed_at');
        $pending = DB::table('search_documents')->where('profile', EmbeddingService::PROFILE)->whereNull('embedding')->count();

        return '未发现当前代词法块的正式章数：'.$count.'；最早缺失章提交时间：'.($oldest ?? '无').'；待嵌入块：'.$pending.'。有索引块不等于全文完整，完整性仍须核对来源与分块。';
    }
}
