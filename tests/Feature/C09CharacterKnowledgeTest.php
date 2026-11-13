<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\ViewCharacterKnowledge;
use App\Models\StoryEntity;
use App\Models\User;
use App\Services\Pipeline\CharacterKnowledge;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** 角色视角按正式章和来源隔离，不由位置或参与事件推断知识。 */
class C09CharacterKnowledgeTest extends TestCase
{
    use DatabaseMigrations;

    /** 隔离内存库迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 清理隔离历史，不触碰开发正文。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 传闻保留分类与来源章位；未来访客不能泄漏到旧章。 */
    public function test_knowledge_is_scoped_to_formal_chapter_and_actor(): void
    {
        [$novel, $actor] = C02StoryStateTest::history(true);
        $data = app(CharacterKnowledge::class)->atChapter($novel, 5, $actor->id);
        $this->assertCount(5, $data['rows']);
        $this->assertSame('传闻', $data['rows'][0]['category']);
        $this->assertSame(1, $data['rows'][0]['chapter']);
        $this->assertSame('信念记录', $data['rows'][0]['kind']);
        $this->assertCount(1, $data['characters']);
        $future = StoryEntity::where('name', '未来访客')->firstOrFail();
        $this->expectException(InvalidArgumentException::class);
        app(CharacterKnowledge::class)->atChapter($novel, 5, $future->id);
    }

    /** 外书人物不得作为当前书的POV查询参数。 */
    public function test_other_book_actor_is_rejected(): void
    {
        [$novel] = C02StoryStateTest::history();
        $other = StoryEntity::factory()->create();
        $this->expectException(InvalidArgumentException::class);
        app(CharacterKnowledge::class)->atChapter($novel, 5, $other->id);
    }

    /** 页面空认知仍显示来源事件，并明确未记录不等于角色不知道。 */
    public function test_page_has_honest_empty_knowledge_and_no_external_requests(): void
    {
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        [$novel, $actor] = C02StoryStateTest::history();
        Livewire::withQueryParams(['chapter' => 5, 'actor' => $actor->id])->test(ViewCharacterKnowledge::class, ['record' => $novel->id])
            ->assertSee('未记录明确认知')->assertSee('旧店')->assertDontSee('未来访客');
        $this->assertSame([], app(CharacterKnowledge::class)->atChapter($novel, 5, $actor->id)['rows']);
        Http::assertNothingSent();
    }

    /** 紧凑展示：顶部数量概览，事件以章号与主体作小标题、类别作标签，证据压成单行；未选角色时不显示认知分区。 */
    public function test_page_shows_compact_overview_and_event_cards(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        [$novel, $actor] = C02StoryStateTest::history();

        Livewire::withQueryParams(['chapter' => 5])->test(ViewCharacterKnowledge::class, ['record' => $novel->id])
            ->assertSee('正式事件 5 条')->assertSee('已登场人物 1 人')
            ->assertSee('第5章 · 苏禾')->assertSee('客观已确认')->assertSee('第1段：苏禾在旧店修补雨伞。')
            ->assertDontSee('角色认知与信念');

        Livewire::withQueryParams(['chapter' => 5, 'actor' => $actor->id])->test(ViewCharacterKnowledge::class, ['record' => $novel->id])
            ->assertSee('苏禾主导的事件 5 条')->assertSee('认知与信念 0 条')->assertSee('角色认知与信念');
    }
}
