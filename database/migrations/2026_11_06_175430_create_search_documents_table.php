<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 正式正文的可重建检索投影，固定分词与嵌入代次，不修改事实来源。 */
return new class extends Migration
{
    /** PostgreSQL使用真实全文及向量类型；SQLite仅用于非检索单元测试。 */
    public function up(): void
    {
        Schema::create('search_documents', function (Blueprint $table): void {
            $table->comment('正式正文分块检索投影');
            $table->id()->comment('检索块编号');
            $table->foreignId('novel_id')->comment('所属小说')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('chapter_id')->comment('来源章节编号，读取时核验正式指针');
            $table->unsignedBigInteger('commit_id')->comment('来源正式提交编号');
            $table->unsignedBigInteger('version_id')->comment('来源正文版本编号');
            $table->unsignedInteger('chapter_number')->comment('来源章号');
            $table->unsignedInteger('chunk_number')->comment('章内块序号');
            $table->unsignedInteger('start_offset')->comment('原文Unicode码点起始偏移');
            $table->text('body')->comment('未经改写的正式原文片段');
            $table->string('body_hash', 64)->comment('整章正式正文摘要');
            $table->string('chunk_hash', 64)->comment('分块原文摘要');
            $table->jsonb('entity_names')->comment('本章已知且在原文出现的实体名称与别名');
            $table->text('entity_text')->comment('用于近似匹配的实体名称集合');
            $table->string('profile')->comment('固定模型维度距离及分块分词代次');
            $table->unsignedBigInteger('embedding_log_id')->nullable()->comment('向量来源调用流水');
            $table->timestampTz('indexed_at')->comment('词法索引生成时间');
            if (DB::getDriverName() !== 'pgsql') {
                $table->text('search_vector')->nullable()->comment('测试占位，禁止冒充中文全文检索');
                $table->text('embedding')->nullable()->comment('测试占位，禁止冒充向量检索');
            }
            $table->unique(['commit_id', 'chunk_number']);
            $table->index(['novel_id', 'chapter_number']);
        });
        if (DB::getDriverName() === 'pgsql') {
            $name = DB::getQueryGrammar()->wrapTable('search_documents');
            // 未安装检索扩展的既有数据库仍可迁移基础业务；检索服务会明确阻塞，绝不伪造向量能力。
            $hasVector = DB::selectOne("select exists(select 1 from pg_extension where extname='vector') as present")->present;
            $embeddingType = $hasVector ? 'public.vector(1536)' : 'text';
            DB::statement("ALTER TABLE {$name} ADD COLUMN search_vector tsvector, ADD COLUMN embedding {$embeddingType}");
            DB::statement("COMMENT ON COLUMN {$name}.search_vector IS '固定中文分词配置的全文向量'");
            DB::statement("COMMENT ON COLUMN {$name}.embedding IS 'OpenAI小型嵌入模型1536维余弦向量，空值表示尚未完成'");
            DB::statement("CREATE INDEX search_documents_fts ON {$name} USING gin(search_vector)");
            if (DB::selectOne("select exists(select 1 from pg_extension where extname='pg_trgm') as present")->present) {
                DB::statement("CREATE INDEX search_documents_names_trgm ON {$name} USING gin(entity_text public.gin_trgm_ops)");
            }
        }
    }

    /** 派生投影可从不可变正式正文重建，删除不影响事实历史。 */
    public function down(): void
    {
        Schema::dropIfExists('search_documents');
    }
};
