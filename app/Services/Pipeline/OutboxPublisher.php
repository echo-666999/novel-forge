<?php

namespace App\Services\Pipeline;

use App\Models\Chapter;
use App\Models\Novel;
use App\Models\StoryOutboxEvent;
use App\Services\Search\ChapterSearchIndex;
use App\Services\Search\EmbeddingService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** 投递正式提交通知：在提交事务之外按小说更新检索投影，确认投影已覆盖该提交后才写投递时间。
 * 投影构建失败时通知保持待投递，下一轮重试（只读写本地库，不产生费用）；
 * 自动补向量只在通知首次投递成功时尝试一次，失败不重试，待嵌入块留给作者手动处理。
 */
final class OutboxPublisher
{
    /** 每轮最多处理的通知数，积压时分多轮完成。 */
    public const BATCH = 500;

    /** 处理一轮待投递通知。
     * @return array{published: list<int>, pending: list<int>, failed_novels: array<int, string>, embedded_novels: list<int>, embedding_failed: array<int, string>}
     */
    public function publish(bool $embeddings = true, int $limit = self::BATCH): array
    {
        $result = ['published' => [], 'pending' => [], 'failed_novels' => [], 'embedded_novels' => [], 'embedding_failed' => []];
        $events = StoryOutboxEvent::query()->join('canonical_commits', 'canonical_commits.id', '=', 'story_outbox_events.commit_id')
            ->whereNull('story_outbox_events.published_at')->where('story_outbox_events.event_type', 'chapter.committed')
            ->orderBy('story_outbox_events.id')->limit(max(1, $limit))->get(['story_outbox_events.*', 'canonical_commits.novel_id']);
        $embeddings = $embeddings && app(GeneralSettings::class)->outbox_auto_embedding;
        $index = app(ChapterSearchIndex::class);
        foreach ($events->groupBy('novel_id') as $novelId => $novelEvents) {
            $novel = Novel::find($novelId);
            try {
                if ($novel === null) {
                    throw new RuntimeException('小说不存在');
                }
                $index->build($novel);
            } catch (Throwable $error) {
                $result['failed_novels'][(int) $novelId] = $error->getMessage();
                array_push($result['pending'], ...$novelEvents->pluck('id')->all());
                Log::warning('事务通知投递失败，下一轮重试', ['novel_id' => $novelId, 'events' => $novelEvents->count(), 'error' => $error->getMessage()]);

                continue;
            }
            $delivered = $novelEvents->filter(fn (StoryOutboxEvent $event): bool => $this->covered($event->commit_id));
            foreach ($delivered as $event) {
                // 条件更新：并发的另一轮已写投递时间时不覆盖原时间。
                StoryOutboxEvent::whereKey($event->id)->whereNull('published_at')->first()?->update(['published_at' => now()]);
                $result['published'][] = $event->id;
            }
            array_push($result['pending'], ...$novelEvents->diffKeys($delivered)->pluck('id')->all());
            if ($embeddings && $delivered->isNotEmpty() && DB::table('search_documents')->where('novel_id', $novel->id)->where('profile', EmbeddingService::PROFILE)->whereNull('embedding')->exists()) {
                try {
                    $index->build($novel, true);
                    $result['embedded_novels'][] = $novel->id;
                } catch (Throwable $error) {
                    $result['embedding_failed'][$novel->id] = $error->getMessage();
                    Log::warning('自动补向量失败，不自动重试', ['novel_id' => $novel->id, 'error' => $error->getMessage()]);
                }
            }
        }

        return $result;
    }

    /** 提交已不是任何章节的当前正式版本（已被修订或重挂取代）时无需投影；否则须已有当前代词法块。 */
    private function covered(int $commitId): bool
    {
        if (! Chapter::where('canonical_commit_id', $commitId)->exists()) {
            return true;
        }

        return DB::table('search_documents')->where('commit_id', $commitId)->where('profile', EmbeddingService::PROFILE)->whereNotNull('search_vector')->exists();
    }
}
