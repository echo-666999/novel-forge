<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageModelPrices;
use App\Models\ModelPrice;
use App\Models\User;
use App\Services\Ai\OfficialPriceFetcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** 从官网价格页（Markdown）自动获取单价：解析 DeepSeek 高峰/闲时价与 OpenAI 标准档、长上下文、专用模型表；全部使用假响应。 */
class OfficialPriceFetcherTest extends TestCase
{
    use DatabaseMigrations;

    /** DeepSeek 价格页的假响应状态码，用于模拟官网出错。 */
    private int $deepSeekStatus = 200;

    /** 内存库迁移业务表与设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 禁止外网，官网价格页使用与真实页面同结构的节选。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            OfficialPriceFetcher::SOURCES['deepseek'] => fn () => Http::response($this->deepSeekStatus === 200 ? self::deepSeekPage() : 'busy', $this->deepSeekStatus, ['Content-Type' => 'text/markdown']),
            OfficialPriceFetcher::SOURCES['openai'] => Http::response(self::openAiPage(), 200, ['Content-Type' => 'text/markdown']),
        ]);
    }

    /** 内存库中清理单价，避免迁移回滚保护拦截。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('model_prices')->delete();
        parent::tearDown();
    }

    /** DeepSeek 价格页节选：模型按列，高峰与闲时分行，脚注写明高峰时段。 */
    private static function deepSeekPage(): string
    {
        return <<<'MD'
        # Models & Pricing

        | MODEL                       | MODEL                          | MODEL    | deepseek-flash(1) | deepseek-v4-pro |
        | --------------------------- | ------------------------------ | -------- | ----------------- | --------------- |
        | MODEL VERSION               | MODEL VERSION                  | MODEL VERSION | DeepSeek-V4.1-Flash-1017 | DeepSeek-V4-Pro-0813 |
        | PRICING(2)                  | 1M INPUT TOKENS / (CACHE HIT)  | OFF-PEAK | $0.003            | $0.022          |
        | PRICING(2)                  | 1M INPUT TOKENS / (CACHE HIT)  | PEAK     | $0.006            | $0.044          |
        | PRICING(2)                  | 1M INPUT TOKENS / (CACHE MISS) | OFF-PEAK | $0.15             | $0.66           |
        | PRICING(2)                  | 1M INPUT TOKENS / (CACHE MISS) | PEAK     | $0.3              | $1.32           |
        | PRICING(2)                  | 1M OUTPUT TOKENS               | OFF-PEAK | $0.6              | $1.98           |
        | PRICING(2)                  | 1M OUTPUT TOKENS               | PEAK     | $1.2              | $3.96           |

        (2) Off-peak rates are half of the peak rates. Peak hours are 01:00 - 04:00 and 06:00 - 10:00 UTC, Monday through Friday, excluding Chinese public holidays.
        MD;
    }

    /** OpenAI 价格页节选：标准档两张表（带长上下文列与不带），批量档同名模型价格不同，专用模型表含嵌入。 */
    private static function openAiPage(): string
    {
        return <<<'MD'
        # Pricing

        ### Standard pricing data

        | Model | Short context input | Short context output | Short context cached input | Short context 30m write | Short context 12h write | Long context input | Long context output | Long context cached input | Long context 30m write | Long context 12h write |
        | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
        | gpt-6.1-terra | $1.00 | $5.00 | $0.05 | $1.25 | $2.50 | $2.00 | $7.50 | $0.10 | $2.50 | $5.00 |
        | gpt-5.4 (<272K context length) | $2.50 | $15.00 | $0.25 | - | - | $5.00 | $22.50 | $0.50 | - | - |

        | Model | Input | Output | Cached input |
        | --- | --- | --- | --- |
        | gpt-4.1-mini | $0.40 | $1.60 | $0.10 |

        ### Batch pricing data

        | Model | Input | Output | Cached input |
        | --- | --- | --- | --- |
        | gpt-4.1-mini | $0.20 | $0.80 | - |

        ### Ultrafast pricing data

        Short context: ≤272K input tokens. Long context: >272K input tokens.

        ### Grouped Pricing Table data

        | Category | Model | Input | Output | Cached input | 30m write | 12h write |
        | --- | --- | --- | --- | --- | --- | --- |
        | Embedding | text-embedding-3-small | $0.02 | - | - | - | - |
        MD;
    }

