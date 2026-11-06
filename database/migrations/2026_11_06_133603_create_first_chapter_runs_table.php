<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 保存首章自动授权及断点，不修改既有故事历史。 */
return new class extends Migration
{
    /** 表及每个字段均保存中文数据库备注。 */
    public function up(): void
    {
        Schema::create('first_chapter_runs', function (Blueprint $table): void {
            $table->comment('新小说首章自动编排与授权审计');
            $table->id()->comment('自动流程编号');
            $table->foreignId('novel_id')->unique()->comment('独占的新小说编号')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->comment('授权操作者编号')->constrained('users')->restrictOnDelete();
            $table->jsonb('policy')->comment('不可变自动创作与提交授权');
            $table->string('policy_hash', 64)->comment('授权内容摘要');
            $table->uuid('commit_key')->unique()->comment('正式提交幂等键');
            $table->string('status')->default('pending')->comment('待处理、执行、失败或完成状态');
            $table->string('step')->default('setting')->comment('当前自动步骤');
            $table->jsonb('artifacts')->default('{}')->comment('各阶段来源及产物编号');
            $table->jsonb('history')->default('[]')->comment('步骤与恢复操作审计');
            $table->text('error')->nullable()->comment('停止原因，不包含凭据');
            $table->uuid('lease_token')->nullable()->comment('当前步骤独占令牌');
            $table->timestampTz('lease_expires_at')->nullable()->comment('领取超时，只供人工恢复判断');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });
    }

    /** 仅允许空表回滚，避免删除自动授权证据。 */
    public function down(): void
    {
        if (DB::table('first_chapter_runs')->exists()) {
            throw new LogicException('首章自动授权历史非空，禁止回滚删除');
        }
        Schema::dropIfExists('first_chapter_runs');
    }
};
