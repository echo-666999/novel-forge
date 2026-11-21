<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 长上下文档位增加缓存写入单价：GPT-6.1 每次请求都会写入缓存，长上下文时写入价也翻倍。 */
    public function up(): void
    {
        Schema::table('model_prices', function (Blueprint $table): void {
            $table->decimal('long_cache_write_per_million', 20, 8)->nullable()->comment('长上下文每百万缓存写入Token单价；为空时长上下文请求有缓存写入则金额未知');
        });
    }

    /** 回滚时移除该列。 */
    public function down(): void
    {
        Schema::table('model_prices', fn (Blueprint $table) => $table->dropColumn('long_cache_write_per_million'));
    }
};
