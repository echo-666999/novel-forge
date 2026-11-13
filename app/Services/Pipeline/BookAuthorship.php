<?php

namespace App\Services\Pipeline;

use App\Enums\NovelStatus;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\Novel;
use App\Models\NovelAuthorTakeover;
use App\Models\NovelSettingConfirmation;
use App\Models\ProductionRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** 一本书的当前作者：没有接管时沿用各运行冻结的操作人；接管后只认最新接管人，原账号不再操作。 */
final class BookAuthorship
{
    /** 最新接管人；没有接管时为空。 */
    public function takenOverBy(int $novelId): ?int
    {
        return NovelAuthorTakeover::where('novel_id', $novelId)->latest('id')->value('to_user_id');
    }

    /** 本书当前作者：最新接管人，否则为确认设定的账号。 */
    public function currentAuthorId(Novel $novel): ?int
    {
        return $this->takenOverBy($novel->id) ?? NovelSettingConfirmation::where('novel_id', $novel->id)->latest('id')->value('confirmed_by');
    }

    /** 能否以运行或确认冻结的操作人身份行事：未接管时须是原操作人，接管后只能是接管人。 */
    public function allows(int $novelId, ?User $user, ?int $recordedOperatorId): bool
    {
        return $this->allowsId($novelId, $user?->id, $recordedOperatorId);
    }

    /** 按账号编号判断，供只持有编号的入口使用。 */
    public function allowsId(int $novelId, ?int $userId, ?int $recordedOperatorId): bool
    {
        $owner = $this->takenOverBy($novelId);

        return $userId !== null && ($owner === null ? $userId === $recordedOperatorId : $userId === $owner);
    }

    /** 需要使用的账号名称，用于提示。 */
    public function requiredAccountName(int $novelId, ?int $recordedOperatorId): string
    {
        $id = $this->takenOverBy($novelId) ?? $recordedOperatorId;

        return User::find($id)?->name ?? '#'.$id;
    }

    /** 作者接管本书：本书没有进行中的生成、提交或未完成的自动授权时才能接管，接管人与原因留档。 */
    public function takeOver(Novel $reference, User $actor, string $reason): NovelAuthorTakeover
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('请写明接管原因');
        }

        return DB::transaction(function () use ($reference, $actor, $reason): NovelAuthorTakeover {
            $novel = Novel::lockForUpdate()->findOrFail($reference->id);
            $from = $this->currentAuthorId($novel);
            if ($from === null) {
                throw new InvalidArgumentException('本书尚未确认设定，没有可接管的作者');
            }
            if ($from === $actor->id) {
                throw new InvalidArgumentException('你已经是本书作者');
            }
            if (in_array($novel->status, [NovelStatus::Pending, NovelStatus::Running], true)
                || ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id))->whereIn('status', ['pending', 'running'])->exists()
                || ChapterSubmissionRun::where('novel_id', $novel->id)->whereIn('status', ['pending', 'running'])->exists()) {
                throw new InvalidArgumentException('本书还有进行中的生成或提交，等它停下后再接管');
            }
            // 自动授权绑定原作者与冻结策略，接管不能转移未完成的自动授权。
            if (ProductionRun::activeFor($novel->id) !== null) {
                throw new InvalidArgumentException('本书有未完成的自动生成授权，请先让其完成或放弃后再接管');
            }

            return NovelAuthorTakeover::create(['novel_id' => $novel->id, 'from_user_id' => $from, 'to_user_id' => $actor->id, 'reason' => trim($reason), 'created_at' => now()]);
        });
    }
}