    /** DeepSeek 取高峰价，闲时倍率由闲时价除以高峰价得出，高峰时段取自脚注；不带长上下文字段。 */
    public function test_deepseek_peak_prices_and_off_peak_rule(): void
    {
        $result = app(OfficialPriceFetcher::class)->fetch('deepseek-flash');
        $this->assertSame(['currency' => 'USD', 'input_per_million' => '0.3', 'cache_read_per_million' => '0.006', 'output_per_million' => '1.2', 'cache_write_per_million' => null,
            'off_peak_ratio' => '0.5', 'peak_hours_utc' => '01:00-04:00,06:00-10:00', 'long_context_threshold' => null, 'long_input_per_million' => null, 'long_cache_read_per_million' => null,
            'long_cache_write_per_million' => null, 'long_output_per_million' => null], collect($result['fields'])->except(['source', 'verified_on'])->all());
        $this->assertStringStartsWith(OfficialPriceFetcher::SOURCES['deepseek'].' 自动获取于 ', $result['fields']['source']);
        $this->assertStringContainsString('节假日', implode('', $result['notes']));
    }

    /** OpenAI 只取标准档：长上下文价与阈值取自同一行和页面说明；同名模型的批量价不被误用；嵌入模型取专用表，输出按0。 */
    public function test_openai_standard_long_context_and_embedding(): void
    {
        $fetcher = app(OfficialPriceFetcher::class);
        $terra = $fetcher->fetch('gpt-6.1-terra')['fields'];
        $this->assertSame(['1.00', '0.05', '1.25', '5.00', '272000', '2.00', '0.10', '2.50', '7.50'], [$terra['input_per_million'], $terra['cache_read_per_million'], $terra['cache_write_per_million'],
            $terra['output_per_million'], $terra['long_context_threshold'], $terra['long_input_per_million'], $terra['long_cache_read_per_million'], $terra['long_cache_write_per_million'], $terra['long_output_per_million']]);
        $mini = $fetcher->fetch('gpt-4.1-mini')['fields'];
        $this->assertSame(['0.40', '0.10', '1.60', null, null], [$mini['input_per_million'], $mini['cache_read_per_million'], $mini['output_per_million'], $mini['cache_write_per_million'], $mini['long_context_threshold']]);
        $embedding = $fetcher->fetch('text-embedding-3-small');
        $this->assertSame(['0.02', '0', null], [$embedding['fields']['input_per_million'], $embedding['fields']['output_per_million'], $embedding['fields']['cache_read_per_million']]);
        $this->assertStringContainsString('专用模型表', implode('', $embedding['notes']));
    }

    /** 找不到模型或官网出错时说明原因，不填入任何价格。 */
    public function test_missing_model_and_http_error_are_explained(): void
    {
        try {
            app(OfficialPriceFetcher::class)->fetch('gpt-4.1');
            $this->fail('页面里没有的模型必须报错');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('没有找到 gpt-4.1', $exception->getMessage());
        }
        $this->deepSeekStatus = 503;
        $this->expectExceptionMessage('HTTP 503');
        app(OfficialPriceFetcher::class)->fetch('deepseek-v4-pro');
    }

    /** 登记弹窗选择模型后自动填入官网单价，核对后登记；表格默认只列当前生效的单价，并提示尚未登记的模型。 */
    public function test_register_modal_fills_official_prices_and_table_shows_current_only(): void
    {
        $author = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($author);
        $old = ModelPrice::factory()->create(['model' => 'deepseek-flash', 'currency' => 'USD', 'input_per_million' => '0.006', 'output_per_million' => '0.3', 'effective_at' => now()->subDay()]);
        Livewire::test(ManageModelPrices::class)->assertSee('未登记：')->assertSee('$0.006')
            ->mountAction('register')->fillForm(['model' => 'deepseek-flash'])
            ->assertNotified('已从官网获取 deepseek-flash 的单价')
            ->assertActionDataSet(['currency' => 'USD', 'input_per_million' => '0.3', 'cache_read_per_million' => '0.006', 'output_per_million' => '1.2', 'off_peak_ratio' => '0.5', 'peak_hours_utc' => '01:00-04:00,06:00-10:00'])
            ->callMountedAction()->assertHasNoActionErrors()->assertNotified('单价已登记');
        $new = ModelPrice::latest('id')->first();
        $this->assertSame(['0.30000000', '1.20000000', '0.50000000', ['01:00-04:00', '06:00-10:00']], [$new->input_per_million, $new->output_per_million, $new->off_peak_ratio, $new->peak_hours_utc]);
        Livewire::test(ManageModelPrices::class)->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old])->assertSee('生效中')
            ->assertSee('闲时 0.5 倍')->removeTableFilter('current')->assertCanSeeTableRecords([$new, $old])->assertSee('已替代');
    }
}
