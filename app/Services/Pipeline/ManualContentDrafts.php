<?php

namespace App\Services\Pipeline;

use App\Enums\NovelStatus;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** 人工工作草稿与正式候选的隔离层，自动保存不启动任何模型调用。 */
final class ManualContentDrafts
{
    /** 在小说锁内调用：只有当前候选的作者可编辑，正式稿和自动编排任务不在本入口修改。 */
    public function source(int $novelId, int $versionId, User $actor): ChapterVersion
    {
        $version = ChapterVersion::whereHas('chapter', fn ($q) => $q->where('novel_id', $novelId))->findOrFail($versionId);
        $chapter = $version->chapter;
        $run = ChapterGenerationRun::findOrFail($version->run_id);
        if (! $actor->exists || ! app(BookAuthorship::class)->allows($novelId, $actor, $run->config['author_id'] ?? null) || $chapter->formal_version_id !== null
            || $chapter->current_candidate_version_id != $versionId || $chapter->novel->status !== NovelStatus::Paused
            || ! app(CanonicalBaselines::class)->writable($chapter->novel, $chapter->number)
            || ! in_array($run->status, ['paused', 'completed', 'error'], true)
            || ChapterGenerationRun::where('chapter_id', $chapter->id)->latest('id')->value('id') !== $run->id
            || ProductionRun::coveringChapter($novelId, $chapter->number, true) !== null
            || ChapterSubmissionRun::where('chapter_id', $chapter->id)->whereIn('status', ['pending', 'running'])->exists()) {
            throw ValidationException::withMessages(['editor' => '当前作者、正文版本或运行状态不允许人工编辑，请刷新工作台。']);
        }
        app(ContentGenerationService::class)->assertFrozenSources($run);

        return $version;
    }

    /** 打开编辑器不写数据库，已有自动保存只对原账号展示。 */
    public function open(int $novelId, int $versionId, User $actor): array
    {
        $version = $this->source($novelId, $versionId, $actor);
        $draft = DB::table('manual_content_drafts')->where(['chapter_id' => $version->chapter_id, 'actor_id' => $actor->id])->first();

        return ['stale' => $draft !== null && (int) $draft->base_version_id !== $versionId, 'title' => $draft?->title ?? $version->title, 'body' => $draft?->body ?? $version->body, 'revision' => (int) ($draft?->revision ?? 0)];
    }

    /** 作者显式确认后将原工作草稿关联到新候选；正文不变，也不自动发布。 */
    public function rebase(int $novelId, int $versionId, User $actor, int $revision): int
    {
        return DB::transaction(function () use ($novelId, $versionId, $actor, $revision): int {
            Novel::lockForUpdate()->findOrFail($novelId);
            $version = $this->source($novelId, $versionId, $actor);
            $updated = DB::table('manual_content_drafts')->where(['chapter_id' => $version->chapter_id, 'actor_id' => $actor->id, 'revision' => $revision])
                ->update(['base_version_id' => $versionId, 'revision' => $revision + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['editor' => '工作草稿已变化，请重新打开后合并。']);
            }

            return $revision + 1;
        });
    }

    /** 乐观锁防止两个浏览器标签互相覆盖工作正文；空正文仍可自动保存。 */
    public function save(int $novelId, int $versionId, User $actor, string $title, string $body, int $expectedRevision): int
    {
        Validator::make(['title' => $title, 'body' => $body], ['title' => 'present|string|max:255', 'body' => 'present|string|max:1000000'])->validate();

        return DB::transaction(function () use ($novelId, $versionId, $actor, $title, $body, $expectedRevision): int {
            Novel::lockForUpdate()->findOrFail($novelId);
            $version = $this->source($novelId, $versionId, $actor);
            $query = DB::table('manual_content_drafts')->where(['chapter_id' => $version->chapter_id, 'actor_id' => $actor->id]);
            $draft = $query->first();
            if ((int) ($draft?->revision ?? 0) !== $expectedRevision || ($draft && (int) $draft->base_version_id !== $versionId)) {
                throw ValidationException::withMessages(['editor' => '工作草稿已被另一标签页更新，请重新打开后合并。']);
            }
            $revision = $expectedRevision + 1;
            DB::table('manual_content_drafts')->updateOrInsert(['chapter_id' => $version->chapter_id, 'actor_id' => $actor->id],
                ['novel_id' => $novelId, 'base_version_id' => $versionId, 'title' => $title, 'body' => $body, 'revision' => $revision, 'updated_at' => now()]);

            return $revision;
        });
    }
}
