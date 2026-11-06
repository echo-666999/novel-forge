<?php

namespace App\Services\Search;

use App\Models\ChapterVersion;
use App\Models\Novel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** 先过滤正式来源，再独立召回并按倒数排名融合；检索结果不是新事实。 */
final class HybridSearch
{
    /** 作者检索仅返回指定小说和章位内的正式原文；角色视角在缺少知情映射时关闭。
     * @return array<string,mixed>
     */
    public function search(Novel $novel, string $query, int $throughChapter, bool $semantic = false, ?int $povEntityId = null): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 200 || $throughChapter < 1) {
            throw new InvalidArgumentException('请输入1至200字的关键词，并选择有效章节上限');
        }
        if ($povEntityId !== null) {
            throw new InvalidArgumentException('整章索引仅供作者检索，尚无角色知情证据映射，禁止用于POV上下文');
        }
        app(ChapterSearchIndex::class)->assertDatabase();
        $base = $this->scope($novel->id, $throughChapter);
        $grammar = DB::getQueryGrammar();
        $body = $grammar->wrap('d.body');
        $names = $grammar->wrap('d.entity_names');
        $vector = $grammar->wrap('d.search_vector');
        // 模糊查询同时覆盖短原文片段和实体别名；使用word_similarity避免长正文稀释短词。
        $fuzzy = "greatest(public.word_similarity(?, {$body}), public.word_similarity(?, ".$grammar->wrap('d.entity_text').'))';
        $channels = [
            'exact' => (clone $base)->whereRaw("{$names} @> ?::jsonb", [json_encode([$query], JSON_UNESCAPED_UNICODE)])->selectRaw('1.0 as channel_score')->orderBy('d.id')->limit(30)->get(),
            'fulltext' => (clone $base)->whereRaw("{$vector} @@ plainto_tsquery('public.nf_zh_cn'::regconfig, ?)", [$query])->selectRaw("ts_rank_cd({$vector}, plainto_tsquery('public.nf_zh_cn'::regconfig, ?)) as channel_score", [$query])->orderByDesc('channel_score')->orderBy('d.id')->limit(30)->get(),
            'fuzzy' => (clone $base)->whereRaw("{$fuzzy} >= 0.3", [$query, $query])->selectRaw("{$fuzzy} as channel_score", [$query, $query])->orderByDesc('channel_score')->orderBy('d.id')->limit(30)->get(),
        ];
        $warning = $semantic ? null : '语义召回未启用，本次仅使用实体、全文和模糊检索';
        $logId = null;
        if ($semantic) {
            try {
                if (! (clone $base)->whereNotNull('d.embedding')->exists()) {
                    throw new InvalidArgumentException('当前范围尚无向量索引');
                }
                $result = app(EmbeddingService::class)->embed($novel->id, [$query], 'query');
                $logId = $result['log_id'];
                $distance = $grammar->wrap('d.embedding').'::public.vector(1536) <=> ?::public.vector';
                // 小库使用精确余弦距离；不为补足候选放宽小说或章位条件。
                $channels['vector'] = (clone $base)->whereNotNull('d.embedding')->selectRaw("1 - ({$distance}) as channel_score", [json_encode($result['vectors'][0], JSON_THROW_ON_ERROR)])->orderByDesc('channel_score')->orderBy('d.id')->limit(30)->get();
                if ((clone $base)->whereNull('d.embedding')->exists()) {
                    $warning = '部分正文向量尚未完成，语义召回覆盖不完整';
                }
            } catch (Throwable $exception) {
                $warning = '语义召回降级：'.$exception->getMessage();
            }
        }
        $merged = [];
        foreach ($channels as $channel => $rows) {
            foreach ($rows as $rank => $row) {
                $merged[$row->id] ??= ['document' => $row, 'score' => 0.0, 'channels' => []];
                $merged[$row->id]['score'] += 1 / (60 + $rank + 1);
                $merged[$row->id]['channels'][$channel] = (float) $row->channel_score;
            }
        }
        uasort($merged, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['document']->id <=> $b['document']->id));
        $results = [];
        $versions = [];
        foreach ($merged as $item) {
            $row = $item['document'];
            // 每个正式版本只返回最佳片段；返回前逐字核验原文与哈希，损坏投影不展示。
            if (isset($versions[$row->version_id])) {
                continue;
            }
            $version = ChapterVersion::findOrFail($row->version_id);
            if ($version->body_hash !== $row->body_hash || hash('sha256', $version->body) !== $row->body_hash || hash('sha256', $row->body) !== $row->chunk_hash || mb_substr($version->body, $row->start_offset, mb_strlen($row->body)) !== $row->body) {
                throw new InvalidArgumentException('检索投影与正式原文不一致，拒绝返回结果');
            }
            $versions[$row->version_id] = true;
            $results[] = ['novel_id' => $row->novel_id, 'chapter' => $row->chapter_number, 'chapter_id' => $row->chapter_id, 'version_id' => $row->version_id, 'commit_id' => $row->commit_id, 'body_hash' => $row->body_hash, 'start_offset' => $row->start_offset, 'quote' => $row->body, 'score' => $item['score'], 'channels' => $item['channels']];
            if (count($results) === 10) {
                break;
            }
        }

        return ['query' => $query, 'novel_id' => $novel->id, 'through_chapter' => $throughChapter, 'visibility' => 'author_only', 'profile' => EmbeddingService::PROFILE, 'warning' => $warning, 'query_log_id' => $logId, 'channel_counts' => array_map(fn ($rows): int => $rows->count(), $channels), 'results' => $results];
    }

    /** 所有通路共享隔离条件，并核对当前正式指针与原始提交身份。 */
    private function scope(int $novelId, int $throughChapter): Builder
    {
        return DB::table('search_documents as d')->join('chapters as c', 'c.id', '=', 'd.chapter_id')->join('canonical_commits as cc', 'cc.id', '=', 'd.commit_id')
            ->where('d.novel_id', $novelId)->where('c.novel_id', $novelId)->where('cc.novel_id', $novelId)
            ->whereColumn('c.formal_version_id', 'd.version_id')->whereColumn('c.canonical_commit_id', 'd.commit_id')->whereColumn('cc.version_id', 'd.version_id')->whereColumn('cc.chapter_id', 'd.chapter_id')
            ->whereColumn('c.number', 'd.chapter_number')->where('d.chapter_number', '<=', $throughChapter)->where('d.profile', EmbeddingService::PROFILE)->select('d.*');
    }
}
