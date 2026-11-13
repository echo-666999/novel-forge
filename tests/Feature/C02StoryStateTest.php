<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewStoryState;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\StoryEvent;
use App\Models\StoryStateProjection;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\StoryStateReplay;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/** 隔离验证历史回放、来源损坏拒绝及后台章节切换，所有请求禁止外网。 */
class C02StoryStateTest extends TestCase
{
    use DatabaseMigrations;

    /** 项目业务迁移仅在测试内存库运行。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 后台账号与外网隔离。 */
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    /** 仅清理内存库夹具，遵循正式历史迁移禁止回滚的保护。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 构造六章独立历史：第五章仍在旧店，第六章搬往新店并新增人物。
     * @return array{0: Novel, 1: StoryEntity}
     */
    public static function history(bool $includeLife = false): array
    {
        $novel = Novel::factory()->create();
        $actor = StoryEntity::factory()->create(['novel_id' => $novel->id, 'name' => '苏禾', 'normalized_name' => '苏禾']);
        $state = ['entities' => []];
        foreach (range(1, 6) as $number) {
            $chapter = Chapter::factory()->create(['novel_id' => $novel->id, 'number' => $number]);
            $run = ChapterGenerationRun::factory()->create(['chapter_id' => $chapter->id]);
            $body = '苏禾在'.($number === 6 ? '新店' : '旧店').'修补雨伞。';
            $version = ChapterVersion::factory()->create(['run_id' => $run->id, 'body' => $body, 'body_hash' => hash('sha256', $body)]);
            $submission = ChapterSubmissionRun::factory()->create(['version_id' => $version->id]);
            $validation = ChapterSubmissionValidation::factory()->create(['submission_run_id' => $submission->id]);
            $commit = CanonicalCommit::factory()->create(['validation_id' => $validation->id, 'previous_state_version' => $number - 1, 'state_version' => $number, 'result' => ['body_hash' => $version->body_hash, 'event_count' => 1]]);
            $chapter->update(['formal_version_id' => $version->id, 'canonical_commit_id' => $commit->id]);
            $ref = 'entity-'.$actor->id;
            $patch = ['entity_ref' => $ref, 'property' => 'location', 'value_ref' => '', 'value_text' => $number === 6 ? '新店' : '旧店', 'before_ref' => '', 'fact_category' => 'objective_confirmed', 'source_event_key' => 'event-1'];
            $targets = $number === 6 ? ['entity-'.StoryEntity::factory()->create(['novel_id' => $novel->id, 'name' => '未来访客', 'normalized_name' => '未来访客'])->id] : [];
            $patches = [$patch];
            if ($includeLife) {
                $patches[] = [...$patch, 'property' => 'life_status', 'value_text' => $number === 6 ? 'dead' : 'alive'];
                $patches[] = [...$patch, 'property' => 'belief', 'value_text' => '听说新店可租', 'fact_category' => 'rumor'];
            }
            $event = StoryEvent::factory()->create(['commit_id' => $commit->id, 'actor_entity_id' => $actor->id,
                'payload' => ['time_scope' => 'present', 'summary' => $body, 'target_refs' => $targets, 'patch' => $patches],
                'evidence' => [['paragraph' => 1, 'start' => 0, 'end' => mb_strlen($body), 'quote' => $body]]]);
            $state['entities'][$ref]['location'] = ['value_ref' => '', 'value_text' => $patch['value_text'], 'fact_category' => 'objective_confirmed', 'source_event_key' => 'event-1', 'source_event_id' => $event->id];
            if ($includeLife) {
                $state['entities'][$ref]['life_status'] = [...$state['entities'][$ref]['location'], 'value_text' => $number === 6 ? 'dead' : 'alive'];
                $state['entities'][$ref]['belief'][] = [...$state['entities'][$ref]['location'], 'value_text' => '听说新店可租', 'fact_category' => 'rumor'];
            }
            StoryStateProjection::factory()->create(['commit_id' => $commit->id, 'state' => $state, 'state_hash' => PlanningJson::hash($state)]);
        }

        return [$novel, $actor];
    }

