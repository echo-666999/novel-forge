<?php

namespace Tests\Feature;

use App\Enums\ProductionFailure;
use App\Filament\Pages\ManageModelPrices;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\RunSettingJob;
use App\Models\CostLog;
use App\Models\ModelPrice;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\CostEstimator;
use App\Services\Ai\CostForecast;
use App\Services\Ai\ModelPrices;
use App\Services\Pipeline\NovelBudget;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/** O4：单价登记、按书预算的请求前预留与拦截、费用区间估算、补充创作要求进入冻结输入。全部使用隔离的假响应，不发真实请求。 */
class BudgetAndPricingTest extends TestCase
{
    use DatabaseMigrations;

    /** 内存库迁移业务表与设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 固定 OpenAI 测试凭据并禁止外网。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.openai.key' => 'sk-o4-test-only', 'ai.providers.openai.url' => 'https://api.openai.com/v1', 'ai.providers.openai.driver' => 'openai']);
        $settings = app(GeneralSettings::class);
        $settings->default_model = 'gpt-4.1-mini';
        $settings->save();
        Http::preventStrayRequests();
    }

    /** 内存库中还原夹具，避免迁移回滚保护拦截清理。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('cost_logs')->update(['reserved_cost' => null, 'reserved_currency' => null]);
        DB::table('novels')->update(['budget_amount' => null, 'budget_currency' => null, 'creative_brief' => null, 'generation_mode' => 'simulation']);
        DB::table('model_prices')->delete();
        parent::tearDown();
    }

    /** 每百万输入1元、输出2元的隔离单价。 */
    private function price(string $model = 'gpt-4.1-mini'): ModelPrice
    {
        return ModelPrice::factory()->create(['model' => $model]);
    }

