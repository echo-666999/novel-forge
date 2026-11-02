<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        /** 固定初始值，避免后续修改设置类时改变历史迁移行为。 */
        $defaults = [
            'default_model' => 'gpt-4',
            'temperature' => 0.7,
            'max_tokens_per_chapter' => 2000,
            'max_retries_per_chapter' => 3,
            'max_execution_count' => 5,
            'enable_fine_outline' => true,
            'enable_volume_checkpoint' => true,
            'ai_repair_max_attempts' => 3,
        ];

        foreach ($defaults as $name => $value) {
            if (! $this->migrator->exists("general.{$name}")) {
                $this->migrator->add("general.{$name}", $value);
            }
        }
    }
};
