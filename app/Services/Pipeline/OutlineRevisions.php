<?php

namespace App\Services\Pipeline;

use App\Enums\NovelStatus;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\OutlineChapterRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** 未提交章节的章纲修订：只追加版本，原大纲章节不变，新运行冻结时叠加最新修订并记录修订身份。 */
final class OutlineRevisions
{
    /** 允许修订的章纲字段；章名和章序不改，避免与已建章节或目录不一致。 */
    public const FIELDS = ['summary', 'objective', 'conflict', 'hook', 'progression_points'];

    /** 推进点可修订的文本字段，编号与结构保持原章纲。 */
    public const POINT_FIELDS = ['subject', 'before', 'action', 'after', 'verification', 'change_type'];

    /** 保存新修订；章节已正式提交或正文正在生成时拒绝，乐观锁防止覆盖他人的新版本。
     * @param  array{summary: string, objective: string, conflict: string, hook: string, progression_points: list<array<string, string>>}  $data
     */
    public function revise(int $novelId, int $outlineChapterId, User $actor, array $data, string $reason, ?int $expectedId): OutlineChapterRevision
    {
        abort_unless($actor->exists, 403);

        return DB::transaction(function () use ($novelId, $outlineChapterId, $actor, $data, $reason, $expectedId): OutlineChapterRevision {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $outline = NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $novelId))->findOrFail($outlineChapterId);
            $latest = $this->latest($outline->id);
            if ($latest?->id !== $expectedId) {
                throw ValidationException::withMessages(['expected_id' => '已有新的章纲修订，请刷新后重试。']);
            }
            $chapter = Chapter::where('novel_id', $novelId)->where('number', $outline->chapter_number)->first();
            if ($chapter?->formal_version_id !== null || $outline->chapter_number <= $novel->chapters_done) {
                throw ValidationException::withMessages(['hook' => '此章已正式提交，正式历史不能通过章纲修订改变。']);
            }
            if (in_array($novel->status, [NovelStatus::Running, NovelStatus::Pending], true)) {
                throw ValidationException::withMessages(['hook' => '正文正在生成，请等当前步骤停下后再修订章纲。']);
            }
            if (trim($reason) === '' || mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages(['reason' => '请填写不超过2000字的修订原因。']);
            }
            $content = $this->normalize($outline, $data);
            $base = $latest === null ? $this->original($outline) : $this->content($latest);
            if (PlanningJson::hash($content) === PlanningJson::hash($base)) {
                throw ValidationException::withMessages(['hook' => '章纲没有变化，无需保存新版本。']);
            }

            return OutlineChapterRevision::create(['novel_id' => $novelId, 'outline_chapter_id' => $outline->id, 'parent_id' => $expectedId, ...$content,
                'content_hash' => PlanningJson::hash($content), 'reason' => $reason, 'actor_id' => $actor->id, 'created_at' => now()]);
        });
    }

    /** 读取某大纲章节的最新修订。 */
    public function latest(int $outlineChapterId): ?OutlineChapterRevision
    {
        return OutlineChapterRevision::where('outline_chapter_id', $outlineChapterId)->latest('id')->first();
    }

    /** 新运行冻结用的章计划：原章纲叠加最新修订，并返回修订身份供后续核对。
     * @return array{source_chapter: array<string, mixed>, revision: array{id: int, content_hash: string}|null}
     */
    public function freeze(NovelOutlineChapter $outline): array
    {
        $latest = $this->latest($outline->id);
        $reference = $latest === null ? null : ['id' => $latest->id, 'content_hash' => $latest->content_hash];

        return ['source_chapter' => $this->expected($outline, $reference), 'revision' => $reference];
    }

    /** 按快照记录的修订身份重建冻结章计划；修订不属于该章或内容摘要不符时拒绝。
     * @param  array{id: int, content_hash: string}|null  $reference
     * @return array<string, mixed>
     */
    public function expected(NovelOutlineChapter $outline, ?array $reference): array
    {
        $attributes = $outline->attributesToArray();
        if ($reference === null) {
            return $attributes;
        }
        $revision = OutlineChapterRevision::where('outline_chapter_id', $outline->id)->findOrFail($reference['id']);
        $content = $this->content($revision);
        if ($revision->content_hash !== $reference['content_hash'] || PlanningJson::hash($content) !== $revision->content_hash) {
            throw new InvalidArgumentException('章纲修订内容与冻结摘要不一致');
        }

        return array_replace($attributes, $content);
    }

    /** 修订版本的章纲内容。
     * @return array<string, mixed>
     */
    public function content(OutlineChapterRevision $revision): array
    {
        return ['summary' => $revision->summary, 'objective' => $revision->objective, 'conflict' => $revision->conflict,
            'hook' => $revision->hook, 'progression_points' => $revision->progression_points];
    }

    /** 原章纲中可修订的部分。
     * @return array<string, mixed>
     */
    public function original(NovelOutlineChapter $outline): array
    {
        return $outline->only(self::FIELDS);
    }

    /** 校验并按原章纲的推进点编号与字段顺序整理修订内容。
     * @return array<string, mixed>
     */
    private function normalize(NovelOutlineChapter $outline, array $data): array
    {
        $errors = [];
        $content = [];
        foreach (['summary', 'objective', 'conflict', 'hook'] as $field) {
            $value = $data[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 5000) {
                $errors[$field] = '请填写不超过5000字的内容。';
            } else {
                $content[$field] = trim($value);
            }
        }
        $points = array_values($data['progression_points'] ?? []);
        $original = $outline->progression_points;
        if (count($points) !== count($original)) {
            $errors['progression_points'] = '推进点数量须与原章纲一致，编号不能增删。';
        } else {
            foreach ($original as $index => $source) {
                $point = $points[$index];
                if (($point['id'] ?? null) !== $source['id']) {
                    $errors['progression_points'] = '推进点编号须与原章纲一致并保持顺序。';

                    break;
                }
                $revised = [];
                foreach (array_keys($source) as $key) {
                    $value = $key === 'id' ? $source['id'] : ($point[$key] ?? null);
                    if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) {
                        $errors['progression_points'] = $source['id'].' 的每一项都必须填写。';

                        break 2;
                    }
                    $revised[$key] = trim($value);
                }
                $compact = fn (string $text): string => preg_replace('/[\s\p{Z}\p{Cf}\p{P}]/u', '', $text);
                if (! in_array($revised['change_type'], OutlineSchema::CHANGE_TYPES, true) || $compact($revised['before']) === $compact($revised['after'])) {
                    $errors['progression_points'] = $source['id'].' 缺少可辨认的前后变化或变化类型无效。';

                    break;
                }
                $content['progression_points'][] = $revised;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['summary' => $content['summary'], 'objective' => $content['objective'], 'conflict' => $content['conflict'],
            'hook' => $content['hook'], 'progression_points' => $content['progression_points']];
    }
}
