<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** B05/B06/B07最小闭环：候选产物与正式事件分表，所有来源均有数据库归属约束。 */
return new class extends Migration
{
    /** 仅增加提交结构，既有正文和验收记录不回填。 */
    public function up(): void
    {
        Schema::table('chapters', fn (Blueprint $table) => $table->unique(['id', 'novel_id']));
        Schema::create('story_entities', function (Blueprint $table): void {
            $table->comment('已批准故事实体');
            $table->id()->comment('主键');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->string('type')->comment('实体类别');
            $table->string('name')->comment('实体名称');
            $table->string('normalized_name')->comment('规范名称');
            $table->jsonb('aliases')->comment('已批准别名');
            $table->jsonb('definition')->comment('冻结定义');
            $table->string('definition_hash', 64)->comment('定义摘要');
            $table->jsonb('origin')->comment('批准来源');
            $table->foreignId('approved_by')->comment('批准人')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->comment('批准时间');
            $table->unique(['novel_id', 'type', 'normalized_name']);
            $table->unique(['id', 'novel_id']);
        });
        Schema::create('chapter_submission_runs', function (Blueprint $table): void {
            $table->comment('章节提交准备运行');
            $table->id()->comment('主键');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('chapter_id')->comment('所属章节');
            $table->unsignedBigInteger('version_id')->comment('冻结正文版本');
            $table->string('body_hash', 64)->comment('正文摘要');
            $table->bigInteger('expected_state_version')->comment('预期故事版本');
            $table->jsonb('snapshot')->comment('冻结来源');
            $table->jsonb('config')->comment('冻结授权');
            $table->string('input_hash', 64)->comment('输入摘要');
            $table->string('status')->comment('运行状态');
            $table->string('step')->comment('当前步骤');
            $table->uuid('invocation_id')->comment('领用令牌');
            $table->integer('requests_used')->default(0)->comment('领用请求数');
            $table->integer('request_limit')->default(2)->comment('请求硬上限');
            $table->string('stop_reason')->nullable()->comment('停止原因');
            $table->jsonb('control_history')->comment('操作历史');
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
            $table->unique(['version_id', 'expected_state_version']);
            $table->unique(['id', 'novel_id', 'chapter_id', 'version_id'], 'submission_run_identity');
            $table->foreign(['chapter_id', 'novel_id'])->references(['id', 'novel_id'])->on('chapters')->restrictOnDelete();
            $table->foreign(['version_id', 'chapter_id'])->references(['id', 'chapter_id'])->on('chapter_versions')->restrictOnDelete();
        });
        foreach (['chapter_reviews' => '章节基础评审', 'chapter_event_extractions' => '章节事件提取'] as $name => $comment) {
            Schema::create($name, function (Blueprint $table) use ($comment): void {
                $table->comment($comment);
                $table->id()->comment('主键');
                $table->unsignedBigInteger('submission_run_id')->unique()->comment('准备运行');
                $table->unsignedBigInteger('novel_id')->comment('所属小说');
                $table->unsignedBigInteger('chapter_id')->comment('所属章节');
                $table->unsignedBigInteger('version_id')->comment('正文版本');
                $table->string('body_hash', 64)->comment('受检正文摘要');
                $table->string('verdict')->comment('处理结论');
                $table->jsonb('result')->comment('结果及证据');
                $table->string('result_hash', 64)->comment('结果摘要');
                $table->foreignId('cost_log_id')->nullable()->comment('请求费用')->constrained('cost_logs')->restrictOnDelete();
                $table->timestampTz('created_at')->comment('保存时间');
                $table->unique(['id', 'submission_run_id']);
                $table->foreign(['submission_run_id', 'novel_id', 'chapter_id', 'version_id'], $table->getTable().'_identity')->references(['id', 'novel_id', 'chapter_id', 'version_id'])->on('chapter_submission_runs')->restrictOnDelete();
            });
        }
        Schema::create('chapter_submission_validations', function (Blueprint $table): void {
            $table->comment('提交确定性验证');
            $table->id()->comment('主键');
            $table->foreignId('submission_run_id')->comment('准备运行')->constrained('chapter_submission_runs')->restrictOnDelete();
            $table->unsignedBigInteger('review_id')->comment('评审记录');
            $table->unsignedBigInteger('extraction_id')->comment('提取记录');
            $table->string('verdict')->comment('验证结论');
            $table->string('input_hash', 64)->comment('验证输入摘要');
            $table->jsonb('result')->comment('问题及批准需求');
            $table->timestampTz('created_at')->comment('验证时间');
            $table->unique(['id', 'submission_run_id']);
            $table->foreign(['review_id', 'submission_run_id'])->references(['id', 'submission_run_id'])->on('chapter_reviews')->restrictOnDelete();
            $table->foreign(['extraction_id', 'submission_run_id'])->references(['id', 'submission_run_id'])->on('chapter_event_extractions')->restrictOnDelete();
        });
        Schema::create('canonical_commits', function (Blueprint $table): void {
            $table->comment('章节正式提交');
            $table->id()->comment('主键');
            $table->foreignId('submission_run_id')->unique()->comment('准备运行')->constrained('chapter_submission_runs')->restrictOnDelete();
            $table->unsignedBigInteger('novel_id')->comment('所属小说');
            $table->unsignedBigInteger('chapter_id')->unique()->comment('正式章节');
            $table->unsignedBigInteger('version_id')->comment('正式正文');
            $table->unsignedBigInteger('validation_id')->comment('通过验证');
            $table->bigInteger('previous_state_version')->comment('提交前故事版本');
            $table->bigInteger('state_version')->comment('提交后故事版本');
            $table->uuid('idempotency_key')->comment('提交幂等键');
            $table->string('request_hash', 64)->comment('请求摘要');
            $table->foreignId('committed_by')->comment('提交人')->constrained('users')->restrictOnDelete();
            $table->text('reason')->comment('提交原因');
            $table->jsonb('approvals')->comment('实体及正文批准');
            $table->jsonb('result')->comment('提交回执');
            $table->timestampTz('created_at')->comment('提交时间');
            $table->unique(['novel_id', 'idempotency_key']);
            $table->unique(['novel_id', 'state_version']);
            $table->unique(['id', 'novel_id']);
            $table->unique(['id', 'chapter_id']);
            $table->unique(['id', 'novel_id', 'chapter_id', 'version_id'], 'canonical_commit_identity');
            $table->foreign(['submission_run_id', 'novel_id', 'chapter_id', 'version_id'])->references(['id', 'novel_id', 'chapter_id', 'version_id'])->on('chapter_submission_runs')->restrictOnDelete();
            $table->foreign(['validation_id', 'submission_run_id'])->references(['id', 'submission_run_id'])->on('chapter_submission_validations')->restrictOnDelete();
        });
        Schema::create('story_events', function (Blueprint $table): void {
            $table->comment('不可变正式事件');
            $table->id()->comment('主键');
            $table->unsignedBigInteger('commit_id')->comment('正式提交');
            $table->unsignedBigInteger('novel_id')->comment('所属小说');
            $table->unsignedBigInteger('chapter_id')->comment('所属章节');
            $table->unsignedBigInteger('version_id')->comment('正文版本');
            $table->unsignedBigInteger('actor_entity_id')->comment('事件主体');
            $table->string('event_key')->comment('提取内事件编号');
            $table->integer('narrative_order')->comment('叙述顺序');
            $table->string('event_type')->comment('事件类别');
            $table->string('fact_category')->comment('事实类别');
            $table->jsonb('payload')->comment('事件及状态差异');
            $table->jsonb('evidence')->comment('正文证据区间');
            $table->timestampTz('created_at')->comment('提交时间');
            $table->unique(['commit_id', 'event_key']);
            $table->unique(['commit_id', 'narrative_order']);
            $table->foreign(['commit_id', 'novel_id', 'chapter_id', 'version_id'])->references(['id', 'novel_id', 'chapter_id', 'version_id'])->on('canonical_commits')->restrictOnDelete();
            $table->foreign(['actor_entity_id', 'novel_id'])->references(['id', 'novel_id'])->on('story_entities')->restrictOnDelete();
        });
        Schema::create('story_state_projections', function (Blueprint $table): void {
            $table->comment('正式故事状态投影');
            $table->id()->comment('主键');
            $table->unsignedBigInteger('commit_id')->unique()->comment('正式提交');
            $table->unsignedBigInteger('novel_id')->comment('所属小说');
            $table->bigInteger('state_version')->comment('故事状态版本');
            $table->jsonb('state')->comment('完整状态投影');
            $table->string('state_hash', 64)->comment('投影摘要');
            $table->timestampTz('created_at')->comment('提交时间');
            $table->unique(['novel_id', 'state_version']);
            $table->foreign(['commit_id', 'novel_id'])->references(['id', 'novel_id'])->on('canonical_commits')->restrictOnDelete();
        });
        Schema::create('story_outbox_events', function (Blueprint $table): void {
            $table->comment('正式提交事务发件箱');
            $table->id()->comment('主键');
            $table->foreignId('commit_id')->unique()->comment('正式提交')->constrained('canonical_commits')->restrictOnDelete();
            $table->string('event_type')->comment('通知类别');
            $table->jsonb('payload')->comment('通知数据');
            $table->timestampTz('created_at')->comment('创建时间');
            $table->timestampTz('published_at')->nullable()->comment('投递时间');
        });
        Schema::table('chapters', function (Blueprint $table): void {
            $table->unsignedBigInteger('formal_version_id')->nullable()->comment('正式正文版本');
            $table->unsignedBigInteger('canonical_commit_id')->nullable()->comment('正式提交记录');
            $table->timestampTz('committed_at')->nullable()->comment('正式提交时间');
            $table->foreign(['formal_version_id', 'id'])->references(['id', 'chapter_id'])->on('chapter_versions')->restrictOnDelete();
            $table->foreign(['canonical_commit_id', 'id'])->references(['id', 'chapter_id'])->on('canonical_commits')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'chapter_submission_runs' => "requests_used BETWEEN 0 AND 2 AND request_limit=2 AND expected_state_version>=0 AND status IN ('pending','running','failed','awaiting_approval','ready','committed') AND step IN ('review','extract')",
                'canonical_commits' => 'previous_state_version>=0 AND state_version=previous_state_version+1',
                'story_events' => "narrative_order>0 AND fact_category IN ('objective_confirmed','character_belief','rumor','inference','lie','author_hidden_truth')",
                'story_state_projections' => 'state_version>0',
            ] as $table => $expression) {
                DB::statement('ALTER TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table).' ADD CONSTRAINT '.$table.'_values CHECK ('.$expression.')');
            }
        }
    }

    /** 有准备或提交历史时禁止回滚；测试先清理已核实的独立库。 */
    public function down(): void
    {
        if (DB::table('chapter_submission_runs')->exists() || DB::table('canonical_commits')->exists()) {
            throw new RuntimeException('已有提交准备或正式历史，拒绝回滚');
        }
        Schema::table('chapters', function (Blueprint $table): void {
            $table->dropForeign(['formal_version_id', 'id']);
            $table->dropForeign(['canonical_commit_id', 'id']);
            $table->dropColumn(['formal_version_id', 'canonical_commit_id', 'committed_at']);
        });
        foreach (['story_outbox_events', 'story_state_projections', 'story_events', 'canonical_commits', 'chapter_submission_validations', 'chapter_event_extractions', 'chapter_reviews', 'chapter_submission_runs', 'story_entities'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('chapters', fn (Blueprint $table) => $table->dropUnique(['id', 'novel_id']));
    }
};
