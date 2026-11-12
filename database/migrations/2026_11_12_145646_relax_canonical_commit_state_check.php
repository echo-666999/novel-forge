<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A-09：状态序号改为全书单调，候选基线的提交跳过其他基线占用的序号；只要求严格前进，不再要求等于上一序号加一。 */
return new class extends Migration
{
    /** 放宽 PostgreSQL 提交状态序号检查。 */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $table = DB::connection()->getQueryGrammar()->wrapTable('canonical_commits');
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS canonical_commits_values');
        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT canonical_commits_values CHECK (previous_state_version>=0 AND state_version>previous_state_version)');
    }

    /** 已有跳号提交时拒绝恢复旧约束。 */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('canonical_commits')->whereRaw('state_version <> previous_state_version + 1')->exists()) {
            throw new RuntimeException('已有跳号的基线提交，拒绝恢复连续序号约束');
        }
        $table = DB::connection()->getQueryGrammar()->wrapTable('canonical_commits');
        DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS canonical_commits_values');
        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT canonical_commits_values CHECK (previous_state_version>=0 AND state_version=previous_state_version+1)');
    }
};
