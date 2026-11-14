<?php

namespace App\Services\Search;

use App\Models\ChapterVersion;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 生成前检索：按本章章纲和作者场景的关键词，从冻结前文链的正式正文中取相关原文片段，供写作保持细节一致；不发模型请求，不产生新事实。 */
final class ContextRetrieval
{
    public const VERSION = 'c05-context-retrieval-v1';

    /** 最多放入上下文的原文片段数。 */
    public const PASSAGES = 6;

    /** 同一章最多取几段，避免一章占满全部名额。 */
    public const PER_CHAPTER = 2;

    /** 最多使用的检索词数，长词优先。 */
    public const TERMS = 60;

    /** 在超过此比例的检索块中都出现的词（如主角名）区分度太低，不参与排序。 */
    public const COMMON_SHARE = 0.6;

    /** 片段说明随快照冻结，写给模型阅读。 */
    public const VISIBILITY = '作者视角的前文正式原文片段，按本章计划的关键词从早前章节检索，仅用于保持人物、物件、地点和既有描写前后一致；不代表本章人物知道或记得这些内容，人物已知事实以正式状态和事件为准；不得照抄原句，不要重演片段中已完成的情节。';

    /** 新运行冻结的检索结果；首章、没有前文、数据库不支持中文检索、没有可用检索词或没有命中时返回 null，生成照常进行。
     * @param  array<string, mixed>  $prior  冻结的前文上下文（CanonicalChapterContext）
     * @param  list<string>  $texts  本章计划文本：章纲、推进点、钩子、作者场景等
     * @return array<string, mixed>|null
     */
    public function context(Novel $novel, int $number, array $prior, array $texts): ?array
    {
        $versions = array_column($prior['chapters'] ?? [], 'version_id', 'number');
        if ($number < 2 || $versions === []) {
            return null;
        }
        try {
            app(ChapterSearchIndex::class)->assertDatabase();
        } catch (RuntimeException) {
            return null;
        }
        $terms = $this->terms(implode("\n", $texts));
        $table = DB::getQueryGrammar()->wrapTable('search_documents');
        $scope = DB::table('search_documents')->where('novel_id', $novel->id)->where('profile', EmbeddingService::PROFILE)
            ->where('chapter_number', '<', $number)->whereIn('version_id', array_values($versions));
        $ids = (clone $scope)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $indexed = (clone $scope)->distinct()->pluck('chapter_number')->map(fn ($chapter): int => (int) $chapter)->sort()->values()->all();
        // 出现在大多数检索块里的词（主角名等）去掉；块太少时不做此过滤。
        if (count($ids) >= 4 && $terms !== []) {
            $frequency = collect(DB::select('select word, ndoc from ts_stat(?)', ["select search_vector from {$table} where id in (".implode(',', $ids).')']))
                ->pluck('ndoc', 'word');
            $terms = array_values(array_filter($terms, fn (string $term): bool => ($frequency[$term] ?? 0) <= count($ids) * self::COMMON_SHARE));
        }
        if ($terms === []) {
            return null;
        }
        $query = implode(' | ', $terms);
        $rows = (clone $scope)->whereRaw("search_vector @@ to_tsquery('public.nf_zh_cn'::regconfig, ?)", [$query])
            ->selectRaw("id, chapter_number, version_id, start_offset, body, chunk_hash, ts_rank_cd(search_vector, to_tsquery('public.nf_zh_cn'::regconfig, ?)) as score", [$query])
            ->orderByDesc('score')->orderBy('id')->limit(self::PASSAGES * 5)->get();
        $passages = [];
        $perChapter = [];
        foreach ($rows as $row) {
            $chapter = (int) $row->chapter_number;
            if (count($passages) === self::PASSAGES || ($perChapter[$chapter] ?? 0) === self::PER_CHAPTER || ($versions[$chapter] ?? null) !== (int) $row->version_id) {
                continue;
            }
            // 投影必须与冻结版本原文逐字一致，不一致的块不进入上下文。
            $body = ChapterVersion::findOrFail($row->version_id)->body;
            if (hash('sha256', $row->body) !== $row->chunk_hash || mb_substr($body, (int) $row->start_offset, mb_strlen($row->body)) !== $row->body) {
                continue;
            }
            $perChapter[$chapter] = ($perChapter[$chapter] ?? 0) + 1;
            $passages[] = ['chapter' => $chapter, 'version_id' => (int) $row->version_id, 'start_offset' => (int) $row->start_offset, 'score' => round((float) $row->score, 4),
                'matched_terms' => array_values(array_filter($terms, fn (string $term): bool => str_contains($row->body, $term))), 'quote' => $row->body];
        }
        if ($passages === []) {
            return null;
        }
        usort($passages, fn (array $a, array $b): int => [$a['chapter'], $a['start_offset']] <=> [$b['chapter'], $b['start_offset']]);

        return ['version' => self::VERSION, 'visibility' => self::VISIBILITY, 'query_terms' => $terms,
            'coverage' => ['prior_chapters' => array_keys($versions), 'indexed_chapters' => $indexed,
                'missing_chapters' => array_values(array_diff(array_keys($versions), $indexed))], 'passages' => $passages];
    }

    /** 冻结片段只能来自冻结前文链的对应版本，且与正式原文逐字一致。 */
    public static function verify(array $memory, array $prior): bool
    {
        $versions = array_column($prior['chapters'] ?? [], 'version_id', 'number');
        if (($memory['version'] ?? null) !== self::VERSION || ($memory['visibility'] ?? null) !== self::VISIBILITY) {
            return false;
        }
        foreach ($memory['passages'] ?? [] as $passage) {
            if (($versions[$passage['chapter'] ?? 0] ?? null) !== ($passage['version_id'] ?? null)) {
                return false;
            }
            $body = ChapterVersion::find($passage['version_id'])?->body;
            if ($body === null || mb_substr($body, $passage['start_offset'], mb_strlen($passage['quote'])) !== $passage['quote']) {
                return false;
            }
        }

        return true;
    }

    /** 用固定中文分词配置切出计划文本的词，只保留两个字以上的中文词，长词优先。
     * @return list<string>
     */
    private function terms(string $text): array
    {
        $words = array_map(fn (object $row): string => $row->word, DB::select("select unnest(tsvector_to_array(to_tsvector('public.nf_zh_cn'::regconfig, ?))) as word", [$text]));
        $words = array_values(array_filter($words, fn (string $word): bool => mb_strlen($word) >= 2 && preg_match('/^\p{Han}+$/u', $word) === 1));
        usort($words, fn (string $a, string $b): int => (mb_strlen($b) <=> mb_strlen($a)) ?: strcmp($a, $b));

        return array_slice($words, 0, self::TERMS);
    }

    /** 收集章纲与作者场景中的全部文字，作为检索词来源。
     * @return list<string>
     */
    public static function planTexts(mixed ...$sources): array
    {
        $texts = [];
        $walk = function (mixed $value) use (&$walk, &$texts): void {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                is_array($decoded) ? $walk($decoded) : $texts[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };
        foreach ($sources as $source) {
            $walk($source);
        }

        return $texts;
    }
}
