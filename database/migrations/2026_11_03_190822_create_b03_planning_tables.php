<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 增量建立确认设定和大纲候选，不写入正式章节或故事状态。 */
return new class extends Migration
{
    /** 使用限制删除外键保留作者决定、来源尝试和候选章的完整关联。 */
    public function up(): void
    {
        Schema::create('novel_setting_confirmations', function (Blueprint $table): void {
            $table->comment('设定确认快照');
            $table->id()->comment('主键');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->foreignId('source_stage_id')->comment('来源设定尝试')->constrained('novel_stages')->restrictOnDelete();
            $table->string('source_hash', 64)->comment('来源摘要');
            $table->jsonb('setting')->comment('确认设定');
            $table->jsonb('decisions')->comment('作者问题决定');
            $table->string('content_hash', 64)->comment('确认内容摘要');
            $table->foreignId('confirmed_by')->comment('确认用户')->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->comment('确认时间');
            $table->unique(['novel_id', 'content_hash']);
        });
        Schema::create('novel_outline_plans', function (Blueprint $table): void {
            $table->comment('分批大纲计划');
            $table->id()->comment('主键');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->foreignId('setting_confirmation_id')->comment('确认设定版本')->unique()->constrained('novel_setting_confirmations')->restrictOnDelete();
            $table->integer('target_chapters')->comment('目标章数');
            $table->jsonb('snapshot')->comment('冻结规划输入');
            $table->jsonb('config')->comment('冻结调用参数');
            $table->jsonb('roadmap')->nullable()->comment('全书走向候选');
            $table->jsonb('handoff')->nullable()->comment('批次延续摘要');
            $table->bigInteger('next_chapter')->default(1)->comment('下批起始章');
            $table->string('status')->default('pending')->comment('计划状态');
            $table->uuid('invocation_id')->comment('当前投递令牌');
            $table->string('stop_reason')->nullable()->comment('停止原因');
            $table->jsonb('control_history')->comment('人工操作记录');
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
            $table->index(['novel_id', 'status']);
        });
        Schema::create('novel_outline_chapters', function (Blueprint $table): void {
            $table->comment('大纲候选章节');
            $table->id()->comment('主键');
            $table->foreignId('outline_plan_id')->comment('所属大纲计划')->constrained('novel_outline_plans')->restrictOnDelete();
            $table->foreignId('source_stage_id')->comment('来源大纲尝试')->constrained('novel_stages')->restrictOnDelete();
            $table->integer('chapter_number')->comment('全书章序');
            $table->string('title')->comment('具体章名');
            $table->text('summary')->comment('章节摘要');
            $table->text('objective')->comment('章节目标');
            $table->text('conflict')->comment('主要冲突');
            $table->text('hook')->comment('章末钩子');
            $table->jsonb('progression_points')->comment('强制推进点');
            $table->unique(['outline_plan_id', 'chapter_number']);
        });
        // 主库从数据库层保护游标和状态；SQLite 由契约及应用服务验证。
        if (DB::connection()->getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $plans = $grammar->wrapTable('novel_outline_plans');
            $chapters = $grammar->wrapTable('novel_outline_chapters');
            DB::statement("ALTER TABLE {$plans} ADD CONSTRAINT b03_plan_values_check CHECK (target_chapters > 0 AND next_chapter >= 1 AND next_chapter::bigint <= target_chapters::bigint + 1 AND status IN ('pending','running','paused','error','completed'))");
            DB::statement("ALTER TABLE {$chapters} ADD CONSTRAINT b03_chapter_number_check CHECK (chapter_number > 0)");
        }
    }

    /** 有候选产物时拒绝直接回滚，防止删除作者决定和已付费输出。 */
    public function down(): void
    {
        if (DB::table('novel_setting_confirmations')->exists() || DB::table('novel_outline_plans')->exists()) {
            throw new RuntimeException('已有设定确认或大纲计划，拒绝删除 B03 历史');
        }
        Schema::dropIfExists('novel_outline_chapters');
        Schema::dropIfExists('novel_outline_plans');
        Schema::dropIfExists('novel_setting_confirmations');
    }
};
