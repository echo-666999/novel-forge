<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 人工编辑的自动保存区，不是正式正文或已批准候选。 */
return new class extends Migration
{
    /** 保存按账号隔离的可变工作草稿及并发修订号。 */
    public function up(): void
    {
        Schema::create('manual_content_drafts', function (Blueprint $table): void {
            $table->comment('人工正文编辑自动保存区，正式候选另建不可变版本');
            $table->id()->comment('工作草稿编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->unsignedBigInteger('chapter_id')->comment('编辑章节编号');
            $table->unsignedBigInteger('actor_id')->comment('编辑账号');
            $table->unsignedBigInteger('base_version_id')->comment('开始编辑时的不可变候选版本');
            $table->unsignedInteger('revision')->comment('自动保存并发修订号');
            $table->string('title')->comment('工作草稿标题');
            $table->longText('body')->comment('自动保存正文，不直接用于生成或提交');
            $table->timestampTz('updated_at')->comment('最近一次自动保存时间');
            $table->unique(['chapter_id', 'actor_id']);
        });
    }

    /** 防止回滚时丢失作者尚未转换为候选的工作内容。 */
    public function down(): void
    {
        if (DB::table('manual_content_drafts')->exists()) {
            throw new RuntimeException('存在人工工作草稿，拒绝删除');
        }
        Schema::dropIfExists('manual_content_drafts');
    }
};
