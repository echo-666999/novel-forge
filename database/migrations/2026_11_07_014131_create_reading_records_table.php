<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 阅读记录与原文问题独立于正式提交，不更改正文或评审结论。 */
return new class extends Migration
{
    /** 每条记录绑定不可变正文版本，所有字段具有中文备注。 */
    public function up(): void
    {
        Schema::create('reading_records', function (Blueprint $table): void {
            $table->comment('作者阅读记录及问题摘录，不代表成品验收通过');
            $table->id()->comment('阅读记录编号');
            $table->foreignId('version_id')->comment('不可变正文版本编号')->constrained('chapter_versions')->restrictOnDelete();
            $table->foreignId('actor_id')->comment('阅读或提出问题的账号')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20)->comment('记录类型：已读或问题');
            $table->text('quote')->nullable()->comment('问题对应的原文摘录');
            $table->text('note')->nullable()->comment('作者的问题说明');
            $table->timestampTz('created_at')->comment('阅读记录时间');
            $table->index(['actor_id', 'version_id', 'kind']);
        });
    }

    /** 已有作者证据时拒绝回滚丢失数据。 */
    public function down(): void
    {
        if (DB::table('reading_records')->exists()) {
            throw new RuntimeException('已有阅读记录，拒绝删除作者证据');
        }
        Schema::dropIfExists('reading_records');
    }
};
