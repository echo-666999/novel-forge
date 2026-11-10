<?php

namespace App\Services\Pipeline;

use App\Models\Chapter;
use App\Models\ChapterVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 从私有验收证据读取已核验的新稿，绝不将对照稿伪装成正式章节版本。 */
final class AcceptanceComparison
{
    /** 反馈只追加到对应稿件的私有证据目录，不改变正式版本或整批验收结论。
     * @param  array<string, mixed>  $data
     */
    public function feedback(Chapter $chapter, int $actorId, array $data): void
    {
        $data = Validator::make($data, [
            'fingerprint' => ['required', 'string'],
            'decision' => ['required', 'in:approve,revise'],
            'comment' => ['required', 'string', 'max:10000', 'regex:/\S/u'],
        ])->validate();
        $comparison = $this->latest($chapter);
        if ($comparison === null || ! hash_equals($this->fingerprint($comparison), $data['fingerprint'])) {
            throw ValidationException::withMessages(['comment' => '对照稿已变化，请关闭后重新阅读最新稿再反馈。']);
        }
        $payload = ['novel_id' => (int) $chapter->novel_id, 'chapter_id' => $chapter->id,
            'source_version_id' => $comparison['source_version_id'], 'body_sha256' => hash('sha256', $comparison['body']),
            'actor_id' => $actorId, 'created_at' => now()->toIso8601String(),
            'decision' => $data['decision'], 'comment' => trim($data['comment']),
            'scope' => 'comparison_feedback_only'];
        // 每次提交使用独立文件保留历史，写入失败必须明确提示，不能显示成功。
        if (! Storage::disk('local')->put('c10-acceptance-evidence/'.$comparison['evidence'].'/feedback/'.Str::uuid().'.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw ValidationException::withMessages(['comment' => '反馈保存失败，请重试。']);
        }
    }

    /** 将目录、来源和正文绑定，防止打开弹层后新稿变化而误记反馈。
     * @param  array<string, mixed>  $comparison
     */
    public function fingerprint(array $comparison): string
    {
        return hash('sha256', $comparison['evidence'].'|'.$comparison['source_version_id'].'|'.$comparison['title'].'|'.$comparison['body']);
    }

    /** 只展示当前稿件的反馈，正文由界面组件转义。
     * @param  array<string, mixed>  $comparison
     */
    public function feedbackHistory(array $comparison): string
    {
        $rows = [];
        $disk = Storage::disk('local');
        foreach ($disk->files('c10-acceptance-evidence/'.$comparison['evidence'].'/feedback') as $path) {
            $row = json_decode($disk->get($path), true);
            if (! is_array($row) || ($row['body_sha256'] ?? null) !== hash('sha256', $comparison['body'])
                || ($row['source_version_id'] ?? null) !== $comparison['source_version_id']) {
                continue;
            }
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return implode("\n\n", array_map(fn (array $row): string => $row['created_at'].' · 用户 #'.$row['actor_id'].' · '.($row['decision'] === 'approve' ? '认可本稿' : '需要修改')."\n".$row['comment'], $rows)) ?: '尚无反馈';
    }

    /** 按完成时间选择本章最新有效对照稿；来源和正文摘要不符时不展示。
     * @return array{title: string, body: string, characters: int, source_version_id: int, completed_at: string, timestamp: int, evidence: string}|null
     */
    public function latest(Chapter $chapter): ?array
    {
        $disk = Storage::disk('local');
        $candidates = [];
        foreach ($disk->directories('c10-acceptance-evidence') as $directory) {
            if (! $disk->exists($directory.'/manifest.json') || ! $disk->exists($directory.'/result.json') || ! $disk->exists($directory.'/candidate.json')) {
                continue;
            }
            try {
                $manifest = json_decode($disk->get($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
                $result = json_decode($disk->get($directory.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
                if (($manifest['source_novel_id'] ?? null) !== (int) $chapter->novel_id
                    || ($result['status'] ?? null) !== 'first_chapter_technical_pass_human_pending') {
                    continue;
                }
                $source = ChapterVersion::where('chapter_id', $chapter->id)->find($manifest['source_version_id'] ?? 0);
                if ($source === null || $source->body_hash !== ($manifest['source_hash'] ?? null)
                    || hash('sha256', $source->body) !== $source->body_hash) {
                    continue;
                }
                $candidate = ContentSchema::body(json_decode($disk->get($directory.'/candidate.json'), true, flags: JSON_THROW_ON_ERROR));
                $count = ContentSchema::count($candidate['body']);
                if (hash('sha256', $candidate['body']) !== ($result['final_body_sha256'] ?? null)
                    || $count !== ($result['final_candidate_characters'] ?? null)) {
                    continue;
                }
                $completed = CarbonImmutable::parse($result['completed_at']);
                $candidates[] = [...$candidate, 'characters' => $count, 'source_version_id' => $source->id,
                    'completed_at' => $completed->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    'timestamp' => $completed->getTimestamp(), 'evidence' => basename($directory)];
            } catch (Throwable $exception) {
                // 单份损坏证据不能使整个章节目录不可用，但必须留下可定位的本地告警。
                report($exception);
            }
        }
        usort($candidates, fn (array $a, array $b): int => ($b['timestamp'] <=> $a['timestamp']) ?: strcmp($b['evidence'], $a['evidence']));

        return $candidates[0] ?? null;
    }
}
