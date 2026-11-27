<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 大纲章节的爽点节奏：兑现点、情绪最高点与钩子类型；旧章节为空，冻结的章计划保持不变。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_outline_chapters', function (Blueprint $table): void {
            $table->jsonb('rhythm')->nullable()->comment('爽点节奏：兑现点、情绪最高点与钩子类型');
        });
    }

    public function down(): void
    {
        Schema::table('novel_outline_chapters', function (Blueprint $table): void {
            $table->dropColumn('rhythm');
        });
    }
};
