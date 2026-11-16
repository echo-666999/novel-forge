<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** 删除从未被读取的设置，补齐新书默认值；仍是旧初始值的项目改成可用的默认值，管理员改过的保留。 */
    public function up(): void
    {
        foreach (['enable_volume_checkpoint', 'ai_repair_max_attempts'] as $name) {
            if ($this->migrator->exists("general.{$name}")) {
                $this->migrator->delete("general.{$name}");
            }
        }
        // 旧初始值：2000 远低于正文弹窗实际使用的 8192；5 次执行写不完第一章。
        $this->migrator->update('general.max_tokens_per_chapter', fn (int $value): int => $value === 2000 ? 8192 : min($value, 8192));
        $this->migrator->update('general.max_execution_count', fn (int $value): int => $value === 5 ? 100 : $value);
        $defaults = [
            'default_target_chapters' => 10,
            'default_min_characters' => 2500,
            'default_max_characters' => 3500,
            'default_budget_currency' => 'CNY',
            'default_production_mode' => 'manual',
        ];
        foreach ($defaults as $name => $value) {
            if (! $this->migrator->exists("general.{$name}")) {
                $this->migrator->add("general.{$name}", $value);
            }
        }
    }

    /** 回滚只恢复已删除的键与新增前的状态，不回退管理员调整过的数值。 */
    public function down(): void
    {
        foreach (['default_target_chapters', 'default_min_characters', 'default_max_characters', 'default_budget_currency', 'default_production_mode'] as $name) {
            if ($this->migrator->exists("general.{$name}")) {
                $this->migrator->delete("general.{$name}");
            }
        }
        foreach (['enable_volume_checkpoint' => true, 'ai_repair_max_attempts' => 3] as $name => $value) {
            if (! $this->migrator->exists("general.{$name}")) {
                $this->migrator->add("general.{$name}", $value);
            }
        }
    }
};
