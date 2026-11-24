<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** 投递正式提交通知时是否自动补向量；默认开启，嵌入费用写入成本流水。 */
    public function up(): void
    {
        if (! $this->migrator->exists('general.outbox_auto_embedding')) {
            $this->migrator->add('general.outbox_auto_embedding', true);
        }
    }

    public function down(): void
    {
        if ($this->migrator->exists('general.outbox_auto_embedding')) {
            $this->migrator->delete('general.outbox_auto_embedding');
        }
    }
};
