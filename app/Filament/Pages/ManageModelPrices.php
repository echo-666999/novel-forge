<?php

namespace App\Filament\Pages;

use App\Filament\Support\HelpHint;
use App\Models\ModelPrice;
use App\Services\Ai\ModelPrices;
use App\Services\Ai\ModelProfiles;
use App\Services\Ai\OfficialPriceFetcher;
use App\Services\Search\EmbeddingService;
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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
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

    /** 金额格式：非负十进制，最多8位小数。 */
    private const DECIMAL = '/\A\d{1,8}(?:\.\d{1,8})?\z/';

    /** 一句结论加「?」说明；只在有模型未登记时提示缺口，下方表格默认只列当前生效的单价。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('单价总览')->description('按实际响应模型匹配单价，只登记官方核实过的价格。')->compact()
                ->headerActions([HelpHint::action('请求结束后按实际响应模型匹配单价（先精确匹配，再匹配最长的“标识-”前缀，例如 gpt-4.1-mini 可匹配 gpt-4.1-mini-2025-04-14）。单价均为每百万Token；分时段计价时登记高峰价，闲时按倍率折算。只登记从官方价格页核实过的单价；历史请求的金额不会因新登记而改变，需要更正时用 ai:reprice-cost-logs 重算。')])
                ->schema([
                    TextEntry::make('missing')->hiddenLabel()->color('warning')->icon(Heroicon::OutlinedExclamationTriangle)
                        ->state(fn (): ?string => $this->missing() === [] ? null : '未登记：'.implode('、', $this->missing()).'。这些模型的费用显示为“未知”，设了预算的书无法使用。')
                        ->visible(fn (): bool => $this->missing() !== []),
                    EmbeddedTable::make(),
                ]),
        ]);
    }

    /** 可登记的模型：后台可选的正文模型加检索用的嵌入模型。
     * @return array<string, string>
     */
    public static function modelOptions(): array
    {
        return [...ModelProfiles::options(), EmbeddingService::MODEL => '嵌入（'.EmbeddingService::MODEL.'）'];
    }

    /** 当前没有生效单价的可登记模型。
     * @return list<string>
     */
    public function missing(): array
    {
        $prices = app(ModelPrices::class)->snapshot();

        return collect(self::modelOptions())->filter(fn (string $label, string $model): bool => ModelPrices::resolve($prices, $model) === null)->values()->all();
    }

    /** 每个模型当前生效的登记编号（同一模型取生效时间最晚的一条）。
     * @return list<int>
     */
    public static function currentIds(): array
    {
        return ModelPrice::where('effective_at', '<=', now())->orderBy('effective_at')->orderBy('id')->get(['id', 'model'])->keyBy('model')->pluck('id')->values()->all();
    }

    /** 金额简写：去掉多余的零并加币种符号，如 USD 0.30000000 显示为 $0.3。 */
    public static function money(?string $amount, string $currency): ?string
    {
        if ($amount === null) {
            return null;
        }
        $trimmed = str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;

        return (['USD' => '$', 'CNY' => '¥'][$currency] ?? $currency.' ').$trimmed;
    }

    /** 闲时与长上下文规则，每条一行；都没有时为空。
     * @return list<string>
     */
    public static function rules(ModelPrice $price): array
    {
        $rules = [];
        if ($price->off_peak_ratio !== null && $price->peak_hours_utc !== null) {
            $rules[] = '闲时 '.rtrim(rtrim($price->off_peak_ratio, '0'), '.').' 倍（工作日高峰 UTC '.implode('、', $price->peak_hours_utc).'）';
        }
        if ($price->long_context_threshold !== null) {
            $money = fn (?string $amount): ?string => self::money($amount, $price->currency);
            $rules[] = '输入超过 '.number_format($price->long_context_threshold / 1000).'K：输入 '.$money($price->long_input_per_million)
                .' · 命中 '.($money($price->long_cache_read_per_million) ?? $money($price->long_input_per_million))
                .($price->long_cache_write_per_million !== null ? ' · 写入 '.$money($price->long_cache_write_per_million) : '').' · 输出 '.$money($price->long_output_per_million);
        }

        return $rules;
    }

    /** 一条单价的完整说明：缓存命中与未命中输入、输出，以及闲时和长上下文规则。
     * @param  array<string, mixed>  $price
     */
    public static function describe(array $price): string
    {
        $text = '输入（未命中缓存）'.$price['input_per_million'].' / 缓存命中 '.($price['cache_read_per_million'] ?? $price['input_per_million']).' / 缓存写入 '.($price['cache_write_per_million'] ?? $price['input_per_million']).' / 输出 '.$price['output_per_million'].' '.$price['currency'].' 每百万Token';
        if (isset($price['off_peak_ratio'], $price['peak_hours_utc'])) {
            $text .= '；高峰时段（UTC，工作日）'.implode('、', (array) $price['peak_hours_utc']).'，其余时间按'.rtrim(rtrim((string) $price['off_peak_ratio'], '0'), '.').'倍计';
        }
        if (isset($price['long_context_threshold'])) {
            $text .= '；输入超过'.$price['long_context_threshold'].'Token时整次请求按输入 '.$price['long_input_per_million'].' / 缓存命中 '.($price['long_cache_read_per_million'] ?? $price['long_input_per_million']).(isset($price['long_cache_write_per_million']) ? ' / 缓存写入 '.$price['long_cache_write_per_million'] : '').' / 输出 '.$price['long_output_per_million'];
        }

        return $text.'（'.$price['version'].'）';
    }

    /** 单价表：默认只看当前生效的单价，关闭筛选可查看全部登记历史。 */
    public function table(Table $table): Table
    {
        $current = self::currentIds();
        $money = fn (string $column) => fn (ModelPrice $record): ?string => self::money($record->{$column}, $record->currency);

        return $table->query(ModelPrice::query())->defaultSort('id', 'desc')->columns([
            TextColumn::make('model')->label('模型')->searchable()->weight('medium')
                ->description(fn (ModelPrice $record): ?string => self::modelOptions()[$record->model] ?? null),
            TextColumn::make('status')->label('状态')->badge()
                ->state(fn (ModelPrice $record): string => in_array($record->id, $current, true) ? '生效中' : '已替代')
                ->color(fn (string $state): string => $state === '生效中' ? 'success' : 'gray'),
            TextColumn::make('input_per_million')->label('输入')->state($money('input_per_million'))->tooltip('缓存未命中的输入，每百万Token'),
            TextColumn::make('cache_read_per_million')->label('缓存命中')->state($money('cache_read_per_million'))->placeholder('同输入'),
            TextColumn::make('cache_write_per_million')->label('缓存写入')->state($money('cache_write_per_million'))->placeholder('同输入'),
            TextColumn::make('output_per_million')->label('输出')->state($money('output_per_million'))->tooltip('含推理，每百万Token'),
            TextColumn::make('rules')->label('计价规则')->state(fn (ModelPrice $record): array => self::rules($record))->listWithLineBreaks()->placeholder('统一价')->wrap(),
            TextColumn::make('verified_on')->label('核实')->date('Y-m-d')
                ->description(fn (ModelPrice $record): string => '生效 '.$record->effective_at->format('Y-m-d H:i')),
            TextColumn::make('source')->label('来源')->formatStateUsing(fn (): string => '查看')->color('primary')->tooltip(fn (ModelPrice $record): string => $record->source)
                ->url(fn (ModelPrice $record): ?string => preg_match('#https?://[^\s，；）)]+#u', $record->source, $match) === 1 ? $match[0] : null)->openUrlInNewTab(),
        ])->filters([
            Filter::make('current')->label('只看当前生效')->toggle()->default()->query(fn (Builder $query): Builder => $query->whereKey(self::currentIds())),
            SelectFilter::make('model')->label('模型')->options(fn (): array => ModelPrice::distinct()->orderBy('model')->pluck('model', 'model')->all()),
        ])->paginated([25, 50, 'all'])
            ->emptyStateHeading('尚未登记单价')->emptyStateDescription('点击右上角“登记单价”。未登记时费用显示为“未知”，不代表免费。');
    }

    /** 登记新单价；不提供修改或删除。选择模型后自动从官网获取单价，作者核对后登记。 */
    protected function getHeaderActions(): array
    {
        $decimal = fn (string $name, string $label): TextInput => TextInput::make($name)->label($label)->regex(self::DECIMAL)->placeholder('-');

        return [Action::make('register')->label('登记单价')->icon(Heroicon::OutlinedPlus)->modalHeading('登记模型单价')->modalWidth('3xl')
            ->modalDescription('选择模型后自动从官网获取单价，请核对后登记。登记后立即对新请求生效，旧单价保留在历史中。')
            ->schema([
                Grid::make(3)->schema([
                    Select::make('model')->label('模型')->options(self::modelOptions())->native(false)->required()->live()->columnSpan(2)
                        ->afterStateUpdated(fn (?string $state, Set $set) => filled($state) ? $this->fillFromOfficial($state, $set) : null)
                        ->suffixAction(Action::make('fetchOfficial')->icon(Heroicon::OutlinedArrowPath)->tooltip('重新从官网获取')
                            ->action(fn (Get $get, Set $set) => filled($get('model')) ? $this->fillFromOfficial((string) $get('model'), $set) : null)),
                    Select::make('currency')->label('币种')->options(['USD' => '美元（USD）', 'CNY' => '人民币（CNY）'])->default('USD')->native(false)->required(),
                ]),
                Section::make('单价（每百万Token）')->description('分时段计价时填高峰价。')->compact()->columns(4)->schema([
                    $decimal('input_per_million', '输入（未命中）')->required(),
                    $decimal('cache_read_per_million', '缓存命中')->helperText('不填按输入价'),
                    $decimal('cache_write_per_million', '缓存写入')->helperText('不填按输入价'),
                    $decimal('output_per_million', '输出（含推理）')->required(),
                ]),
                Section::make('闲时折扣')->description('工作日高峰时段以外（含周末）按倍率计价，如 DeepSeek。')->compact()->columns(2)
                    ->collapsed(fn (Get $get): bool => blank($get('off_peak_ratio')))->schema([
                        $decimal('off_peak_ratio', '闲时倍率')->placeholder('0.5')->requiredWith('peak_hours_utc'),
                        TextInput::make('peak_hours_utc')->label('高峰时段（UTC）')->placeholder('01:00-04:00,06:00-10:00')->requiredWith('off_peak_ratio'),
                    ]),
                Section::make('长上下文')->description('输入超过阈值时整次请求改按以下单价，如 GPT-6.1。')->compact()->columns(5)
                    ->collapsed(fn (Get $get): bool => blank($get('long_context_threshold')))->schema([
                        TextInput::make('long_context_threshold')->label('阈值（输入Token）')->integer()->minValue(1)->placeholder('272000'),
                        $decimal('long_input_per_million', '输入')->requiredWith('long_context_threshold'),
                        $decimal('long_cache_read_per_million', '缓存命中'),
                        $decimal('long_cache_write_per_million', '缓存写入'),
                        $decimal('long_output_per_million', '输出')->requiredWith('long_context_threshold'),
                    ]),
                Grid::make(3)->schema([
                    Textarea::make('source')->label('来源与核实说明')->required()->maxLength(2000)->rows(2)->columnSpan(2)->placeholder('官方价格页地址与说明；自动获取时已填好'),
                    DatePicker::make('verified_on')->label('核实日期')->required()->default(now())->maxDate(now()),
                ]),
            ])->action(function (array $data): void {
                app(ModelPrices::class)->register(Filament::auth()->user(), $data);
                Notification::make()->success()->title('单价已登记')->body('新请求将按此单价估算；历史请求金额不变。')->send();
            })];
    }

    /** 从官网获取单价并填入表单；失败时保留已填内容并提示原因，可改为手工填写。 */
    private function fillFromOfficial(string $model, Set $set): void
    {
        try {
            $result = app(OfficialPriceFetcher::class)->fetch($model);
        } catch (RuntimeException $exception) {
            Notification::make()->warning()->title('未能从官网获取单价')->body($exception->getMessage())->send();

            return;
        }
        foreach ($result['fields'] as $field => $value) {
            $set($field, $value);
        }
        Notification::make()->success()->title('已从官网获取 '.$model.' 的单价')->body(implode('', $result['notes']).'请核对后登记。')->send();
    }
}
