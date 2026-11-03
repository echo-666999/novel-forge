<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\ListNovels;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Jobs\RunNovelPipelineJob;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\CreateNovelService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** 隔离验证后台建书、运行字段白名单、投递结果及只读权限。 */
class B01NovelCreationTest extends TestCase
{
    use DatabaseMigrations;

    /** 仅迁移应用表，SQLite 测试不执行 PostgreSQL 扩展迁移。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 使用工厂账号及假队列，任何表单操作都不能触发真实网络。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** 每项测试确认没有遗漏的供应商调用。 */
    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    /** 返回有换行和具体中文细节的合法输入。
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return ['title' => '归舟录', 'genre' => '悬疑', 'premise_raw' => "守灯人每晚收到未来的来信。\n她必须找到十年前失踪的师父。", 'target_chapters' => 10, 'chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500]];
    }

    /** 表单保存完整原文后只入队，返回可轮询列表，不在 HTTP 内运行阶段。 */
    public function test_form_creates_one_pending_novel_and_queues_start_after_commit(): void
    {
        $page = Livewire::test(CreateNovel::class)->assertSuccessful()->fillForm($this->input())->call('create')
            ->assertHasNoFormErrors()->assertRedirect(NovelResource::getUrl('index'))
            ->assertNotified('小说已创建，已提交启动请求');
        $novel = Novel::query()->sole();
        $this->assertSame($this->input()['premise_raw'], $novel->premise_raw);
        $this->assertSame($this->input()['chapter_length_policy'], $novel->chapter_length_policy);
        $this->assertSame(NovelStatus::Pending, $novel->status);
        $this->assertSame(0, $novel->execution_count);
        $this->assertSame(0, $novel->stages()->count());
        Queue::assertPushed(RunNovelPipelineJob::class, fn (RunNovelPipelineJob $job): bool => $job->novelId === $novel->id && $job->connection === 'redis' && $job->enableFineOutline);
        // Filament 提交锁在跳转前阻止同一组件再次创建，禁用“创建并继续”。
        $page->call('create');
        $this->assertSame(1, Novel::query()->count());
        Queue::assertPushed(RunNovelPipelineJob::class, 1);
        $this->assertFalse($page->instance()->canCreateAnother());
    }

