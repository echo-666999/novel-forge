<?php

use App\Services\Ai\BuiltinPromptTemplates;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 将现有固定提示词登记为初始发布版本；不回填或修改旧小说的冻结快照。 */
return new class extends Migration
{
    /** 数据迁移复用带中文备注的版本表；账号零表示系统导入，不冒充人工发布。 */
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (BuiltinPromptTemplates::defaults() as $stage => $body) {
                $scope = 'builtin_'.$stage;
                $hash = PlanningJson::hash([$scope, $body]);
                $id = DB::table('prompt_template_versions')->insertGetId([
                    'scope' => $scope, 'body' => $body, 'notes' => '系统导入现有固定提示词，初始文本与原程序一致。',
                    'language' => 'zh-CN', 'status' => 'published', 'content_hash' => $hash,
                    'test_report' => json_encode(['passed' => true, 'kind' => 'builtin-baseline-import', 'content_hash' => $hash, 'real_ai_requests' => 0], JSON_THROW_ON_ERROR),
                    'created_by' => 0, 'created_at' => now(),
                ]);
                DB::table('prompt_template_routes')->insert(['scope' => $scope, 'version_id' => $id]);
                DB::table('prompt_template_events')->insert(['scope' => $scope, 'to_version_id' => $id, 'action' => 'bootstrap', 'actor_id' => 0, 'created_at' => now()]);
            }
        });
    }

    /** 仅撤销本迁移写入的初始基线；作者发布、回滚或新建过内置模板版本时拒绝删除历史。 */
    public function down(): void
    {
        $scopes = array_keys(BuiltinPromptTemplates::scopes());
        $authored = DB::table('prompt_template_events')->whereIn('scope', $scopes)->where('action', '!=', 'bootstrap')->exists()
            || DB::table('prompt_template_versions')->whereIn('scope', $scopes)->where('created_by', '!=', 0)->exists();
        // 小说运行快照已保存完整正文与摘要，删除基线不改变历史请求；作者操作记录才是需保护的证据。
        // 内存测试库随进程销毁，不承载真实运行证据。
        $memoryTest = app()->runningUnitTests() && DB::connection()->getDatabaseName() === ':memory:';
        if (! $memoryTest && $authored) {
            throw new RuntimeException('内置提示词已有发布历史，请在后台切换版本，不得删除运行证据。');
        }
        DB::table('prompt_template_routes')->whereIn('scope', $scopes)->delete();
        DB::table('prompt_template_events')->whereIn('scope', $scopes)->delete();
        DB::table('prompt_template_versions')->whereIn('scope', $scopes)->delete();
    }
};
