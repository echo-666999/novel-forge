<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 冻结导出任务与派生文件生命周期，不改变正式版本。 */
return new class extends Migration
{
    /** 冻结清单独立于可变执行状态，所有字段附中文备注。 */
    public function up(): void
    {
        Schema::create('novel_exports', function (Blueprint $table): void {
            $table->comment('正式正文导出任务及私有派生文件审计');
            $table->id()->comment('导出任务编号');
            $table->uuid('uuid')->unique()->comment('服务端生成的私有文件标识');
            $table->foreignId('novel_id')->comment('所属小说')->constrained('novels')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->comment('授权创建导出的账号');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('按同一冻结清单再生成时的前驱任务');
            $table->string('format', 8)->comment('冻结文件格式txt或md');
            $table->unsignedInteger('end_chapter')->comment('从首章导出至该正式章');
            $table->json('snapshot')->comment('不可变来源清单书名格式文件摘要与保留策略');
            $table->string('snapshot_hash', 64)->comment('冻结清单摘要');
            $table->string('status', 20)->default('queued')->index()->comment('排队生成就绪失败过期状态');
            $table->unsignedBigInteger('byte_size')->nullable()->comment('完成文件字节数');
            $table->string('error', 1000)->nullable()->comment('脱敏失败说明');
            $table->json('history')->comment('只追加的操作与执行记录');
            $table->timestampTz('created_at')->comment('任务创建时间');
            $table->timestampTz('started_at')->nullable()->comment('消费者开始执行时间');
            $table->timestampTz('completed_at')->nullable()->comment('文件完成时间');
            $table->timestampTz('expires_at')->nullable()->index()->comment('文件下载及保留截止时间');
            $table->timestampTz('deleted_at')->nullable()->comment('仅派生文件删除时间，任务历史保留');
        });
    }

    /** 存在导出审计时不允许回滚删除。 */
    public function down(): void
    {
        if (DB::table('novel_exports')->exists()) {
            throw new RuntimeException('已有导出任务，拒绝删除审计');
        }
        Schema::dropIfExists('novel_exports');
    }
};
