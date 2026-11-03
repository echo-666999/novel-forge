<?php

namespace App\Services\Pipeline;

use App\Enums\NovelStatus;
use App\Jobs\RunNovelPipelineJob;
use App\Models\Novel;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Throwable;

/** 创建小说并异步投递模拟流程，运行状态不接受客户端赋值。 */
final class CreateNovelService
{
    /** 校验业务字段，先提交小说，再投递 Redis；投递失败仍保留原始创意。
     * @param  array<string, mixed>  $input
     */
    public function createAndStart(array $input): NovelCreationResult
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('请在数据库事务之外创建并启动小说');
        }
        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:255', 'regex:/(*UCP)\S/u'],
            'genre' => ['nullable', 'string', 'max:255'],
            'premise_raw' => ['required', 'string', 'regex:/(*UCP)\S/u'],
            'target_chapters' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'chapter_length_policy' => ['required', 'array:min_characters,max_characters'],
            'chapter_length_policy.min_characters' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'chapter_length_policy.max_characters' => ['required', 'integer', 'min:1', 'max:2147483647', 'gte:chapter_length_policy.min_characters'],
        ], [
            'required' => '请填写:attribute。', 'string' => ':attribute必须为文本。',
            'max' => ':attribute超出允许范围。', 'min' => ':attribute必须大于0。',
            'integer' => ':attribute必须为整数。', 'regex' => ':attribute不能只包含空白。',
            'array' => '篇幅策略格式不正确。', 'gte' => '每章字数上限不得小于下限。',
        ], [
            'title' => '书名', 'genre' => '题材', 'premise_raw' => '原始创意', 'target_chapters' => '目标章数',
            'chapter_length_policy.min_characters' => '每章字数下限', 'chapter_length_policy.max_characters' => '每章字数上限',
        ])->validate();
        $settings = app(GeneralSettings::class);
        $limit = $settings->max_execution_count;
        $fineOutline = $settings->enable_fine_outline;
        if ($limit < 1 || $limit > 2147483647) {
            throw new LogicException('通用设置中的执行次数上限不合法');
        }
        // 只写入白名单字段；创意原文不摘要、不改写，篇幅数值统一保存为整数。
        $novel = DB::transaction(fn (): Novel => Novel::query()->create([
            'title' => trim($data['title']),
            'genre' => filled($data['genre'] ?? null) ? trim($data['genre']) : null,
            'premise_raw' => $data['premise_raw'],
            'target_chapters' => (int) $data['target_chapters'],
            'chapter_length_policy' => [
                'min_characters' => (int) $data['chapter_length_policy']['min_characters'],
                'max_characters' => (int) $data['chapter_length_policy']['max_characters'],
            ],
            'max_execution_count' => $limit,
        ]));

        try {
            // 显式分发而非依赖 PendingDispatch 析构，确保投递异常在本处捕获。
            Bus::dispatch(new RunNovelPipelineJob($novel->id, $fineOutline));

            return new NovelCreationResult($novel->refresh(), true);
        } catch (Throwable $exception) {
            // Redis 回执失败时任务可能已被领取，不能覆盖正在运行或已完成的状态。
            Novel::query()->whereKey($novel->id)->where('status', NovelStatus::Pending->value)
                ->where('execution_count', 0)->whereDoesntHave('stages')->update(['status' => NovelStatus::Error]);
            Log::warning('小说已保存，启动投递未确认', ['novel_id' => $novel->id, 'error_category' => class_basename($exception)]);

            return new NovelCreationResult($novel->refresh(), false);
        }
    }
}
