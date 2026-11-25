<?php

namespace App\Services\Ai;

use App\Settings\ProviderSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/** 把后台维护的供应商密钥与地址覆盖到运行配置；后台未填写时保留本机环境变量的值。容器内单例，记录本进程环境变量原值。 */
final class ProviderCredentials
{
    /** 各供应商允许的官方地址，与适配器白名单一致。 */
    public const URLS = [
        'openai' => ['https://api.openai.com/v1'],
        'deepseek' => ['https://api.deepseek.com', 'https://api.deepseek.com/v1'],
        'anthropic' => ['https://api.anthropic.com/v1'],
    ];

    /** 各供应商的显示名称。 */
    public const LABELS = ['openai' => 'OpenAI', 'deepseek' => 'DeepSeek', 'anthropic' => 'Claude'];

    /** 环境变量中的原始值，首次覆盖前记录。
     * @var array<string, array{key: mixed, url: mixed}>
     */
    private array $environment = [];

    /** 启动时调用：设置表尚未迁移或读取失败时不覆盖，继续使用环境变量。 */
    public function apply(): void
    {
        foreach (array_keys(self::URLS) as $provider) {
            // 首次调用时记下环境变量的值；保存设置后重新覆盖，清空后台密钥能回到环境变量。
            $this->environment[$provider] ??= ['key' => config("ai.providers.{$provider}.key"), 'url' => config("ai.providers.{$provider}.url")];
            config(["ai.providers.{$provider}.key" => $this->environment[$provider]['key'], "ai.providers.{$provider}.url" => $this->environment[$provider]['url']]);
        }
        try {
            $settings = app(ProviderSettings::class);
            $values = collect(array_keys(self::URLS))->mapWithKeys(fn (string $provider): array => [$provider => [$settings->{$provider.'_api_key'}, $settings->{$provider.'_url'}]])->all();
        } catch (Throwable) {
            return;
        }
        foreach ($values as $provider => [$key, $url]) {
            if (self::clean($key) !== null) {
                config(["ai.providers.{$provider}.key" => self::clean($key)]);
            }
            if (self::clean($url) !== null) {
                config(["ai.providers.{$provider}.url" => self::clean($url)]);
            }
        }
    }

    /** 当前生效密钥的来源与掩码，页面只显示掩码，不回传明文。
     * @return array{source: string, masked: ?string}
     */
    public function status(string $provider): array
    {
        $key = self::clean(config("ai.providers.{$provider}.key"));
        if ($key === null) {
            return ['source' => '未配置', 'masked' => null];
        }
        try {
            $stored = self::clean(app(ProviderSettings::class)->{$provider.'_api_key'});
        } catch (Throwable) {
            $stored = null;
        }

        return ['source' => $stored === $key ? '后台设置' : '本机环境变量（.env）', 'masked' => self::mask($key)];
    }

    /** 掩码：保留前缀与末4位。 */
    public static function mask(string $key): string
    {
        return mb_strlen($key) <= 12 ? str_repeat('•', 8) : mb_substr($key, 0, 7).'…'.mb_substr($key, -4);
    }

    /** 用模型列表接口校验密钥：只读、不消耗 Token、不产生费用；不回传供应商原始消息。
     * @return array{ok: bool, message: string}
     */
    public function check(string $provider, string $key, string $url): array
    {
        try {
            // Claude 使用 x-api-key 与版本请求头，其余供应商使用 Bearer。
            $request = $provider === 'anthropic' ? Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01']) : Http::withToken($key);
            $response = $request->acceptJson()->connectTimeout(10)->timeout(20)->get(rtrim($url, '/').'/models');
        } catch (Throwable) {
            return ['ok' => false, 'message' => '无法连接 '.self::LABELS[$provider].'，请检查网络。'];
        }

        return ['ok' => $response->successful(), 'message' => match (true) {
            $response->successful() => self::LABELS[$provider].' 密钥有效，可用模型 '.count((array) $response->json('data')).' 个。',
            in_array($response->status(), [401, 403], true) => self::LABELS[$provider].' 拒绝了该密钥（HTTP '.$response->status().'），请核对。',
            default => self::LABELS[$provider].' 返回 HTTP '.$response->status().'，暂时无法确认密钥是否有效。',
        }];
    }

    /** 空白与占位值视为未填写。 */
    private static function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' || in_array($value, ['sk-...', 'sk-ant-...', 'your-api-key', 'your_deepseek_api_key'], true) ? null : $value;
    }
}