    /** 服务忽略伪造的运行字段，并在实际投递时已能读到提交后的小说。 */
    public function test_service_uses_whitelist_and_freezes_current_settings(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->max_execution_count = 7;
        $settings->enable_fine_outline = false;
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (RunNovelPipelineJob $job): string {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(NovelStatus::Pending, Novel::findOrFail($job->novelId)->status);
            $this->assertFalse($job->enableFineOutline);

            return 'mock-receipt';
        });
        $result = app(CreateNovelService::class)->createAndStart([...$this->input(), 'status' => 'completed', 'execution_count' => 999, 'max_execution_count' => 999, 'state_version' => 99, 'progress' => 100]);
        $this->assertTrue($result->startupConfirmed);
        $this->assertSame(NovelStatus::Pending, $result->novel->status);
        $this->assertSame(7, $result->novel->max_execution_count);
        $this->assertSame(0, $result->novel->execution_count);
        $this->assertSame(0, $result->novel->state_version);
        $this->assertSame('0.00', $result->novel->progress);
    }

    /** 非法输入在任何持久化或入队之前失败。
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_never_creates_or_queues(array $changes): void
    {
        try {
            app(CreateNovelService::class)->createAndStart(array_replace_recursive($this->input(), $changes));
            $this->fail('非法输入必须被拒绝');
        } catch (ValidationException) {
            $this->assertSame(0, Novel::query()->count());
            Queue::assertNothingPushed();
        }
    }

    /** 提供空白、长度、数值、跨字段边界及 JSON 额外键的样本。
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidInput(): array
    {
        return [
            '空书名' => [['title' => '']], '全角空白' => [['title' => '　']],
            '超长书名' => [['title' => str_repeat('名', 256)]], '超长题材' => [['genre' => str_repeat('类', 256)]],
            '空创意' => [['premise_raw' => " \n\t"]], '零章数' => [['target_chapters' => 0]],
            '小数章数' => [['target_chapters' => 1.5]], '章数溢出' => [['target_chapters' => 2147483648]],
            '负字数' => [['chapter_length_policy' => ['min_characters' => -1]]],
            '范围反转' => [['chapter_length_policy' => ['max_characters' => 2000]]],
            '额外策略字段' => [['chapter_length_policy' => ['status' => 'completed']]],
        ];
    }

    /** 界面先给出字段级错误，不写入不合规篇幅。 */
    public function test_form_validates_length_range_and_required_fields(): void
    {
        Livewire::test(CreateNovel::class)->fillForm([...$this->input(), 'title' => '', 'target_chapters' => 0, 'chapter_length_policy' => ['min_characters' => 3000, 'max_characters' => 2000]])
            ->call('create')->assertHasFormErrors(['title' => 'required', 'target_chapters' => 'min', 'chapter_length_policy.max_characters' => 'gte']);
        $this->assertSame(0, Novel::query()->count());
        Queue::assertNothingPushed();
    }

    /** 投递异常保留原文并明确警告，不显示启动成功或要求重建小说。 */
    public function test_dispatch_failure_preserves_novel_and_warns_on_form(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('secret-不能回显'));
        Livewire::test(CreateNovel::class)->fillForm($this->input())->call('create')
            ->assertRedirect(NovelResource::getUrl('index'))->assertNotified('小说已保存，启动投递未确认');
        $novel = Novel::query()->sole();
        $this->assertSame(NovelStatus::Error, $novel->status);
        $this->assertSame($this->input()['premise_raw'], $novel->premise_raw);
        $this->assertSame(0, $novel->stages()->count());
    }

    /** 回执异常晚于消费者领用时，不得把已运行状态覆盖为启动异常。 */
    public function test_ambiguous_dispatch_does_not_overwrite_running_novel(): void
    {
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (RunNovelPipelineJob $job): void {
            Novel::query()->whereKey($job->novelId)->update(['status' => NovelStatus::Running, 'execution_count' => 1]);
            throw new RuntimeException('模拟投递回执中断');
        });
        $result = app(CreateNovelService::class)->createAndStart($this->input());
        $this->assertFalse($result->startupConfirmed);
        $this->assertSame(NovelStatus::Running, $result->novel->status);
        $this->assertSame(1, $result->novel->execution_count);
    }

    /** 保存事务中的异常不会留下小说，也不会投递启动请求。 */
    public function test_persistence_failure_rolls_back_and_does_not_dispatch(): void
    {
        Novel::created(static function (Novel $novel): void {
            throw new RuntimeException('模拟数据库写入后故障');
        });
        try {
            app(CreateNovelService::class)->createAndStart($this->input());
            $this->fail('保存异常必须传递');
        } catch (RuntimeException) {
            $this->assertSame(0, Novel::query()->count());
            Queue::assertNothingPushed();
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    /** 拒绝调用方外层事务，防止未提交小说被抢先消费。 */
    public function test_outer_transaction_is_rejected_without_writes(): void
    {
        $this->expectException(LogicException::class);
        DB::transaction(fn () => app(CreateNovelService::class)->createAndStart($this->input()));
    }

    /** 登录后可读列表与完整详情，作者输入保持文本转义且刷新不执行流程。 */
    public function test_list_and_details_are_read_only_searchable_and_filterable(): void
    {
        $novel = Novel::factory()->create(['title' => '归舟录', 'premise_raw' => '<script>unsafe()</script>', 'status' => NovelStatus::Pending]);
        $other = Novel::factory()->create(['title' => '另一条创意', 'status' => NovelStatus::Paused]);
        Livewire::test(ListNovels::class)->assertCanSeeTableRecords([$novel, $other])
            ->searchTable('归舟')->assertCanSeeTableRecords([$novel])->assertCanNotSeeTableRecords([$other]);
        Livewire::test(ListNovels::class)->filterTable('status', 'paused')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$novel]);
        Livewire::test(ViewNovel::class, ['record' => $novel->id])->assertSuccessful()->assertSee('归舟录')
            ->assertDontSee('<script>unsafe()</script>', false)->callAction('refreshStatus');
        $this->assertSame(0, $novel->fresh()->execution_count);
        Queue::assertNothingPushed();
    }

    /** 服务端策略和路由同时禁止编辑删除，未登录请求必须去登录页。 */
    public function test_private_routes_and_mutation_policy_are_enforced(): void
    {
        $novel = Novel::factory()->create();
        $this->assertTrue(Gate::allows('view', $novel));
        foreach (['update', 'delete', 'forceDelete', 'restore', 'replicate'] as $ability) {
            $this->assertFalse(Gate::allows($ability, $novel));
        }
        $this->assertFalse(Gate::allows('deleteAny', Novel::class));
        $this->get('/admin/novels/'.$novel->id.'/edit')->assertNotFound();
        $this->app['auth']->guard()->logout();
        foreach (['/admin/novels', '/admin/novels/create', '/admin/novels/'.$novel->id] as $url) {
            $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
        }
    }
}
