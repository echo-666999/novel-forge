<?php

namespace App\Services\Pipeline;

use App\Jobs\BuildNovelExport;
use App\Models\Novel;
use App\Models\NovelExport;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** 导出文件的私有生命周期，冻结来源与任务历史永不随文件清理而删除。 */
final class NovelExportLifecycle
{
    /** 创建时同步预检完整来源，文件写入交给专用队列。 */
    public function request(int $novelId, User $actor, int $end, string $format): NovelExport
    {
        if (! $actor->exists) {
            throw ValidationException::withMessages(['export' => '需要有效的后台账号。']);
        }
        $record = DB::transaction(function () use ($novelId, $actor, $end, $format): NovelExport {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $file = app(FormalChapterExport::class)->build($novelId, $end, $format);
            $snapshot = ['novel_id' => $novelId, 'book_title' => $novel->title, 'end_chapter' => $end, 'format' => $format,
                'manifest' => $file['manifest'], 'filename' => $file['filename'], 'mime' => $file['mime'], 'content_hash' => hash('sha256', $file['content']), 'retention_days' => 7];

            return $this->create($actor, $snapshot);
        });
        $this->dispatch($record);

        return $record->refresh();
    }

    /** 重新生成使用原清单和书名，另建任务保留前次失败、删除及下载证据。 */
    public function regenerate(int $novelId, int $id, User $actor): NovelExport
    {
        $record = DB::transaction(function () use ($novelId, $id, $actor): NovelExport {
            $source = $this->owned($novelId, $id, $actor, true);
            if (in_array($source->status, ['queued', 'building'], true)) {
                throw ValidationException::withMessages(['export' => '原任务仍在排队或生成，不能重复派发。']);
            }
            $this->snapshot($source);

            return $this->create($actor, $source->snapshot, $source->id);
        });
        $this->dispatch($record);

        return $record->refresh();
    }

    /** 队列消息只领用queued一次，迟到或重复消息不重新写文件。 */
    public function build(int $id): void
    {
        $record = DB::transaction(function () use ($id): ?NovelExport {
            $record = NovelExport::lockForUpdate()->findOrFail($id);
            if ($record->status !== 'queued') {
                return null;
            }
            $record->update(['status' => 'building', 'started_at' => now(), 'history' => $this->history($record, 'building')]);

            return $record;
        });
        if ($record === null) {
            return;
        }
        try {
            $file = $this->render($record);
            $path = $this->path($record);
            $disk = Storage::disk('local');
            if (! $disk->put($path.'.part', $file['content'])) {
                throw new InvalidArgumentException('私有文件写入失败');
            }
            DB::transaction(function () use ($record, $file, $path, $disk): void {
                $locked = NovelExport::lockForUpdate()->findOrFail($record->id);
                if ($locked->status !== 'building') {
                    $disk->delete($path.'.part');

                    return;
                }
                if (! $disk->move($path.'.part', $path)) {
                    throw new InvalidArgumentException('私有文件发布失败');
                }
                $locked->update(['status' => 'ready', 'byte_size' => strlen($file['content']), 'completed_at' => now(),
                    'expires_at' => now()->addDays($locked->snapshot['retention_days']), 'history' => $this->history($locked, 'ready')]);
            });
        } catch (Throwable) {
            $this->fail($id, '文件生成失败或冻结来源不一致；未输出部分文件，请检查后按原清单重新生成。');
        }
    }

