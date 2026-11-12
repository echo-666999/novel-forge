<?php

namespace App\Services\Pipeline;

use App\Models\CanonicalBaseline;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** 按正式回执冻结连续章节，下载期间不再查询可变候选或章节标题。 */
final class FormalChapterExport
{
    /** 在正式提交使用的小说锁内构造完整文件，任何缺章或来源损坏均拒绝整次导出。
     * @return array{filename: string, mime: string, content: string, manifest: array}
     */
    public function build(int $novelId, int $endChapter, string $format, ?int $baselineId = null): array
    {
        if (! in_array($format, ['txt', 'md'], true) || $endChapter < 1) {
            throw new InvalidArgumentException('请选择TXT或Markdown格式，并指定有效的截至章号');
        }

        return DB::transaction(function () use ($novelId, $endChapter, $format, $baselineId): array {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            // 默认导出活动基线；也可导出已被替换或候选的基线，正文按该基线提交链取。
            $baselines = app(CanonicalBaselines::class);
            $baseline = $baselineId === null ? $baselines->current($novel) : CanonicalBaseline::where('novel_id', $novel->id)->findOrFail($baselineId);
            if ($baseline === null || $endChapter > $baselines->length($baseline)) {
                throw new InvalidArgumentException('导出范围超出已正式提交的连续章节');
            }
            $active = $baseline->status === 'active';
            $manifest = [];
            $toc = [];
            $sections = [];
            foreach ($baselines->chain($baseline, $endChapter) as $index => $commit) {
                $number = $index + 1;
                $chapter = Chapter::where('novel_id', $novel->id)->find($commit->chapter_id);
                $version = ChapterVersion::find($commit->version_id);
                if ($chapter === null || $chapter->number !== $number || $version === null
                    || $commit->novel_id !== $novel->id || $version->chapter_id !== $chapter->id
                    || ($active && ($chapter->canonical_commit_id !== $commit->id || $chapter->formal_version_id !== $version->id))
                    || ($commit->result['body_hash'] ?? null) !== $version->body_hash
                    || hash('sha256', $version->body) !== $version->body_hash) {
                    throw new InvalidArgumentException('第'.$number.'章正式回执、版本或正文摘要不一致');
                }
                // 正式章名可能已带中文章号；只规范展示编号，正文和来源标题保持不变。
                $name = preg_replace('/^第[〇零一二三四五六七八九十百千万两0-9]+[章节回][\s：:、.\-]*/u', '', $this->singleLine($version->title));
                $title = '第'.$number.'章 '.$name;
                $toc[] = $format === 'md' ? '- '.$this->markdown($title) : $title;
                // Markdown只转义标题；正文按正式版本逐字输出，避免破坏段落和Unicode字符。
                $sections[] = ($format === 'md' ? '## '.$this->markdown($title) : $title)."\n\n".$version->body;
                $manifest[] = ['number' => $number, 'commit_id' => $commit->id, 'version_id' => $version->id,
                    'title' => $version->title, 'body_hash' => $version->body_hash];
            }
            $title = $this->singleLine($novel->title);
            $content = ($format === 'md' ? '# '.$this->markdown($title) : $title)."\n\n"
                .($format === 'md' ? '## 目录' : '目录')."\n\n".implode("\n", $toc)."\n\n".implode("\n\n", $sections)."\n";

            return ['filename' => 'novel-'.$novel->id.'-chapters-1-'.$endChapter.'.'.$format,
                'mime' => $format === 'txt' ? 'text/plain; charset=UTF-8' : 'text/markdown; charset=UTF-8',
                'content' => $content, 'manifest' => $manifest];
        });
    }

    /** 从已冻结的正式清单重建文件，不改用当前候选，也不读取后来修改的书名。
     * @return array{filename: string, mime: string, content: string, manifest: array}
     */
    public function fromManifest(int $novelId, string $bookTitle, array $manifest, string $format): array
    {
        if (! in_array($format, ['txt', 'md'], true) || $manifest === [] || ! array_is_list($manifest)) {
            throw new InvalidArgumentException('冻结导出清单或格式无效');
        }
        $toc = [];
        $sections = [];
        $previousState = 0;
        foreach ($manifest as $index => $entry) {
            $number = $index + 1;
            $version = ChapterVersion::find($entry['version_id']);
            $commit = CanonicalCommit::find($entry['commit_id']);
            if ($entry['number'] !== $number || $version === null || $commit === null || $commit->novel_id !== $novelId
                || $commit->version_id !== $version->id || $commit->chapter_id !== $version->chapter_id
                || ! Chapter::where('novel_id', $novelId)->where('number', $number)->whereKey($commit->chapter_id)->exists()
                || $commit->chapter_number !== $number || $commit->previous_state_version !== $previousState
                || $version->title !== $entry['title'] || $version->body_hash !== $entry['body_hash']
                || ($commit->result['body_hash'] ?? null) !== $version->body_hash || hash('sha256', $version->body) !== $version->body_hash) {
                throw new InvalidArgumentException('冻结清单与正式来源不一致');
            }
            $previousState = $commit->state_version;
            $name = preg_replace('/^第[〇零一二三四五六七八九十百千万两0-9]+[章节回][\s：:、.\-]*/u', '', $this->singleLine($version->title));
            $title = '第'.$number.'章 '.$name;
            $toc[] = $format === 'md' ? '- '.$this->markdown($title) : $title;
            $sections[] = ($format === 'md' ? '## '.$this->markdown($title) : $title)."\n\n".$version->body;
        }
        $title = $this->singleLine($bookTitle);
        $content = ($format === 'md' ? '# '.$this->markdown($title) : $title)."\n\n"
            .($format === 'md' ? '## 目录' : '目录')."\n\n".implode("\n", $toc)."\n\n".implode("\n\n", $sections)."\n";

        return ['filename' => 'novel-'.$novelId.'-chapters-1-'.count($manifest).'.'.$format,
            'mime' => $format === 'txt' ? 'text/plain; charset=UTF-8' : 'text/markdown; charset=UTF-8', 'content' => $content, 'manifest' => $manifest];
    }

    /** 标题只占一行，避免把标题中的换行注入目录结构。 */
    private function singleLine(string $title): string
    {
        return trim(preg_replace('/[\r\n]+/u', ' ', $title));
    }

    /** 标题中的Markdown控制字符按字面显示，不执行HTML。 */
    private function markdown(string $title): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], preg_replace('/([\\\\`*_{}\[\]()#+.!|~\-])/u', '\\\\$1', $title));
    }
}
