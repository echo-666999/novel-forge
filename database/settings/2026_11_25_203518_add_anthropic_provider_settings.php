<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** 新增 Claude 供应商设置：密钥按本机环境变量现值初始化（加密保存），地址为官方默认值。 */
    public function up(): void
    {
        if (! $this->migrator->exists('providers.anthropic_api_key')) {
            $this->migrator->addEncrypted('providers.anthropic_api_key', trim((string) config('ai.providers.anthropic.key')) ?: null);
        }
        if (! $this->migrator->exists('providers.anthropic_url')) {
            $this->migrator->add('providers.anthropic_url', 'https://api.anthropic.com/v1');
        }
    }

    public function down(): void
    {
        foreach (['anthropic_api_key', 'anthropic_url'] as $name) {
            if ($this->migrator->exists("providers.{$name}")) {
                $this->migrator->delete("providers.{$name}");
            }
        }
    }
};
