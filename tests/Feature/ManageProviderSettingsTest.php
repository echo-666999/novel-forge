<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageProviderSettings;
use App\Models\User;
use App\Services\Ai\ProviderCredentials;
use App\Settings\ProviderSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** 后台维护供应商密钥：加密保存、只显示掩码、留空保持、清除回退环境变量；连接测试只用受控响应。 */
class ManageProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** 仅使用应用迁移与设置迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 模拟环境变量中的旧密钥，清空后台密钥后重新应用设置。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->forgetInstance(ProviderCredentials::class);
        // 设置迁移会按本机环境变量初始化密钥，测试先清空，只使用下面的假密钥。
        $settings = app(ProviderSettings::class);
        $settings->fill(['openai_api_key' => null, 'deepseek_api_key' => null, 'openai_url' => 'https://api.openai.com/v1', 'deepseek_url' => 'https://api.deepseek.com'])->save();
        config(['ai.providers.openai.key' => 'sk-env-openai-000011112222', 'ai.providers.deepseek.key' => null, 'ai.providers.deepseek.url' => 'https://api.deepseek.com']);
        app(ProviderCredentials::class)->apply();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    /** 保存后加密存储并立即生效；页面不回填明文；留空保持；清除后回到环境变量。 */
    public function test_keys_are_encrypted_masked_kept_and_cleared(): void
    {
        Livewire::test(ManageProviderSettings::class)->assertSuccessful()->assertSee('sk-env-…2222')->assertSee('本机环境变量')->assertSee('当前未配置密钥')
            ->assertFormSet(['openai_api_key' => null, 'deepseek_api_key' => null])
            ->fillForm(['deepseek_api_key' => 'sk-deepseek-admin-33334444', 'deepseek_url' => 'https://api.deepseek.com/v1'])->call('save')->assertHasNoFormErrors()
            ->assertFormSet(['deepseek_api_key' => null]);
        $this->assertSame(['sk-deepseek-admin-33334444', 'https://api.deepseek.com/v1'], [config('ai.providers.deepseek.key'), config('ai.providers.deepseek.url')]);
        $stored = DB::table('settings')->where('group', 'providers')->where('name', 'deepseek_api_key')->value('payload');
        $this->assertStringNotContainsString('sk-deepseek-admin', $stored);
        $this->assertSame('后台设置', app(ProviderCredentials::class)->status('deepseek')['source']);

        Livewire::test(ManageProviderSettings::class)->assertSee('sk-deep…4444')->call('save')->assertHasNoFormErrors();
        $this->assertSame('sk-deepseek-admin-33334444', config('ai.providers.deepseek.key'));

        Livewire::test(ManageProviderSettings::class)->fillForm(['openai_api_key' => 'sk-admin-openai-55556666'])->call('save');
        $this->assertSame('sk-admin-openai-55556666', config('ai.providers.openai.key'));
        Livewire::test(ManageProviderSettings::class)->fillForm(['clear_openai_api_key' => true])->call('save');
        $this->assertSame(['sk-env-openai-000011112222', '本机环境变量（.env）'], [config('ai.providers.openai.key'), app(ProviderCredentials::class)->status('openai')['source']]);
    }

    /** 只允许官方地址。 */
    public function test_unofficial_url_is_rejected(): void
    {
        Livewire::test(ManageProviderSettings::class)->fillForm(['openai_url' => 'https://proxy.invalid/v1'])->call('save')->assertHasFormErrors(['openai_url']);
        $this->assertSame('https://api.openai.com/v1', config('ai.providers.openai.url'));
    }

    /** 连接测试调用只读模型列表，区分有效与被拒绝，不回显供应商原始消息。 */
    public function test_connection_check_uses_models_endpoint(): void
    {
        Http::fake([
            'api.deepseek.com/models' => Http::response(['data' => [['id' => 'deepseek-flash'], ['id' => 'deepseek-v4-pro']]]),
            'api.openai.com/v1/models' => Http::response(['error' => ['message' => 'secret detail']], 401),
        ]);
        Livewire::test(ManageProviderSettings::class)->fillForm(['deepseek_api_key' => 'sk-typed-deepseek-77778888'])
            ->callAction(['name' => 'check_deepseek', 'context' => ['schemaComponent' => 'form.deepseek_api_key']])->assertNotified('DeepSeek 密钥有效，可用模型 2 个。')
            ->callAction(['name' => 'check_openai', 'context' => ['schemaComponent' => 'form.openai_api_key']])->assertNotified('OpenAI 拒绝了该密钥（HTTP 401），请核对。');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.deepseek.com/models' && $request->hasHeader('Authorization', 'Bearer sk-typed-deepseek-77778888'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/models' && $request->hasHeader('Authorization', 'Bearer sk-env-openai-000011112222'));
    }
}
