<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 建立章节节点基础结构及中文备注。 */
return new class extends Migration
{
    /** 创建表、关联约束和必要索引。 */
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table): void {
            $table->comment('章节节点');
            $table->id()->comment('主键');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->integer('number')->comment('章节序号');
            $table->string('title')->comment('章节标题');
            $table->text('objective')->nullable()->comment('章节目标');
            $table->string('status')->default('pending')->comment('生产状态');
            $table->unique(['novel_id', 'number']);
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
        });

        // 生产主库由 PostgreSQL 检查计数边界，SQLite 仅供既有页面测试使用。
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('chapters');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chapters_values_check CHECK (number > 0)");
        }
    }

    /** 撤销本表；有子记录时依赖外键保护并按逆序回滚。 */
    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
