<?php

namespace App\Services\Ai;

use App\Models\CostLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** 账单导出只包含白名单计费字段，不导出提示词、正文、原始响应或错误原文。 */
final class CostLedgerExport
{
    /** 按当前筛选重新限定小说；超限明确拒绝，避免把截断清单当作完整账单。
     * @return array{novel_id: int, scope: string, records: array}
     */
    public function payload(int $novelId, Builder $filtered): array
    {
        $query = (clone $filtered)->where('novel_id', $novelId);
        if ($query->count() > 10000) {
            throw ValidationException::withMessages(['export' => '筛选结果超过10000条，请缩小日期范围后导出。']);
        }
        $fields = ['id', 'novel_id', 'novel_stage_id', 'request_uuid', 'mode', 'requested_model', 'model', 'status', 'billing_status',
            'currency', 'estimated_cost', 'actual_cost', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens',
            'reasoning_tokens', 'price_version', 'error_category', 'duration_ms', 'started_at'];
        $rows = $query->reorder()->orderBy('id')->limit(10001)->select($fields)->get();
        // 查询期间新增记录也不能让文件悄悄截断，且从模型取值再次使用白名单。
        if ($rows->count() > 10000) {
            throw ValidationException::withMessages(['export' => '账单在查询期间新增，请缩小范围后重试。']);
        }

        return ['novel_id' => $novelId, 'scope' => '当前筛选的计费元数据；null表示未知，不表示免费；模拟调用不计费',
            'records' => $rows->map(fn (CostLog $row): array => $row->only($fields))->all()];
    }
}
