<?php

namespace App\Models;

use Database\Factories\StoryPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** 题材包（genre）或文风预设（style）：后台可增删、可停用，建书时整份复制，之后修改不影响已建的书。 */
#[Fillable(['kind', 'key', 'name', 'description', 'content', 'is_builtin', 'version', 'disabled_at'])]
class StoryPreset extends Model
{
    /** @use HasFactory<StoryPresetFactory> */
    use HasFactory;

    public const KINDS = ['genre' => '题材包', 'style' => '文风预设'];

    /** 结构化内容与停用时间。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['content' => 'array', 'is_builtin' => 'boolean', 'version' => 'integer', 'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** 建书下拉框只列启用中的项。
     * @param  Builder<StoryPreset>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }

    /** 某类启用中的选项，按内置在前、编号排序。
     * @return array<string, string>
     */
    public static function options(string $kind): array
    {
        return self::query()->enabled()->where('kind', $kind)->orderByDesc('is_builtin')->orderBy('id')->pluck('name', 'key')->all();
    }
}
