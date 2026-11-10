<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** O4：登记核实单价、按书金额预算与请求前预留，以及建书时的补充创作要求；旧记录不回填。 */
return new class extends Migration
{
    /** 表与字段均带中文备注；单价表只追加，新版本替代旧版本。 */
    public function up(): void
    {
        Schema::create('model_prices', function (Blueprint $table): void {
            $table->comment('作者登记的已核实模型单价，只追加不覆盖');
            $table->id()->comment('单价记录编号');
            $table->string('model', 128)->comment('模型标识，按最长前缀匹配实际响应模型');
            $table->char('currency', 3)->comment('币种，三位大写代码');
            $table->decimal('input_per_million', 20, 8)->comment('每百万输入Token单价');
            $table->decimal('output_per_million', 20, 8)->comment('每百万输出Token单价（含推理）');
            $table->decimal('cache_read_per_million', 20, 8)->nullable()->comment('每百万缓存读取Token单价，未登记时按输入单价');
            $table->decimal('cache_write_per_million', 20, 8)->nullable()->comment('每百万缓存写入Token单价，未登记时按输入单价');
            $table->text('source')->comment('单价来源与核实说明');
            $table->date('verified_on')->comment('核实日期');
            $table->timestampTz('effective_at')->comment('生效时间，之后的新请求按此单价计算');
            $table->foreignId('created_by')->comment('登记账号编号')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->comment('登记时间');
            $table->index(['model', 'effective_at']);
        });
        Schema::table('novels', function (Blueprint $table): void {
            $table->jsonb('creative_brief')->nullable()->comment('补充创作要求：目标读者、核心卖点、禁用内容、文风、结局偏好');
            $table->decimal('budget_amount', 20, 8)->nullable()->comment('本书金额预算，空值表示未设预算');
            $table->char('budget_currency', 3)->nullable()->comment('预算币种');
            $table->jsonb('budget_history')->default('[]')->comment('预算调整记录，只追加');
        });
        Schema::table('cost_logs', function (Blueprint $table): void {
            $table->decimal('reserved_cost', 20, 8)->nullable()->comment('请求前按单价上限预留的金额，未设预算时为空');
            $table->char('reserved_currency', 3)->nullable()->comment('预留金额币种');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            $prices = DB::connection()->getQueryGrammar()->wrapTable('model_prices');
            $novels = DB::connection()->getQueryGrammar()->wrapTable('novels');
            $logs = DB::connection()->getQueryGrammar()->wrapTable('cost_logs');
            DB::statement("ALTER TABLE {$prices} ADD CONSTRAINT model_prices_values_check CHECK (input_per_million >= 0 AND output_per_million >= 0 AND (cache_read_per_million IS NULL OR cache_read_per_million >= 0) AND (cache_write_per_million IS NULL OR cache_write_per_million >= 0) AND currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE {$novels} ADD CONSTRAINT novels_budget_check CHECK ((budget_amount IS NULL AND budget_currency IS NULL) OR (budget_amount >= 0 AND budget_currency ~ '^[A-Z]{3}$'))");
            DB::statement("ALTER TABLE {$logs} ADD CONSTRAINT cost_logs_reservation_check CHECK ((reserved_cost IS NULL AND reserved_currency IS NULL) OR (reserved_cost >= 0 AND reserved_currency ~ '^[A-Z]{3}$'))");
        }
    }

    /** 已登记单价、设置预算或存在预留时拒绝回滚，避免丢失计费依据。 */
    public function down(): void
    {
        $logs = Schema::hasTable('cost_logs');
        if (DB::table('model_prices')->exists() || DB::table('novels')->whereNotNull('budget_amount')->exists()
            || DB::table('novels')->whereNotNull('creative_brief')->exists() || ($logs && DB::table('cost_logs')->whereNotNull('reserved_cost')->exists())) {
            throw new LogicException('已有单价、预算、预留或补充创作要求，禁止回滚删除');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.DB::connection()->getQueryGrammar()->wrapTable('novels').' DROP CONSTRAINT IF EXISTS novels_budget_check');
            if ($logs) {
                DB::statement('ALTER TABLE '.DB::connection()->getQueryGrammar()->wrapTable('cost_logs').' DROP CONSTRAINT IF EXISTS cost_logs_reservation_check');
            }
        }
        // 个别隔离测试会先删除费用表以模拟写入失败，回滚时跳过不存在的表。
        if ($logs) {
            Schema::table('cost_logs', fn (Blueprint $table) => $table->dropColumn(['reserved_cost', 'reserved_currency']));
        }
        Schema::table('novels', fn (Blueprint $table) => $table->dropColumn(['creative_brief', 'budget_amount', 'budget_currency', 'budget_history']));
        Schema::dropIfExists('model_prices');
    }
};
