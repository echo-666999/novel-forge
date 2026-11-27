<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageStoryPresets;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\StoryPreset;
use App\Models\User;
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\StoryProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 题材包与文风预设：内置数据、建书冻结、后台增删改与 AI 起草示范段落。 */
class StoryPresetsTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移项目表和设置。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 后台账号与假队列，禁止意外网络请求。 */
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** 向导默认创建真实设定小说；还原模式后迁移回滚的保护才不拦截夹具清理。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('novels')->update(['generation_mode' => 'simulation']);
        parent::tearDown();
    }

    /** 合法建书输入。
     * @return array<string, mixed>
     */
    private function input(array $extra = []): array
    {
        return ['title' => '灶火人家', 'premise_raw' => '重生回到八十年代，摆摊卖卤味养活一家人。', 'target_chapters' => 10,
            'chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500], ...$extra];
    }

    /** 迁移带上六个内置题材包和六个内置文风，避开现实生活与现实悬疑。 */
    public function test_builtin_presets_are_seeded(): void
    {
        $this->assertSame(['era', 'rebirth', 'transmigration', 'history', 'alt_history', 'xuanhuan'], array_keys(StoryPreset::options('genre')));
        $this->assertSame(['humorous', 'rigorous', 'colloquial', 'passionate', 'sweet', 'classical'], array_keys(StoryPreset::options('style')));
        foreach (StoryPreset::all() as $preset) {
            $this->assertSame($preset->content, StoryProfile::normalize($preset->kind, $preset->content), $preset->key.'的内置内容应已是规范结构');
        }
    }

    /** 建书冻结整份副本：之后修改或删除列表项都不影响本书输入；没填题材文字时用题材包名称。 */
    public function test_creation_freezes_a_copy_into_the_novel(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart($this->input(['genre_pack' => 'era', 'style_preset' => 'colloquial']))->novel;
        $this->assertSame('年代', $novel->genre);
        $this->assertSame(StoryProfile::VERSION, $novel->story_profile['version']);
        $frozen = $novel->planningInput()['story_profile'];
        StoryPreset::where('key', 'colloquial')->update(['name' => '改过的名字']);
        $this->assertSame($frozen, $novel->fresh()->planningInput()['story_profile']);
        $this->assertSame('生活口语 · 泼辣', $frozen['style']['name']);
        $this->assertArrayNotHasKey('story_profile', app(CreateNovelService::class)->createAndStart($this->input(['title' => '未选题材']))->novel->planningInput());
    }

    /** 停用的项不能用于建书，也不出现在下拉框。 */
    public function test_disabled_preset_cannot_be_used(): void
    {
        StoryPreset::where('key', 'era')->update(['disabled_at' => now()]);
        $this->assertArrayNotHasKey('era', StoryPreset::options('genre'));
        $this->expectException(ValidationException::class);
        app(CreateNovelService::class)->createAndStart($this->input(['genre_pack' => 'era']));
    }

    /** 向导选题材包后自动带出推荐文风，建书后两者都冻结。 */
    public function test_wizard_suggests_style_from_genre(): void
    {
        Livewire::test(CreateNovel::class)->fillForm($this->input())->set('data.genre_pack', 'xuanhuan')->assertSet('data.style_preset', 'passionate')
            ->call('create')->assertHasNoFormErrors();
        $profile = Novel::sole()->story_profile;
        $this->assertSame(['xuanhuan', 'passionate'], [$profile['genre']['key'], $profile['style']['key']]);
    }

    /** 后台新建、修改（版本加一）、复制、停用、删除；内置项不能删除。 */
    public function test_admin_manages_presets(): void
    {
        $page = Livewire::test(ManageStoryPresets::class)->assertSee('幽默风趣')
            ->callAction('create', ['kind' => 'style', 'key' => 'dialect', 'name' => '东北话', 'content' => ['narrator_attitude' => '唠嗑', 'humor_level' => 'high']])
            ->assertHasNoActionErrors();
        $custom = StoryPreset::where('key', 'dialect')->sole();
        $this->assertFalse($custom->is_builtin);
        $this->assertSame('唠嗑', $custom->content['narrator_attitude']);
        $page->callTableAction('edit', $custom, ['content' => ['vocabulary' => '东北方言']])->assertHasNoTableActionErrors();
        $this->assertSame([2, '东北方言'], [$custom->fresh()->version, $custom->fresh()->content['vocabulary']]);
        $page->callTableAction('duplicate', $custom, ['key' => 'dialect_two', 'name' => '东北话二'])->assertHasNoTableActionErrors();
        $this->assertSame('东北方言', StoryPreset::where('key', 'dialect_two')->sole()->content['vocabulary']);
        $builtin = StoryPreset::where('key', 'humorous')->sole();
        $page->callTableAction('toggle', $builtin)->assertTableActionHidden('delete', $builtin);
        $this->assertNotNull($builtin->fresh()->disabled_at);
        $page->callTableAction('delete', $custom);
        $this->assertNull($custom->fresh());
        Livewire::test(ManageStoryPresets::class)->callAction('create', ['kind' => 'style', 'key' => 'dialect_two', 'name' => '重复'])->assertHasActionErrors(['key' => 'unique']);
    }

    /** AI 起草示范段落：一次请求，去掉编号和过短的行，替换现有示范并加版本。 */
    public function test_ai_drafts_style_samples(): void
    {
        config(['ai.providers.openai.key' => 'sk-isolated-style-samples', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        $text = "1. 老周把秤砣往案板上一磕，眼睛都不抬：“缺斤短两？您拿回家称，差一钱我赔您一斤。”\n\n2. 巷口的风裹着卤香往人鼻子里钻，排队的人越排越长，连对门卖烧饼的都探出头来瞅。\n\n好的\n\n3. 她攥着那张欠条，手抖得厉害，可开口的时候声音一点没抖：“今天这钱，您还也得还，不还也得还。”";
        Http::fake(['*' => Http::response(B02SettingTest::response($text))]);
        $preset = StoryPreset::where('key', 'colloquial')->sole();
        Livewire::test(ManageStoryPresets::class)->callTableAction('samples', $preset, ['model' => 'gpt-4.1-mini'])->assertHasNoTableActionErrors()->assertNotified('已写入 3 段示范');
        $samples = $preset->fresh()->content['samples'];
        $this->assertCount(3, $samples);
        $this->assertStringStartsWith('老周把秤砣', $samples[0]);
        $this->assertSame(2, $preset->fresh()->version);
        $this->assertSame('style-samples-v1', CostLog::sole()->prompt_version);
        Http::assertSentCount(1);
    }
}
