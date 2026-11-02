<?php

namespace App\Services;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/** 验证 PostgreSQL 扩展的真实行为，仅使用事务内临时表，不安装或升级扩展。 */
class PostgresExtensionVerifier
{
    /**
     * 返回服务器版本和逐项验收结果；缺失中文解析器仍属于未通过。
     *
     * @return array<string, mixed>
     */
    public function verify(Connection $connection, string $parser = 'zhparser'): array
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('扩展验收必须连接 PostgreSQL，不能以 SQLite 替代');
        }
        if (! in_array($parser, ['zhparser', 'pg_jieba'], true)) {
            throw new RuntimeException('解析器仅支持 zhparser 或 pg_jieba，不自动降级');
        }

        $server = (array) $connection->selectOne('select current_database() as database, version() as version');
        $installed = $connection->select('select e.extname, e.extversion, n.nspname as schema from pg_extension e join pg_namespace n on n.oid=e.extnamespace order by e.extname');
        $checks = [];
        foreach (['vector', 'pg_trgm', $parser] as $extension) {
            $metadata = collect($installed)->firstWhere('extname', $extension);
            if ($metadata === null) {
                $checks[$extension] = ['status' => 'blocked', 'message' => '当前数据库未启用此扩展，需先完成服务器安装和数据库配置'];

                continue;
            }
            if ($metadata->schema !== 'public') {
                $checks[$extension] = ['status' => 'blocked', 'message' => '本次固定配置要求扩展位于 public，未修改现有安装'];

                continue;
            }

            // 每个检查独立回滚，使一次 SQL 失败不会阻止其他扩展的结果输出。
            $level = $connection->transactionLevel();
            $connection->beginTransaction();
            try {
                $connection->statement('SET LOCAL statement_timeout = 15000');
                $connection->statement('SET LOCAL search_path = public, pg_catalog');
                $details = match ($extension) {
                    'vector' => $this->verifyVector($connection),
                    'pg_trgm' => $this->verifyTrigram($connection),
                    default => $this->verifyChinese($connection, $parser),
                };
                $checks[$extension] = ['status' => 'passed', 'version' => $metadata->extversion, 'details' => $details];
            } catch (Throwable $exception) {
                $checks[$extension] = ['status' => 'failed', 'version' => $metadata->extversion, 'message' => $exception->getMessage()];
            } finally {
                $connection->rollBack($level);
            }
        }

        return [
            'passed' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'passed'),
            'server' => $server,
            'selected_parser' => $parser,
            'installed_extensions' => $installed,
            'checks' => $checks,
        ];
    }

    /**
     * 使用预置 SQL 检查三维向量、距离、近邻排序及 HNSW 查询能力。
     *
     * @return array<string, mixed>
     */
    private function verifyVector(Connection $connection): array
    {
        $connection->unprepared(file_get_contents(database_path('verification/a02_vector.sql')));
        $rows = $connection->select("select id, embedding <-> '[1,0,0]'::public.vector as distance from a02_vector_probe order by embedding <-> '[1,0,0]'::public.vector");
        $query = "select id from a02_vector_probe order by embedding <-> '[1,0,0]'::public.vector limit 2";

        return ['neighbors' => $rows, 'plans' => $this->verifyIndexPlans($connection, $query, 'a02_vector_probe_hnsw')];
    }

    /**
     * 检查中文名称相似度和 GIN 三元组索引的查询能力。
     *
     * @return array<string, mixed>
     */
    private function verifyTrigram(Connection $connection): array
    {
        $connection->statement('SET LOCAL pg_trgm.similarity_threshold = 0.3');
        $connection->unprepared(file_get_contents(database_path('verification/a02_trigram.sql')));
        $rows = $connection->select("select id, public.similarity(title, '江湖风云') as similarity from a02_trigram_probe order by similarity desc, id");

        return ['similarities' => $rows, 'plans' => $this->verifyIndexPlans($connection, "select id from a02_trigram_probe where title % '江湖风云'", 'a02_trigram_probe_gin')];
    }

    /**
     * 检查显式中文配置、基础词项、专有词、混合文本及全文命中结果。
     *
     * @return array<string, mixed>
     */
    private function verifyChinese(Connection $connection, string $parser): array
    {
        $configuration = $parser === 'zhparser' ? 'public.nf_zh_cn' : 'public.jiebacfg';
        // 配置不存在时通过目录查询返回空结果，避免依赖不存在的转换函数。
        $actual = $connection->selectOne('select p.prsname, pn.nspname as parser_schema from pg_ts_config c join pg_namespace n on n.oid=c.cfgnamespace join pg_ts_parser p on p.oid=c.cfgparser join pg_namespace pn on pn.oid=p.prsnamespace where n.nspname = ? and c.cfgname = ?', ['public', $parser === 'zhparser' ? 'nf_zh_cn' : 'jiebacfg']);
        $expected = $parser === 'zhparser' ? 'zhparser' : 'jieba';
        if ($actual === null || $actual->prsname !== $expected || $actual->parser_schema !== 'public') {
            throw new RuntimeException('缺少正确的中文全文配置：'.$configuration);
        }
        $text = '小说主角学习武功，在北京旅行。NovelForge 2026';
        $row = $connection->selectOne('select tsvector_to_array(to_tsvector(?::regconfig, ?)) as tokens, to_tsvector(?::regconfig, ?) @@ plainto_tsquery(?::regconfig, ?) as hit, to_tsvector(?::regconfig, ?) @@ plainto_tsquery(?::regconfig, ?) as miss, to_tsvector(?::regconfig, ?) = ?::tsvector as empty', [$configuration, $text, $configuration, $text, $configuration, '北京', $configuration, $text, $configuration, '火星', $configuration, '', '']);
        if (! $row->hit || $row->miss || ! $row->empty) {
            throw new RuntimeException('中文命中、未命中或空输入结果不符合预期');
        }
        $lexemes = array_column($connection->select('select unnest(tsvector_to_array(to_tsvector(?::regconfig, ?))) as token', [$configuration, $text]), 'token');
        foreach (['北京', '武功', 'novelforge'] as $token) {
            if (! in_array($token, $lexemes, true)) {
                throw new RuntimeException('中文或混合文本缺少预期词项：'.$token);
            }
        }
        // 专有词由随安装说明提供的固定词典定义，先验证词典加载再建全文索引。
        $proper = array_column($connection->select('select unnest(tsvector_to_array(to_tsvector(?::regconfig, ?))) as token', [$configuration, '玄霄宗修炼']), 'token');
        if (! in_array('玄霄宗', $proper, true)) {
            throw new RuntimeException('固定专有词词典未生效：玄霄宗');
        }
        $connection->unprepared("CREATE TEMP TABLE a02_chinese_probe (id bigint PRIMARY KEY, body text NOT NULL, document tsvector NOT NULL) ON COMMIT DROP;
            COMMENT ON TABLE a02_chinese_probe IS '中文检索验收样本';
            COMMENT ON COLUMN a02_chinese_probe.id IS '样本编号';
            COMMENT ON COLUMN a02_chinese_probe.body IS '中文样本';
            COMMENT ON COLUMN a02_chinese_probe.document IS '分词向量';
            CREATE INDEX a02_chinese_probe_gin ON a02_chinese_probe USING gin(document)");
        $connection->insert('insert into a02_chinese_probe values (1, ?, to_tsvector(?::regconfig, ?))', [$text, $configuration, $text]);
        $query = "select id from a02_chinese_probe where document @@ plainto_tsquery('{$configuration}'::regconfig, '北京')";

        return ['configuration' => $configuration, 'tokens' => $lexemes, 'proper_tokens' => $proper, 'plans' => $this->verifyIndexPlans($connection, $query, 'a02_chinese_probe_gin')];
    }

    /**
     * 同时记录自然计划和索引能力计划；关闭顺序扫描只用于能力验证，不代表性能结论。
     *
     * @return array<string, mixed>
     */
    private function verifyIndexPlans(Connection $connection, string $query, string $index): array
    {
        $natural = $connection->select('EXPLAIN (FORMAT JSON) '.$query);
        $connection->statement('SET LOCAL enable_seqscan = off');
        $indexed = $connection->select('EXPLAIN (FORMAT JSON) '.$query);
        if (! str_contains(json_encode($indexed, JSON_THROW_ON_ERROR), $index)) {
            throw new RuntimeException('查询未能使用预期索引：'.$index);
        }

        return ['natural' => $natural, 'index_capability' => $indexed];
    }
}