    /** 下载前核验归属、保留期、来源及文件摘要，输出已冻结的完整字节。 */
    public function download(int $novelId, int $id, User $actor): array
    {
        $record = $this->owned($novelId, $id, $actor);
        if ($record->status === 'ready' && $record->expires_at?->isPast()) {
            $this->remove($novelId, $id, $actor, 'expired');
            throw ValidationException::withMessages(['export' => '文件已过期，可按原冻结清单重新生成。']);
        }

        $result = DB::transaction(function () use ($novelId, $id, $actor): array {
            $record = $this->owned($novelId, $id, $actor, true);
            if ($record->status !== 'ready' || $record->deleted_at !== null || $record->expires_at === null || $record->expires_at->isPast()) {
                throw ValidationException::withMessages(['export' => '文件尚未就绪、已删除或已过期。']);
            }
            try {
                $expected = $this->render($record);
                $content = Storage::disk('local')->get($this->path($record));
                if (! is_string($content) || hash('sha256', $content) !== $record->snapshot['content_hash'] || strlen($content) !== $record->byte_size) {
                    throw new InvalidArgumentException('文件摘要不一致');
                }
            } catch (Throwable) {
                $message = '文件或正式来源缺失、损坏，拒绝下载；可检查后重新生成。';
                $record->update(['status' => 'failed', 'error' => $message, 'history' => $this->history($record, 'download_rejected', $actor->id)]);

                return ['error' => $message];
            }
            $record->update(['history' => $this->history($record, 'downloaded', $actor->id)]);

            return ['filename' => $expected['filename'], 'mime' => $expected['mime'], 'content' => $content];
        });
        if (isset($result['error'])) {
            throw ValidationException::withMessages(['export' => $result['error']]);
        }

        return $result;
    }

    /** 删除只影响该任务的私有派生文件，不删除正式正文及冻结清单。 */
    public function remove(int $novelId, int $id, User $actor, string $action = 'deleted'): void
    {
        if (! in_array($action, ['deleted', 'expired'], true)) {
            throw new InvalidArgumentException('不支持的文件清理动作');
        }
        DB::transaction(function () use ($novelId, $id, $actor, $action): void {
            $record = $this->owned($novelId, $id, $actor, true);
            if ($record->deleted_at !== null) {
                return;
            }
            if (in_array($record->status, ['queued', 'building'], true)) {
                throw ValidationException::withMessages(['export' => '生成期间不可删除文件。']);
            }
            $this->clearFile($record, $action, $actor->id);
        });
    }

    /** 消费者异常或超时保留失败状态，清理可能生成的半文件。 */
    public function fail(int $id, string $message): void
    {
        DB::transaction(function () use ($id, $message): void {
            $record = NovelExport::lockForUpdate()->find($id);
            if ($record === null || ! in_array($record->status, ['queued', 'building'], true)) {
                return;
            }
            $record->update(['status' => 'failed', 'error' => $message, 'history' => $this->history($record, 'failed')]);
            try {
                $path = $this->path($record);
                Storage::disk('local')->delete([$path, $path.'.part']);
            } catch (Throwable) {
                // 状态已失败且不可下载，稍后清理命令会重试删除残留派生文件。
            }
        });
    }

