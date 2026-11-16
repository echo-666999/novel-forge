<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** 原“每章最大重试次数”实际只区分 0 与非 0（最多一次自动修订），改为开关；非 0 视为开启，行为不变。 */
    public function up(): void
    {
        if (! $this->migrator->exists('general.auto_revise_on_check_failure')) {
            $retries = $this->migrator->exists('general.max_retries_per_chapter')
                ? (int) json_decode((string) DB::table('settings')->where('group', 'general')->where('name', 'max_retries_per_chapter')->value('payload'))
                : 3;
            $this->migrator->add('general.auto_revise_on_check_failure', $retries > 0);
        }
        if ($this->migrator->exists('general.max_retries_per_chapter')) {
            $this->migrator->delete('general.max_retries_per_chapter');
        }
    }

    /** 回滚：开启还原为原默认 3 次，关闭还原为 0。 */
    public function down(): void
    {
        if (! $this->migrator->exists('general.auto_revise_on_check_failure')) {
            return;
        }
        $enabled = (bool) json_decode((string) DB::table('settings')->where('group', 'general')->where('name', 'auto_revise_on_check_failure')->value('payload'));
        $this->migrator->delete('general.auto_revise_on_check_failure');
        if (! $this->migrator->exists('general.max_retries_per_chapter')) {
            $this->migrator->add('general.max_retries_per_chapter', $enabled ? 3 : 0);
        }
    }
};
