<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 已揭示事实的前文补录：按原始正式提交逐章保存，台账重放时与该章的台账结果合并。 */
    public function up(): void
    {
        Schema::create('open_item_backfills', function (Blueprint $table): void {
            $table->comment('已揭示事实前文补录');
            $table->id()->comment('主键');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->foreignId('commit_id')->unique()->comment('原始正式提交')->constrained('canonical_commits')->restrictOnDelete();
            $table->unsignedInteger('chapter_number')->comment('章号');
            $table->jsonb('entries')->comment('补录的揭示及原文证据');
            $table->string('entries_hash', 64)->comment('结果摘要');
            $table->foreignId('cost_log_id')->nullable()->comment('请求费用')->constrained('cost_logs')->restrictOnDelete();
            $table->foreignId('created_by')->comment('执行人')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->comment('保存时间');
        });
    }

    /** 回滚时删除该表。 */
    public function down(): void
    {
        Schema::dropIfExists('open_item_backfills');
    }
};
