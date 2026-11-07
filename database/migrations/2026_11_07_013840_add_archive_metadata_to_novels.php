<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 归档仅用于书架分类，不删除小说、不改变运行或正式剧情。 */
return new class extends Migration
{
    /** 归档字段及操作历史均添加中文数据库备注。 */
    public function up(): void
    {
        Schema::table('novels', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable()->index()->comment('书架归档时间，空值表示未归档');
            $table->jsonb('archive_history')->nullable()->comment('归档与恢复操作历史，记录账号时间及原因');
        });
    }

    /** 回滚前保留已有审计，禁止静默丢弃归档历史。 */
    public function down(): void
    {
        if (DB::table('novels')->whereNotNull('archive_history')->exists()) {
            throw new RuntimeException('已有归档操作历史，拒绝删除审计字段');
        }
        Schema::table('novels', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'archive_history']);
        });
    }
};
