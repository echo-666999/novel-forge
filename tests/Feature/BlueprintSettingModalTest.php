<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\BlueprintVersions;
use App\Models\NovelSettingConfirmation;
use App\Models\User;
use App\Services\Pipeline\SettingVersionDiff;
use Database\Factories\NovelSettingConfirmationFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** “查看完整设定”弹窗按重点分区展示，作者决定与待决定问题突出，未知字段不丢失。 */
class BlueprintSettingModalTest extends TestCase
{
    use DatabaseMigrations;

    /** 只运行项目迁移与设置迁移，跳过 SQLite 不支持的扩展迁移。
     * @return array{'--path': list<string>}
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 后台资源页依赖当前面板。 */
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    /** 只清理已确认的内存库。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 核心矛盾、作者决定、阻塞问题、人物卡片、规则与保留细节分区展示，额外字段归入“其他”。 */
    public function test_modal_groups_setting_and_highlights_decisions(): void
    {
        $setting = [...NovelSettingConfirmationFactory::setting(),
            'open_questions' => [['question' => '师父失踪当晚是否有目击者？', 'impact' => '决定第一条线索来源', 'blocking' => true]],
            'themes' => ['信任与验证']];
        $confirmation = NovelSettingConfirmation::factory()->create(['setting' => $setting,
            'decisions' => [['question' => '来信由谁寄出？', 'answer' => '来信者身份留到终局揭示。', 'impact' => '影响伏笔安排', 'blocking' => false]]]);

        $page = Livewire::test(BlueprintVersions::class, ['record' => $confirmation->novel_id])->mountTableAction('inspect', $confirmation);
        $action = $page->instance()->getMountedAction();
        $this->assertSame('完整设定 · 确认记录 #'.$confirmation->id, $action->getModalHeading());
        $modal = $action->getModalContent()->render();

        foreach (['核心矛盾', '调查者在误导信息中寻找可信证据。', '待决定问题（1）', '师父失踪当晚是否有目击者？', '阻塞', '作者决定（1）',
            '来信者身份留到终局揭示。', '影响：影响伏笔安排', '林澈', '来信只提供线索，调查必须由人物完成。', '原始创意中必须保留的细节（2）',
            'themes / 1：信任与验证', '摘要校验有效', '尚无大纲引用此记录'] as $text) {
            $this->assertStringContainsString($text, $modal);
        }
        // 重点区（核心矛盾与待决定问题）排在作者决定和人物之前。
        $this->assertLessThan(strpos($modal, '作者决定（1）'), strpos($modal, '师父失踪当晚是否有目击者？'));
        $this->assertLessThan(strpos($modal, '林澈'), strpos($modal, '来信者身份留到终局揭示。'));
    }

    /** 字段级对照：插入一条规则只报一处新增，人物按姓名、决定按问题匹配，相同版本没有变化。 */
    public function test_setting_diff_matches_by_meaning_not_line_position(): void
    {
        $old = NovelSettingConfirmationFactory::setting();
        $old['world']['rules'] = ['规则甲', '规则乙'];
        $old['characters'][] = ['name' => '老周', 'role' => '邻居'];
        $decisions = [['question' => '来信由谁寄出？', 'answer' => '终局揭示。', 'impact' => '伏笔', 'blocking' => false]];
        $new = $old;
        array_unshift($new['world']['rules'], '新规则');
        $new['characters'] = array_reverse($new['characters']);
        $new['characters'][1]['goal'] = '找到师父的下落';
        $new['preserved_details'][] = '灯塔';
        $newDecisions = [['question' => '来信由谁寄出？', 'answer' => '第一卷末揭示。', 'impact' => '伏笔', 'blocking' => false]];

        $diff = SettingVersionDiff::compare($old, $decisions, $new, $newDecisions);
        $changes = collect($diff['sections'])->flatMap(fn (array $section): array => $section['changes']);

        $this->assertFalse($diff['identical']);
        $this->assertSame(['modified' => 2, 'added' => 2, 'removed' => 0], array_intersect_key($diff['counts'], array_flip(['modified', 'added', 'removed'])));
        $this->assertTrue($changes->contains(fn (array $change): bool => $change['kind'] === 'added' && $change['label'] === '规则' && $change['new'] === '新规则'));
        $this->assertTrue($changes->contains(fn (array $change): bool => $change['label'] === '林澈 · 目标' && $change['old'] === '找到失踪师父' && $change['new'] === '找到师父的下落'));
        $this->assertTrue($changes->contains(fn (array $change): bool => $change['label'] === '来信由谁寄出？' && str_starts_with((string) $change['new'], '第一卷末揭示。')));
        $this->assertFalse($changes->contains(fn (array $change): bool => str_starts_with($change['label'], '老周')));

        $same = SettingVersionDiff::compare($old, $decisions, $old, $decisions);
        $this->assertTrue($same['identical']);
        $this->assertSame(0, $same['counts']['modified'] + $same['counts']['added'] + $same['counts']['removed']);
    }

    /** 只有一个确认版本时不提供对照；对照选项排除当前版本，结果按编号先后显示旧版到新版。 */
    public function test_compare_action_excludes_self_and_renders_field_diff(): void
    {
        $first = NovelSettingConfirmation::factory()->create();
        Livewire::test(BlueprintVersions::class, ['record' => $first->novel_id])->assertTableActionHidden('compare', $first);

        $setting = NovelSettingConfirmationFactory::setting();
        $setting['core_conflict'] = '调查者必须在两份互相矛盾的来信中判断真伪。';
        $second = NovelSettingConfirmation::factory()->create(['novel_id' => $first->novel_id, 'source_stage_id' => $first->source_stage_id, 'source_hash' => $first->source_hash, 'setting' => $setting]);

        $page = Livewire::test(BlueprintVersions::class, ['record' => $first->novel_id])
            ->assertTableActionVisible('compare', $second)
            ->mountTableAction('compare', $second);
        $form = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('确认记录 #'.$first->id.'（', $form);
        $this->assertStringNotContainsString('确认记录 #'.$second->id.'（', $form);

        $page->setTableActionData(['other' => $first->id])->callMountedTableAction()->assertHasNoTableActionErrors()
            ->assertSet('comparison', [$first->id, $second->id])
            ->assertSee('版本对照：确认记录 #'.$first->id.' → #'.$second->id)
            ->assertSee('修改 1')
            ->assertSee('调查者在误导信息中寻找可信证据。')
            ->assertSee('调查者必须在两份互相矛盾的来信中判断真伪。')
            ->assertSee('未变化的内容');
    }
}
