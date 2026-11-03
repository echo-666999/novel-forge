<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 保存每次 AI 调用及费用证据，不通过级联删除抹除历史。 */
return new class extends Migration
{
    /** 新建成本明细，未知用量与金额使用空值而非零。 */
    public function up(): void
    {
        Schema::create('cost_logs', function (Blueprint $table): void {
            $table->comment('AI调用成本明细');
            $table->id()->comment('主键');
            $table->uuid('request_uuid')->unique()->comment('本地调用标识');
            $table->foreignId('novel_id')->nullable()->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->foreignId('novel_stage_id')->nullable()->comment('所属阶段')->constrained('novel_stages')->restrictOnDelete();
            $table->string('mode', 16)->comment('调用模式');
            $table->string('requested_provider', 64)->comment('请求供应商');
            $table->string('requested_model', 128)->comment('请求模型');
            $table->string('provider', 64)->nullable()->comment('实际供应商');
            $table->string('model', 128)->nullable()->comment('实际模型');
            $table->string('route_version', 64)->comment('路由版本');
            $table->string('prompt_version', 64)->nullable()->comment('提示模板版本');
            $table->string('prompt_hash', 64)->comment('提示内容摘要');
            $table->jsonb('request_parameters')->comment('请求参数快照');
            $table->string('status', 16)->default('pending')->comment('请求状态');
            $table->string('provider_request_id', 255)->nullable()->comment('供应商请求标识');
            foreach (['input_tokens' => '输入Token数', 'output_tokens' => '输出Token数', 'cache_read_tokens' => '缓存读取Token数', 'cache_write_tokens' => '缓存写入Token数', 'reasoning_tokens' => '推理Token数'] as $column => $comment) {
                $table->bigInteger($column)->nullable()->comment($comment);
            }
            $table->char('currency', 3)->nullable()->comment('计费币种');
            $table->string('price_version', 128)->nullable()->comment('价格版本');
            $table->jsonb('price_snapshot')->nullable()->comment('价格快照');
            $table->decimal('estimated_cost', 20, 8)->nullable()->comment('估算费用');
            $table->decimal('actual_cost', 20, 8)->nullable()->comment('实际费用');
            $table->string('billing_status', 16)->default('unknown')->comment('计费可信度');
            $table->text('response_text')->nullable()->comment('返回正文');
            $table->jsonb('structured_output')->nullable()->comment('结构化结果');
            $table->jsonb('raw_response')->nullable()->comment('脱敏原始响应');
            $table->string('error_category', 64)->nullable()->comment('错误类别');
            $table->text('error_message')->nullable()->comment('安全错误说明');
            $table->bigInteger('duration_ms')->nullable()->comment('调用耗时毫秒');
            $table->timestampTz('started_at')->nullable()->comment('请求开始时间');
            $table->timestampTz('finished_at')->nullable()->comment('请求结束时间');
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
            $table->index(['novel_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('cost_logs');
            // PostgreSQL 负责保护金额、计数及稳定状态值，不依赖调用方自觉校验。
            $nonnegative = implode(' AND ', array_map(static fn (string $column): string => "({$column} IS NULL OR {$column} >= 0)", ['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'estimated_cost', 'actual_cost', 'duration_ms']));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT cost_logs_values_check CHECK ({$nonnegative} AND mode IN ('fake', 'real') AND status IN ('pending', 'succeeded', 'failed', 'unknown') AND billing_status IN ('simulated', 'estimated', 'unknown'))");
        }
    }

    /** 仅回滚本表，不修改已有小说或阶段。 */
    public function down(): void
    {
        Schema::dropIfExists('cost_logs');
    }
};
