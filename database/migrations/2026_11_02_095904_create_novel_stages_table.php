<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 建立阶段执行记录基础结构及中文备注。 */
return new class extends Migration
{
    /** 创建表、关联约束和必要索引。 */
    public function up(): void
    {
        Schema::create('novel_stages', function (Blueprint $table): void {
            $table->comment('阶段执行记录');
            $table->id()->comment('主键');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->string('stage')->comment('阶段名称');
            $table->string('status')->default('pending')->comment('执行状态');
            $table->integer('execution_count')->default(0)->comment('执行次数');
            $table->jsonb('input')->nullable()->comment('输入快照');
            $table->jsonb('output')->nullable()->comment('输出快照');
            $table->text('error_message')->nullable()->comment('失败原因');
            $table->timestampTz('started_at')->nullable()->comment('开始时间');
            $table->timestampTz('finished_at')->nullable()->comment('结束时间');
            $table->index(['novel_id', 'stage', 'status']);
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
        });

        // 生产主库由 PostgreSQL 检查计数边界，SQLite 仅供既有页面测试使用。
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('novel_stages');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novel_stages_values_check CHECK (execution_count >= 0)");
        }
    }

    /** 撤销本表；有子记录时依赖外键保护并按逆序回滚。 */
    public function down(): void
    {
        Schema::dropIfExists('novel_stages');
    }
};
