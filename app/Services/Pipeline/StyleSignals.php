<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalBaseline;
use App\Models\ChapterVersion;
use App\Models\Novel;

/** 跨章规则文风信号：只从已正式提交的近章正文计算重复短语和开头结尾，不发请求，只作避免模板化的提示，不能据此自动删改。 */
final class StyleSignals
{
    public const VERSION = 'c-style-signals-v1';

    /** 统计近多少章正式正文。 */
    public const WINDOW = 10;

    /** 至少在几章中出现才算反复使用。 */
    public const MIN_CHAPTERS = 3;

    /** 判定重复的最短连续字符数，与本章阅读审计的段间重复区分。 */
    public const MIN_LENGTH = 8;

    /** 最多列出的重复短语数。 */
    public const PHRASE_LIMIT = 8;

    /** 列出最近几章的开头和结尾。 */
    public const RECENT = 3;

    /** 开头、结尾句最多保留的字符数。 */
    private const SENTENCE_LIMIT = 60;

    /** 新运行冻结的近章文风信号；首章或没有已提交前文时返回 null。
     * @return array{version: string, window: array{from: int, to: int}, instruction: string, overused_phrases: list<array{phrase: string, chapters: list<int>}>, recent_openings: list<array{chapter: int, text: string}>, recent_endings: list<array{chapter: int, text: string}>}|null
     */
    public function context(Novel $novel, int $chapterNumber, ?CanonicalBaseline $baseline = null): ?array
    {
        $bodies = $this->recentBodies($novel, $chapterNumber, $baseline);
        if ($bodies === []) {
            return null;
        }
        $recent = array_slice(array_keys($bodies), -self::RECENT, null);

        return ['version' => self::VERSION, 'window' => ['from' => array_key_first($bodies), 'to' => array_key_last($bodies)],
            'instruction' => '以下是近章已反复使用的表达和最近几章的开头、结尾，仅为避免模板化的提示：本章不要照搬这些句式来开头、过渡或收束，换成与本章场景相符的具体写法。人物、地点、证据名称和必要的程序事实照常使用；不得为避开重复删减事实、线索、限制或因果步骤。',
            'overused_phrases' => self::phrases($bodies),
            'recent_openings' => array_map(fn (int $number): array => ['chapter' => $number, 'text' => self::opening($bodies[$number])], $recent),
            'recent_endings' => array_map(fn (int $number): array => ['chapter' => $number, 'text' => self::ending($bodies[$number])], $recent)];
    }

    /** 已提交章节的规则信号（正式稿修订用）：取该章前后各五章正式正文，列出本章参与的跨章重复短语、与前三章雷同的开头结尾、章内重复，以及相邻章的结尾和开头。
     * @return array{version: string, overused_phrases: list<array{phrase: string, chapters: list<int>}>, similar: list<array{type: string, text: string, chapters: list<int>}>, in_chapter_repeats: list<string>, previous_ending: ?string, next_opening: ?string}
     */
    public function forCommitted(Novel $novel, int $number): array
    {
        $baselines = app(CanonicalBaselines::class);
        $baseline = $baselines->current($novel) ?? throw new \InvalidArgumentException('尚无正式提交');
        $bodies = [];
        foreach (range(max(1, $number - 5), $number + 5) as $chapter) {
            $commit = $baselines->commitFor($baseline, $chapter);
            if ($commit !== null) {
                $bodies[$chapter] = (string) ChapterVersion::whereKey($commit->version_id)->value('body');
            }
        }
        $body = $bodies[$number] ?? throw new \InvalidArgumentException('第'.$number.'章尚未正式提交');
        $phrases = array_values(array_filter(self::phrases($bodies, 50), fn (array $item): bool => in_array($number, $item['chapters'], true)));
        $phrases = array_slice($phrases, 0, self::PHRASE_LIMIT);
        $prior = $this->context($novel, $number, $baseline);
        $similar = $prior === null ? [] : array_values(array_filter(self::inspect($body, $prior), fn (array $hint): bool => $hint['type'] !== 'overused_phrase'));
        $repeats = array_values(array_unique(array_map(fn (array $issue): string => $issue['evidence'][0]['quote'], array_filter(ContentReadingAudit::inspect($body)['issues'], fn (array $issue): bool => $issue['type'] === 'repeated_text'))));

        return ['version' => self::VERSION, 'overused_phrases' => $phrases, 'similar' => $similar, 'in_chapter_repeats' => array_slice($repeats, 0, self::PHRASE_LIMIT),
            'previous_ending' => isset($bodies[$number - 1]) ? self::ending($bodies[$number - 1]) : null, 'next_opening' => isset($bodies[$number + 1]) ? self::opening($bodies[$number + 1]) : null];
    }

    /** 当前正文复用了哪些冻结的重复短语，以及开头、结尾是否与近章雷同；仅供作者阅读。
     * @param  array<string, mixed>  $signals
     * @return list<array{type: string, text: string, chapters: list<int>}>
     */
    public static function inspect(string $body, array $signals): array
    {
        $hints = [];
        foreach ($signals['overused_phrases'] ?? [] as $item) {
            if (str_contains($body, $item['phrase'])) {
                $hints[] = ['type' => 'overused_phrase', 'text' => $item['phrase'], 'chapters' => $item['chapters']];
            }
        }
        foreach (['opening' => [self::opening($body), $signals['recent_openings'] ?? []], 'ending' => [self::ending($body), $signals['recent_endings'] ?? []]] as $type => [$sentence, $recent]) {
            $similar = array_values(array_map(fn (array $item): int => $item['chapter'], array_filter($recent, fn (array $item): bool => self::shared($sentence, $item['text']) >= self::MIN_LENGTH)));
            if ($similar !== []) {
                $hints[] = ['type' => 'similar_'.$type, 'text' => $sentence, 'chapters' => $similar];
            }
        }

        return $hints;
    }