    /** 假供应商返回固定用量：输入180、输出400 Token。 */
    private function fakeProvider(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(B02SettingTest::response('收到'))]);
    }

    /** 余额不足时在发送前拒绝：没有请求、没有费用记录。 */
    public function test_budget_blocks_request_before_sending(): void
    {
        $this->price();
        $this->fakeProvider();
        $novel = Novel::factory()->create(['budget_amount' => '0.0001', 'budget_currency' => 'CNY']);
        try {
            app(AiTextGenerationService::class)->generate('请只回复收到', 'real', 'gpt-4.1-mini', $novel->id);
            $this->fail('预算不足必须拒绝');
        } catch (AiGenerationException $exception) {
            $this->assertSame('budget_exhausted', $exception->category);
            $this->assertNull($exception->costLogId);
        }
        $this->assertSame(0, CostLog::count());
        Http::assertNothingSent();
    }

    /** 余额充足时预留上限、按登记单价结算，预算摘要计入实际估算。 */
    public function test_budget_reserves_and_settles_with_registered_price(): void
    {
        $price = $this->price();
        $this->fakeProvider();
        $novel = Novel::factory()->create(['budget_amount' => '1', 'budget_currency' => 'CNY']);
        $log = app(AiTextGenerationService::class)->generate('请只回复收到', 'real', 'gpt-4.1-mini', $novel->id);
        // 预留：输入按字符数（6）计、输出按上限128计：(6×1 + 128×2) / 1,000,000。
        $this->assertSame('0.00026200', $log->reserved_cost);
        $this->assertSame('CNY', $log->reserved_currency);
        // 结算：实际响应模型带日期后缀，按前缀匹配登记单价：(180×1 + 400×2) / 1,000,000。
        $this->assertSame('estimated', $log->billing_status);
        $this->assertSame('0.00098000', $log->estimated_cost);
        $this->assertSame('model-price-'.$price->id, $log->price_version);
        $summary = app(NovelBudget::class)->summary($novel->fresh());
        $this->assertSame(['0.00098000', '0.00000000', '0.99902000', 'normal'], [$summary['spent'], $summary['reserved'], $summary['remaining'], $summary['state']]);
    }

    /** 设了预算但模型没有登记同币种单价时拒绝，避免无法控制的花费。 */
    public function test_budget_without_matching_price_is_blocked(): void
    {
        $this->fakeProvider();
        $novel = Novel::factory()->create(['budget_amount' => '100', 'budget_currency' => 'USD']);
        $this->price();
        try {
            app(AiTextGenerationService::class)->generate('请只回复收到', 'real', 'gpt-4.1-mini', $novel->id);
            $this->fail('缺少同币种单价必须拒绝');
        } catch (AiGenerationException $exception) {
            $this->assertSame('budget_price_unknown', $exception->category);
            $this->assertStringContainsString('尚未发送请求', $exception->getMessage());
        }
        $this->assertSame(0, CostLog::count());
        Http::assertNothingSent();
    }

    /** 未设预算的书保持原行为：不预留，未登记单价时金额为未知。 */
    public function test_no_budget_keeps_previous_behavior(): void
    {
        $this->fakeProvider();
        $novel = Novel::factory()->create();
        $log = app(AiTextGenerationService::class)->generate('请只回复收到', 'real', 'gpt-4.1-mini', $novel->id);
        $this->assertSame(['succeeded', 'unknown'], [$log->status, $log->billing_status]);
        $this->assertNull($log->reserved_cost);
        $this->assertSame('none', app(NovelBudget::class)->summary($novel->fresh())['state']);
    }

    /** 单价按最长前缀匹配，配置文件中的同名单价优先；登记后不可修改。 */
    public function test_price_resolution_and_immutability(): void
    {
        $this->price('gpt-4.1');
        $mini = $this->price('gpt-4.1-mini');
        $snapshot = app(ModelPrices::class)->snapshot();
        $this->assertSame('model-price-'.$mini->id, ModelPrices::resolve($snapshot, 'gpt-4.1-mini-2025-04-14')['version']);
        $this->assertNull(ModelPrices::resolve($snapshot, 'gpt-4o'));
        config(['ai.costs.prices' => ['gpt-4.1-mini' => ['version' => 'config-v1', 'currency' => 'USD', 'input_per_million' => '0.4', 'output_per_million' => '1.6']]]);
        $this->assertSame('config-v1', app(ModelPrices::class)->current('gpt-4.1-mini')['version']);
        $this->expectException(LogicException::class);
        $mini->update(['input_per_million' => '0']);
    }

    /** 费用区间：未登记单价时明确说明无法估算，登记后给出区间。 */
    public function test_forecast_text(): void
    {
        $forecast = app(CostForecast::class);
        $unknown = $forecast->range('gpt-4.1-mini', 5, 7, 8192);
        $this->assertFalse($unknown['known']);
        $this->assertStringContainsString('尚未登记单价', $unknown['text']);
        $this->price();
        $known = $forecast->range('gpt-4.1-mini', 5, 7, 8192);
        $this->assertTrue($known['known']);
        $this->assertSame(1, bccomp($known['high'], $known['low'], 8));
        $this->assertStringContainsString('¥', $known['text']);
    }

    /** 预算拦截的停止原因有中文说明和调整建议，且不会被自动恢复反复尝试。 */
    public function test_budget_stop_reason_is_described_and_not_auto_recovered(): void
    {
        $described = ProductionFailure::describe('CONTENT_STOPPED:budget_exhausted', 'content');
        $this->assertStringContainsString('预算不足', $described['label']);
        $this->assertStringContainsString('调整预算', $described['advice']);
        $this->assertFalse(ProductionFailure::isAutomaticallyRecoverable('CONTENT_STOPPED:budget_exhausted', 'content'));
    }

    /** 向导保存补充创作要求与预算，补充要求随冻结输入发送给设定请求。 */
    public function test_wizard_saves_brief_and_budget_and_brief_reaches_setting_prompt(): void
    {
        Queue::fake();
        $author = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        $this->price();
        Livewire::test(CreateNovel::class)->fillForm([...B02SettingTest::input(), 'production_mode' => 'manual', 'budget_amount' => '5', 'budget_currency' => 'CNY',
            'creative_brief' => ['target_readers' => '成年读者', 'forbidden_content' => '不写穿越和重生', 'style' => '', 'selling_points' => null, 'ending_preference' => '主线必须解决']])
            ->assertSee('预计费用')->call('create')->assertHasNoFormErrors();
        $novel = Novel::sole();
        $this->assertSame(['target_readers' => '成年读者', 'forbidden_content' => '不写穿越和重生', 'ending_preference' => '主线必须解决'], $novel->creative_brief);
        $this->assertSame(['5.00000000', 'CNY'], [$novel->budget_amount, $novel->budget_currency]);
        $this->assertCount(1, $novel->budget_history);
        Http::fake(['https://api.openai.com/v1/responses' => function (Request $request) {
            $this->assertStringContainsString('不写穿越和重生', self::requestPrompt($request));

            return Http::response(B02SettingTest::response(json_encode(B02SettingTest::setting(), JSON_UNESCAPED_UNICODE)));
        }]);
        Queue::assertPushed(RunSettingJob::class, function (RunSettingJob $job): bool {
            app()->call([$job, 'handle']);

            return true;
        });
        $log = CostLog::sole();
        $this->assertSame('succeeded', $log->status);
        $this->assertNotNull($log->reserved_cost);
        Http::assertSentCount(1);
    }

    /** 后台登记单价与调整预算：单价只追加，预算调整写入记录。 */
    public function test_price_page_and_budget_action(): void
    {
        $author = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        Livewire::test(ManageModelPrices::class)->assertSee('未登记')
            ->callAction('register', ['model' => 'gpt-4.1-mini', 'currency' => 'CNY', 'input_per_million' => '1.5', 'output_per_million' => '6', 'source' => '官方价格页（隔离测试）', 'verified_on' => now()->toDateString()])
            ->assertHasNoActionErrors()->assertNotified('单价已登记');
        $this->assertSame('1.50000000', ModelPrice::sole()->input_per_million);
        $novel = Novel::factory()->create(['generation_mode' => 'setting_real']);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSee('未设预算')
            ->callAction('adjustBudget', ['amount' => '20', 'currency' => 'CNY', 'reason' => '控制前十章费用'])->assertHasNoActionErrors();
        $novel->refresh();
        $this->assertSame('20.00000000', $novel->budget_amount);
        $this->assertSame('控制前十章费用', $novel->budget_history[0]['reason']);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSee('预算 ¥20.00');
        Http::assertNothingSent();
    }

    /** DeepSeek 式分时段单价：工作日高峰按标准价，其余时间与周末按闲时倍率；缓存命中与未命中分开计。 */
    public function test_off_peak_ratio_follows_request_time_in_utc(): void
    {
        $price = ['version' => 'v-test', 'currency' => 'USD', 'input_per_million' => '0.3', 'cache_read_per_million' => '0.006', 'output_per_million' => '1.2',
            'off_peak_ratio' => '0.5', 'peak_hours_utc' => ['01:00-04:00', '06:00-10:00']];
        $usage = ['input_tokens' => 1_000_000, 'output_tokens' => 100_000, 'cache_read_tokens' => 200_000, 'cache_write_tokens' => null, 'reasoning_tokens' => 80_000];
        $estimator = app(CostEstimator::class);
        // (800000×0.3 + 200000×0.006 + 100000×1.2) / 1e6 = 0.3612
        $peak = $estimator->estimateUsage($usage, null, 'real', $price, CarbonImmutable::parse('2026-11-20 15:30:00', 'Asia/Shanghai'));
        $this->assertSame(['0.36120000', 'peak'], [$peak['estimated_cost'], $peak['price_snapshot']['applied']['period']]);
        // 北京时间15:30是UTC 07:30，落在高峰内；北京时间23:43是UTC 15:43，工作日但不在高峰内，半价。
        $night = $estimator->estimateUsage($usage, null, 'real', $price, CarbonImmutable::parse('2026-11-20 23:43:00', 'Asia/Shanghai'));
        $this->assertSame(['0.18060000', 'off_peak'], [$night['estimated_cost'], $night['price_snapshot']['applied']['period']]);
        $weekend = $estimator->estimateUsage($usage, null, 'real', $price, CarbonImmutable::parse('2026-11-21 10:00:00', 'Asia/Shanghai'));
        $this->assertSame('0.18060000', $weekend['estimated_cost']);
        // 时间未知时不打折，宁可多算。
        $this->assertSame('0.36120000', $estimator->estimateUsage($usage, null, 'real', $price)['estimated_cost']);
    }

    /** OpenAI 式长上下文档位：输入超过阈值时整次请求按长上下文单价；非标准服务档位金额未知。 */
    public function test_long_context_tier_and_non_standard_service_tier(): void
    {
        $price = ['version' => 'v-test', 'currency' => 'USD', 'input_per_million' => '1', 'cache_read_per_million' => '0.05', 'output_per_million' => '5',
            'long_context_threshold' => 272000, 'long_input_per_million' => '2', 'long_cache_read_per_million' => '0.1', 'long_output_per_million' => '7.5'];
        $estimator = app(CostEstimator::class);
        $short = ['input_tokens' => 272000, 'output_tokens' => 10000, 'cache_read_tokens' => 72000, 'cache_write_tokens' => null, 'reasoning_tokens' => null];
        // (200000×1 + 72000×0.05 + 10000×5) / 1e6
        $this->assertSame('0.25360000', $estimator->estimateUsage($short, ['service_tier' => 'default'], 'real', $price)['estimated_cost']);
        $long = [...$short, 'input_tokens' => 272001];
        // (200001×2 + 72000×0.1 + 10000×7.5) / 1e6
        $result = $estimator->estimateUsage($long, null, 'real', $price);
        $this->assertSame(['0.48220200', 'long'], [$result['estimated_cost'], $result['price_snapshot']['applied']['context']]);
        // 缓存写入按写入单价计；长上下文时未登记长上下文写入价则金额未知，登记后按长上下文写入价。
        $written = [...$short, 'cache_write_tokens' => 100000];
        // (100000×1 + 72000×0.05 + 100000×1.25 + 10000×5) / 1e6
        $this->assertSame('0.27860000', $estimator->estimateUsage($written, null, 'real', [...$price, 'cache_write_per_million' => '1.25'])['estimated_cost']);
        $longWritten = [...$written, 'input_tokens' => 300000];
        $this->assertSame('unknown', $estimator->estimateUsage($longWritten, null, 'real', [...$price, 'cache_write_per_million' => '1.25'])['billing_status']);
        // (128000×2 + 72000×0.1 + 100000×2.5 + 10000×7.5) / 1e6
        $this->assertSame('0.58820000', $estimator->estimateUsage($longWritten, null, 'real', [...$price, 'cache_write_per_million' => '1.25', 'long_cache_write_per_million' => '2.5'])['estimated_cost']);
        $twelveHours = ['usage' => ['input_tokens_details' => ['cache_write_tokens_details' => ['ttl_30m_tokens' => 0, 'ttl_12h_tokens' => 100000]]]];
        $this->assertSame('unknown', $estimator->estimateUsage($written, $twelveHours, 'real', [...$price, 'cache_write_per_million' => '1.25'])['billing_status']);
        $flex = $estimator->estimateUsage($short, ['service_tier' => 'flex'], 'real', $price);
        $this->assertSame(['unknown', null], [$flex['billing_status'], $flex['estimated_cost']]);
        $this->assertSame(['input' => '2', 'output' => '7.5'], ModelPrices::ceilingRates($price, 300000));
        $this->assertSame(['input' => '1', 'output' => '5'], ModelPrices::ceilingRates($price, 1000));
    }

    /** 输出被截断的失败请求供应商照样收费：按单价估算并计入预算已花，不记为金额未知。 */
    public function test_truncated_failure_with_usage_is_estimated(): void
    {
        $this->price();
        $response = [...B02SettingTest::response('写到一半', 'incomplete'), 'incomplete_details' => ['reason' => 'max_output_tokens']];
        Http::fake(['https://api.openai.com/v1/responses' => Http::response($response)]);
        $novel = Novel::factory()->create(['budget_amount' => '1', 'budget_currency' => 'CNY']);
        try {
            app(AiTextGenerationService::class)->generate('请只回复收到', 'real', 'gpt-4.1-mini', $novel->id);
            $this->fail('截断必须报错');
        } catch (AiGenerationException $exception) {
            $this->assertSame('output_truncated', $exception->category);
        }
        $log = CostLog::sole();
        $this->assertSame(['failed', 'estimated', '0.00098000'], [$log->status, $log->billing_status, $log->estimated_cost]);
        $this->assertSame('0.00098000', app(NovelBudget::class)->summary($novel->fresh())['spent']);
    }

    /** 登记闲时与长上下文规则：须成对填写；高峰时段可用逗号分隔填写。 */
    public function test_register_price_with_off_peak_and_long_context_rules(): void
    {
        $author = User::factory()->create();
        $base = ['model' => 'deepseek-flash', 'currency' => 'USD', 'input_per_million' => '0.3', 'cache_read_per_million' => '0.006', 'output_per_million' => '1.2', 'source' => '官方价格页（隔离测试）', 'verified_on' => now()->toDateString()];
        $prices = app(ModelPrices::class);
        try {
            $prices->register($author, [...$base, 'off_peak_ratio' => '0.5']);
            $this->fail('闲时倍率缺少高峰时段必须拒绝');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('peak_hours_utc', $exception->errors());
        }
        try {
            $prices->register($author, [...$base, 'off_peak_ratio' => '0.5', 'peak_hours_utc' => '10:00-06:00']);
            $this->fail('结束早于开始必须拒绝');
        } catch (ValidationException) {
        }
        $row = $prices->register($author, [...$base, 'off_peak_ratio' => '0.5', 'peak_hours_utc' => '01:00-04:00, 06:00-10:00', 'long_context_threshold' => '', 'long_input_per_million' => '']);
        $snapshot = $row->toPriceSnapshot();
        $this->assertSame(['0.50000000', ['01:00-04:00', '06:00-10:00']], [$snapshot['off_peak_ratio'], $snapshot['peak_hours_utc']]);
        $this->assertArrayNotHasKey('long_context_threshold', $snapshot);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        Livewire::test(ManageModelPrices::class)->assertSee('闲时 0.5 倍（工作日高峰 UTC 01:00-04:00、06:00-10:00）');
    }

    /** 单价登记有误时按当前单价重算：默认只预览，写入须填原因并追加重算记录；已有实际账单的不变。 */
    public function test_reprice_command_previews_then_applies_with_history(): void
    {
        $wrong = ModelPrice::factory()->create(['model' => 'deepseek-flash', 'currency' => 'USD', 'input_per_million' => '0.006', 'output_per_million' => '0.3', 'effective_at' => now()->subDays(2)]);
        $log = CostLog::factory()->create(['mode' => 'real', 'status' => 'failed', 'model' => 'deepseek-flash', 'requested_model' => 'deepseek-flash', 'input_tokens' => 1_000_000, 'output_tokens' => 100_000, 'cache_read_tokens' => 0,
            'currency' => 'USD', 'price_version' => 'model-price-'.$wrong->id, 'estimated_cost' => '0.03600000', 'billing_status' => 'estimated', 'started_at' => CarbonImmutable::parse('2026-11-20 23:43:00', 'Asia/Shanghai')]);
        $billed = CostLog::factory()->create(['mode' => 'real', 'model' => 'deepseek-flash', 'input_tokens' => 10, 'output_tokens' => 10, 'actual_cost' => '1.00000000', 'currency' => 'USD', 'billing_status' => 'actual']);
        $right = app(ModelPrices::class)->register(User::factory()->create(), ['model' => 'deepseek-flash', 'currency' => 'USD', 'input_per_million' => '0.3', 'cache_read_per_million' => '0.006', 'output_per_million' => '1.2',
            'off_peak_ratio' => '0.5', 'peak_hours_utc' => '01:00-04:00,06:00-10:00', 'source' => '官方价格页（隔离测试）', 'verified_on' => now()->toDateString()]);
        $this->artisan('ai:reprice-cost-logs')->expectsOutputToContain('0.03600000 改为 USD 0.21000000（off_peak）')->expectsOutputToContain('预览：将重算 1 条')->assertSuccessful();
        $this->assertSame('0.03600000', $log->fresh()->estimated_cost);
        $this->artisan('ai:reprice-cost-logs', ['--apply' => true])->assertFailed();
        $this->artisan('ai:reprice-cost-logs', ['--apply' => true, '--reason' => '单价登记错位'])->expectsOutputToContain('已重算 1 条')->assertSuccessful();
        $log->refresh();
        $this->assertSame(['0.21000000', 'model-price-'.$right->id, 'estimated'], [$log->estimated_cost, $log->price_version, $log->billing_status]);
        $this->assertSame(['0.03600000', '单价登记错位'], [$log->repricing_history[0]['from']['estimated_cost'], $log->repricing_history[0]['reason']]);
        $this->assertSame('1.00000000', $billed->fresh()->actual_cost);
        $this->artisan('ai:reprice-cost-logs')->expectsOutputToContain('预览：将重算 0 条')->assertSuccessful();
    }

    /** 数据库会话时区须与应用时区一致，否则带时区时间列会把北京时间当成UTC保存，分时段计价随之出错。 */
    public function test_database_session_timezone_matches_application_timezone(): void
    {
        $this->assertSame(config('app.timezone'), config('database.connections.pgsql.timezone'));
    }
}
