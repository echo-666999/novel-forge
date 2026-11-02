<?php

namespace Tests\Feature;

use App\Services\PostgresExtensionVerifier;
use Illuminate\Database\Connection;
use Tests\TestCase;

/** 验证命令在无 PostgreSQL 条件下也能明确失败，不误报成功。 */
class VerifyPostgresExtensionsTest extends TestCase
{
    /** 禁止以 SQLite 替代主库的扩展兼容性验证。 */
    public function test_verifier_rejects_sqlite(): void
    {
        $connection = $this->mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不能以 SQLite 替代');
        app(PostgresExtensionVerifier::class)->verify($connection);
    }

    /** 非法库名在连接之前即被拒绝，并以结构化输出返回失败。 */
    public function test_command_rejects_invalid_database_names(): void
    {
        $this->artisan('app:verify-postgres-extensions', ['--database' => 'invalid;name', '--json' => true])
            ->expectsOutputToContain('数据库名格式不正确')
            ->assertExitCode(1);
    }

    /** 即使有两项已通过，缺失中文扩展仍须返回非零退出码。 */
    public function test_command_returns_failure_for_partial_verification(): void
    {
        $this->mock(PostgresExtensionVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn([
                'passed' => false,
                'checks' => ['vector' => ['status' => 'passed', 'version' => 'test'], 'zhparser' => ['status' => 'blocked', 'message' => '等待安装']],
            ]);
        });
        $this->artisan('app:verify-postgres-extensions', ['--json' => true])
            ->expectsOutputToContain('"passed": false')
            ->assertExitCode(1);
    }

    /** 全部真实检查通过的报告才能使命令返回成功。 */
    public function test_command_returns_success_for_complete_verification(): void
    {
        $this->mock(PostgresExtensionVerifier::class, function ($mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(['passed' => true, 'checks' => []]);
        });
        $this->artisan('app:verify-postgres-extensions')->assertExitCode(0);
    }
}
