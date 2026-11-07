<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 验收批次冻结来源，评分与测试证据只追加。 */
return new class extends Migration
{
    /** 每个表和字段记录中文用途，验收记录不会改变正式故事。 */
    public function up(): void
    {
        Schema::create('acceptance_batches', function (Blueprint $table): void {
            $table->comment('冻结正式正文来源的验收批次，不代表生成前契约');
            $table->id()->comment('验收批次编号');
            $table->foreignId('novel_id')->comment('受验小说')->constrained('novels')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->comment('创建并负责验收的账号');
            $table->string('kind', 24)->comment('十章百章恢复或安全验收类别');
            $table->unsignedInteger('end_chapter')->comment('从首章到该正式章的连续范围');
            $table->json('snapshot')->comment('不可变来源版本与评分政策');
            $table->string('snapshot_hash', 64)->comment('冻结快照摘要');
            $table->string('status', 24)->default('not_run')->comment('展示状态，不由模型直接置为认可');
            $table->timestampTz('created_at')->comment('批次冻结时间');
        });
        Schema::create('acceptance_evidence', function (Blueprint $table): void {
            $table->comment('验收机器结果人工评分及结论的追加式证据');
            $table->id()->comment('证据编号');
            $table->foreignId('batch_id')->comment('所属验收批次')->constrained('acceptance_batches')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->comment('执行或明确确认的账号');
            $table->string('kind', 24)->comment('机器核验人工评分外部证据或关闭结论');
            $table->string('case_key', 64)->comment('用例标识或章号');
            $table->json('payload')->comment('完整结果原因与来源证据');
            $table->string('payload_hash', 64)->comment('证据载荷摘要');
            $table->timestampTz('created_at')->comment('本次证据记录时间');
            $table->index(['batch_id', 'kind', 'case_key']);
        });
    }

    /** 已产生验收记录后禁止回滚丢失审计历史。 */
    public function down(): void
    {
        if (DB::table('acceptance_batches')->exists() || DB::table('acceptance_evidence')->exists()) {
            throw new RuntimeException('已有验收证据，拒绝删除');
        }
        Schema::dropIfExists('acceptance_evidence');
        Schema::dropIfExists('acceptance_batches');
    }
};
