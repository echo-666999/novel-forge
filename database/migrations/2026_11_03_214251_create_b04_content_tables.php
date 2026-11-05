<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 增量保存首章生产、不可变正文和推进证据，不建立正式版本指针。 */
return new class extends Migration
{
    /** 所有新增表和字段均提供简短中文备注。 */
    public function up(): void
    {
        Schema::create('chapter_generation_runs', function (Blueprint $table): void {
            $table->comment('首章生成运行');
            $table->id()->comment('主键');
            $table->foreignId('chapter_id')->comment('所属章节')->constrained()->restrictOnDelete();
            $table->foreignId('outline_chapter_id')->comment('来源章计划')->unique()->constrained('novel_outline_chapters')->restrictOnDelete();
            $table->jsonb('snapshot')->comment('冻结输入');
            $table->jsonb('config')->comment('冻结配置');
            $table->string('input_hash', 64)->comment('输入摘要');
            $table->jsonb('plan_data')->nullable()->comment('首章细纲候选');
            $table->foreignId('fine_stage_id')->nullable()->comment('细纲阶段')->constrained('novel_stages')->restrictOnDelete();
            $table->string('status')->default('pending')->comment('运行状态');
            $table->string('next_step')->comment('下一步骤');
            $table->uuid('invocation_id')->comment('归属令牌');
            $table->integer('requests_used')->default(0)->comment('已领用请求');
            $table->integer('request_limit')->comment('请求上限');
            $table->integer('retry_count')->default(0)->comment('已用修订次数');
            $table->string('stop_reason')->nullable()->comment('停止原因');
            $table->jsonb('control_history')->comment('操作历史');
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
            $table->unique(['id', 'chapter_id']);
        });
        Schema::create('chapter_versions', function (Blueprint $table): void {
            $table->comment('正文候选版本');
            $table->id()->comment('主键');
            $table->foreignId('chapter_id')->comment('所属章节')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('run_id')->comment('来源运行');
            $table->foreignId('source_stage_id')->comment('来源阶段')->unique()->constrained('novel_stages')->restrictOnDelete();
            $table->unsignedBigInteger('parent_version_id')->nullable()->comment('父正文版本');
            $table->integer('version')->comment('版本序号');
            $table->string('title')->comment('具体章名');
            $table->text('body')->comment('候选正文');
            $table->string('body_hash', 64)->comment('正文摘要');
            $table->integer('character_count')->comment('可见字符数');
            $table->string('count_version')->comment('计数算法版本');
            $table->timestampTz('created_at')->comment('创建时间');
            $table->unique(['chapter_id', 'version']);
            $table->unique(['id', 'chapter_id']);
            $table->foreign(['run_id', 'chapter_id'])->references(['id', 'chapter_id'])->on('chapter_generation_runs')->restrictOnDelete();
            $table->foreign(['parent_version_id', 'chapter_id'])->references(['id', 'chapter_id'])->on('chapter_versions')->restrictOnDelete();
        });
        Schema::create('chapter_progression_checks', function (Blueprint $table): void {
            $table->comment('正文推进检查');
            $table->id()->comment('主键');
            $table->foreignId('chapter_id')->comment('所属章节')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('version_id')->comment('检查正文版本');
            $table->foreignId('source_stage_id')->comment('检查阶段')->unique()->constrained('novel_stages')->restrictOnDelete();
            $table->string('body_hash', 64)->comment('受检正文摘要');
            $table->string('verdict')->comment('检查结论');
            $table->jsonb('result')->comment('逐点检查证据');
            $table->timestampTz('created_at')->comment('创建时间');
            $table->foreign(['version_id', 'chapter_id'])->references(['id', 'chapter_id'])->on('chapter_versions')->restrictOnDelete();
        });
        Schema::table('chapters', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_candidate_version_id')->nullable()->comment('当前候选版本');
            $table->jsonb('progression_result')->nullable()->comment('当前推进检查摘要');
            $table->foreign(['current_candidate_version_id', 'id'])->references(['id', 'chapter_id'])->on('chapter_versions')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $runs = $grammar->wrapTable('chapter_generation_runs');
            $versions = $grammar->wrapTable('chapter_versions');
            $checks = $grammar->wrapTable('chapter_progression_checks');
            DB::statement("ALTER TABLE {$runs} ADD CONSTRAINT b04_run_values CHECK (requests_used >= 0 AND request_limit >= requests_used AND retry_count >= 0 AND status IN ('pending','running','paused','error','completed') AND next_step IN ('fine','draft','check','revise'))");
            DB::statement("ALTER TABLE {$versions} ADD CONSTRAINT b04_version_values CHECK (version > 0 AND character_count > 0)");
            DB::statement("ALTER TABLE {$checks} ADD CONSTRAINT b04_check_values CHECK (verdict IN ('pass','insufficient','blocked'))");
        }
    }

    /** 有已付费产物时拒绝回滚，测试只能先清理已核验隔离库的夹具。 */
    public function down(): void
    {
        if (DB::table('chapter_generation_runs')->exists() || DB::table('chapter_versions')->exists()) {
            throw new RuntimeException('已有首章运行或正文，拒绝删除 B04 历史');
        }
        Schema::table('chapters', function (Blueprint $table): void {
            $table->dropForeign(['current_candidate_version_id', 'id']);
            $table->dropColumn(['current_candidate_version_id', 'progression_result']);
        });
        Schema::dropIfExists('chapter_progression_checks');
        Schema::dropIfExists('chapter_versions');
        Schema::dropIfExists('chapter_generation_runs');
    }
};
