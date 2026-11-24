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
            'auto_revise_on_check_failure' => false,
            'max_execution_count' => 8,
            'enable_fine_outline' => false,
            'default_target_chapters' => 30,
            'default_min_characters' => 2000,
            'default_max_characters' => 3000,
            'default_budget_currency' => 'USD',
            'default_production_mode' => 'auto_first',
            'continuation_max_chapters' => 5,
            'continuation_executions_per_chapter' => 30,
            'context_retrieval_enabled' => false,
            'context_retrieval_passages' => 4,
            'context_retrieval_per_chapter' => 1,
            'outbox_auto_embedding' => false,
            'export_retention_days' => 30,
            'export_failed_retention_days' => 3,
        ];

        $page = Livewire::test(ManageGeneralSettings::class)
            ->assertSuccessful()
            ->assertSee('通用设置')
            ->assertSee('模型参数')
            ->assertSee('执行限制')
            ->assertSee('流程开关')
            ->assertSee('新书默认值')
            ->assertSee('自动续写')
            ->assertSee('前文检索')
            ->assertSee('导出')
            ->assertDontSee('AI 修复最大尝试次数')
            ->assertDontSee('启用分卷检查点');

        foreach (array_keys($values) as $name) {
            $page->assertFormFieldExists($name);
        }

        $page->fillForm($values)->call('save')->assertHasNoFormErrors();

        // 丢弃当前请求中的实例，确保读取的是已持久化数据。
        app()->forgetInstance(GeneralSettings::class);
        $this->assertSame($values, app(GeneralSettings::class)->toArray());
        Livewire::test(ManageGeneralSettings::class)->assertSchemaStateSet($values);
        $this->assertSame(19, DB::table('settings')->where('group', 'general')->count());
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
            'temperature above two' => ['temperature', 2.5],
            'zero token budget' => ['max_tokens_per_chapter', 0],
            'fractional token budget' => ['max_tokens_per_chapter', 1.5],
            'token budget above modal cap' => ['max_tokens_per_chapter', 8193],
            'zero execution count' => ['max_execution_count', 0],
            'fractional execution count' => ['max_execution_count', 1.5],
            'zero default chapters' => ['default_target_chapters', 0],
            'max below min characters' => ['default_max_characters', 100],
            'unknown currency' => ['default_budget_currency', 'EUR'],
            'unknown production mode' => ['default_production_mode', 'other'],
            'continuation above run range' => ['continuation_max_chapters', 11],
            'zero executions per chapter' => ['continuation_executions_per_chapter', 0],
            'too many passages' => ['context_retrieval_passages', 13],
            'per chapter above passages' => ['context_retrieval_per_chapter', 7],
            'zero retention days' => ['export_retention_days', 0],
            'zero failed retention days' => ['export_failed_retention_days', 0],
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
        // 初始化迁移会补回已废弃的键，后续清理迁移负责再次移除。
        (require database_path('settings/2026_11_16_120000_clean_up_general_settings.php'))->up();
        (require database_path('settings/2026_11_16_130000_add_operational_settings.php'))->up();
        (require database_path('settings/2026_11_16_140000_convert_retry_setting_to_switch.php'))->up();

        app()->forgetInstance(GeneralSettings::class);
        $settings = app(GeneralSettings::class);
        $this->assertSame('existing-model', $settings->default_model);
        $this->assertSame(0.7, $settings->temperature);
        $this->assertSame(19, DB::table('settings')->where('group', 'general')->count());
    }

    /** 原重试次数按 0 与非 0 转为开关，行为不变；回滚还原为次数。 */
    public function test_retry_count_converts_to_switch(): void
    {
        $this->assertTrue(app(GeneralSettings::class)->auto_revise_on_check_failure);
        $migration = require database_path('settings/2026_11_16_140000_convert_retry_setting_to_switch.php');
        $migration->down();
        $this->assertSame(json_encode(3), DB::table('settings')->where('group', 'general')->where('name', 'max_retries_per_chapter')->value('payload'));
        DB::table('settings')->where('group', 'general')->where('name', 'max_retries_per_chapter')->update(['payload' => json_encode(0)]);
        $migration->up();

        app()->forgetInstance(GeneralSettings::class);
        $this->assertFalse(app(GeneralSettings::class)->auto_revise_on_check_failure);
        $this->assertFalse(DB::table('settings')->where('group', 'general')->where('name', 'max_retries_per_chapter')->exists());
    }

    /** 清理迁移删除从未使用的设置；仍是旧初始值的项改为可用默认值，管理员改过的值保留。 */
    public function test_cleanup_migration_updates_untouched_defaults_only(): void
    {
        $this->assertSame(8192, app(GeneralSettings::class)->max_tokens_per_chapter);
        $this->assertSame(100, app(GeneralSettings::class)->max_execution_count);
        $this->assertFalse(DB::table('settings')->where('group', 'general')->whereIn('name', ['enable_volume_checkpoint', 'ai_repair_max_attempts'])->exists());

        $migration = require database_path('settings/2026_11_16_120000_clean_up_general_settings.php');
        $migration->down();
        DB::table('settings')->where('group', 'general')->where('name', 'max_tokens_per_chapter')->update(['payload' => json_encode(4000)]);
        DB::table('settings')->where('group', 'general')->where('name', 'max_execution_count')->update(['payload' => json_encode(5)]);
        $migration->up();

        app()->forgetInstance(GeneralSettings::class);
        $this->assertSame(4000, app(GeneralSettings::class)->max_tokens_per_chapter);
        $this->assertSame(100, app(GeneralSettings::class)->max_execution_count);
        $this->assertSame(19, DB::table('settings')->where('group', 'general')->count());
    }
}
