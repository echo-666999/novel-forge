<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 建书时冻结的题材包与文风预设整份副本；为空的旧书输入保持不变。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->jsonb('story_profile')->nullable()->comment('建书时冻结的题材包与文风预设副本');
        });
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->dropColumn('story_profile');
        });
    }
};
