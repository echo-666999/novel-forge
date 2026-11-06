<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 保存发送前及阻塞时的上下文清单，不回填旧请求。 */
return new class extends Migration
{
    /** 每张表和每个字段都有中文备注，清单始终归属单本小说。 */
    public function up(): void
    {
        Schema::create('context_manifests', function (Blueprint $table): void {
            $table->comment('不可变请求上下文及裁剪审计清单');
            $table->id()->comment('上下文清单编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->unsignedInteger('chapter_number')->comment('目标章节编号');
            $table->string('purpose')->comment('生成检查评审或提取用途');
            $table->string('status')->comment('组装就绪或容量阻塞状态，不代表请求已发送');
            $table->jsonb('document')->comment('冻结条目来源哈希顺序预算及实际提示');
            $table->string('document_hash', 64)->comment('完整清单摘要');
            $table->timestampTz('created_at')->comment('组装时间');
            $table->index(['novel_id', 'chapter_number']);
        });
    }

    /** 审计历史存在时禁止回滚删除。 */
    public function down(): void
    {
        if (DB::table('context_manifests')->exists()) {
            throw new RuntimeException('上下文清单已有历史，拒绝回滚');
        }
        Schema::dropIfExists('context_manifests');
    }
};
