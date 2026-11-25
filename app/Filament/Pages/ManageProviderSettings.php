<?php

namespace App\Filament\Pages;

use App\Services\Ai\ProviderCredentials;
use App\Settings\ProviderSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/** 维护模型供应商的接口密钥与地址：密钥加密保存，页面只显示掩码，留空表示保持不变。 */
class ManageProviderSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string $settings = ProviderSettings::class;

    protected static ?string $title = '模型供应商';

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '配置与运维';

    protected static ?string $navigationLabel = '模型供应商';

    /** 每个供应商一个分区：密钥（只写）、清除开关、官方地址与连接测试。 */
    public function form(Schema $schema): Schema
    {
        return $schema->components(collect(ProviderCredentials::LABELS)->map(fn (string $label, string $provider): Section => Section::make($label)
            ->description(fn (): string => $this->describe($provider))
            ->columns(2)
            ->columnSpanFull()
            ->schema([
                TextInput::make($provider.'_api_key')->label('API 密钥')->password()->revealable()->autocomplete('off')
                    ->placeholder(fn (): string => app(ProviderCredentials::class)->status($provider)['masked'] ?? '尚未配置')
                    ->helperText('留空表示保持当前密钥不变；保存后加密存储，页面不再显示明文。')
                    ->hintAction(Action::make('check_'.$provider)->label('测试连接')->icon(Heroicon::OutlinedSignal)
                        ->action(fn (Get $get) => $this->check($provider, $get($provider.'_api_key'), $get($provider.'_url')))),
                Select::make($provider.'_url')->label('接口地址')->options(array_combine(ProviderCredentials::URLS[$provider], ProviderCredentials::URLS[$provider]))
                    ->native(false)->selectablePlaceholder(false)->required()->helperText('只允许官方地址。'),
                Toggle::make('clear_'.$provider.'_api_key')->label('清除后台保存的密钥')->inline(false)
                    ->helperText('清除后改用本机环境变量（.env）中的密钥；两处都没有时无法调用该供应商。'),
            ]))->values()->all());
    }

    /** 当前生效来源与掩码。 */
    private function describe(string $provider): string
    {
        $status = app(ProviderCredentials::class)->status($provider);

        return $status['masked'] === null ? '当前未配置密钥，调用该供应商的模型会直接报错，不会发送请求。' : '当前生效：'.$status['masked'].'（来自'.$status['source'].'）。';
    }

    /** 密钥不回填到表单，避免明文进入页面。
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'openai_api_key' => null, 'deepseek_api_key' => null, 'clear_openai_api_key' => false, 'clear_deepseek_api_key' => false];
    }

    /** 留空保持原密钥，勾选清除时置空；清除开关不写入设置。
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $settings = app(ProviderSettings::class);
        foreach (array_keys(ProviderCredentials::LABELS) as $provider) {
            $field = $provider.'_api_key';
            $typed = trim((string) ($data[$field] ?? ''));
            $data[$field] = match (true) {
                (bool) ($data['clear_'.$field] ?? false) => null,
                $typed !== '' => $typed,
                default => $settings->{$field},
            };
            unset($data['clear_'.$field]);
        }

        return $data;
    }

    /** 立即在本进程生效，并通知队列消费者在当前任务结束后重启以读取新密钥。 */
    protected function afterSave(): void
    {
        app(ProviderCredentials::class)->apply();
        Artisan::call('queue:restart');
        $this->fillForm();
    }

    /** 已保存通知说明消费者会重启。 */
    public function getSavedNotificationTitle(): ?string
    {
        return '供应商设置已保存，队列消费者完成当前任务后会自动重启并使用新设置';
    }

    /** 测试连接：优先使用刚输入的密钥，否则使用当前生效的密钥；只读接口，不产生费用。 */
    private function check(string $provider, ?string $typed, ?string $url): void
    {
        $key = trim((string) $typed) ?: trim((string) config("ai.providers.{$provider}.key"));
        if ($key === '') {
            Notification::make()->title('没有可测试的密钥')->warning()->send();

            return;
        }
        $result = app(ProviderCredentials::class)->check($provider, $key, $url ?: ProviderCredentials::URLS[$provider][0]);
        Notification::make()->title($result['message'])->status($result['ok'] ? 'success' : 'warning')->send();
    }
}
