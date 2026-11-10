<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Services\Ai\CostForecast;
use App\Services\Ai\ModelProfiles;
use App\Services\Pipeline\CreateNovelService;
use App\Settings\GeneralSettings;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;

/** 建书向导：创意输入 → 篇幅 → 生产方式 → 确认启动。运行字段不进入作者输入，模拟流程只保留给命令行自检。 */
class NovelForm
{
    /** 补充创作要求的填写提示。 */
    private const BRIEF_HINTS = [
        'target_readers' => '例如：喜欢现实题材、节奏偏慢的成年读者', 'selling_points' => '例如：小镇修复手艺与人情冲突',
        'forbidden_content' => '例如：不写穿越、不写重生、不出现血腥描写', 'style' => '例如：克制、生活化，对白多于旁白',
        'ending_preference' => '例如：开放式结局，但主线冲突必须解决',
    ];

    /** 三种生产方式；“逐章确认”是默认推荐。 */
    public const MODES = [
        'manual' => '逐章确认（推荐）：先生成设定，等你确认后再生成大纲，之后每一章都由你批准提交',
        'auto_first' => '自动生成并正式提交第1章：自动决定设定问题、采纳通过校验的新实体',
        'auto_ten' => '自动生成并正式提交前10章：同上，目标章数至少10章',
    ];

    /** 向导的四个步骤。
     * @return list<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make('创意输入')->description('原文完整保存')->schema([
                TextInput::make('title')->label('书名')->required()->maxLength(255)->regex('/(*UCP)\S/u')
                    ->validationMessages(['required' => '请输入书名。', 'max' => '书名不得超过255个字符。', 'regex' => '书名不能只包含空白。']),
                TextInput::make('genre')->label('题材')->maxLength(255)->placeholder('例如：玄幻、都市、悬疑'),
                Textarea::make('premise_raw')->label('原始创意')->required()->rows(10)
                    ->placeholder('写下故事背景、人物目标和主要矛盾，保留你希望实现的具体细节。')
                    ->helperText('原始创意会完整保存，不做摘要或改写。')->regex('/(*UCP)\S/u')
                    ->validationMessages(['required' => '请输入原始创意。', 'regex' => '原始创意不能只包含空白。']),
                Section::make('补充创作要求（可选）')->description('填写后会和原始创意一起冻结进设定、大纲和正文的输入，作为约束交给模型。')->collapsible()->collapsed()->columns(2)
                    ->schema(collect(CreateNovelService::BRIEF_FIELDS)->map(fn (string $label, string $key): Textarea => Textarea::make('creative_brief.'.$key)->label($label)->rows(2)->maxLength(2000)
                        ->placeholder(self::BRIEF_HINTS[$key]))->values()->all()),
            ]),
            Step::make('篇幅')->columns(3)->schema([
                self::integerField('target_chapters', '目标章数', 10)->suffix('章'),
                self::integerField('chapter_length_policy.min_characters', '每章字数下限', 2500)->suffix('字'),
                self::integerField('chapter_length_policy.max_characters', '每章字数上限', 3500)->suffix('字')
                    ->gte('chapter_length_policy.min_characters'),
                TextInput::make('budget_amount')->label('本书金额预算（可选）')->regex('/\A\d{1,12}(?:\.\d{1,8})?\z/')->live(onBlur: true)
                    ->helperText('设了预算后，每次请求前按单价上限预留，余额不足时在发送前停下。需要先在“模型单价”登记所用模型的单价。')->columnSpan(2),
                Select::make('budget_currency')->label('币种')->options(['CNY' => '人民币（CNY）', 'USD' => '美元（USD）'])->default('CNY')->native(false)->requiredWith('budget_amount'),
            ]),
            Step::make('生产方式')->schema([
                Radio::make('production_mode')->label('生产方式')->options(self::MODES)->default('manual')->required()->live(),
                Select::make('automatic_model')->label('模型（可选）')->options(ModelProfiles::options())->native(false)
                    ->placeholder(fn (): string => '使用通用设置的默认模型：'.app(GeneralSettings::class)->default_model)
                    ->helperText('只对这本书生效，不修改全局设置。'),
            ]),
            Step::make('确认启动')->schema([
                TextEntry::make('summary')->label('即将启动')->state(fn (Get $get): array => self::summary($get))->listWithLineBreaks(),
                TextEntry::make('cost_notice')->hiddenLabel()->state('启动后会立即调用模型并产生费用。当前未配置模型单价，金额会显示为“未知”，不代表免费。所有请求和失败都会记录在“费用明细”。'),
            ]),
        ];
    }

    /** 启动前的冻结配置摘要与预计请求次数。
     * @return list<string>
     */
    public static function summary(Get $get): array
    {
        $chapters = (int) $get('target_chapters');
        $mode = $get('production_mode') ?? 'manual';
        $model = $get('automatic_model') ?: app(GeneralSettings::class)->default_model;
        $batches = max(1, (int) ceil($chapters / 10));
        $lines = [
            '书名：'.($get('title') ?: '（未填写）'),
            '篇幅：'.$chapters.'章，每章'.$get('chapter_length_policy.min_characters').'～'.$get('chapter_length_policy.max_characters').'字',
            '生产方式：'.(self::MODES[$mode] ?? $mode),
            '模型：'.$model,
            '预算：'.(filled($get('budget_amount')) ? $get('budget_amount').' '.$get('budget_currency') : '未设（只受执行次数上限约束）'),
        ];
        $lines[] = match ($mode) {
            'auto_first' => '预计请求：设定、设定决策约2次，大纲'.$batches.'批，第1章约5～7次；失败会自动恢复一次，再失败则停下等你处理。',
            'auto_ten' => '预计请求：设定、设定决策约2次，大纲'.$batches.'批，前10章每章约5～7次（合计约50～70次）；失败会自动恢复一次，再失败则停下等你处理。',
            default => '本次只发送1次设定请求；之后每一步都由你在工作台确认再启动。',
        };
        $requests = match ($mode) {
            'auto_first' => [2 + $batches + 5, 2 + $batches + 7],
            'auto_ten' => [2 + $batches + 50, 2 + $batches + 70],
            default => [1, 1],
        };
        $lines[] = '预计费用：'.app(CostForecast::class)->range($model, $requests[0], $requests[1], $mode === 'manual' ? 4096 : 8192)['text'];

        return $lines;
    }

    /** 统一正整数边界与中文反馈，JSON 内的数值在应用服务中转换为整数。 */
    private static function integerField(string $name, string $label, int $default): TextInput
    {
        return TextInput::make($name)->label($label)->numeric()->integer()->required()
            ->minValue(1)->maxValue(2147483647)->default($default)
            ->validationMessages([
                'required' => '请填写:attribute。', 'integer' => ':attribute必须为整数。',
                'min' => ':attribute必须大于0。', 'max' => ':attribute超出允许范围。',
                'gte' => '每章字数上限不得小于下限。',
            ]);
    }
}
