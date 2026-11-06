<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 伏笔计划与追加式正式正文证据分表，既有故事事件不回填。 */
return new class extends Migration
{
    /** 建立小说隔离、正式提交来源及操作幂等约束，所有表字段保留中文备注。 */
    public function up(): void
    {
        Schema::create('foreshadows', function (Blueprint $table): void {
            $table->comment('伏笔计划及当前账本状态');
            $table->id()->comment('伏笔编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->string('title')->comment('伏笔标题');
            $table->text('description')->comment('计划内容及回收目标');
            $table->unsignedInteger('plant_chapter')->comment('计划埋设章号');
            $table->unsignedInteger('target_chapter')->comment('最迟回收章号');
            $table->string('status')->default('planned')->comment('计划或已埋设强化回收状态');
            $table->unsignedInteger('revision')->default(0)->comment('证据状态修订序号');
            $table->uuid('request_key')->unique()->comment('创建幂等键');
            $table->string('request_hash', 64)->comment('创建请求摘要');
            $table->foreignId('created_by')->comment('登记操作者')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->comment('创建时间');
            $table->timestampTz('updated_at')->comment('状态更新时间');
            $table->unique(['id', 'novel_id']);
            $table->index(['novel_id', 'status', 'target_chapter']);
        });
        Schema::create('foreshadow_evidence', function (Blueprint $table): void {
            $table->comment('不可变伏笔流转及正式正文证据');
            $table->id()->comment('证据编号');
            $table->unsignedBigInteger('foreshadow_id')->comment('所属伏笔');
            $table->unsignedBigInteger('novel_id')->comment('所属小说');
            $table->unsignedInteger('revision')->comment('本次流转序号');
            $table->string('from_status')->comment('流转前状态');
            $table->string('to_status')->comment('流转后状态');
            $table->unsignedBigInteger('commit_id')->comment('依据的正式提交');
            $table->unsignedBigInteger('chapter_id')->comment('证据章节');
            $table->unsignedBigInteger('version_id')->comment('证据正式正文版本');
            $table->unsignedInteger('chapter_number')->comment('证据章号');
            $table->string('body_hash', 64)->comment('正式正文摘要');
            $table->jsonb('evidence')->comment('正文段落及字符区间证据');
            $table->text('reason')->comment('登记流转理由');
            $table->foreignId('recorded_by')->comment('登记操作者')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique()->comment('流转幂等键');
            $table->string('request_hash', 64)->comment('流转请求摘要');
            $table->timestampTz('created_at')->comment('登记时间');
            $table->unique(['foreshadow_id', 'revision']);
            $table->foreign(['foreshadow_id', 'novel_id'])->references(['id', 'novel_id'])->on('foreshadows')->restrictOnDelete();
            $table->foreign(['commit_id', 'novel_id', 'chapter_id', 'version_id'], 'foreshadow_evidence_commit_identity')->references(['id', 'novel_id', 'chapter_id', 'version_id'])->on('canonical_commits')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            // 原生约束语句必须保留连接配置的表名前缀。
            $plans = DB::connection()->getQueryGrammar()->wrapTable('foreshadows');
            $evidence = DB::connection()->getQueryGrammar()->wrapTable('foreshadow_evidence');
            DB::statement("ALTER TABLE {$plans} ADD CONSTRAINT foreshadow_plan_valid CHECK (plant_chapter > 0 AND target_chapter >= plant_chapter AND revision >= 0 AND status IN ('planned','planted','reinforced','resolved'))");
            DB::statement("ALTER TABLE {$evidence} ADD CONSTRAINT foreshadow_transition_valid CHECK (revision > 0 AND chapter_number > 0 AND ((from_status = 'planned' AND to_status = 'planted') OR (from_status IN ('planted','reinforced') AND to_status IN ('reinforced','resolved'))))");
        }
    }

    /** 正式证据不能通过回滚迁移丢弃，空表才允许撤销。 */
    public function down(): void
    {
        if (DB::table('foreshadows')->exists() || DB::table('foreshadow_evidence')->exists()) {
            throw new RuntimeException('伏笔账本已有历史，拒绝回滚');
        }
        Schema::dropIfExists('foreshadow_evidence');
        Schema::dropIfExists('foreshadows');
    }
};
