<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 实体编辑独立保存作者版本，不直接覆盖正式实体或关系事件。 */
return new class extends Migration
{
    /** 创建定义版本及其来源和生效章位。 */
    public function up(): void
    {
        Schema::create('entity_definition_versions', function (Blueprint $table): void {
            $table->comment('实体设定与关系作者版本，正式状态仍由提交事件维护');
            $table->id()->comment('设定版本编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->foreignId('entity_id')->nullable()->comment('既有正式实体编号，新增候选暂空')->constrained('story_entities')->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable()->comment('所修订或批准的版本');
            $table->string('status', 20)->comment('候选或作者已批准');
            $table->jsonb('definition')->comment('名称别名描述规则人物设定及初始关系');
            $table->string('content_hash', 64)->comment('定义内容摘要');
            $table->unsignedInteger('effective_from')->comment('可用于新运行的起始章位');
            $table->text('reason')->comment('新增修订或批准原因');
            $table->unsignedBigInteger('actor_id')->comment('操作账号');
            $table->timestampTz('created_at')->comment('版本创建时间');
            $table->index(['novel_id', 'entity_id', 'status']);
        });
    }

    /** 保护已保存作者版本。 */
    public function down(): void
    {
        if (DB::table('entity_definition_versions')->exists()) {
            throw new RuntimeException('已有实体设定版本，拒绝删除');
        }
        Schema::dropIfExists('entity_definition_versions');
    }
};
