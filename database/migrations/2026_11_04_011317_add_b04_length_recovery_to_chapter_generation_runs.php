<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 为篇幅修复建立可追溯后继运行，原运行及正文保持不变。 */
return new class extends Migration
{
    /** 增加同章来源约束及活动运行唯一性，兼容现有表前缀。 */
    public function up(): void
    {
        Schema::table('chapter_generation_runs', function (Blueprint $table): void {
            $table->comment('首章生成及修复运行');
            $table->unsignedBigInteger('parent_run_id')->nullable()->unique()->comment('上次暂停运行');
            $table->unsignedBigInteger('source_version_id')->nullable()->comment('扩写源正文');
            $table->integer('length_repair_count')->default(0)->comment('已用扩写次数');
            $table->foreign(['parent_run_id', 'chapter_id'])->references(['id', 'chapter_id'])->on('chapter_generation_runs')->restrictOnDelete();
            $table->foreign(['source_version_id', 'chapter_id'])->references(['id', 'chapter_id'])->on('chapter_versions')->restrictOnDelete();
            $table->dropUnique(['outline_chapter_id']);
        });
        $grammar = DB::connection()->getQueryGrammar();
        $runs = $grammar->wrapTable('chapter_generation_runs');
        $prefix = DB::getTablePrefix();
        $active = $grammar->wrap($prefix.'b04_active_run');
        $root = $grammar->wrap($prefix.'b04_root_run');
        DB::statement("CREATE UNIQUE INDEX {$active} ON {$runs} (chapter_id) WHERE status IN ('pending','running')");
        DB::statement("CREATE UNIQUE INDEX {$root} ON {$runs} (outline_chapter_id) WHERE parent_run_id IS NULL");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$runs} DROP CONSTRAINT b04_run_values");
            DB::statement("ALTER TABLE {$runs} ADD CONSTRAINT b04_run_values CHECK (requests_used >= 0 AND request_limit >= requests_used AND retry_count >= 0 AND length_repair_count BETWEEN 0 AND 2 AND status IN ('pending','running','paused','error','completed') AND next_step IN ('fine','draft','check','revise','length_repair'))");
        }
    }

    /** 有后继修复运行时不能撤销来源记录或恢复旧唯一约束。 */
    public function down(): void
    {
        if (DB::table('chapter_generation_runs')->whereNotNull('parent_run_id')->exists()) {
            throw new RuntimeException('已有篇幅恢复历史，拒绝回滚');
        }
        $grammar = DB::connection()->getQueryGrammar();
        foreach (['b04_active_run', 'b04_root_run'] as $index) {
            DB::statement('DROP INDEX '.$grammar->wrap(DB::getTablePrefix().$index));
        }
        $runs = $grammar->wrapTable('chapter_generation_runs');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$runs} DROP CONSTRAINT b04_run_values");
            DB::statement("ALTER TABLE {$runs} ADD CONSTRAINT b04_run_values CHECK (requests_used >= 0 AND request_limit >= requests_used AND retry_count >= 0 AND status IN ('pending','running','paused','error','completed') AND next_step IN ('fine','draft','check','revise'))");
        }
        Schema::table('chapter_generation_runs', function (Blueprint $table): void {
            $table->dropForeign(['parent_run_id', 'chapter_id']);
            $table->dropForeign(['source_version_id', 'chapter_id']);
            $table->dropUnique(['parent_run_id']);
            $table->dropColumn(['parent_run_id', 'source_version_id', 'length_repair_count']);
            $table->unique('outline_chapter_id');
            $table->comment('首章生成运行');
        });
    }
};
