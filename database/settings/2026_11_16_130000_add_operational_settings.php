<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** 续写、前文检索与导出参数原为代码常量，按原值迁入通用设置，行为不变。 */
    private const DEFAULTS = [
        'continuation_max_chapters' => 10,
        'continuation_executions_per_chapter' => 20,
        'context_retrieval_enabled' => true,
        'context_retrieval_passages' => 6,
        'context_retrieval_per_chapter' => 2,
        'export_retention_days' => 7,
        'export_failed_retention_days' => 7,
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $name => $value) {
            if (! $this->migrator->exists("general.{$name}")) {
                $this->migrator->add("general.{$name}", $value);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::DEFAULTS) as $name) {
            if ($this->migrator->exists("general.{$name}")) {
                $this->migrator->delete("general.{$name}");
            }
        }
    }
};
