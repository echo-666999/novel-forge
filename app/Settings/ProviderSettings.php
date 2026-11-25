<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings as BaseSettings;

/** 模型供应商的接口密钥与地址，在管理后台维护；密钥使用应用密钥加密保存，留空时回退到本机环境变量。 */
class ProviderSettings extends BaseSettings
{
    public ?string $openai_api_key = null;

    public string $openai_url = 'https://api.openai.com/v1';

    public ?string $deepseek_api_key = null;

    public string $deepseek_url = 'https://api.deepseek.com';

    public ?string $anthropic_api_key = null;

    public string $anthropic_url = 'https://api.anthropic.com/v1';

    /** 设置分组。 */
    public static function group(): string
    {
        return 'providers';
    }

    /** 密钥加密存储，数据库中不出现明文。
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return ['openai_api_key', 'deepseek_api_key', 'anthropic_api_key'];
    }
}
