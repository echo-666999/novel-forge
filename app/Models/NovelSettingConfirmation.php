<?php

namespace App\Models;

use App\Services\Pipeline\PlanningJson;
use Database\Factories\NovelSettingConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 作者显式确认的不可变设定快照，与供应商候选及正式剧情分离。 */
#[Fillable(['novel_id', 'source_stage_id', 'source_hash', 'setting', 'decisions', 'content_hash', 'confirmed_by', 'confirmed_at'])]
class NovelSettingConfirmation extends Model
{
    /** @use HasFactory<NovelSettingConfirmationFactory> */
    use HasFactory;

    public $timestamps = false;

    /** 防止普通模型保存或删除改写已确认内容。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('确认快照不可修改，请创建新版本'));
        static::deleting(fn () => throw new LogicException('确认快照不可删除'));
    }

    /** 结构化设定及时间按原类型读回。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['setting' => 'array', 'decisions' => 'array', 'confirmed_at' => 'immutable_datetime'];
    }

    /** 来源设定尝试。
     * @return BelongsTo<NovelStage, $this>
     */
    public function sourceStage(): BelongsTo
    {
        return $this->belongsTo(NovelStage::class, 'source_stage_id');
    }

    /** 所属小说。
     * @return BelongsTo<Novel, $this>
     */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** 复验快照摘要，阻止绕过模型事件的外部写入被静默采用。 */
    public function hasValidHash(): bool
    {
        return hash_equals($this->content_hash, PlanningJson::hash(['source_hash' => $this->source_hash, 'setting' => $this->setting, 'decisions' => $this->decisions]));
    }
}
