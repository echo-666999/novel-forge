<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 单章放宽的 AI 修订轮次上限；为空时使用全局默认上限。 */
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->unsignedSmallInteger('revision_round_limit')->nullable()->comment('本章放宽后的 AI 修订轮次上限，为空时用全局上限');
        });
    }

    /** 回滚时移除单章上限。 */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('revision_round_limit');
        });
    }
};
