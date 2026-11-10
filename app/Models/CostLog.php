<?php

namespace App\Models;

use Database\Factories\CostLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 一次供应商调用的可审计明细，真实未知计费与离线模拟明确分离。 */
#[Fillable(['request_uuid', 'novel_id', 'novel_stage_id', 'mode', 'requested_provider', 'requested_model', 'provider', 'model', 'route_version', 'prompt_version', 'prompt_hash', 'request_parameters', 'status', 'provider_request_id', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'currency', 'price_version', 'price_snapshot', 'estimated_cost', 'actual_cost', 'billing_status', 'response_text', 'structured_output', 'raw_response', 'error_category', 'error_message', 'duration_ms', 'started_at', 'finished_at', 'reserved_cost', 'reserved_currency'])]
class CostLog extends Model
{
    /** @use HasFactory<CostLogFactory> 工厂仅创建测试明细，不触发模型调用。 */
    use HasFactory;

    /** 使用定点金额、结构化快照和不可变时间，避免浮点费用累加。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_parameters' => 'array', 'price_snapshot' => 'array', 'structured_output' => 'array', 'raw_response' => 'array',
            'estimated_cost' => 'decimal:8', 'actual_cost' => 'decimal:8', 'reserved_cost' => 'decimal:8',
            'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cache_read_tokens' => 'integer', 'cache_write_tokens' => 'integer', 'reasoning_tokens' => 'integer', 'duration_ms' => 'integer',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    /** 所属小说；独立连通验收允许为空。
     * @return BelongsTo<Novel, $this>
     */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** 所属阶段；不因阶段变化自动重发或删除明细。
     * @return BelongsTo<NovelStage, $this>
     */
    public function novelStage(): BelongsTo
    {
        return $this->belongsTo(NovelStage::class);
    }
}
