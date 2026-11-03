<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** 将已确认的旧默认模型切换到 A03 验收模型，不覆盖其他显式模型选择。 */
return new class extends SettingsMigration
{
    /** 增量更新旧值；保留历史初始化迁移以及所有其他通用设置。 */
    public function up(): void
    {
        $this->migrator->update('general.default_model', static fn (mixed $value): mixed => in_array($value, ['gpt-4', 'gpt-6.1'], true) ? 'gpt-4.1-mini' : $value);
    }

    /** 只回退尚未再次修改的新默认值，不覆盖迁移后手动选择的模型。 */
    public function down(): void
    {
        $this->migrator->update('general.default_model', static fn (mixed $value): mixed => $value === 'gpt-4.1-mini' ? 'gpt-4' : $value);
    }
};
