<?php

namespace App\Filament\Resources\Novels\Schemas;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Ai\NovelCostSummary;
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\NovelBudget;
use App\Services\Pipeline\NovelProgressQuery;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** 只读呈现作者原文与当前运行快照，不提供阶段恢复或正文编辑。 */
class NovelInfolist
{
    /** 原始创意完整显示，作者内容按默认文本转义，不作为 HTML 执行。 */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // 进度卡片：在哪一步、为什么停、下一步做什么；有在途任务时每5秒刷新。
                Section::make('当前进度')->columnSpanFull()
                    ->extraAttributes(fn (Novel $record): array => app(NovelProgressQuery::class)->for($record)['running'] ? ['wire:poll.5s' => ''] : [])
                    ->schema([
                        Flex::make([
                            TextEntry::make('status')->hiddenLabel()->badge()->grow(false)
                                ->formatStateUsing(fn (NovelStatus $state, Novel $record): string => NovelResource::statusLabel($state, $record))
                                ->color(fn (NovelStatus $state): string => NovelResource::statusColor($state)),
                            TextEntry::make('chapters_overview')->hiddenLabel()->badge()->grow(false)->color('primary')
                                ->state(fn (Novel $record): string => '已完成 '.$record->chapters_done.' / '.$record->target_chapters.' 章'),
                            TextEntry::make('length_overview')->hiddenLabel()->badge()->grow(false)->color('gray')
                                ->state(fn (Novel $record): string => filled($record->chapter_length_policy['min_characters'] ?? null) ? '每章 '.$record->chapter_length_policy['min_characters'].'～'.$record->chapter_length_policy['max_characters'].' 字' : '每章字数未设置'),
                            TextEntry::make('execution_overview')->hiddenLabel()->badge()->grow(false)->color('gray')
                                ->state(fn (Novel $record): string => '执行 '.$record->execution_count.' / '.$record->max_execution_count),
                            TextEntry::make('budget_overview')->hiddenLabel()->badge()->grow(false)
                                ->state(fn (Novel $record): string => match (app(NovelBudget::class)->summary($record)['state']) {
                                    'exhausted' => '预算已用完', 'warning' => '预算已用超过80%', default => app(NovelBudget::class)->summary($record)['budget'] === null ? '未设预算' : '预算正常',
                                })
                                ->color(fn (Novel $record): string => match (app(NovelBudget::class)->summary($record)['state']) {
                                    'exhausted' => 'danger', 'warning' => 'warning', default => 'gray',
                                }),
                        ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                        TextEntry::make('progress_headline')->hiddenLabel()->weight('bold')->size('lg')->state(fn (Novel $record): string => app(NovelProgressQuery::class)->for($record)['headline']),
                        TextEntry::make('progress_detail')->hiddenLabel()->state(fn (Novel $record): string => app(NovelProgressQuery::class)->for($record)['detail']),
                        TextEntry::make('progress_link')->hiddenLabel()->visible(fn (Novel $record): bool => app(NovelProgressQuery::class)->for($record)['url'] !== null)
                            ->state('打开处理页面 →')->weight('bold')->color('primary')->url(fn (Novel $record): ?string => app(NovelProgressQuery::class)->for($record)['url']),
                        TextEntry::make('progress_steps')->hiddenLabel()->color('gray')->size('sm')->state(fn (Novel $record): string => app(NovelProgressQuery::class)->stepBar(app(NovelProgressQuery::class)->for($record)['step'])),
                        TextEntry::make('activity')->hiddenLabel()->color('gray')->size('sm')
                            ->state(fn (Novel $record): string => '当前阶段：'.($record->current_stage?->label() ?? '尚未开始').' · '.($record->generation_mode === GenerationMode::SettingReal ? '流水线进度 ' : '模拟进度 ').$record->progress.'% · 创建于 '.$record->created_at->format('Y-m-d H:i').' · 最近活动 '.$record->updated_at->format('Y-m-d H:i')),
                    ]),
                Section::make('近期章节')->columnSpanFull()->visible(fn (Novel $record): bool => $record->chapters()->exists())
                    ->description('显示最近10章；完整列表见左侧“章节目录”。')
                    ->schema([
                        RepeatableEntry::make('recent_chapters')->hiddenLabel()->state(fn (Novel $record): array => self::recentChapters($record))
                            ->table([TableColumn::make('章节'), TableColumn::make('状态'), TableColumn::make('字数'), TableColumn::make('入口')])
                            ->schema([
                                TextEntry::make('heading')->weight('medium'),
                                TextEntry::make('status')->badge()->color(fn (string $state): string => match ($state) {
                                    '已正式提交' => 'success', '候选，未提交' => 'warning', default => 'info',
                                }),
                                TextEntry::make('characters')->color('gray'),
                                TextEntry::make('url')->url(fn (?string $state): ?string => $state)->color('primary')->formatStateUsing(fn (?string $state): string => str_contains((string) $state, '/reader') ? '阅读' : '去处理'),
                            ]),
                    ]),
                Section::make('创作信息')->collapsible()->compact()->columnSpanFull()
                    ->description(fn (Novel $record): string => $record->generation_mode === GenerationMode::SettingReal ? '原始生成结果始终保留为候选；作者确认另存版本，只有显式启动才生成大纲。' : '当前为模拟流程。模拟完成不代表小说作品已生成。')
                    ->schema([
                        Flex::make([
                            TextEntry::make('title')->hiddenLabel()->weight('bold')->size('lg')->grow(false),
                            TextEntry::make('genre')->hiddenLabel()->badge()->color('gray')->grow(false)->placeholder('题材未填写'),
                            TextEntry::make('generation_mode')->hiddenLabel()->badge()->color('gray')->grow(false)->formatStateUsing(fn (GenerationMode $state): string => $state->label()),
                        ])->verticallyAlignCenter()->extraAttributes(['style' => 'flex-wrap: wrap']),
                        TextEntry::make('premise_raw')->label('原始创意')->extraAttributes(['style' => 'line-height: 1.8']),
                        TextEntry::make('creative_brief_text')->label('补充创作要求')->visible(fn (Novel $record): bool => filled($record->creative_brief))
                            ->state(fn (Novel $record): array => collect($record->creative_brief ?? [])->map(fn (string $value, string $key): string => (CreateNovelService::BRIEF_FIELDS[$key] ?? $key).'：'.$value)->values()->all())->listWithLineBreaks()->bulleted(),
                    ]),
                // 后台以维护小说内容为主：费用与执行次数默认收起；预算接近或已经用尽时自动展开提醒。
                Section::make('预算、费用与执行次数')->collapsible()->compact()
                    ->collapsed(fn (Novel $record): bool => ! in_array(app(NovelBudget::class)->summary($record)['state'], ['exhausted', 'warning'], true))
                    ->description('设了预算时，每次请求前按单价上限预留，余额不足即在发送前停下；未设预算时只受执行次数上限约束。金额未知不等于免费。')->columnSpanFull()->schema([
                        TextEntry::make('budget_summary')->label('预算')->state(fn (Novel $record): string => app(NovelBudget::class)->describe($record))
                            ->color(fn (Novel $record): ?string => match (app(NovelBudget::class)->summary($record)['state']) {
                                'exhausted' => 'danger', 'warning' => 'warning', default => null
                            }),
                        TextEntry::make('cost_summary')->label('全部调用')->state(fn (Novel $record): string => app(NovelCostSummary::class)->describe(app(NovelCostSummary::class)->forNovel($record->id))),
                        TextEntry::make('execution_count')->label('执行次数 / 上限')
                            ->formatStateUsing(fn (Novel $record): string => $record->execution_count.' / '.$record->max_execution_count),
                    ]),
                Section::make('最新确认设定版本')->collapsible()->compact()->columnSpanFull()
                    ->visible(fn (Novel $record): bool => $record->generation_mode === GenerationMode::SettingReal && $record->settingConfirmations()->exists())
                    ->description('这里保存作者修订与决定；下方供应商原始候选保留原样。确认不等于已经发送大纲请求。')
                    ->schema([
                        RepeatableEntry::make('confirmed_versions')->label('确认版本')->hiddenLabel()->contained(false)
                            ->state(fn (Novel $record): array => $record->settingConfirmations()->latest('id')->limit(1)->get()
                                ->map(fn ($confirmation): array => [...$confirmation->toArray(), 'meta' => '确认记录 #'.$confirmation->id.' · 用户 #'.$confirmation->confirmed_by.' · '.$confirmation->confirmed_at->format('Y-m-d H:i')])->all())
                            ->schema([
                                TextEntry::make('meta')->hiddenLabel()->color('gray')->size('sm'),
                                ...self::settingFields('setting.'),
                                RepeatableEntry::make('decisions')->label('作者问题决定')->schema([
                                    TextEntry::make('question')->hiddenLabel()->weight('bold'),
                                    TextEntry::make('answer')->hiddenLabel()->placeholder('非阻塞问题尚未决定'),
                                ]),
                            ]),
                    ]),
                Section::make('设定执行记录')->collapsible()->collapsed()->compact()->columnSpanFull()
                    ->visible(fn (Novel $record): bool => $record->generation_mode === GenerationMode::SettingReal)
                    ->description('保留每次尝试。只有结构校验通过的结果才显示为设定候选，未知费用不代表免费。')
                    ->schema([
                        RepeatableEntry::make('setting_attempts')->label('设定尝试')->hiddenLabel()->state(fn (Novel $record): array => self::attempts($record))
                            ->placeholder('任务尚未领取，请稍后刷新。')->columns(3)->schema([
                                TextEntry::make('id')->label('阶段编号'),
                                TextEntry::make('attempt')->label('尝试次数'),
                                TextEntry::make('status')->label('执行状态'),
                                TextEntry::make('model')->label('请求模型'),
                                TextEntry::make('duration')->label('耗时'),
                                TextEntry::make('cost')->label('费用'),
                                TextEntry::make('cost_log_id')->label('调用日志编号')->placeholder('尚无调用记录'),
                                TextEntry::make('error')->label('失败说明')->placeholder('无')->columnSpanFull(),
                                ...self::settingFields(),
                            ]),
                    ]),
            ]);
    }

    /** 原始候选与作者确认版本复用中文只读展示，文本始终按默认方式转义。
     * @return list<Component>
     */
    private static function settingFields(string $prefix = ''): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 4])->schema([
                TextEntry::make($prefix.'world.era')->label('时代')->placeholder('尚无有效候选'),
                TextEntry::make($prefix.'tone.narrative_style')->label('文风'),
                TextEntry::make($prefix.'tone.pov')->label('叙事视角'),
                TextEntry::make($prefix.'tone.pacing')->label('节奏'),
            ]),
            TextEntry::make($prefix.'core_conflict')->label('核心矛盾')->weight('bold'),
            TextEntry::make($prefix.'world.background')->label('世界背景')->placeholder('尚无有效候选'),
            TextEntry::make($prefix.'world.rules')->label('世界规则')->listWithLineBreaks()->bulleted(),
            RepeatableEntry::make($prefix.'characters')->label('人物卡')->grid(['default' => 1, 'lg' => 2])->schema([
                TextEntry::make('name')->hiddenLabel()->weight('bold')->size('lg'),
                TextEntry::make('role')->hiddenLabel()->color('gray'),
                TextEntry::make('goal')->label('目标')->inlineLabel(),
                TextEntry::make('personality')->label('性格')->inlineLabel(),
                TextEntry::make('speech_style')->label('语言习惯')->inlineLabel(),
                TextEntry::make('abilities')->label('能力')->inlineLabel(),
                TextEntry::make('limitations')->label('限制')->inlineLabel(),
            ]),
            TextEntry::make($prefix.'preserved_details')->label('保留的创意细节')->listWithLineBreaks()->bulleted(),
            RepeatableEntry::make($prefix.'open_questions')->label('待作者确认')->placeholder('尚无待确认问题记录')->schema([
                Flex::make([
                    TextEntry::make('question')->hiddenLabel()->weight('bold'),
                    TextEntry::make('blocking')->hiddenLabel()->badge()->grow(false)->formatStateUsing(fn (bool $state): string => $state ? '阻塞后续规划' : '不阻塞')
                        ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
                ])->verticallyAlignCenter(),
                TextEntry::make('impact')->hiddenLabel()->color('gray'),
            ]),
        ];
    }

    /** 最近10章的状态、字数与入口；正式章打开阅读器，未提交章打开正文工作台。
     * @return list<array{heading: string, status: string, characters: string, url: string}>
     */
    private static function recentChapters(Novel $novel): array
    {
        return $novel->chapters()->orderByDesc('number')->limit(10)->get()->map(function (Chapter $chapter) use ($novel): array {
            $formal = $chapter->formal_version_id !== null;
            $version = ChapterVersion::find($chapter->formal_version_id ?? $chapter->current_candidate_version_id);

            return ['heading' => (new Chapter(['number' => $chapter->number, 'title' => (string) ($version?->title ?? $chapter->title)]))->numberedTitle(), 'status' => $formal ? '已正式提交' : ($version === null ? '生成中' : '候选，未提交'),
                'characters' => $version === null ? '-' : mb_strlen(preg_replace('/\s+/u', '', $version->body)).' 字',
                'url' => $formal ? NovelResource::getUrl('reader', ['record' => $novel, 'chapter' => $chapter->number]) : NovelResource::getUrl('content', ['record' => $novel, 'chapter' => $chapter->number])];
        })->all();
    }

    /** 一次性读取有序尝试并投影可展示字段，认证信息和原始供应商响应不进入页面。
     * @return list<array<string, mixed>>
     */
    private static function attempts(Novel $novel): array
    {
        $logs = CostLog::query()->select(['id', 'novel_stage_id'])->where('novel_id', $novel->id)->orderBy('id')->get()->keyBy('novel_stage_id');

        return $novel->stages()->where('stage', Stage::Setting->value)->orderBy('id')->get()->map(function (NovelStage $stage) use ($logs): array {
            $setting = data_get($stage->output, 'data.setting', []);
            $cost = $stage->cost;

            return [
                ...$setting, 'id' => $stage->id, 'attempt' => $stage->execution_count, 'status' => $stage->status->label(),
                'model' => data_get($stage->input, 'generation_config.model'),
                'duration' => $stage->duration_ms === null ? '未知' : $stage->duration_ms.'毫秒',
                'cost' => ($cost['billing_status'] ?? 'unknown') === 'estimated' ? '估算 '.$cost['estimated_cost'].' '.$cost['currency'] : '未知（请核查调用记录）',
                'cost_log_id' => data_get($stage->output, 'data.cost_log_id') ?? $logs->get($stage->id)?->id, 'error' => $stage->error_message,
            ];
        })->all();
    }
}
