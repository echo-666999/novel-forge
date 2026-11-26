<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 待核台账独立请求的不可变产物：与事件提取分开，提取保持步骤指令在前，台账使用共享资料前置。 */
    public function up(): void
    {
        Schema::create('chapter_open_item_extractions', function (Blueprint $table): void {
            $table->comment('章节待核事项提取');
            $table->id()->comment('主键');
            $table->unsignedBigInteger('submission_run_id')->unique()->comment('准备运行');
            $table->unsignedBigInteger('novel_id')->comment('所属小说');
            $table->unsignedBigInteger('chapter_id')->comment('所属章节');
            $table->unsignedBigInteger('version_id')->comment('正文版本');
            $table->string('body_hash', 64)->comment('受检正文摘要');
            $table->jsonb('result')->comment('待核事项变化及证据');
            $table->string('result_hash', 64)->comment('结果摘要');
            $table->foreignId('cost_log_id')->nullable()->comment('请求费用')->constrained('cost_logs')->restrictOnDelete();
            $table->timestampTz('created_at')->comment('保存时间');
            $table->foreign(['submission_run_id', 'novel_id', 'chapter_id', 'version_id'], 'chapter_open_item_extractions_identity')->references(['id', 'novel_id', 'chapter_id', 'version_id'])->on('chapter_submission_runs')->restrictOnDelete();
        });
        // 带台账的准备运行多一个台账步骤和一次请求；旧运行仍是两步两次。
        $this->constrainRuns("requests_used>=0 AND request_limit IN (2,3) AND requests_used<=request_limit AND expected_state_version>=0 AND status IN ('pending','running','failed','awaiting_approval','ready','committed') AND step IN ('review','extract','ledger')");
    }

    /** 替换准备运行的取值约束；仅 PostgreSQL 有该约束。 */
    private function constrainRuns(string $expression): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $table = DB::connection()->getQueryGrammar()->wrapTable('chapter_submission_runs');
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT chapter_submission_runs_values');
        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT chapter_submission_runs_values CHECK ('.$expression.')');
    }

    /** 回滚时删除该表并恢复两步约束；已有台账步骤的运行时约束恢复会失败，须先处理这些运行。 */
    public function down(): void
    {
        Schema::dropIfExists('chapter_open_item_extractions');
        $this->constrainRuns("requests_used BETWEEN 0 AND 2 AND request_limit=2 AND expected_state_version>=0 AND status IN ('pending','running','failed','awaiting_approval','ready','committed') AND step IN ('review','extract')");
    }
};
