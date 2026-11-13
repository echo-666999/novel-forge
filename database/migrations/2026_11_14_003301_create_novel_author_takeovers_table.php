<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 作者接管记录：只追加，最新一条决定本书当前作者。 */
    public function up(): void
    {
        Schema::create('novel_author_takeovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['novel_id', 'id']);
        });
    }

    /** 回滚只删除本表。 */
    public function down(): void
    {
        Schema::dropIfExists('novel_author_takeovers');
    }
};
