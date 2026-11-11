<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D01：伏笔长程契约字段与正式提交自动流转来源；旧伏笔各字段为空，仍按原规则处理。 */
return new class extends Migration
{
    /** 新增字段均带中文备注；回收窗口为空表示沿用原“最迟回收章号”规则。 */
    public function up(): void
    {
        Schema::table('foreshadows', function (Blueprint $table): void {
            $table->text('surface_clue')->nullable()->comment('读者可见的表面线索');
            $table->text('hidden_meaning')->nullable()->comment('隐藏含义，回收前不得揭示');
            $table->text('payoff_effect')->nullable()->comment('回收时应产生的剧情作用');
            $table->text('knowledge_constraint')->nullable()->comment('角色知情约束，谁在何时可以知道');
            $table->unsignedInteger('payoff_from')->nullable()->comment('允许回收的最早章号');
            $table->unsignedInteger('payoff_to')->nullable()->comment('允许回收的最晚章号');
            $table->boolean('must_payoff')->default(false)->comment('是否必须在窗口内回收，未回收时阻塞窗口末章提交');
            $table->boolean('planned_before_generation')->default(false)->comment('是否在第1章生成前登记');
        });
        Schema::table('foreshadow_evidence', function (Blueprint $table): void {
            $table->string('source', 32)->default('manual')->comment('流转来源：作者登记或正式提交时的自动提取');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            $table = DB::connection()->getQueryGrammar()->wrapTable('foreshadows');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT foreshadows_payoff_window_check CHECK ((payoff_from IS NULL AND payoff_to IS NULL) OR (payoff_from >= 1 AND payoff_to >= payoff_from))");
        }
    }

    /** 已登记长程契约或自动流转证据时拒绝回滚。 */
    public function down(): void
    {
        if (DB::table('foreshadows')->whereNotNull('payoff_to')->exists() || DB::table('foreshadow_evidence')->where('source', '!=', 'manual')->exists()) {
            throw new LogicException('已有长程伏笔契约或自动流转证据，禁止回滚删除');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.DB::connection()->getQueryGrammar()->wrapTable('foreshadows').' DROP CONSTRAINT IF EXISTS foreshadows_payoff_window_check');
        }
        Schema::table('foreshadow_evidence', fn (Blueprint $table) => $table->dropColumn('source'));
        Schema::table('foreshadows', fn (Blueprint $table) => $table->dropColumn(['surface_clue', 'hidden_meaning', 'payoff_effect', 'knowledge_constraint', 'payoff_from', 'payoff_to', 'must_payoff', 'planned_before_generation']));
    }
};
