<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 单价增加闲时折扣与长上下文档位，费用明细增加重算记录。 */
    public function up(): void
    {
        Schema::table('model_prices', function (Blueprint $table): void {
            $table->decimal('off_peak_ratio', 10, 8)->nullable()->comment('闲时价相对标准价的倍率，如0.5为半价；为空表示不分时段');
            $table->jsonb('peak_hours_utc')->nullable()->comment('高峰时段（UTC，周一至周五），如["01:00-04:00","06:00-10:00"]；其余时间按闲时价');
            $table->unsignedInteger('long_context_threshold')->nullable()->comment('输入Token超过此值时整次请求按长上下文单价计费；为空表示不分档');
            $table->decimal('long_input_per_million', 20, 8)->nullable()->comment('长上下文每百万输入Token单价');
            $table->decimal('long_cache_read_per_million', 20, 8)->nullable()->comment('长上下文每百万缓存读取Token单价');
            $table->decimal('long_output_per_million', 20, 8)->nullable()->comment('长上下文每百万输出Token单价（含推理）');
        });
        Schema::table('cost_logs', function (Blueprint $table): void {
            $table->jsonb('repricing_history')->default('[]')->comment('按更正后单价重算金额的记录，只追加');
        });
    }

    /** 回滚时移除新增列。 */
    public function down(): void
    {
        // 个别隔离测试会先删除费用表以模拟写入失败，回滚时跳过不存在的表。
        if (Schema::hasTable('cost_logs')) {
            Schema::table('cost_logs', fn (Blueprint $table) => $table->dropColumn('repricing_history'));
        }
        Schema::table('model_prices', fn (Blueprint $table) => $table->dropColumn(['off_peak_ratio', 'peak_hours_utc', 'long_context_threshold', 'long_input_per_million', 'long_cache_read_per_million', 'long_output_per_million']));
    }
};
