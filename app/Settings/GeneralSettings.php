<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings as BaseSettings;

/**
 * 小说生成的通用参数，与 general 分组中的持久化设置对应。
 */
class GeneralSettings extends BaseSettings
{
    public string $default_model = 'gpt-4.1-mini';

    public float $temperature = 0.7;

    public int $max_tokens_per_chapter = 2000;

    public int $max_retries_per_chapter = 3;

    public int $max_execution_count = 5;

    public bool $enable_fine_outline = true;

    public bool $enable_volume_checkpoint = true;

    public int $ai_repair_max_attempts = 3;

    public static function group(): string
    {
        return 'general';
    }
}
