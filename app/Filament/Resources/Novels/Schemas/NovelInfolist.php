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
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Services\Ai\NovelCostSummary;
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\NovelBudget;
use App\Services\Pipeline\NovelProgressQuery;
use App\Services\Pipeline\SettingSchema;
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
                // 进度卡片：在哪一步、为什么停、下一步做什么；只保留需要关注的标签，有在途任务时每5秒刷新。
                Section::make('当前进度')->compact()->columnSpanFull()
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
                            // 执行次数快用完时生成会停下，提前提醒；平时只在底部记录里显示。
                            TextEntry::make('execution_overview')->hiddenLabel()->badge()->grow(false)
                                ->visible(fn (Novel $record): bool => self::executionsLeft($record) <= self::EXECUTION_WARNING)
                                ->state(fn (Novel $record): string => self::executionsLeft($record) === 0 ? '执行次数已用完（'.$record->execution_count.' / '.$record->max_execution_count.'）' : '执行次数仅剩 '.self::executionsLeft($record).' 次（'.$record->execution_count.' / '.$record->max_execution_count.'）')
                                ->color(fn (Novel $record): string => self::executionsLeft($record) === 0 ? 'danger' : 'warning'),
                            // 请求与费用默认收起，顶部只在需要处理时出现提醒标签。
                            TextEntry::make('budget_overview')->hiddenLabel()->badge()->grow(false)
                                ->visible(fn (Novel $record): bool => in_array(app(NovelBudget::class)->summary($record)['state'], ['exhausted', 'warning'], true))
                                ->state(fn (Novel $record): string => app(NovelBudget::class)->summary($record)['state'] === 'exhausted' ? '预算已用完' : '预算已用超过80%')
                                ->color(fn (Novel $record): string => app(NovelBudget::class)->summary($record)['state'] === 'exhausted' ? 'danger' : 'warning'),
                            TextEntry::make('unknown_cost_overview')->hiddenLabel()->badge()->grow(false)->color('warning')
                                ->visible(fn (Novel $record): bool => app(NovelCostSummary::class)->forNovel($record->id)['unknown_amounts'] > 0)
                                ->state(fn (Novel $record): string => app(NovelCostSummary::class)->forNovel($record->id)['unknown_amounts'].' 次请求金额未知')
                                ->tooltip('未登记单价或尚未结算，不代表免费；点击查看费用明细')
                                ->url(fn (Novel $record): string => NovelResource::getUrl('costs', ['record' => $record, 'tableFilters' => ['billing_status' => ['value' => 'unknown']]])),
                        ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                        TextEntry::make('progress_headline')->hiddenLabel()->weight('bold')->size('lg')->color('primary')->state(fn (Novel $record): string => app(NovelProgressQuery::class)->for($record)['headline']),
                        TextEntry::make('progress_detail')->hiddenLabel()->state(fn (Novel $record): string => app(NovelProgressQuery::class)->for($record)['detail']),
                        TextEntry::make('progress_link')->hiddenLabel()->visible(fn (Novel $record): bool => app(NovelProgressQuery::class)->for($record)['url'] !== null)
                            ->state('打开处理页面 →')->weight('bold')->color('primary')->url(fn (Novel $record): ?string => app(NovelProgressQuery::class)->for($record)['url']),
                        // 步骤条与最近活动合为一行弱化显示；阶段名已由步骤条表达，模拟流程才附百分比。
                        TextEntry::make('progress_steps')->hiddenLabel()->color('gray')->size('sm')
                            ->state(fn (Novel $record): string => app(NovelProgressQuery::class)->stepBar(app(NovelProgressQuery::class)->for($record)['step'])
                                .'　·　'.($record->generation_mode === GenerationMode::SettingReal ? '' : '模拟进度 '.$record->progress.'% · ').'最近活动 '.$record->updated_at->format('Y-m-d H:i')),
                    ]),
                Section::make('近期章节')->compact()->columnSpanFull()->visible(fn (Novel $record): bool => $record->chapters()->exists())
                    ->description('最近 '.self::RECENT_CHAPTERS.' 章；完整列表见左侧“章节目录”。')
                    ->schema([
                        RepeatableEntry::make('recent_chapters')->hiddenLabel()->state(fn (Novel $record): array => self::recentChapters($record))
                            ->table([TableColumn::make('章节'), TableColumn::make('状态'), TableColumn::make('字数'), TableColumn::make('成本'), TableColumn::make('入口')])
                            ->schema([
                                TextEntry::make('heading')->weight('medium'),
                                TextEntry::make('status')->badge()->color(fn (string $state): string => match ($state) {
                                    '已正式提交' => 'success', '候选，未提交' => 'warning', default => 'info',
                                }),
                                TextEntry::make('characters')->color('gray'),
                                TextEntry::make('cost')->color('gray')->tooltip('本章全部模型请求开支：生成、检查、修订、评审与提取；只记录，不限制执行。'),
                                TextEntry::make('url')->url(fn (?string $state): ?string => $state)->color('primary')->formatStateUsing(fn (?string $state): string => str_contains((string) $state, '/reader') ? '阅读' : '去处理'),
                            ]),
                    ]),
                // 作品概要只放写作时最常对照的内容：核心矛盾、主要人物与原始创意；完整设定收进下方。
                Section::make('作品概要')->collapsible()->compact()->columnSpanFull()
                    ->description(fn (Novel $record): ?string => $record->generation_mode === GenerationMode::SettingReal ? null : '当前为模拟流程。模拟完成不代表小说作品已生成。')
                    ->schema([
                        Flex::make([
                            TextEntry::make('genre')->hiddenLabel()->badge()->color('gray')->grow(false)->placeholder('题材未填写'),
                            TextEntry::make('generation_mode')->hiddenLabel()->badge()->color('gray')->grow(false)->formatStateUsing(fn (GenerationMode $state): string => $state->label()),
                        ])->verticallyAlignCenter()->extraAttributes(['style' => 'flex-wrap: wrap']),
                        TextEntry::make('core_conflict_overview')->label('核心矛盾')->weight('bold')
                            ->visible(fn (Novel $record): bool => filled(data_get(self::latestConfirmation($record)?->setting, 'core_conflict')))
                            ->state(fn (Novel $record): ?string => data_get(self::latestConfirmation($record)?->setting, 'core_conflict'))->extraAttributes(['style' => 'line-height: 1.7']),
                        TextEntry::make('characters_overview')->label('主要人物')
                            ->visible(fn (Novel $record): bool => self::characterLine($record) !== null)->state(fn (Novel $record): ?string => self::characterLine($record)),
                        TextEntry::make('premise_raw')->label('原始创意')->color('gray')->extraAttributes(['style' => 'line-height: 1.7']),
                    ]),
                Section::make('完整设定与创作要求')->collapsible()->collapsed()->compact()->columnSpanFull()
                    ->visible(fn (Novel $record): bool => filled($record->creative_brief) || ($record->generation_mode === GenerationMode::SettingReal && self::latestConfirmation($record) !== null))
                    ->description(fn (Novel $record): string => self::settingBrief($record))
                    ->schema([
                        TextEntry::make('creative_brief_text')->label('补充创作要求')->visible(fn (Novel $record): bool => filled($record->creative_brief))
                            ->state(fn (Novel $record): array => collect($record->creative_brief ?? [])->map(fn (string $value, string $key): string => (CreateNovelService::BRIEF_FIELDS[$key] ?? $key).'：'.$value)->values()->all())->listWithLineBreaks()->bulleted(),
                        RepeatableEntry::make('confirmed_versions')->label('确认版本')->hiddenLabel()->contained(false)
                            ->visible(fn (Novel $record): bool => $record->generation_mode === GenerationMode::SettingReal && self::latestConfirmation($record) !== null)
                            ->state(fn (Novel $record): array => array_map(fn ($confirmation): array => [...$confirmation->toArray(), 'meta' => '最新确认设定 · 确认记录 #'.$confirmation->id.' · '.$confirmation->confirmed_at->format('Y-m-d H:i').'。这里保存作者修订与决定；供应商原始候选在下方另行保留。'],
                                array_filter([self::latestConfirmation($record)])))
                            ->schema([
                                TextEntry::make('meta')->hiddenLabel()->color('gray')->size('sm'),
                                ...self::settingFields('setting.'),
                                RepeatableEntry::make('decisions')->label('作者问题决定')->schema([
                                    TextEntry::make('question')->hiddenLabel()->weight('bold'),
                                    TextEntry::make('answer')->hiddenLabel()->placeholder('非阻塞问题尚未决定'),
                                ]),
                            ]),
                    ]),
                Section::make('原始设定候选')->collapsible()->collapsed()->compact()->columnSpanFull()
                    ->visible(fn (Novel $record): bool => $record->generation_mode === GenerationMode::SettingReal && self::latestCandidate($record) !== null)
                    ->description('模型最近一次通过结构校验的设定结果，保留原样。')
                    ->schema([
                        RepeatableEntry::make('setting_candidate')->hiddenLabel()->contained(false)
                            ->state(fn (Novel $record): array => array_filter([self::latestCandidate($record)]))
                            ->schema(self::settingFields()),
                    ]),
                // 请求、费用、预算与执行次数统一收起在页面底部，标题描述给出一行摘要。
                Section::make('请求与费用记录')->collapsible()->collapsed()->compact()->columnSpanFull()
                    ->description(fn (Novel $record): string => app(NovelCostSummary::class)->brief(app(NovelCostSummary::class)->forNovel($record->id)).' · 执行 '.$record->execution_count.' / '.$record->max_execution_count)
                    ->schema([
                        TextEntry::make('budget_summary')->label('预算')->state(fn (Novel $record): string => app(NovelBudget::class)->describe($record))
                            ->color(fn (Novel $record): ?string => match (app(NovelBudget::class)->summary($record)['state']) {
                                'exhausted' => 'danger', 'warning' => 'warning', default => null
                            }),
                        TextEntry::make('cost_summary')->label('全部调用')->state(fn (Novel $record): string => app(NovelCostSummary::class)->describe(app(NovelCostSummary::class)->forNovel($record->id))),
                        TextEntry::make('execution_count')->label('执行次数 / 上限')
                            ->formatStateUsing(fn (Novel $record): string => $record->execution_count.' / '.$record->max_execution_count),
                        RepeatableEntry::make('setting_attempts')->label('设定请求')->visible(fn (Novel $record): bool => $record->generation_mode === GenerationMode::SettingReal)
                            ->state(fn (Novel $record): array => self::attempts($record))->placeholder('任务尚未领取，请稍后刷新。')->schema([
                                TextEntry::make('line')->hiddenLabel(),
                                TextEntry::make('error')->hiddenLabel()->color('danger')->visible(fn (?string $state): bool => filled($state)),
                            ]),
                        TextEntry::make('costs_link')->hiddenLabel()->state('查看费用明细 →')->color('primary')
                            ->url(fn (Novel $record): string => NovelResource::getUrl('costs', ['record' => $record])),
                    ]),
            ]);
    }

    /** 近期章节显示条数。 */
    private const RECENT_CHAPTERS = 5;

    /** 剩余执行次数不超过此值时在顶部提醒。 */
    private const EXECUTION_WARNING = 5;

    /** 剩余执行次数，不小于0。 */
    private static function executionsLeft(Novel $novel): int
    {
        return max(0, (int) $novel->max_execution_count - (int) $novel->execution_count);
    }

    /** 最新作者确认设定；模拟流程或尚未确认时为空。 */
    private static function latestConfirmation(Novel $novel): ?NovelSettingConfirmation
    {
        return $novel->generation_mode === GenerationMode::SettingReal ? $novel->settingConfirmations()->latest('id')->first() : null;
    }

    /** 主要人物压成一行：姓名加身份的第一句。 */
    private static function characterLine(Novel $novel): ?string
    {
        $characters = collect(data_get(self::latestConfirmation($novel)?->setting, 'characters', []))
            ->filter(fn (mixed $character): bool => filled(data_get($character, 'name')))
            ->map(fn (mixed $character): string => data_get($character, 'name').(filled($role = preg_split('/[，,；;。]/u', (string) data_get($character, 'role'))[0] ?? null) ? '（'.$role.'）' : ''));

        return $characters->isEmpty() ? null : $characters->implode('、');
    }

    /** 收起区块的一行摘要，不展开也知道里面有什么。 */
    private static function settingBrief(Novel $novel): string
    {
        $confirmation = self::latestConfirmation($novel);
        $parts = $confirmation === null ? [] : ['确认记录 #'.$confirmation->id.' · '.$confirmation->confirmed_at->format('Y-m-d H:i'),
            count(data_get($confirmation->setting, 'characters', [])).' 个人物', count(data_get($confirmation->setting, 'world.rules', [])).' 条世界规则',
            count(data_get($confirmation->setting, 'preserved_details', [])).' 项保留细节', count($confirmation->decisions ?? []).' 项作者决定'];
        if (filled($novel->creative_brief)) {
            $parts[] = count($novel->creative_brief).' 项补充创作要求';
        }

        return implode(' · ', $parts);
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
                // 扩展人物卡才有以下字段，七项旧卡不显示。
                TextEntry::make('tier')->label('层级')->inlineLabel()->badge()->formatStateUsing(fn (string $state): string => SettingSchema::TIERS[$state] ?? $state)->visible(fn (?string $state): bool => filled($state)),
                ...array_map(fn (string $field, string $label): TextEntry => TextEntry::make($field)->label($label)->inlineLabel()->visible(fn (mixed $state): bool => filled($state)),
                    ['desire', 'fear', 'flaw', 'secret', 'relation_to_protagonist', 'arc'], ['欲望', '恐惧', '毛病', '秘密', '与主角关系', '人物弧线']),
                TextEntry::make('quirks')->label('小习惯')->inlineLabel()->badge()->visible(fn (mixed $state): bool => filled($state)),
                TextEntry::make('voice_samples')->label('代表台词')->listWithLineBreaks()->visible(fn (mixed $state): bool => filled($state)),
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

    /** 最近几章的状态、字数与入口；正式章打开阅读器，未提交章打开正文工作台。
     * @return list<array{heading: string, status: string, characters: string, cost: string, url: string}>
     */
    private static function recentChapters(Novel $novel): array
    {
        $costs = app(NovelCostSummary::class)->forChapters($novel->id);

        return $novel->chapters()->orderByDesc('number')->limit(self::RECENT_CHAPTERS)->get()->map(function (Chapter $chapter) use ($novel, $costs): array {
            $formal = $chapter->formal_version_id !== null;
            $version = ChapterVersion::find($chapter->formal_version_id ?? $chapter->current_candidate_version_id);

            return ['heading' => (new Chapter(['number' => $chapter->number, 'title' => (string) ($version?->title ?? $chapter->title)]))->numberedTitle(), 'status' => $formal ? '已正式提交' : ($version === null ? '生成中' : '候选，未提交'),
                'characters' => $version === null ? '-' : mb_strlen(preg_replace('/\s+/u', '', $version->body)).' 字',
                'cost' => app(NovelCostSummary::class)->cell($costs[$chapter->number] ?? null),
                'url' => $formal ? NovelResource::getUrl('reader', ['record' => $novel, 'chapter' => $chapter->number]) : NovelResource::getUrl('content', ['record' => $novel, 'chapter' => $chapter->number])];
        })->all();
    }

    /** 设定请求压成一行：第几次 · 状态 · 模型 · 耗时 · 费用，编号附在末尾；认证信息和原始供应商响应不进入页面。
     * @return list<array{line: string, error: ?string}>
     */
    private static function attempts(Novel $novel): array
    {
        $logs = CostLog::query()->select(['id', 'novel_stage_id'])->where('novel_id', $novel->id)->orderBy('id')->get()->keyBy('novel_stage_id');

        return $novel->stages()->where('stage', Stage::Setting->value)->orderBy('id')->get()->map(function (NovelStage $stage) use ($logs): array {
            $cost = $stage->cost;
            $logId = data_get($stage->output, 'data.cost_log_id') ?? $logs->get($stage->id)?->id;

            return ['line' => implode(' · ', array_filter([
                '第 '.$stage->execution_count.' 次', $stage->status->label(), data_get($stage->input, 'generation_config.model'),
                $stage->duration_ms === null ? null : round($stage->duration_ms / 1000, 1).' 秒',
                ($cost['billing_status'] ?? 'unknown') === 'estimated' ? '估算 '.$cost['estimated_cost'].' '.$cost['currency'] : '金额未知',
                '#'.$stage->id.($logId === null ? '' : ' / 日志 #'.$logId),
            ])), 'error' => $stage->error_message];
        })->all();
    }

    /** 最近一次通过结构校验的原始设定候选；没有有效结果时返回空。
     * @return array<string, mixed>|null
     */
    private static function latestCandidate(Novel $novel): ?array
    {
        foreach ($novel->stages()->where('stage', Stage::Setting->value)->orderByDesc('id')->get() as $stage) {
            $setting = data_get($stage->output, 'data.setting');
            if (is_array($setting) && $setting !== []) {
                return $setting;
            }
        }

        return null;
    }
}
