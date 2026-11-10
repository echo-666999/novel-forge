<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Jobs\RunNovelPipelineJob;
use App\Jobs\RunSettingJob;
use App\Models\Novel;
use App\Services\Ai\PromptTemplateManager;
use App\Services\Ai\TextRequestOptions;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** 创建小说并按显式模式异步投递流程，运行状态不接受客户端赋值。 */
final class CreateNovelService
{
    /** 补充创作要求的字段与中文名称。 */
    public const BRIEF_FIELDS = ['target_readers' => '目标读者', 'selling_points' => '核心卖点', 'forbidden_content' => '禁用内容', 'style' => '文风', 'ending_preference' => '结局偏好'];

    /** 校验业务字段，先提交小说，再投递 Redis；投递失败仍保留原始创意。
     * @param  array<string, mixed>  $input
     */
    public function createAndStart(array $input, bool $dispatch = true, ?string $modelOverride = null): NovelCreationResult
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('请在数据库事务之外创建并启动小说');
        }
        $data = Validator::make($input, [
            'generation_mode' => ['sometimes', 'required', 'in:simulation,setting_real'],
            'title' => ['required', 'string', 'max:255', 'regex:/(*UCP)\S/u'],
            'genre' => ['nullable', 'string', 'max:255'],
            'premise_raw' => ['required', 'string', 'regex:/(*UCP)\S/u'],
            'target_chapters' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'chapter_length_policy' => ['required', 'array:min_characters,max_characters'],
            'chapter_length_policy.min_characters' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'chapter_length_policy.max_characters' => ['required', 'integer', 'min:1', 'max:2147483647', 'gte:chapter_length_policy.min_characters'],
            // 补充创作要求可选，原文保存，进入设定、大纲和正文的冻结输入。
            'creative_brief' => ['nullable', 'array:'.implode(',', array_keys(self::BRIEF_FIELDS))],
            ...collect(self::BRIEF_FIELDS)->mapWithKeys(fn (string $label, string $key): array => ['creative_brief.'.$key => ['nullable', 'string', 'max:2000']])->all(),
            'budget_amount' => ['nullable', 'regex:/\A\d{1,12}(?:\.\d{1,8})?\z/'],
            'budget_currency' => ['nullable', 'required_with:budget_amount', 'in:CNY,USD'],
        ], [
            'required' => '请填写:attribute。', 'string' => ':attribute必须为文本。',
            'max' => ':attribute超出允许范围。', 'min' => ':attribute必须大于0。',
            'integer' => ':attribute必须为整数。', 'regex' => ':attribute不能只包含空白。',
            'in' => '生成模式不合法。', 'array' => '篇幅策略格式不正确。', 'gte' => '每章字数上限不得小于下限。',
        ], [
            'generation_mode' => '生成模式', 'title' => '书名', 'genre' => '题材', 'premise_raw' => '原始创意', 'target_chapters' => '目标章数',
            'chapter_length_policy.min_characters' => '每章字数下限', 'chapter_length_policy.max_characters' => '每章字数上限',
            'budget_amount' => '预算金额', 'budget_currency' => '预算币种',
            ...collect(self::BRIEF_FIELDS)->mapWithKeys(fn (string $label, string $key): array => ['creative_brief.'.$key => $label])->all(),
        ])->validate();
        $brief = array_filter(array_map(fn ($value): string => trim((string) $value), $data['creative_brief'] ?? []), fn (string $value): bool => $value !== '');
        $budget = filled($data['budget_amount'] ?? null) ? (string) $data['budget_amount'] : null;
        $mode = GenerationMode::from($data['generation_mode'] ?? 'simulation');
        $settings = app(GeneralSettings::class);
        $limit = $settings->max_execution_count;
        $fineOutline = $settings->enable_fine_outline;
        if ($limit < 1 || $limit > 2147483647) {
            throw new LogicException('通用设置中的执行次数上限不合法');
        }
        // 真实调用参数只能由服务端冻结，不接受表单伪造的配置快照。
        $config = $mode === GenerationMode::SettingReal ? [
            'invocation_id' => (string) Str::uuid7(),
            'model' => trim($modelOverride ?? $settings->default_model),
            'enable_fine_outline' => $fineOutline,
            ...TextRequestOptions::setting($settings->temperature)->toArray(),
            'prompt_version' => 'b02-setting-prompt-v1',
            // 模板正文、版本与变量在小说根运行创建时冻结，恢复和后续章节沿用此快照。
            'prompt_templates' => app(PromptTemplateManager::class)->snapshot(),
            'prompt_variables' => ['title' => trim($data['title']), 'genre' => $data['genre'] ?? ''],
        ] : null;
        if ($config !== null && ($config['model'] === '' || strlen($config['model']) > 128)) {
            throw new LogicException('默认模型标识不合法');
        }
        // 只写入白名单字段；创意原文不摘要、不改写，篇幅数值统一保存为整数。
        $novel = DB::transaction(fn (): Novel => Novel::query()->create([
            'generation_mode' => $mode,
            'generation_config' => $config,
            'title' => trim($data['title']),
            'genre' => filled($data['genre'] ?? null) ? trim($data['genre']) : null,
            'premise_raw' => $data['premise_raw'],
            'target_chapters' => (int) $data['target_chapters'],
            'chapter_length_policy' => [
                'min_characters' => (int) $data['chapter_length_policy']['min_characters'],
                'max_characters' => (int) $data['chapter_length_policy']['max_characters'],
            ],
            'max_execution_count' => $limit,
            'creative_brief' => $brief === [] ? null : $brief,
            'budget_amount' => $budget,
            'budget_currency' => $budget === null ? null : $data['budget_currency'],
            'budget_history' => $budget === null ? [] : [['from' => null, 'to' => $budget, 'to_currency' => $data['budget_currency'], 'reason' => '建书时设定', 'at' => now()->toIso8601String()]],
        ]));

        // 自动首章由独立编排器接管，避免旧队列重复领取。
        if (! $dispatch) {
            return new NovelCreationResult($novel, true);
        }

        try {
            // 显式分发而非依赖 PendingDispatch 析构，确保投递异常在本处捕获。
            Bus::dispatch($mode === GenerationMode::SettingReal ? new RunSettingJob($novel->id, $config['invocation_id']) : new RunNovelPipelineJob($novel->id, $fineOutline));

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
