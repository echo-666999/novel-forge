<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 作者卷弧结构版本独立于模型大纲，冻结版本用于未来运行。 */
return new class extends Migration
{
    /** 创建版本历史；结构内的卷弧范围、目标、转折和终态由服务验证。 */
    public function up(): void
    {
        Schema::create('story_structure_versions', function (Blueprint $table): void {
            $table->comment('作者卷弧结构版本，旧运行继续使用原快照');
            $table->id()->comment('结构版本编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable()->comment('所修订的结构版本');
            $table->string('status', 20)->comment('草稿或已冻结');
            $table->jsonb('structure')->comment('卷及故事弧树，含章范围目标转折高潮和终态');
            $table->string('content_hash', 64)->comment('结构稳定摘要');
            $table->text('reason')->comment('作者新增修订或冻结原因');
            $table->unsignedBigInteger('actor_id')->comment('作者账号');
            $table->timestampTz('created_at')->comment('版本建立时间');
            $table->index(['novel_id', 'status']);
        });
    }

    /** 保护已有作者规划历史。 */
    public function down(): void
    {
        if (DB::table('story_structure_versions')->exists()) {
            throw new RuntimeException('已有卷弧版本，拒绝删除');
        }
        Schema::dropIfExists('story_structure_versions');
    }
};
