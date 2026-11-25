<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** 供应商密钥与地址迁入后台设置：按本机环境变量的现值初始化，行为不变；密钥加密保存。 */
    public function up(): void
    {
        $key = fn (string $provider): ?string => trim((string) config("ai.providers.{$provider}.key")) ?: null;
        foreach (['openai', 'deepseek'] as $provider) {
            if (! $this->migrator->exists("providers.{$provider}_api_key")) {
                $this->migrator->addEncrypted("providers.{$provider}_api_key", $key($provider));
            }
        }
        $defaults = ['openai_url' => 'https://api.openai.com/v1', 'deepseek_url' => 'https://api.deepseek.com'];
        foreach ($defaults as $name => $default) {
            if (! $this->migrator->exists("providers.{$name}")) {
                $this->migrator->add("providers.{$name}", rtrim((string) config('ai.providers.'.str_replace('_url', '', $name).'.url'), '/') ?: $default);
            }
        }
    }

    public function down(): void
    {
        foreach (['openai_api_key', 'openai_url', 'deepseek_api_key', 'deepseek_url'] as $name) {
            if ($this->migrator->exists("providers.{$name}")) {
                $this->migrator->delete("providers.{$name}");
            }
        }
    }
};
