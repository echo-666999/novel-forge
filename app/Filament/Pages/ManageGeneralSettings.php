<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageGeneralSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string $settings = GeneralSettings::class;

    protected static ?string $title = '通用设置';

    protected static ?string $navigationLabel = '通用设置';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('模型参数')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('default_model')
                            ->label('默认模型')
                            ->required(),
                        TextInput::make('temperature')
                            ->label('采样温度')
                            ->numeric()
                            ->step('any')
                            ->minValue(0)
                            ->required(),
                        TextInput::make('max_tokens_per_chapter')
                            ->label('每章最大 Token 数')
                            ->integer()
                            ->minValue(1)
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
                            ->label('最大执行次数')
                            ->integer()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('ai_repair_max_attempts')
                            ->label('AI 修复最大尝试次数')
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ]),
                Section::make('流程开关')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('enable_fine_outline')->label('启用细纲'),
                        Toggle::make('enable_volume_checkpoint')->label('启用分卷检查点'),
                    ]),
            ]);
    }
}