    /** 目标章之前窗口内的正式正文（按基线提交链，默认活动基线），按章号升序。
     * @return array<int, string>
     */
    private function recentBodies(Novel $novel, int $chapterNumber, ?CanonicalBaseline $baseline): array
    {
        $baselines = app(CanonicalBaselines::class);
        $baseline ??= $baselines->current($novel);
        if ($baseline === null || $chapterNumber <= 1) {
            return [];
        }
        $bodies = [];
        foreach (range(max(1, $chapterNumber - self::WINDOW), $chapterNumber - 1) as $number) {
            $commit = $baselines->commitFor($baseline, $number);
            if ($commit !== null) {
                $bodies[$number] = (string) ChapterVersion::whereKey($commit->version_id)->value('body');
            }
        }

        return $bodies;
    }

    /** 在多章中出现的连续原文，扩展到不丢失出现章数的最长形式，去掉被更长短语包含的项。
     * @param  array<int, string>  $bodies
     * @return list<array{phrase: string, chapters: list<int>}>
     */
    private static function phrases(array $bodies, int $limit = self::PHRASE_LIMIT): array
    {
        $grams = [];
        foreach ($bodies as $number => $body) {
            foreach (self::clauses($body) as $clause) {
                for ($offset = 0; $offset <= mb_strlen($clause) - self::MIN_LENGTH; $offset++) {
                    $grams[mb_substr($clause, $offset, self::MIN_LENGTH)][$number] = $clause;
                }
            }
        }
        $found = [];
        foreach ($grams as $gram => $sources) {
            if (count($sources) < self::MIN_CHAPTERS) {
                continue;
            }
            $phrase = self::extend((string) $gram, reset($sources), $bodies, count($sources));
            $found[$phrase] = self::chaptersContaining($phrase, $bodies);
        }
        $kept = array_filter($found, function (array $chapters, string $phrase) use ($found): bool {
            foreach ($found as $other => $otherChapters) {
                if ($other !== $phrase && str_contains($other, $phrase) && count($otherChapters) >= count($chapters)) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);
        $items = array_map(fn (string $phrase): array => ['phrase' => $phrase, 'chapters' => $kept[$phrase]], array_keys($kept));
        usort($items, fn (array $a, array $b): int => [count($b['chapters']), mb_strlen($b['phrase']), $a['phrase']] <=> [count($a['chapters']), mb_strlen($a['phrase']), $b['phrase']]);

        return array_slice($items, 0, $limit);
    }

    /** 在所在分句内向两侧逐字扩展，出现章数不减少才保留扩展。
     * @param  array<int, string>  $bodies
     */
    private static function extend(string $phrase, string $clause, array $bodies, int $count): string
    {
        $start = mb_strpos($clause, $phrase);
        $end = $start + mb_strlen($phrase);
        while ($start > 0 && count(self::chaptersContaining(mb_substr($clause, $start - 1, $end - $start + 1), $bodies)) >= $count) {
            $start--;
        }
        while ($end < mb_strlen($clause) && count(self::chaptersContaining(mb_substr($clause, $start, $end - $start + 1), $bodies)) >= $count) {
            $end++;
        }

        return mb_substr($clause, $start, $end - $start);
    }

    /** 含有该原文的章号。
     * @param  array<int, string>  $bodies
     * @return list<int>
     */
    private static function chaptersContaining(string $phrase, array $bodies): array
    {
        return array_values(array_keys(array_filter($bodies, fn (string $body): bool => str_contains($body, $phrase))));
    }

    /** 按标点和空白切分的分句，重复短语不跨标点。
     * @return list<string>
     */
    private static function clauses(string $body): array
    {
        return array_values(array_filter(preg_split('/[\s\p{P}]+/u', $body) ?: [], fn (string $clause): bool => mb_strlen($clause) >= self::MIN_LENGTH));
    }

    /** 正文第一句。 */
    private static function opening(string $body): string
    {
        $text = trim($body);
        $end = preg_match('/[。！？]/u', $text, $match, PREG_OFFSET_CAPTURE) === 1 ? mb_strlen(substr($text, 0, $match[0][1])) + 1 : mb_strlen($text);

        return mb_substr($text, 0, min($end, self::SENTENCE_LIMIT));
    }

    /** 正文最后一句。 */
    private static function ending(string $body): string
    {
        $sentences = preg_split('/(?<=[。！？])/u', trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: [''];
        $last = trim((string) end($sentences));

        return mb_strlen($last) > self::SENTENCE_LIMIT ? mb_substr($last, -self::SENTENCE_LIMIT) : $last;
    }

    /** 两句最长公共连续子串长度，用于判断开头结尾是否沿用同一句式。 */
    private static function shared(string $a, string $b): int
    {
        $best = 0;
        $lengthA = mb_strlen($a);
        for ($i = 0; $i < $lengthA; $i++) {
            for ($length = max($best + 1, 1); $i + $length <= $lengthA && str_contains($b, mb_substr($a, $i, $length)); $length++) {
                $best = $length;
            }
        }

        return $best;
    }
}
