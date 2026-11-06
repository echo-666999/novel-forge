<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 公共与阶段模板的不可覆盖版本、发布路由及操作审计。 */
return new class extends Migration
{
    /** 所有表及字段包含中文备注；初始空路由保持现有程序契约。 */
    public function up(): void
    {
        Schema::create('prompt_template_versions', function (Blueprint $table): void {
            $table->comment('提示词模板版本，正文保存后不可覆盖');
            $table->id()->comment('模板版本编号');
            $table->string('scope')->comment('公共或阶段键');
            $table->text('body')->comment('模板正文，仅支持白名单变量');
            $table->text('notes')->comment('版本变更说明');
            $table->string('language')->default('zh-CN')->comment('模板语言');
            $table->string('status')->default('draft')->comment('草稿已测试已发布或已退役');
            $table->string('content_hash', 64)->comment('正文与阶段的完整性摘要');
            $table->jsonb('test_report')->nullable()->comment('本地变量及渲染回归结果，不代表真实模型评测');
            $table->unsignedBigInteger('created_by')->comment('操作账号编号');
            $table->timestampTz('created_at')->comment('版本创建时间');
            $table->index(['scope', 'status']);
        });
        Schema::create('prompt_template_routes', function (Blueprint $table): void {
            $table->comment('新小说运行使用的提示词发布指针');
            $table->string('scope')->primary()->comment('公共或阶段键');
            $table->foreignId('version_id')->nullable()->comment('当前发布版本，空值沿用程序契约')->constrained('prompt_template_versions')->restrictOnDelete();
        });
        Schema::create('prompt_template_events', function (Blueprint $table): void {
            $table->comment('模板发布退役及回滚审计，不覆盖既有运行');
            $table->id()->comment('操作编号');
            $table->string('scope')->comment('模板阶段键');
            $table->unsignedBigInteger('from_version_id')->nullable()->comment('操作前发布版本');
            $table->unsignedBigInteger('to_version_id')->nullable()->comment('操作后发布版本');
            $table->string('action')->comment('发布退役或回滚动作');
            $table->unsignedBigInteger('actor_id')->comment('操作账号编号');
            $table->timestampTz('created_at')->comment('操作时间');
        });
        foreach (['common', 'setting', 'decision', 'outline', 'fine', 'draft', 'revise', 'check', 'review', 'extract'] as $scope) {
            DB::table('prompt_template_routes')->insert(['scope' => $scope]);
        }
    }

    /** 存在版本或发布历史时拒绝删除证据。 */
    public function down(): void
    {
        if (DB::table('prompt_template_versions')->exists() || DB::table('prompt_template_events')->exists()) {
            throw new RuntimeException('模板已有版本或审计历史，拒绝回滚');
        }
        Schema::dropIfExists('prompt_template_events');
        Schema::dropIfExists('prompt_template_routes');
        Schema::dropIfExists('prompt_template_versions');
    }
};
