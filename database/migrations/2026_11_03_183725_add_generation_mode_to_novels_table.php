<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 增量冻结生成模式和非敏感参数，既有小说默认保持模拟。 */
return new class extends Migration
{
    /** 新字段仅补默认模式，不改写既有创意和阶段历史。 */
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->comment('小说');
            $table->string('generation_mode')->default('simulation')->comment('生成模式');
            $table->jsonb('generation_config')->nullable()->comment('生成参数快照');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('novels');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novels_generation_mode_check CHECK (generation_mode IN ('simulation', 'setting_real'))");
        }
    }

    /** 存在真实设定小说时拒绝丢失模式信息，避免回滚后将其当作模拟书。 */
    public function down(): void
    {
        if (DB::table('novels')->where('generation_mode', '<>', 'simulation')->exists()) {
            throw new RuntimeException('已有真实设定小说，拒绝删除生成模式字段');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('novels');
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT novels_generation_mode_check");
        }
        Schema::table('novels', function (Blueprint $table): void {
            $table->dropColumn(['generation_mode', 'generation_config']);
        });
    }
};
