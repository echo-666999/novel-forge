<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 连续章节授权独立冻结，旧首章授权保持一章范围。 */
return new class extends Migration
{
    /** 新增字段的中文备注说明授权上限，不代表已完成章数。 */
    public function up(): void
    {
        Schema::table('first_chapter_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('chapter_limit')->default(1)->comment('本次自动授权章节上限，旧记录仅授权首章');
        });
    }

    /** 已存在多章授权时禁止回滚丢失授权范围。 */
    public function down(): void
    {
        if (DB::table('first_chapter_runs')->where('chapter_limit', '>', 1)->exists()) {
            throw new LogicException('已有连续章节授权，禁止删除范围记录');
        }
        Schema::table('first_chapter_runs', fn (Blueprint $table) => $table->dropColumn('chapter_limit'));
    }
};
