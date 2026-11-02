<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 建立小说基础结构及中文备注。 */
return new class extends Migration
{
    /** 创建表、关联约束和必要索引。 */
    public function up(): void
    {
        Schema::create('novels', function (Blueprint $table): void {
            $table->comment('小说');
            $table->id()->comment('主键');
            $table->string('title')->comment('小说标题');
            $table->string('genre')->nullable()->comment('小说题材');
            $table->text('premise_raw')->comment('原始创意');
            $table->integer('target_chapters')->comment('目标章数');
            $table->jsonb('chapter_length_policy')->nullable()->comment('篇幅策略');
            $table->string('status')->default('pending')->comment('运行状态');
            $table->string('current_stage')->nullable()->comment('当前阶段');
            $table->integer('execution_count')->default(0)->comment('累计执行次数');
            $table->integer('max_execution_count')->default(5)->comment('执行次数上限');
            $table->decimal('progress', 5, 2)->default(0)->comment('进度百分比');
            $table->integer('chapters_done')->default(0)->comment('已完成章数');
            $table->integer('chapters_total')->default(0)->comment('运行计划章数');
            $table->bigInteger('state_version')->default(0)->comment('故事状态版本');
            $table->index(['status', 'updated_at']);
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
        });

        // 生产主库由 PostgreSQL 检查计数边界，SQLite 仅供既有页面测试使用。
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('novels');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novels_values_check CHECK (target_chapters > 0 AND execution_count >= 0 AND max_execution_count > 0 AND progress BETWEEN 0 AND 100 AND chapters_done >= 0 AND chapters_total >= 0 AND state_version >= 0)");
        }
    }

    /** 撤销本表；有子记录时依赖外键保护并按逆序回滚。 */
    public function down(): void
    {
        Schema::dropIfExists('novels');
    }
};