    /** 定时清理过期文件并隔离超时消费者，无需模型调用或生产内容恢复。 */
    public function prune(): int
    {
        foreach (NovelExport::where('status', 'building')->where('started_at', '<', now()->subMinutes(10))->pluck('id') as $id) {
            $this->fail($id, '消费者已超过执行时限，任务已隔离，可按原清单重新生成。');
        }
        $count = 0;
        foreach (NovelExport::whereNull('deleted_at')->where(function ($query): void {
            $query->where(fn ($ready) => $ready->where('status', 'ready')->where('expires_at', '<=', now()))
                ->orWhere(fn ($failed) => $failed->where('status', 'failed')->where('created_at', '<=', now()->subDays(7)));
        })->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $record = NovelExport::lockForUpdate()->findOrFail($id);
                if ($record->deleted_at === null && (($record->status === 'ready' && $record->expires_at?->isPast())
                    || ($record->status === 'failed' && $record->created_at->lt(now()->subDays(7))))) {
                    $this->clearFile($record, 'expired', null);
                    $count++;
                }
            });
        }

        return $count;
    }

    /** 持有任务行锁时删除确定路径，系统清理不依赖创建账号仍然存在。 */
    private function clearFile(NovelExport $record, string $action, ?int $actorId): void
    {
        $path = $this->path($record);
        if (! Storage::disk('local')->delete([$path, $path.'.part'])) {
            throw ValidationException::withMessages(['export' => '文件清理失败，未标记删除，请重试。']);
        }
        $record->update(['status' => 'expired', 'deleted_at' => now(), 'history' => $this->history($record, $action, $actorId)]);
    }

    /** 创建新任务，不在任何旧任务上替换冻结来源。 */
    private function create(User $actor, array $snapshot, ?int $parentId = null): NovelExport
    {
        return NovelExport::create(['uuid' => (string) Str::uuid7(), 'novel_id' => $snapshot['novel_id'], 'actor_id' => $actor->id, 'parent_id' => $parentId,
            'format' => $snapshot['format'], 'end_chapter' => $snapshot['end_chapter'], 'snapshot' => $snapshot, 'snapshot_hash' => PlanningJson::hash($snapshot),
            'status' => 'queued', 'history' => [['action' => $parentId === null ? 'requested' : 'regenerated', 'actor_id' => $actor->id, 'at' => now()->toIso8601String()]], 'created_at' => now()]);
    }

    /** 入队失败保留任务，不能显示不存在的排队成功。 */
    private function dispatch(NovelExport $record): void
    {
        try {
            Bus::dispatch(new BuildNovelExport($record->id));
        } catch (Throwable) {
            $this->fail($record->id, '导出入队失败，未生成文件，可按冻结清单重建任务。');
        }
    }

    /** 查询固定当前小说和账号，不能通过伪造任务编号跨书或跨账号下载。 */
    private function owned(int $novelId, int $id, User $actor, bool $lock = false): NovelExport
    {
        if (! $actor->exists) {
            throw ValidationException::withMessages(['export' => '需要有效账号。']);
        }
        $query = NovelExport::where('novel_id', $novelId)->where('actor_id', $actor->id);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    /** 快照摘要、服务端任务范围和路径字段必须一致。 */
    private function snapshot(NovelExport $record): void
    {
        if ($record->snapshot_hash !== PlanningJson::hash($record->snapshot) || $record->snapshot['novel_id'] !== $record->novel_id
            || $record->snapshot['format'] !== $record->format || $record->snapshot['end_chapter'] !== $record->end_chapter
            || count($record->snapshot['manifest']) !== $record->end_chapter || ($record->snapshot['retention_days'] ?? null) !== 7) {
            throw ValidationException::withMessages(['export' => '冻结导出清单已损坏。']);
        }
    }

    /** 固定目录与服务端UUID，永不使用表单提供的文件路径。 */
    private function path(NovelExport $record): string
    {
        if (! Str::isUuid($record->uuid) || ! in_array($record->format, ['txt', 'md'], true)) {
            throw new InvalidArgumentException('文件标识无效');
        }

        return 'novel-exports/'.$record->uuid.'.'.$record->format;
    }

    /** 重新校验不可变正文和正式回执，并核对与请求时完全一致的文件摘要。 */
    private function render(NovelExport $record): array
    {
        $this->snapshot($record);
        $file = app(FormalChapterExport::class)->fromManifest($record->novel_id, $record->snapshot['book_title'], $record->snapshot['manifest'], $record->format);
        if (hash('sha256', $file['content']) !== $record->snapshot['content_hash'] || $file['filename'] !== $record->snapshot['filename'] || $file['mime'] !== $record->snapshot['mime']) {
            throw new InvalidArgumentException('冻结输出摘要不一致');
        }

        return $file;
    }

    /** 保留原审计顺序，下载和清理都有明确操作者及时间。 */
    private function history(NovelExport $record, string $action, ?int $actorId = null): array
    {
        return [...$record->history, ['action' => $action, 'actor_id' => $actorId, 'at' => now()->toIso8601String()]];
    }
}
