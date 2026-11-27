<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 评审严格度：新书默认宽松；已有的书保持原有逐项阻断（严格），作者可在小说页改。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->string('review_strictness', 16)->default('loose')->comment('提交评审严格度：loose只拦硬伤，standard加拦待核与已揭示，strict逐项阻断');
        });
        DB::table('novels')->update(['review_strictness' => 'strict']);
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->dropColumn('review_strictness');
        });
    }
};
