<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 场景计划保存独立版本，不覆盖已接纳的大纲或旧运行。 */
return new class extends Migration
{
    /** 创建带来源摘要和作者审计的场景规划。 */
    public function up(): void
    {
        Schema::create('scene_plan_versions', function (Blueprint $table): void {
            $table->comment('作者场景规划不可变版本');
            $table->id()->comment('场景规划版本编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->foreignId('outline_chapter_id')->comment('所属大纲章节')->constrained('novel_outline_chapters')->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable()->comment('前一规划版本');
            $table->string('status', 20)->comment('草稿或冻结状态');
            $table->jsonb('scenes')->comment('按顺序保存的场景及推进点字数与衔接');
            $table->string('source_hash', 64)->comment('大纲与章字数政策的来源摘要');
            $table->string('content_hash', 64)->comment('场景内容摘要');
            $table->text('reason')->comment('修订或冻结原因');
            $table->unsignedBigInteger('actor_id')->comment('操作账号');
            $table->timestampTz('created_at')->comment('创建时间');
            $table->index(['novel_id', 'outline_chapter_id', 'status']);
        });
    }

    /** 已有规划历史时禁止自动删除。 */
    public function down(): void
    {
        if (DB::table('scene_plan_versions')->exists()) {
            throw new RuntimeException('已有场景规划，拒绝删除');
        }
        Schema::dropIfExists('scene_plan_versions');
    }
};
