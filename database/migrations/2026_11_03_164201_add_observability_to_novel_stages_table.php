<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 增量补齐阶段可观测字段，旧记录不回填，以免伪造历史计量。 */
return new class extends Migration
{
    /** 新增可空费用快照、毫秒耗时及非负约束，保留原有业务数据。 */
    public function up(): void
    {
        Schema::table('novel_stages', function (Blueprint $table): void {
            $table->comment('阶段执行记录');
            $table->jsonb('cost')->nullable()->comment('费用快照');
            $table->bigInteger('duration_ms')->nullable()->comment('执行耗时毫秒');
        });

        // PostgreSQL 从数据库层保护耗时边界；空值表示尚未采集。
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('novel_stages');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novel_stages_duration_check CHECK (duration_ms IS NULL OR duration_ms >= 0)");
        }
    }

    /** 仅撤销本次新增结构，不删除小说、阶段或原有输入输出。 */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('novel_stages');
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT novel_stages_duration_check");
        }
        Schema::table('novel_stages', function (Blueprint $table): void {
            $table->dropColumn(['cost', 'duration_ms']);
        });
    }
};
