<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 为明确追加授权的准备运行增加后继谱系，旧运行及产物不覆盖。 */
return new class extends Migration
{
    /** 保留首轮序号与原行内容，通过唯一约束防止重复授权分叉。 */
    public function up(): void
    {
        Schema::table('chapter_submission_runs', function (Blueprint $table): void {
            $table->unsignedInteger('attempt')->default(1)->comment('同正文同故事版本的授权轮次');
            $table->foreignId('predecessor_id')->nullable()->unique()->comment('被接续的失败准备运行')->constrained('chapter_submission_runs')->restrictOnDelete();
            $table->dropUnique(['version_id', 'expected_state_version']);
            $table->unique(['version_id', 'expected_state_version', 'attempt'], 'submission_attempt_unique');
        });
    }

    /** 存在后继历史时拒绝回滚，避免丢失谱系或恢复不成立的唯一键。 */
    public function down(): void
    {
        if (DB::table('chapter_submission_runs')->whereNotNull('predecessor_id')->exists()) {
            throw new RuntimeException('已有后继准备历史，拒绝回滚');
        }
        Schema::table('chapter_submission_runs', function (Blueprint $table): void {
            $table->dropForeign(['predecessor_id']);
            $table->dropUnique(['predecessor_id']);
            $table->dropUnique('submission_attempt_unique');
            $table->dropColumn(['attempt', 'predecessor_id']);
            $table->unique(['version_id', 'expected_state_version']);
        });
    }
};
