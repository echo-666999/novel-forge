<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 正式稿修订：只改文字、事实不变；逐章保存规则信号、模型建议、作者取舍、候选正文和事实等价结果，在候选基线中提交。 */
return new class extends Migration
{
    /** 修订任务与逐章记录。 */
    public function up(): void
    {
        Schema::create('formal_revisions', function (Blueprint $table): void {
            $table->comment('已提交正文的正式稿修订任务');
            $table->id()->comment('修订任务编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->foreignId('baseline_id')->comment('候选基线')->constrained('canonical_baselines')->restrictOnDelete();
            $table->unsignedInteger('from_chapter')->comment('修订起始章');
            $table->unsignedInteger('to_chapter')->comment('修订结束章');
            $table->text('intent')->comment('作者修订意图');
            $table->string('status', 20)->comment('open进行中、ready候选已验证、switched已切换、abandoned已放弃');
            $table->string('model', 128)->comment('建议与核对使用的模型');
            $table->unsignedInteger('request_limit')->comment('本任务允许的模型请求次数');
            $table->unsignedInteger('requests_used')->default(0)->comment('已用模型请求次数');
            $table->foreignId('created_by')->comment('发起人')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['novel_id', 'status']);
        });
        Schema::create('formal_revision_chapters', function (Blueprint $table): void {
            $table->comment('正式稿修订的逐章记录');
            $table->id()->comment('逐章记录编号');
            $table->foreignId('formal_revision_id')->comment('修订任务')->constrained('formal_revisions')->restrictOnDelete();
            $table->unsignedInteger('chapter_number')->comment('章号');
            $table->foreignId('source_commit_id')->comment('修订依据的正式提交')->constrained('canonical_commits')->restrictOnDelete();
            $table->foreignId('source_version_id')->comment('修订依据的正式正文')->constrained('chapter_versions')->restrictOnDelete();
            $table->jsonb('signals')->comment('规则文风信号，不发请求');
            $table->jsonb('suggestions')->nullable()->comment('模型逐条建议及程序校验结果');
            $table->jsonb('decisions')->nullable()->comment('作者逐条取舍：accept接受、reject拒绝、exception有意保留');
            $table->foreignId('candidate_version_id')->nullable()->comment('按接受项生成的候选正文')->constrained('chapter_versions')->restrictOnDelete();
            $table->jsonb('equivalence')->nullable()->comment('事实等价核对与证据重新定位结果');
            $table->string('status', 20)->comment('pending待建议、suggested待取舍、decided待核对、unchanged无改动、verified已核对、failed未通过、committed已提交候选');
            $table->text('error')->nullable()->comment('最近一次失败原因');
            $table->jsonb('cost_log_ids')->comment('本章模型请求费用记录');
            $table->timestampsTz();
            $table->unique(['formal_revision_id', 'chapter_number']);
        });
    }

    /** 已有修订记录时拒绝回滚。 */
    public function down(): void
    {
        if (DB::table('formal_revisions')->exists()) {
            throw new RuntimeException('已有正式稿修订记录，拒绝回滚');
        }
        Schema::dropIfExists('formal_revision_chapters');
        Schema::dropIfExists('formal_revisions');
    }
};
