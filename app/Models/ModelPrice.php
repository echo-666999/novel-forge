<?php

namespace App\Models;

use Database\Factories\ModelPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 作者登记的已核实单价；只追加，新版本按生效时间替代旧版本。 */
#[Fillable(['model', 'currency', 'input_per_million', 'output_per_million', 'cache_read_per_million', 'cache_write_per_million', 'source', 'verified_on', 'effective_at', 'created_by', 'created_at'])]
class ModelPrice extends Model
{
    /** @use HasFactory<ModelPriceFactory> */
    use HasFactory;

    /** 只记录登记时间。 */
    public $timestamps = false;

    /** 历史单价是计费依据，禁止修改或删除。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('已登记单价不可修改，请登记新版本'));
        static::deleting(fn () => throw new LogicException('已登记单价不可删除'));
    }

    /** 金额保持十进制字符串，时间为不可变对象。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['input_per_million' => 'decimal:8', 'output_per_million' => 'decimal:8', 'cache_read_per_million' => 'decimal:8', 'cache_write_per_million' => 'decimal:8',
            'verified_on' => 'immutable_date', 'effective_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'created_by' => 'integer'];
    }

    /** 转为计费器使用的冻结单价结构；版本号绑定记录编号，便于追溯。
     * @return array<string, string>
     */
    public function toPriceSnapshot(): array
    {
        return array_filter(['version' => 'model-price-'.$this->id, 'currency' => $this->currency, 'input_per_million' => $this->input_per_million, 'output_per_million' => $this->output_per_million,
            'cache_read_per_million' => $this->cache_read_per_million ?? $this->input_per_million, 'cache_write_per_million' => $this->cache_write_per_million ?? $this->input_per_million], fn ($value): bool => $value !== null);
    }
}
