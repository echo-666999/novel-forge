<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 要求提炼与作者覆盖决定的不可变版本历史。 */
return new class extends Migration
{
    /** 所有证据字段添加中文备注，不把人工标记解释成自动语义证明。 */
    public function up(): void
    {
        Schema::create('requirement_versions', function (Blueprint $table): void {
            $table->comment('原始要求的提炼及覆盖版本，不覆盖已冻结运行');
            $table->id()->comment('要求版本编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->uuid('requirement_key')->comment('同一要求跨版本稳定标识');
            $table->unsignedInteger('revision')->comment('要求修订序号');
            $table->text('source_quote')->comment('原始创意中的原句');
            $table->string('source_hash', 64)->comment('原始创意完整摘要');
            $table->text('description')->comment('作者确认的提炼描述');
            $table->string('category')->comment('要求分类');
            $table->string('strength', 20)->comment('硬约束或软要求');
            $table->string('status', 20)->comment('未解决已覆盖冲突或作者豁免');
            $table->unsignedBigInteger('outline_chapter_id')->nullable()->comment('同书承接章节计划编号，历史来源不级联删除');
            $table->text('evidence_quote')->nullable()->comment('章节计划中的覆盖证据原句');
            $table->string('evidence_hash', 64)->nullable()->comment('覆盖计划完整内容摘要');
            $table->text('reason')->comment('作者修改或决定的原因');
            $table->unsignedBigInteger('actor_id')->comment('明确作出决定的账号');
            $table->timestampTz('created_at')->comment('版本建立时间');
            $table->unique(['novel_id', 'requirement_key', 'revision']);
            $table->index(['novel_id', 'status']);
        });
    }

    /** 已有作者决定时拒绝删除历史。 */
    public function down(): void
    {
        if (DB::table('requirement_versions')->exists()) {
            throw new RuntimeException('已有要求版本，拒绝删除作者决定');
        }
        Schema::dropIfExists('requirement_versions');
    }
};
