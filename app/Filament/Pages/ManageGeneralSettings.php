<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Novels\Schemas\NovelForm;
use App\Models\ProductionRun;
use App\Services\Ai\ModelProfiles;
use App\Services\Search\ContextRetrieval;
use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** 维护新运行默认参数，供应商由模型名称识别，密钥仅保存在本机环境。 */
class ManageGeneralSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string $settings = GeneralSettings::class;

    protected static ?string $title = '通用设置';

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '配置与运维';

    protected static ?string $navigationLabel = '通用设置';

    /** 展示模型与执行设置；不在页面暴露供应商密钥。 */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('模型参数')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('default_model')
                            ->label('默认模型')
                            ->options(fn (?string $state): array => ModelProfiles::options($state))
                            ->native(false)
                            ->helperText('可选模型来自 config/ai.php 的 models；DeepSeek 模型需在本机配置 DEEPSEEK_API_KEY。')
                            ->required(),
                        TextInput::make('temperature')
                            ->label('采样温度')
                            ->numeric()
                            ->step('any')
                            ->minValue(0)
                            ->required(),
                        TextInput::make('max_tokens_per_chapter')
                            ->label('正文输出 Token 上限默认值')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(8192)
                            ->helperText('“生成第N章”弹窗中“本次正文输出Token上限”的默认值，最大 8192；每次生成时仍可单独修改。')
                            ->required(),
                    ]),
                Section::make('执行限制')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('max_retries_per_chapter')
                            ->label('每章最大重试次数')
                            ->integer()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('max_execution_count')
                            ->label('新书执行次数上限')
                            ->integer()
                            ->minValue(1)
                            ->helperText('新建小说时的初始执行次数上限，之后可在生成弹窗或续写授权中追加；不影响已有小说。')
                            ->required(),
                    ]),
                Section::make('流程开关')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('enable_fine_outline')->label('启用细纲'),
                    ]),
                Section::make('自动续写')
                    ->description('影响之后的“继续自动生成”授权，已开始的运行不变。')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('continuation_max_chapters')->label('单次最多授权章数')->integer()->minValue(1)->maxValue(ProductionRun::MAX_RANGE)->suffix('章')
                            ->helperText('最多 '.ProductionRun::MAX_RANGE.' 章。')->required(),
                        TextInput::make('continuation_executions_per_chapter')->label('每章预留执行次数')->integer()->minValue(1)->maxValue(1000)->suffix('次')
                            ->helperText('授权时按“章数 × 此值”自动追加本书执行次数上限，不会降低已有上限。')->required(),
                    ]),
                Section::make('前文检索')
                    ->description('生成细纲、正文和修订时，从已正式提交的前文中检索相关原文片段放入上下文；只在新运行创建时读取并冻结，不产生模型费用。')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('context_retrieval_enabled')->label('启用前文检索')->inline(false),
                        TextInput::make('context_retrieval_passages')->label('最多片段数')->integer()->minValue(1)->maxValue(ContextRetrieval::MAX_PASSAGES)->suffix('段')->required(),
                        TextInput::make('context_retrieval_per_chapter')->label('同一章最多片段数')->integer()->minValue(1)->suffix('段')
                            ->lte('context_retrieval_passages')->validationMessages(['lte' => '同一章最多片段数不能大于最多片段数。'])->required(),
                    ]),
                Section::make('导出')
                    ->description('只影响之后新建的导出任务；已生成文件按创建时的保留期清理。')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('export_retention_days')->label('导出文件保留天数')->integer()->minValue(1)->maxValue(365)->suffix('天')->required(),
                        TextInput::make('export_failed_retention_days')->label('失败导出保留天数')->integer()->minValue(1)->maxValue(365)->suffix('天')->required(),
                    ]),
                Section::make('新书默认值')
                    ->description('只作为“新建小说”表单的预填值，创建时仍可逐项修改；不影响已有小说。')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('default_target_chapters')->label('目标章数')->integer()->minValue(1)->suffix('章')->required(),
                        TextInput::make('default_min_characters')->label('每章字数下限')->integer()->minValue(1)->suffix('字')->required(),
                        TextInput::make('default_max_characters')->label('每章字数上限')->integer()->minValue(1)->suffix('字')
                            ->gte('default_min_characters')->validationMessages(['gte' => '每章字数上限不得小于下限。'])->required(),
                        Select::make('default_budget_currency')->label('预算币种')->options(NovelForm::CURRENCIES)->native(false)->required(),
                        Select::make('default_production_mode')->label('生产方式')->options(NovelForm::MODES)->native(false)->required(),
                    ]),
            ]);
    }
}
