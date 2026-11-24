<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings as BaseSettings;

/**
 * 小说生成的通用参数，与 general 分组中的持久化设置对应。
 * 只在新书或新运行创建时读取并冻结，修改不影响已开始的运行。
 */
class GeneralSettings extends BaseSettings
{
    public string $default_model = 'gpt-4.1-mini';

    public float $temperature = 0.7;

    /** 生成正文弹窗中“本次正文输出Token上限”的默认值。 */
    public int $max_tokens_per_chapter = 8192;

    /** 推进检查不通过时，在同一运行内自动定点修订并复查一次（占用 2 次请求）。 */
    public bool $auto_revise_on_check_failure = true;

    /** 新书创建时的执行次数上限。 */
    public int $max_execution_count = 100;

    public bool $enable_fine_outline = true;

    /** 新建小说表单的默认目标章数。 */
    public int $default_target_chapters = 10;

    /** 新建小说表单的默认每章字数下限。 */
    public int $default_min_characters = 2500;

    /** 新建小说表单的默认每章字数上限。 */
    public int $default_max_characters = 3500;

    /** 新建小说表单的默认预算币种。 */
    public string $default_budget_currency = 'CNY';

    /** 新建小说表单的默认生产方式。 */
    public string $default_production_mode = 'manual';

    /** “继续自动生成”单次最多授权章数，不超过运行模型允许的 10 章。 */
    public int $continuation_max_chapters = 10;

    /** 自动生成每授权一章预留的执行次数，用于自动追加执行次数上限。 */
    public int $continuation_executions_per_chapter = 20;

    /** 新运行是否检索前文原文片段放入上下文。 */
    public bool $context_retrieval_enabled = true;

    /** 前文检索最多放入的片段数。 */
    public int $context_retrieval_passages = 6;

    /** 前文检索同一章最多取几段。 */
    public int $context_retrieval_per_chapter = 2;

    /** 投递正式提交通知时自动为新正文补向量（产生少量嵌入费用）。 */
    public bool $outbox_auto_embedding = true;

    /** 导出文件生成后保留天数。 */
    public int $export_retention_days = 7;

    /** 失败导出任务保留天数，到期清理文件。 */
    public int $export_failed_retention_days = 7;

    public static function group(): string
    {
        return 'general';
    }
}
