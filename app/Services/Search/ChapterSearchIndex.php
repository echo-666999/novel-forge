<?php

namespace App\Services\Search;

use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\StoryOutboxEvent;
use App\Services\Pipeline\CanonicalBaselines;
use App\Services\Pipeline\CanonicalChapterContext;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 从正式提交outbox构建可重入投影，不占用正式提交事务，不消费其他订阅者标记。 */
final class ChapterSearchIndex
{
    /** 校验真实中文分词配置；缺失时阻塞，禁止退回英文分词。 */
    public function assertDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('中文混合检索必须使用PostgreSQL');
        }
        if (DB::selectOne("select count(*) as n from pg_extension where extname in ('vector','pg_trgm','zhparser')")->n !== 3) {
            throw new RuntimeException('中文检索需要vector、pg_trgm及zhparser扩展');
        }
        $parser = DB::selectOne("select p.prsname from pg_ts_config c join pg_ts_parser p on p.oid=c.cfgparser join pg_namespace n on n.oid=c.cfgnamespace where n.nspname='public' and c.cfgname='nf_zh_cn'");
        if (($parser->prsname ?? null) !== 'zhparser') {
            throw new RuntimeException('固定中文分词配置public.nf_zh_cn缺失或已变更，拒绝混用索引');
        }
    }

    /** 只索引有outbox且仍为正式版本的正文；重复执行不会重发已成功嵌入。
     * @return array{documents:int, embedded:int, pending:int}
     */
    public function build(Novel $novel, bool $embeddings = false): array
    {
        $this->assertDatabase();

        return Cache::lock('c05-index-'.$novel->id, 3600)->block(3, function () use ($novel, $embeddings): array {
            $chapters = Chapter::where('novel_id', $novel->id)->whereNotNull('canonical_commit_id')->orderBy('number')->get();
            if ($chapters->isNotEmpty()) {
                // 正式链、实体引入时间及正文哈希复用前文上下文校验，不读取候选稿。
                app(CanonicalChapterContext::class)->build($novel, $chapters->max('number') + 1);
            }
            $entities = StoryEntity::where('novel_id', $novel->id)->get();
            $knownCommits = [];
            foreach ($chapters as $chapter) {
                if (! StoryOutboxEvent::where('commit_id', $chapter->canonical_commit_id)->where('event_type', 'chapter.committed')->exists()) {
                    throw new RuntimeException('正式提交缺少outbox，拒绝生成检索投影');
                }
                // 修订或重挂提交复制自原提交，原提交引入的实体同样可见。
                array_push($knownCommits, ...app(CanonicalBaselines::class)->lineage(CanonicalCommit::findOrFail($chapter->canonical_commit_id)));
                $body = ChapterVersion::findOrFail($chapter->formal_version_id)->body;
                $names = $entities->filter(fn (StoryEntity $entity): bool => ($entity->origin['kind'] ?? '') === 'confirmed_setting' || in_array($entity->origin['commit_id'] ?? null, $knownCommits, true))
                    ->map(fn (StoryEntity $entity): array => array_values(array_unique([$entity->name, ...$entity->aliases])))->all();
                foreach (mb_str_split($body, 600) as $number => $chunk) {
                    // 名称与别名成组，仅在该块原文实际提及已知实体时附加别名。
                    $chunkNames = [];
                    foreach ($names as $group) {
                        if (collect($group)->contains(fn (string $name): bool => $name !== '' && str_contains($chunk, $name))) {
                            $chunkNames = [...$chunkNames, ...$group];
                        }
                    }
                    $existing = DB::table('search_documents')->where('commit_id', $chapter->canonical_commit_id)->where('chunk_number', $number)->first();
                    if ($existing !== null) {
                        if ($existing->chunk_hash !== hash('sha256', $chunk) || $existing->profile !== EmbeddingService::PROFILE) {
                            throw new RuntimeException('索引代次或来源不一致，需要单独重建，不能覆盖原向量');
                        }

                        continue;
                    }
                    DB::table('search_documents')->insert(['novel_id' => $novel->id, 'chapter_id' => $chapter->id, 'commit_id' => $chapter->canonical_commit_id, 'version_id' => $chapter->formal_version_id, 'chapter_number' => $chapter->number,
                        'chunk_number' => $number, 'start_offset' => $number * 600, 'body' => $chunk, 'body_hash' => hash('sha256', $body), 'chunk_hash' => hash('sha256', $chunk),
                        'entity_names' => PlanningJson::encode(array_values(array_unique($chunkNames))), 'entity_text' => implode(' ', array_unique($chunkNames)), 'profile' => EmbeddingService::PROFILE, 'indexed_at' => now()]);
                }
            }
            $table = DB::getQueryGrammar()->wrapTable('search_documents');
            DB::update("UPDATE {$table} SET search_vector=to_tsvector('public.nf_zh_cn'::regconfig,body) WHERE novel_id=? AND search_vector IS NULL", [$novel->id]);
            if ($embeddings) {
                foreach (DB::table('search_documents')->where('novel_id', $novel->id)->where('profile', EmbeddingService::PROFILE)->whereNull('embedding')->orderBy('id')->get()->chunk(16) as $batch) {
                    $result = app(EmbeddingService::class)->embed($novel->id, $batch->pluck('body')->all(), 'index');
                    DB::transaction(function () use ($batch, $result, $table): void {
                        foreach ($batch->values() as $index => $document) {
                            DB::update("UPDATE {$table} SET embedding=?::public.vector, embedding_log_id=? WHERE id=? AND chunk_hash=?", [json_encode($result['vectors'][$index], JSON_THROW_ON_ERROR), $result['log_id'], $document->id, $document->chunk_hash]);
                        }
                    });
                }
            }
            $query = DB::table('search_documents')->where('novel_id', $novel->id);

            return ['documents' => (clone $query)->count(), 'embedded' => (clone $query)->whereNotNull('embedding')->count(), 'pending' => (clone $query)->whereNull('embedding')->count()];
        });
    }
}
