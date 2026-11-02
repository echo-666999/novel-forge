<?php

namespace Tests\Feature;

use App\Services\PostgresExtensionVerifier;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 使用独立 PostgreSQL 库执行 A02，所有样本均由验证器回滚。 */
class A02ExtensionsTest extends TestCase
{
    /** 指定专用数据库，防止测试误用开发库或其他项目的测试库。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_a02_test');

        return $app;
    }

    /** 检查数据库身份；测试不自动创建或安装任何扩展。 */
    private function connection(): Connection
    {
        $connection = DB::connection('pgsql');
        $identity = $connection->selectOne("select current_database() as name, shobj_description(oid, 'pg_database') as purpose from pg_database where datname=current_database()");
        $this->assertSame('novel_forge_app_a02_test', $identity->name);
        $this->assertSame('novel-forge A02 isolated tests', $identity->purpose);

        return $connection;
    }

    /** 验证真实向量和相似度查询，并确保临时样本未保留。 */
    public function test_vector_and_trigram_queries_and_indexes_work_without_persistent_samples(): void
    {
        $connection = $this->connection();
        $report = app(PostgresExtensionVerifier::class)->verify($connection);
        $this->assertSame('passed', $report['checks']['vector']['status'], json_encode($report));
        $this->assertSame('passed', $report['checks']['pg_trgm']['status'], json_encode($report));
        $this->assertSame([1, 2, 3], array_column($report['checks']['vector']['details']['neighbors'], 'id'));
        $this->assertSame(0, $connection->transactionLevel());
        foreach (['a02_vector_probe', 'a02_trigram_probe', 'a02_chinese_probe'] as $table) {
            $this->assertNull($connection->selectOne('select to_regclass(?) as relation', [$table])->relation);
        }
    }

    /** 汇总通过状态必须与逐项验收一致，不允许忽略失败或阻塞。 */
    public function test_report_cannot_pass_without_all_required_extensions(): void
    {
        $report = app(PostgresExtensionVerifier::class)->verify($this->connection());
        $statuses = array_column($report['checks'], 'status');
        $this->assertSame(! in_array('blocked', $statuses, true) && ! in_array('failed', $statuses, true), $report['passed']);
        $this->assertArrayHasKey('zhparser', $report['checks']);
    }

    /** 中文能力须在实际安装后完成；缺少安装条件明确标记为未完成。 */
    public function test_chinese_segmentation_and_dictionary_are_really_available(): void
    {
        $connection = $this->connection();
        $parser = getenv('A02_PARSER') ?: 'zhparser';
        $report = app(PostgresExtensionVerifier::class)->verify($connection, $parser);
        if ($report['checks'][$parser]['status'] === 'blocked') {
            $this->markTestIncomplete('A02 中文分词待用户完成服务器安装和配置：'.$report['checks'][$parser]['message']);
        }
        $this->assertTrue($report['passed'], json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertContains('北京', $report['checks'][$parser]['details']['tokens']);
        $this->assertContains('玄霄宗', $report['checks'][$parser]['details']['proper_tokens']);
    }

    /** 缺少配置时给出业务诊断，且嵌套验收不能破坏调用方事务。 */
    public function test_missing_chinese_configuration_is_reported_without_undefined_function_errors(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->statement('DROP TEXT SEARCH CONFIGURATION public.nf_zh_cn');
            $report = app(PostgresExtensionVerifier::class)->verify($connection);
            $this->assertFalse($report['passed']);
            $this->assertSame('failed', $report['checks']['zhparser']['status']);
            $this->assertStringContainsString('中文全文配置', $report['checks']['zhparser']['message']);
            $this->assertStringNotContainsString('42883', $report['checks']['zhparser']['message']);
            $this->assertSame(1, $connection->transactionLevel());
        } finally {
            $connection->rollBack();
        }
    }

    /** 重复配置及重连后仍须完整识别专有词，覆盖启动参数丢失的回归。 */
    public function test_configuration_can_be_reapplied_and_dictionary_survives_reconnection(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $sql = file_get_contents(database_path('verification/a02_zhparser_configuration.sql'));
            $connection->unprepared($sql);
            $connection->unprepared($sql);
        } finally {
            $connection->rollBack();
        }

        DB::purge('pgsql');
        $connection = $this->connection();
        $report = app(PostgresExtensionVerifier::class)->verify($connection);
        $this->assertTrue($report['passed'], json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertSame('a02_custom_dict.txt', $connection->selectOne("select current_setting('zhparser.extra_dicts') as dictionary")->dictionary);
        $this->assertContains('玄霄宗', $report['checks']['zhparser']['details']['proper_tokens']);
    }
}
