<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 首章自动编排升级为章节范围生产运行：一本书可有多次授权，旧记录及提交键保持不变。 */
return new class extends Migration
{
    /** 改名、去掉每书唯一约束并增加起始章与前序运行；旧记录起始章为1。 */
    public function up(): void
    {
        Schema::table('first_chapter_runs', function (Blueprint $table): void {
            $table->dropUnique(['novel_id']);
        });
        Schema::rename('first_chapter_runs', 'production_runs');
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->comment('章节范围自动生产运行与授权审计');
            $table->index('novel_id');
            $table->unsignedSmallInteger('start_chapter')->default(1)->comment('本次授权的起始章号，旧记录为第1章');
            $table->unsignedSmallInteger('chapter_limit')->default(1)->comment('本次授权的截止章号，旧记录为一章或十章')->change();
            $table->foreignId('predecessor_id')->nullable()->comment('同书上一次生产运行编号，首次授权为空')->constrained('production_runs')->restrictOnDelete();
        });
    }

    /** 已存在续写授权时拒绝回滚，避免丢失授权范围。 */
    public function down(): void
    {
        if (DB::table('production_runs')->where('start_chapter', '>', 1)->exists()
            || DB::table('production_runs')->select('novel_id')->groupBy('novel_id')->havingRaw('count(*) > 1')->exists()) {
            throw new LogicException('已有章节范围续写授权，禁止回滚删除');
        }
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('predecessor_id');
            $table->dropColumn('start_chapter');
            $table->dropIndex(['novel_id']);
        });
        Schema::rename('production_runs', 'first_chapter_runs');
        Schema::table('first_chapter_runs', function (Blueprint $table): void {
            $table->comment('新小说首章自动编排与授权审计');
            $table->unique('novel_id');
        });
    }
};
