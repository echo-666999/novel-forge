<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageGeneralSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageGeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** 设置页测试仅使用应用迁移，排除不兼容 SQLite 的第三方向量扩展迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    public function test_settings_are_visible_and_changes_survive_a_fresh_load(): void
    {
        $values = [
            'default_model' => 'test-model',
            'temperature' => 0.25,
            'max_tokens_per_chapter' => 4000,
            'max_retries_per_chapter' => 0,
            'max_execution_count' => 8,
            'enable_fine_outline' => false,
            'enable_volume_checkpoint' => false,
            'ai_repair_max_attempts' => 0,
        ];

        $page = Livewire::test(ManageGeneralSettings::class)
            ->assertSuccessful()
            ->assertSee('通用设置')
            ->assertSee('模型参数')
            ->assertSee('执行限制')
            ->assertSee('流程开关');

        foreach (array_keys($values) as $name) {
            $page->assertFormFieldExists($name);
        }

        $page->fillForm($values)->call('save')->assertHasNoFormErrors();

        // 丢弃当前请求中的实例，确保读取的是已持久化数据。
        app()->forgetInstance(GeneralSettings::class);
        $this->assertSame($values, app(GeneralSettings::class)->toArray());
        Livewire::test(ManageGeneralSettings::class)->assertSchemaStateSet($values);
        $this->assertSame(8, DB::table('settings')->where('group', 'general')->count());
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_values_do_not_change_stored_settings(string $field, mixed $value): void
    {
        $before = DB::table('settings')->orderBy('id')->get()->toJson();

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm([$field => $value])
            ->call('save')
            ->assertHasFormErrors([$field]);

        $this->assertSame($before, DB::table('settings')->orderBy('id')->get()->toJson());
    }

    public static function invalidSettings(): array
    {
        return [
            'empty model' => ['default_model', ''],
            'non-numeric temperature' => ['temperature', 'invalid'],
            'negative temperature' => ['temperature', -0.1],
            'zero token budget' => ['max_tokens_per_chapter', 0],
            'fractional token budget' => ['max_tokens_per_chapter', 1.5],
            'negative retry count' => ['max_retries_per_chapter', -1],
            'fractional retry count' => ['max_retries_per_chapter', 1.5],
            'zero execution count' => ['max_execution_count', 0],
            'fractional execution count' => ['max_execution_count', 1.5],
            'negative repair count' => ['ai_repair_max_attempts', -1],
            'fractional repair count' => ['ai_repair_max_attempts', 1.5],
        ];
    }

    public function test_initialization_fills_missing_keys_without_overwriting_existing_values(): void
    {
        DB::table('settings')->where('group', 'general')->where('name', 'default_model')
            ->update(['payload' => json_encode('existing-model')]);
        DB::table('settings')->where('group', 'general')->where('name', 'temperature')->delete();

        $migration = require database_path('settings/2026_11_01_103431_initialize_general_settings.php');
        $migration->up();
        $migration->up();

        app()->forgetInstance(GeneralSettings::class);
        $settings = app(GeneralSettings::class);
        $this->assertSame('existing-model', $settings->default_model);
        $this->assertSame(0.7, $settings->temperature);
        $this->assertSame(8, DB::table('settings')->where('group', 'general')->count());
    }
}
