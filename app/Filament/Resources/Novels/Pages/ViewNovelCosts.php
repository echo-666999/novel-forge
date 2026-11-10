<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\CostLog;
use App\Services\Ai\CostLedgerExport;
use App\Services\Ai\NovelCostSummary;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** 逐次成本明细只读页面，不重发请求、不修订账单、不影响流水线。 */
class ViewNovelCosts extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 所属资源用于权限和路由。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '费用明细';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 17;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '交付';

    /** 作者可见的页面名称。 */
    protected static ?string $title = '调用成本明细';

    /** 查询固定在当前小说，并沿用私有后台查看权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 汇总覆盖整本小说，不随表格筛选或分页改变。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('小说累计费用')->description('实际金额与未结算估算分别累计；不同币种不折算。失败及未知请求不会被删除，费用不参与运行停止判断。')->schema([
                TextEntry::make('summary')->label('全部调用汇总')->state(fn (): string => app(NovelCostSummary::class)->describe(app(NovelCostSummary::class)->forNovel($this->getRecord()->id))),
            ]), EmbeddedTable::make(),
        ]);
    }

    /** 展示每次调用的计费与用量，弹窗只含安全元数据，不加载原始正文或凭据。 */
    public function table(Table $table): Table
    {
        return $table->query(CostLog::where('novel_id', $this->getRecord()->id)->select([
            'id', 'novel_id', 'novel_stage_id', 'request_uuid', 'mode', 'requested_model', 'model', 'status', 'billing_status', 'currency',
            'estimated_cost', 'actual_cost', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens',
            'provider_request_id', 'price_version', 'error_category', 'error_message', 'duration_ms', 'started_at',
        ]))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('调用编号')->sortable(),
            TextColumn::make('mode')->label('模式')->formatStateUsing(fn (string $state): string => $state === 'fake' ? '模拟' : '真实'),
            TextColumn::make('novel_stage_id')->label('阶段编号')->placeholder('独立调用'),
            TextColumn::make('requested_model')->label('请求模型')->searchable(),
            TextColumn::make('status')->label('请求结果')->formatStateUsing(fn (string $state): string => match ($state) {
                'succeeded' => '成功', 'failed' => '失败', 'pending' => '待返回', default => '结果未知'
            }),
            TextColumn::make('billing_status')->label('计费状态')->formatStateUsing(fn (string $state): string => match ($state) {
                'simulated' => '模拟', 'estimated' => '估算', default => '未知'
            }),
            TextColumn::make('actual_cost')->label('实际费用')->placeholder('未知')->formatStateUsing(fn (string $state, CostLog $record): string => self::amount($state, $record)),
            TextColumn::make('estimated_cost')->label('估算费用')->placeholder('未知')->formatStateUsing(fn (string $state, CostLog $record): string => self::amount($state, $record)),
            TextColumn::make('started_at')->label('请求时间')->dateTime('Y-m-d H:i:s')->placeholder('未记录'),
        ])->filters([
            SelectFilter::make('requested_model')->label('请求模型')->options(fn (): array => CostLog::where('novel_id', $this->getRecord()->id)->distinct()->pluck('requested_model', 'requested_model')->all())->searchable(),
            SelectFilter::make('billing_status')->label('计费状态')->options(['unknown' => '未知', 'estimated' => '估算', 'simulated' => '模拟']),
            SelectFilter::make('novel_stage_id')->label('阶段编号')->options(fn (): array => CostLog::where('novel_id', $this->getRecord()->id)->whereNotNull('novel_stage_id')->distinct()->pluck('novel_stage_id', 'novel_stage_id')->all()),
            Filter::make('date')->label('请求日期')->schema([DatePicker::make('from')->label('起始日期'), DatePicker::make('to')->label('截至日期')->afterOrEqual('from')])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('started_at', '>=', $date))->when($data['to'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('started_at', '<=', $date))),
            SelectFilter::make('mode')->label('模式')->options(['real' => '真实', 'fake' => '模拟']),
            SelectFilter::make('status')->label('请求结果')->options(['pending' => '待返回', 'succeeded' => '成功', 'failed' => '失败', 'unknown' => '结果未知']),
        ])->recordActions([
            Action::make('inspectCost')->label('查看用量与来源')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->schema(fn (CostLog $record): array => [
                TextEntry::make('request')->label('本地请求编号')->state($record->request_uuid),
                TextEntry::make('provider_request')->label('供应商请求编号')->state($record->provider_request_id)->placeholder('未知'),
                TextEntry::make('model')->label('实际模型')->state($record->model)->placeholder('未知'),
                TextEntry::make('price')->label('冻结价格版本')->state($record->price_version)->placeholder('未配置价格；不能推断为免费'),
                ...array_map(fn (string $field, string $label): TextEntry => TextEntry::make($field)->label($label)->state($record->$field)->placeholder('未知'),
                    ['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'duration_ms'], ['输入Token', '输出Token', '缓存读取Token', '缓存写入Token', '推理Token（输出子集）', '耗时（毫秒）']),
                TextEntry::make('error')->label('错误类别')->state($record->error_category)->placeholder('无'),
                TextEntry::make('message')->label('失败说明')->state($record->error_message)->placeholder('无'),
            ]),
        ]);
    }

    /** 缺少币种不伪造货币标识，模拟金额始终另作标记。 */
    private static function amount(string $amount, CostLog $record): string
    {
        return $record->mode === 'fake' ? $amount.'（模拟）' : $amount.' '.($record->currency ?? '币种未知');
    }

    /** 刷新只重新读取数据库，不能触发任何生成请求。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('exportCosts')->label('导出筛选账单')->requiresConfirmation()->modalDescription('导出当前小说与筛选范围的计费元数据，未知金额保持为空；不包含正文、原始请求或密钥。超过10000条时需缩小范围。')
            ->action(function (): StreamedResponse {
                $payload = app(CostLedgerExport::class)->payload($this->getRecord()->id, $this->getFilteredTableQuery());
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                return response()->streamDownload(function () use ($json): void {
                    echo $json;
                }, 'novel-'.$this->getRecord()->id.'-costs.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
            }), Action::make('refreshCosts')->label('刷新费用')->action(fn () => $this->getRecord()->refresh()),
            Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }
}
