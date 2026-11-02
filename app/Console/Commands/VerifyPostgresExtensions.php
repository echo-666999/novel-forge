<?php

namespace App\Console\Commands;

use App\Services\PostgresExtensionVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** A02 扩展验收入口，明确区分通过、失败和缺少安装条件。 */
#[Signature('app:verify-postgres-extensions {--database= : 同一 PostgreSQL 服务上的目标数据库名} {--parser=zhparser : 已选定的中文解析器} {--json : 输出完整 JSON 证据}')]
#[Description('验证 PostgreSQL 向量、中文分词及三元组扩展，不自动安装扩展')]
class VerifyPostgresExtensions extends Command
{
    /** 连接目标库并运行验收；任意一项未通过即返回失败退出码。 */
    public function handle(PostgresExtensionVerifier $verifier): int
    {
        try {
            $database = $this->option('database');
            if ($database !== null && ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $database)) {
                throw new \RuntimeException('数据库名格式不正确');
            }
            $configuration = config('database.connections.pgsql');
            if ($database !== null) {
                $configuration['url'] = null;
                $configuration['database'] = $database;
            }
            // 使用临时连接配置，不改动当前应用默认连接或持久配置文件。
            $connection = DB::build($configuration);
            try {
                $report = $verifier->verify($connection, (string) $this->option('parser'));
            } finally {
                $connection->disconnect();
            }
        } catch (Throwable $exception) {
            $report = ['passed' => false, 'error' => $exception->getMessage()];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($report['checks'] ?? [] as $extension => $check) {
                $this->line($extension.': '.$check['status'].' '.($check['message'] ?? $check['version'] ?? ''));
            }
            $this->line($report['error'] ?? ($report['passed'] ? '全部扩展验收通过' : '扩展验收尚未通过，请查看逐项结果'));
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
