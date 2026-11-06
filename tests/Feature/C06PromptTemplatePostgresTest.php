<?php

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 专库验证模板发布事务、实际请求快照及数据库中文备注。 */
class C06PromptTemplatePostgresTest extends C06PromptTemplateTest
{
    /** 只有固定用途专库才允许迁移和夹具清理。 */
    private bool $identityVerified = false;

    /** 探测只读系统目录；测试写连接只能指向提交专库。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $probe = $app['config']->get('database.connections.pgsql');
        $probe['url'] = null;
        $probe['database'] = 'novel_forge_app';
        $app['config']->set('database.connections.commit_probe', $probe);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_commit_test');

        return $app;
    }

    /** 身份、所有者、用途三项全部匹配后才能迁移，未建库明确未完成。 */
    protected function migrateFreshUsing(): array
    {
        $present = DB::connection('commit_probe')->selectOne('select exists(select 1 from pg_database where datname=?) as present', ['novel_forge_app_commit_test']);
        if (! $present->present) {
            $this->markTestIncomplete('管理员尚未创建 novel_forge_app_commit_test，禁止开发库迁移与真实提交准备');
        }
        $identity = DB::selectOne("select current_database() as name, pg_get_userbyid(datdba) as owner, shobj_description(oid, 'pg_database') as purpose from pg_database where datname=current_database()");
        if ($identity->name !== 'novel_forge_app_commit_test' || $identity->owner !== 'novel_forge_app' || $identity->purpose !== 'novel-forge B05 B06 B07 isolated tests') {
            throw new RuntimeException('提交专库身份不符，拒绝迁移');
        }
        $this->identityVerified = true;

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 复用功能测试前后均不得落入开发数据库。 */
    protected function assertDatabaseIdentity(): void
    {
        $this->assertTrue($this->identityVerified);
        $this->assertSame('novel_forge_app_commit_test', DB::connection()->getDatabaseName());
    }

    /** PostgreSQL目录验证每张新增表及每个字段的中文备注。 */
    public function test_all_template_tables_and_columns_have_chinese_comments(): void
    {
        foreach (['prompt_template_versions', 'prompt_template_routes', 'prompt_template_events'] as $table) {
            $name = DB::getTablePrefix().$table;
            $record = DB::selectOne('select obj_description(c.oid) as remark from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname=current_schema() and c.relname=?', [$name]);
            $this->assertMatchesRegularExpression('/\p{Han}/u', $record->remark ?? '');
            $columns = DB::select('select col_description(c.oid,a.attnum) as remark from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_attribute a on a.attrelid=c.oid where n.nspname=current_schema() and c.relname=? and a.attnum>0 and not a.attisdropped', [$name]);
            $this->assertNotEmpty($columns);
            foreach ($columns as $column) {
                $this->assertMatchesRegularExpression('/\p{Han}/u', $column->remark ?? '');
            }
        }
    }
}
