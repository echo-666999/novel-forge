<?php

namespace App\Filament\Pages;

use App\Models\ModelPrice;
use App\Services\Ai\ModelPrices;
use App\Services\Ai\ModelProfiles;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** 模型单价登记：只追加，新登记替代同一模型的旧单价；用于费用估算、预算预留和预算控制。 */
class ManageModelPrices extends Page implements HasTable
{
    use InteractsWithTable;

    /** 页面标题。 */
    protected static ?string $title = '模型单价';

    /** 侧栏图标。 */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyYen;

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '配置与运维';

    /** 分组内排序。 */
    protected static ?int $navigationSort = 1;

    /** 说明单价的用途与核实要求。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('当前生效的单价')->description('请求结束后按实际响应模型匹配单价（先精确匹配，再匹配最长的“标识-”前缀，例如 gpt-4.1-mini 可匹配 gpt-4.1-mini-2025-04-14）。只登记从官方价格页核实过的单价；历史请求的金额不会因新登记而改变。')->schema([
                TextEntry::make('coverage')->hiddenLabel()->state(fn (): array => $this->coverage())->listWithLineBreaks(),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    /** 每个可选模型当前是否已有单价。
     * @return list<string>
     */
    public function coverage(): array
    {
        $prices = app(ModelPrices::class)->snapshot();

        return collect(ModelProfiles::options())->map(function (string $label, string $model) use ($prices): string {
            $price = ModelPrices::resolve($prices, $model);

            return $label.'：'.($price === null ? '未登记，金额显示为“未知”，设了预算的书无法使用' : '输入 '.$price['input_per_million'].' / 输出 '.$price['output_per_million'].' '.$price['currency'].' 每百万Token（'.$price['version'].'）');
        })->values()->all();
    }

    /** 全部登记历史，按时间倒序。 */
    public function table(Table $table): Table
    {
        return $table->query(ModelPrice::query())->defaultSort('id', 'desc')->columns([
            TextColumn::make('model')->label('模型标识')->searchable(),
            TextColumn::make('currency')->label('币种'),
            TextColumn::make('input_per_million')->label('输入 / 百万Token'),
            TextColumn::make('output_per_million')->label('输出 / 百万Token'),
            TextColumn::make('cache_read_per_million')->label('缓存读取')->placeholder('同输入单价'),
            TextColumn::make('source')->label('来源与核实说明')->wrap()->limit(80),
            TextColumn::make('verified_on')->label('核实日期')->date('Y-m-d'),
            TextColumn::make('effective_at')->label('生效时间')->dateTime('Y-m-d H:i'),
        ])->filters([SelectFilter::make('model')->label('模型')->options(fn (): array => ModelPrice::distinct()->pluck('model', 'model')->all())])
            ->emptyStateHeading('尚未登记单价')->emptyStateDescription('点击右上角“登记单价”。未登记时费用显示为“未知”，不代表免费。');
    }

    /** 登记新单价；不提供修改或删除。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('register')->label('登记单价')->modalHeading('登记已核实的模型单价')
            ->modalDescription('登记后立即对新请求生效，旧单价保留在历史中。请填写官方价格页地址或账单依据。')
            ->schema([
                Select::make('model')->label('模型')->options(ModelProfiles::options())->native(false)->required()
                    ->helperText('如需登记带日期后缀的实际响应模型，可选择基础模型，系统按前缀匹配。'),
                Select::make('currency')->label('币种')->options(['CNY' => '人民币（CNY）', 'USD' => '美元（USD）'])->default('CNY')->native(false)->required(),
                TextInput::make('input_per_million')->label('每百万输入Token单价')->required()->regex('/\A\d{1,8}(?:\.\d{1,8})?\z/'),
                TextInput::make('output_per_million')->label('每百万输出Token单价（含推理）')->required()->regex('/\A\d{1,8}(?:\.\d{1,8})?\z/'),
                TextInput::make('cache_read_per_million')->label('每百万缓存命中Token单价（可选）')->regex('/\A\d{1,8}(?:\.\d{1,8})?\z/'),
                Textarea::make('source')->label('来源与核实说明')->required()->maxLength(2000)->placeholder('例如：官方价格页地址，核实时的页面说明'),
                DatePicker::make('verified_on')->label('核实日期')->required()->default(now())->maxDate(now()),
            ])->action(function (array $data): void {
                app(ModelPrices::class)->register(Filament::auth()->user(), $data);
                Notification::make()->success()->title('单价已登记')->body('新请求将按此单价估算；历史请求金额不变。')->send();
            })];
    }
}
