<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 正式基线：状态版本改为全书单调序号，章位由基线链解析；候选基线隔离生成、验证后原子切换，旧基线只读可回放。 */
return new class extends Migration
{
    /** 建表、给提交与伏笔证据挂基线，并把已有正式历史回填为各书的初始基线。 */
    public function up(): void
    {
        Schema::create('canonical_baselines', function (Blueprint $table): void {
            $table->comment('小说正式基线；同书只有一个活动基线');
            $table->id()->comment('基线编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->comment('父基线，起始章之前的提交沿父基线解析')->constrained('canonical_baselines')->restrictOnDelete();
            $table->unsignedInteger('fork_chapter')->comment('本基线自有提交的起始章');
            $table->string('status', 20)->comment('active活动、candidate候选、verified已验证、superseded已被替换、abandoned已放弃');
            $table->string('purpose', 30)->comment('initial初始、formal_revision正式稿修订');
            $table->unsignedBigInteger('base_head_state')->default(0)->comment('创建时父基线链头状态序号，切换时用于判断是否过期');
            $table->jsonb('verification')->nullable()->comment('候选基线回放与证据核验结果');
            $table->text('reason')->comment('创建原因');
            $table->foreignId('created_by')->nullable()->comment('创建人，回填的初始基线为空')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->comment('切换为活动基线的操作人')->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable()->comment('成为活动基线的时间');
            $table->timestampsTz();
            $table->unique(['id', 'novel_id']);
            $table->index(['novel_id', 'status']);
        });
        // 每本书最多一个活动基线。
        $prefix = DB::getTablePrefix();
        DB::statement("CREATE UNIQUE INDEX {$prefix}canonical_baselines_one_active ON {$prefix}canonical_baselines (novel_id) WHERE status = 'active'");

        Schema::table('novels', function (Blueprint $table): void {
            $table->foreignId('active_baseline_id')->nullable()->comment('当前活动正式基线')->constrained('canonical_baselines')->restrictOnDelete();
        });

        Schema::table('canonical_commits', function (Blueprint $table): void {
            $table->unsignedBigInteger('baseline_id')->nullable()->comment('所属正式基线');
            $table->unsignedInteger('chapter_number')->nullable()->comment('基线内章位');
            $table->string('origin', 20)->default('submission')->comment('submission评审提交、revision正式稿修订、rebase下游重挂');
            $table->unsignedBigInteger('source_commit_id')->nullable()->comment('修订或重挂所依据的原提交');
            $table->unsignedBigInteger('submission_run_id')->nullable()->comment('准备运行，修订与重挂提交为空')->change();
            $table->unsignedBigInteger('validation_id')->nullable()->comment('通过验证，修订与重挂提交为空')->change();
        });

        Schema::table('foreshadow_evidence', function (Blueprint $table): void {
            $table->unsignedBigInteger('baseline_id')->nullable()->comment('所属正式基线；切换基线时随提交复制');
        });

        // 回填：已有提交或伏笔证据的小说各建初始基线，提交按章号归入；现有状态序号恰等于章号，旧快照与回放摘要不变。
        $novels = DB::table('canonical_commits')->distinct()->pluck('novel_id')->merge(DB::table('foreshadow_evidence')->distinct()->pluck('novel_id'))->unique()->sort()->values();
        foreach ($novels as $novelId) {
            $baselineId = DB::table('canonical_baselines')->insertGetId(['novel_id' => $novelId, 'parent_id' => null, 'fork_chapter' => 1, 'status' => 'active', 'purpose' => 'initial',
                'base_head_state' => 0, 'verification' => null, 'reason' => '迁移回填：已有正式提交归入初始基线', 'created_by' => null, 'activated_by' => null,
                'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('novels')->where('id', $novelId)->update(['active_baseline_id' => $baselineId]);
            foreach (DB::table('canonical_commits')->where('novel_id', $novelId)->get(['id', 'chapter_id']) as $commit) {
                DB::table('canonical_commits')->where('id', $commit->id)->update(['baseline_id' => $baselineId,
                    'chapter_number' => DB::table('chapters')->where('id', $commit->chapter_id)->value('number')]);
            }
            DB::table('foreshadow_evidence')->where('novel_id', $novelId)->update(['baseline_id' => $baselineId]);
        }

        Schema::table('canonical_commits', function (Blueprint $table): void {
            $table->dropUnique(['chapter_id']);
            $table->unique(['baseline_id', 'chapter_number']);
            $table->index('chapter_id');
            $table->foreign(['baseline_id', 'novel_id'])->references(['id', 'novel_id'])->on('canonical_baselines')->restrictOnDelete();
            $table->foreign('source_commit_id')->references('id')->on('canonical_commits')->restrictOnDelete();
        });
        Schema::table('foreshadow_evidence', function (Blueprint $table): void {
            $table->dropUnique(['foreshadow_id', 'revision']);
            $table->unique(['foreshadow_id', 'baseline_id', 'revision']);
            $table->foreign('baseline_id')->references('id')->on('canonical_baselines')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$prefix}canonical_commits ADD CONSTRAINT {$prefix}canonical_commits_origin_check CHECK ((origin = 'submission' AND submission_run_id IS NOT NULL AND validation_id IS NOT NULL AND source_commit_id IS NULL) OR (origin IN ('revision', 'rebase') AND source_commit_id IS NOT NULL))");
            DB::statement("ALTER TABLE {$prefix}canonical_commits ALTER COLUMN baseline_id SET NOT NULL, ALTER COLUMN chapter_number SET NOT NULL");
        }
    }

    /** 已有修订基线或修订提交时拒绝回滚，正式历史不能被自动折叠。 */
    public function down(): void
    {
        if (DB::table('canonical_baselines')->where('purpose', '!=', 'initial')->exists() || DB::table('canonical_commits')->where('origin', '!=', 'submission')->exists()) {
            throw new RuntimeException('已有正式稿修订基线或修订提交，拒绝回滚基线');
        }
        if (DB::getDriverName() === 'pgsql') {
            $prefix = DB::getTablePrefix();
            DB::statement("ALTER TABLE {$prefix}canonical_commits DROP CONSTRAINT IF EXISTS {$prefix}canonical_commits_origin_check");
        }
        Schema::table('canonical_commits', function (Blueprint $table): void {
            $table->dropForeign(['baseline_id', 'novel_id']);
            $table->dropForeign(['source_commit_id']);
            $table->dropUnique(['baseline_id', 'chapter_number']);
            $table->dropIndex(['chapter_id']);
            $table->unique('chapter_id');
        });
        Schema::table('canonical_commits', function (Blueprint $table): void {
            $table->dropColumn(['baseline_id', 'chapter_number', 'origin', 'source_commit_id']);
            $table->unsignedBigInteger('submission_run_id')->nullable(false)->comment('准备运行')->change();
            $table->unsignedBigInteger('validation_id')->nullable(false)->comment('通过验证')->change();
        });
        Schema::table('foreshadow_evidence', function (Blueprint $table): void {
            $table->dropForeign(['baseline_id']);
            $table->dropUnique(['foreshadow_id', 'baseline_id', 'revision']);
            $table->unique(['foreshadow_id', 'revision']);
        });
        Schema::table('foreshadow_evidence', function (Blueprint $table): void {
            $table->dropColumn('baseline_id');
        });
        Schema::table('novels', function (Blueprint $table): void {
            $table->dropForeign(['active_baseline_id']);
            $table->dropColumn('active_baseline_id');
        });
        Schema::dropIfExists('canonical_baselines');
    }
};
