<?php

namespace Tests\Feature;

use App\Services\Ai\BuiltinPromptTemplates;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use ReflectionClassConstant;
use Tests\TestCase;

/** 设定与设定决策的新固定提示词：只替换仍是系统导入原文的发布版本，作者改过的版本不动，回滚恢复原版本。 */
class StyleRulesSettingPromptsTest extends TestCase
{
    use DatabaseMigrations;

    /** 仅迁移隔离库及设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 发布指针指向系统导入原文时发布新文本，原版本退役；回滚后恢复原版本并删除新版本。 */
    public function test_untouched_system_versions_are_replaced_and_restorable(): void
    {
        $migration = $this->migration();
        $bodies = (new ReflectionClassConstant($migration, 'BODIES'))->getValue();
        $original = $this->publish('builtin_setting', $bodies['builtin_setting']['old'], 0);

        $migration->up();

        $route = DB::table('prompt_template_routes')->where('scope', 'builtin_setting')->value('version_id');
        $this->assertSame($bodies['builtin_setting']['new'], DB::table('prompt_template_versions')->where('id', $route)->value('body'));
        $this->assertSame(BuiltinPromptTemplates::defaults()['setting'], $bodies['builtin_setting']['new']);
        $this->assertSame('retired', DB::table('prompt_template_versions')->where('id', $original)->value('status'));
        $this->assertTrue(DB::table('prompt_template_events')->where('from_version_id', $original)->where('to_version_id', $route)->where('action', 'publish')->exists());

        $migration->down();

        $this->assertSame($original, DB::table('prompt_template_routes')->where('scope', 'builtin_setting')->value('version_id'));
        $this->assertSame('published', DB::table('prompt_template_versions')->where('id', $original)->value('status'));
        $this->assertFalse(DB::table('prompt_template_versions')->where('id', $route)->exists());
    }

    /** 作者在后台发布过的版本不被迁移覆盖。 */
    public function test_author_published_versions_are_kept(): void
    {
        $authored = $this->publish('builtin_decision', '作者自己改写的设定决策指令。', 1);

        $this->migration()->up();

        $this->assertSame($authored, DB::table('prompt_template_routes')->where('scope', 'builtin_decision')->value('version_id'));
    }

    /** 迁移实例。 */
    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_27_013244_publish_style_rules_setting_prompts.php');
    }

    /** 把指定文本登记为该阶段当前发布版本。 */
    private function publish(string $scope, string $body, int $actor): int
    {
        $id = DB::table('prompt_template_versions')->insertGetId(['scope' => $scope, 'body' => $body, 'notes' => '测试发布', 'language' => 'zh-CN', 'status' => 'published',
            'content_hash' => PlanningJson::hash([$scope, $body]), 'created_by' => $actor, 'created_at' => now()]);
        DB::table('prompt_template_versions')->where('scope', $scope)->where('id', '!=', $id)->update(['status' => 'retired']);
        DB::table('prompt_template_routes')->where('scope', $scope)->update(['version_id' => $id]);

        return $id;
    }
}
