<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 保存作者的安全暂停请求与运行控制审计，不修改既有生成快照。 */
return new class extends Migration
{
    /** 新字段有中文备注，已有小说默认不请求暂停。 */
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->boolean('pause_requested')->default(false)->comment('作者请求在当前调用结束后暂停');
            $table->jsonb('run_control_history')->nullable()->comment('运行暂停及恢复的操作人原因和时间审计');
        });
    }

    /** 只移除本次新增字段，保留所有章节与阶段历史。 */
    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->dropColumn(['pause_requested', 'run_control_history']);
        });
    }
};
