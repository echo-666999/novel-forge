<?php

namespace App\Console\Commands;

use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/** A03 命令入口；默认离线，真实验收必须显式选择且仅请求一次。 */
#[Signature('app:verify-ai {--mode=fake : fake 或 real} {--model= : 覆盖本次模型，不修改通用设置} {--json : 输出脱敏 JSON 结果}')]
#[Description('验证 AI 文本调用与成本日志，不重试、不自动切换供应商')]
class VerifyAi extends Command
{
    /** 返回持久化日志编号和真实状态，调用或保存失败均返回退出码1。 */
    public function handle(AiTextGenerationService $service): int
    {
        try {
            $log = $service->generate('请只回复：A03连接成功。', (string) $this->option('mode'), model: $this->option('model') ?: null, promptVersion: 'a03-probe-v1');
            $report = ['passed' => true, 'cost_log_id' => $log->id, 'request_uuid' => $log->request_uuid, 'mode' => $log->mode, 'requested_model' => $log->requested_model, 'provider' => $log->provider, 'model' => $log->model, 'text' => $log->response_text, 'structured_output' => $log->structured_output, 'usage' => $log->only(['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens']), 'estimated_cost' => $log->estimated_cost, 'actual_cost' => $log->actual_cost, 'billing_status' => $log->billing_status];
        } catch (AiGenerationException $exception) {
            $report = ['passed' => false, 'cost_log_id' => $exception->costLogId, 'status' => $exception->status, 'error_category' => $exception->category, 'error' => $exception->getMessage()];
        } catch (InvalidArgumentException $exception) {
            $report = ['passed' => false, 'error' => $exception->getMessage()];
        } catch (Throwable) {
            $report = ['passed' => false, 'error' => '验收未完成，请检查数据库迁移、连接及本地配置；不要盲目重发。'];
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | ($this->option('json') ? JSON_PRETTY_PRINT : 0)));

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
