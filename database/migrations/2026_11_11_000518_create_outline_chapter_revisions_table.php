<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 未提交章节的章纲修订版本：只追加，原大纲章节与旧运行快照保持不变，只作用于之后冻结的新运行。 */
return new class extends Migration
{
    /** 创建带父版本、内容摘要和作者审计的章纲修订表。 */
    public function up(): void
    {
        Schema::create('outline_chapter_revisions', function (Blueprint $table): void {
            $table->comment('作者对未提交章节章纲的不可变修订版本');
            $table->id()->comment('章纲修订编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->foreignId('outline_chapter_id')->comment('被修订的大纲章节')->constrained('novel_outline_chapters')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->comment('前一修订版本，空值表示直接修订原章纲')->constrained('outline_chapter_revisions')->restrictOnDelete();
            $table->text('summary')->comment('修订后的章节概要');
            $table->text('objective')->comment('修订后的章节目标');
            $table->text('conflict')->comment('修订后的章节冲突');
            $table->text('hook')->comment('修订后的章末钩子');
            $table->jsonb('progression_points')->comment('修订后的推进点，编号与原章纲一致');
            $table->string('content_hash', 64)->comment('修订内容摘要');
            $table->text('reason')->comment('修订原因');
            $table->foreignId('actor_id')->comment('操作账号')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->comment('创建时间');
            $table->unique(['outline_chapter_id', 'parent_id']);
            $table->index(['novel_id', 'outline_chapter_id']);
        });
    }

    /** 已有修订历史时禁止自动删除。 */
    public function down(): void
    {
        if (DB::table('outline_chapter_revisions')->exists()) {
            throw new RuntimeException('已有章纲修订，拒绝删除');
        }
        Schema::dropIfExists('outline_chapter_revisions');
    }
};