    /** 后来发生的搬迁和新人物不能污染第五章；切换页面后可见第六章变化。 */
    public function test_history_is_scoped_and_page_switches_without_writes_or_ai(): void
    {
        [$novel, $actor] = $this->history();
        $before = DB::table('story_state_projections')->get()->toJson();
        $replay = app(StoryStateReplay::class)->atChapter($novel, 5);
        $this->assertSame('旧店', $replay['state']['entities']['entity-'.$actor->id]['location']['value_text']);
        $this->assertCount(5, $replay['events']);
        $this->assertCount(1, $replay['entities']);
        $page = Livewire::test(ViewStoryState::class, ['record' => $novel->id])->callAction('selectChapter', data: ['number' => 5])
            ->assertRedirect(NovelResource::getUrl('state', ['record' => $novel, 'chapter' => 5]));
        // 跳转目标必须从地址章号重建内容，不能只验证动作内存属性。
        $page = Livewire::withQueryParams(['chapter' => 5])->test(ViewStoryState::class, ['record' => $novel->id])->assertSee('第5章提交后')->assertSee('旧店')->assertSee('未记录：存亡')->assertSee('状态版本 #5')->assertSee('累计事件 5 条')->assertSee('第5章 · 事件 #')->assertSee('第1段：苏禾在旧店修补雨伞。')->assertDontSee('未来访客');
        $page->callAction('selectChapter', data: ['number' => 6])->assertRedirect(NovelResource::getUrl('state', ['record' => $novel, 'chapter' => 6]));
        $page = Livewire::withQueryParams(['chapter' => 6])->test(ViewStoryState::class, ['record' => $novel->id])->assertSee('新店')->assertSee('未来访客');
        $page->set('chapterNumber', 7)->assertSee('所选章节尚未正式提交')->assertDontSee('未来访客');
        $this->assertSame($before, DB::table('story_state_projections')->get()->toJson());
        $this->assertDatabaseCount('cost_logs', 0);
        Http::assertNothingSent();
    }

    /** 生死状态按章节冻结；传闻持续追加且不会升级为客观事实。 */
    public function test_life_status_and_subjective_history_remain_distinct(): void
    {
        [$novel, $actor] = $this->history(true);
        $service = app(StoryStateReplay::class);
        $fifth = $service->atChapter($novel, 5);
        $sixth = $service->atChapter($novel, 6);
        $ref = 'entity-'.$actor->id;
        $this->assertSame('alive', $fifth['state']['entities'][$ref]['life_status']['value_text']);
        $this->assertSame('dead', $sixth['state']['entities'][$ref]['life_status']['value_text']);
        $this->assertCount(5, $fifth['state']['entities'][$ref]['belief']);
        $this->assertSame('rumor', $fifth['state']['entities'][$ref]['belief'][0]['fact_category']);
        $this->assertStringContainsString('存亡：存活', $fifth['entities'][0]['facts']);
        $this->assertStringContainsString('传闻', $fifth['entities'][0]['facts']);
    }

    /** 空小说不能通过传入另一小说的章号读取外部历史。 */
    public function test_empty_novel_and_guest_access_are_rejected(): void
    {
        $this->history();
        $empty = Novel::factory()->create();
        Livewire::test(ViewStoryState::class, ['record' => $empty->id])->set('chapterNumber', 5)->assertSee('尚未正式提交')->assertDontSee('苏禾');
        auth()->logout();
        $this->get(NovelResource::getUrl('state', ['record' => $empty]))->assertRedirect();
    }

    /** 快照被篡改但摘要同步更新也必须被事件重放发现。 */
    public function test_replay_rejects_corrupted_projection_even_with_matching_hash(): void
    {
        [$novel] = $this->history();
        $bad = ['entities' => []];
        DB::table('story_state_projections')->where('state_version', 3)->update(['state' => json_encode($bad), 'state_hash' => PlanningJson::hash($bad)]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('事件回放与正式状态快照不一致');
        app(StoryStateReplay::class)->atChapter($novel, 5);
    }

    /** 证据被替换后拒绝展示可信状态。 */
    public function test_replay_rejects_evidence_not_in_formal_body(): void
    {
        [$novel] = $this->history();
        DB::table('story_events')->where('id', StoryEvent::min('id'))->update(['evidence' => json_encode([['paragraph' => 1, 'start' => 0, 'end' => 2, 'quote' => '伪造']])]);
        Livewire::test(ViewStoryState::class, ['record' => $novel->id])->assertSee('事件证据与正式正文不一致')->assertDontSee('事件回放与正式快照一致');
    }
}
