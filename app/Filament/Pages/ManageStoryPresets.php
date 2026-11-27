<?php

namespace App\Filament\Pages;

use App\Models\StoryPreset;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\ModelProfiles;
use App\Services\Ai\TextRequestOptions;
use App\Services\Pipeline\StoryProfile;
use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/** 题材包与文风预设：可新建、复制、修改、停用和删除；建书时整份复制进小说，之后的修改不影响已建的书。 */
class ManageStoryPresets extends Page implements HasTable
{
    use InteractsWithTable;

    /** 页面标题。 */
    protected static ?string $title = '题材与文风';

    /** 侧栏图标。 */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '配置与运维';

    /** 分组内排序。 */
    protected static ?int $navigationSort = 0;

    /** 一句说明加列表。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('题材包与文风预设')->description('建书时选择一项，整份复制进那本书。之后在这里修改、停用或删除，都不影响已经建好的书。')->compact()
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    /** 列表：内置在前；停用的项不出现在建书下拉框。 */
    public function table(Table $table): Table
    {
        return $table->query(StoryPreset::query())->defaultSort(fn ($query) => $query->orderBy('kind')->orderByDesc('is_builtin')->orderBy('id'))->columns([
            TextColumn::make('kind')->label('类别')->badge()->formatStateUsing(fn (string $state): string => StoryPreset::KINDS[$state] ?? $state)
                ->color(fn (string $state): string => $state === 'genre' ? 'info' : 'warning'),
            TextColumn::make('name')->label('名称')->weight('medium')->description(fn (StoryPreset $record): ?string => $record->description)->searchable(),
            TextColumn::make('summary')->label('要点')->state(fn (StoryPreset $record): string => $record->kind === 'genre'
                ? '爽点：'.collect($record->content['payoff_types'] ?? [])->map(fn (array $item): string => $item['name'].' '.$item['weight'].'%')->implode('、')
                : StoryProfile::fragment(StoryProfile::normalize('style', $record->content)))->wrap()->lineClamp(3),
            TextColumn::make('status')->label('状态')->badge()->state(fn (StoryPreset $record): string => $record->disabled_at === null ? '启用' : '已停用')
                ->color(fn (string $state): string => $state === '启用' ? 'success' : 'gray'),
            TextColumn::make('origin')->label('来源')->state(fn (StoryPreset $record): string => ($record->is_builtin ? '内置' : '自建').' · 第'.$record->version.'版')->color('gray'),
        ])->filters([
            SelectFilter::make('kind')->label('类别')->options(StoryPreset::KINDS),
        ])->recordActions([
            Action::make('edit')->label('修改')->icon(Heroicon::OutlinedPencilSquare)->modalHeading(fn (StoryPreset $record): string => '修改'.StoryPreset::KINDS[$record->kind].'「'.$record->name.'」')
                ->modalDescription('保存后版本号加一，只影响之后新建的书。')->modalWidth('4xl')
                ->fillForm(fn (StoryPreset $record): array => ['kind' => $record->kind, 'key' => $record->key, 'name' => $record->name, 'description' => $record->description, 'content' => StoryProfile::normalize($record->kind, $record->content)])
                ->schema(fn (StoryPreset $record): array => self::form($record))
                ->action(function (StoryPreset $record, array $data): void {
                    $record->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'content' => StoryProfile::normalize($record->kind, $data['content'] ?? []), 'version' => $record->version + 1]);
                    Notification::make()->success()->title('已保存为第'.$record->version.'版')->send();
                }),
            Action::make('samples')->label('AI 写示范段落')->icon(Heroicon::OutlinedSparkles)->visible(fn (StoryPreset $record): bool => $record->kind === 'style')
                ->modalHeading(fn (StoryPreset $record): string => '让模型为「'.$record->name.'」起草示范段落')
                ->modalDescription('发送 1 次模型请求，按本预设各项写 3 段原创示范，替换现有示范段落，版本号加一。费用记在“费用明细”（不属于任何一本书）。写完可以在“修改”里逐段改。')
                ->schema([Select::make('model')->label('模型')->options(ModelProfiles::options())->native(false)->default(fn (): string => app(GeneralSettings::class)->default_model)->required()])
                ->action(function (StoryPreset $record, array $data): void {
                    try {
                        $samples = self::draftSamples($record, $data['model']);
                    } catch (AiGenerationException $exception) {
                        Notification::make()->danger()->title('模型请求失败')->body($exception->getMessage())->send();

                        return;
                    }
                    if ($samples === []) {
                        Notification::make()->warning()->title('没有拿到可用的段落')->body('现有示范段落未改动，可以重试或手写。')->send();

                        return;
                    }
                    $record->update(['content' => [...StoryProfile::normalize('style', $record->content), 'samples' => $samples], 'version' => $record->version + 1]);
                    Notification::make()->success()->title('已写入 '.count($samples).' 段示范')->body('保存为第'.$record->version.'版，可在“修改”里逐段改。')->send();
                }),
            Action::make('duplicate')->label('复制')->icon(Heroicon::OutlinedDocumentDuplicate)->modalHeading('复制为新的一项')
                ->fillForm(fn (StoryPreset $record): array => ['key' => $record->key.'_copy', 'name' => $record->name.'（副本）'])
                ->schema(fn (StoryPreset $record): array => [self::keyField($record->kind), TextInput::make('name')->label('名称')->required()->maxLength(64)])
                ->action(function (StoryPreset $record, array $data): void {
                    StoryPreset::create(['kind' => $record->kind, 'key' => $data['key'], 'name' => $data['name'], 'description' => $record->description, 'content' => $record->content, 'is_builtin' => false, 'version' => 1]);
                    Notification::make()->success()->title('已复制为「'.$data['name'].'」')->send();
                }),
            Action::make('toggle')->label(fn (StoryPreset $record): string => $record->disabled_at === null ? '停用' : '启用')->requiresConfirmation()
                ->modalDescription(fn (StoryPreset $record): string => $record->disabled_at === null ? '停用后不出现在建书下拉框，已经用了它的书不受影响。' : '启用后重新出现在建书下拉框。')
                ->action(fn (StoryPreset $record) => $record->update(['disabled_at' => $record->disabled_at === null ? now() : null])),
            Action::make('delete')->label('删除')->color('danger')->icon(Heroicon::OutlinedTrash)->visible(fn (StoryPreset $record): bool => ! $record->is_builtin)->requiresConfirmation()
                ->modalDescription('删除后不能恢复。已经用了它的书保留各自的副本，不受影响。内置项不能删除，只能停用。')
                ->action(fn (StoryPreset $record) => $record->delete()),
        ])->paginated(false)->emptyStateHeading('还没有题材包或文风预设');
    }

    /** 新建一项。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('新建')->icon(Heroicon::OutlinedPlus)->modalHeading('新建题材包或文风预设')->modalWidth('4xl')
            ->schema(fn (): array => self::form(null))
            ->action(function (array $data): void {
                StoryPreset::create(['kind' => $data['kind'], 'key' => $data['key'], 'name' => $data['name'], 'description' => $data['description'] ?? null,
                    'content' => StoryProfile::normalize($data['kind'], $data['content'] ?? []), 'is_builtin' => false, 'version' => 1]);
                Notification::make()->success()->title('已新建「'.$data['name'].'」')->send();
            })];
    }

    /** 编辑表单：类别决定内容字段；修改时类别与标识固定。
     * @return list<mixed>
     */
    private static function form(?StoryPreset $record): array
    {
        $kind = fn (Get $get): ?string => $record?->kind ?? $get('kind');

        return [
            Grid::make(3)->schema([
                Select::make('kind')->label('类别')->options(StoryPreset::KINDS)->native(false)->required()->live()->disabled($record !== null)->dehydrated($record === null),
                $record === null ? self::keyField(null) : TextInput::make('key')->label('标识')->disabled()->dehydrated(false),
                TextInput::make('name')->label('名称')->required()->maxLength(64),
            ]),
            TextInput::make('description')->label('一句话说明')->maxLength(255),
            Section::make('题材包内容')->compact()->visible(fn (Get $get): bool => $kind($get) === 'genre')->schema([
                Textarea::make('content.core_promise')->label('给读者的核心承诺')->rows(2)->required()->helperText('读者为什么追这本书。正文每章都要让读者感到它在兑现或蓄力。'),
                Repeater::make('content.payoff_types')->label('爽点类型与权重')->schema([
                    TextInput::make('name')->label('爽点')->required()->maxLength(32),
                    TextInput::make('weight')->label('权重（%）')->integer()->minValue(0)->maxValue(100)->required(),
                ])->columns(2)->defaultItems(0)->reorderable(false),
                TextInput::make('content.beat_template')->label('卷级节拍')->maxLength(255)->placeholder('受压 → 反击小胜 → 更大的麻烦 → 借势翻盘 → 新台阶'),
                Textarea::make('content.world_rules_hints')->label('世界观要点')->rows(2),
                TagsInput::make('content.archetypes')->label('常见人物原型'),
                TagsInput::make('content.taboos')->label('题材禁忌')->helperText('会写进正文要求，例如“主角受辱后长期不反击”。'),
                TagsInput::make('content.opening_checklist')->label('开篇必备交代')->helperText('前三章必须让读者知道的背景，例如“前世的结局和最大的遗憾”。设定阶段会据此列出本书的开篇交代清单。'),
                Select::make('content.default_style')->label('推荐文风')->options(fn (): array => StoryPreset::options('style'))->native(false)->placeholder('不推荐'),
            ]),
            Section::make('文风内容')->compact()->visible(fn (Get $get): bool => $kind($get) === 'style')->columns(2)->schema([
                TextInput::make('content.narrator_attitude')->label('叙述口吻')->maxLength(255),
                TextInput::make('content.sentence_rhythm')->label('句子节奏')->maxLength(255),
                TextInput::make('content.dialogue_share')->label('对白占比')->maxLength(32)->placeholder('35%–55%'),
                Select::make('content.humor_level')->label('幽默程度')->options(StoryProfile::HUMOR_LEVELS)->native(false)->default('none'),
                TagsInput::make('content.humor_devices')->label('幽默手法'),
                TextInput::make('content.emotion_expression')->label('情绪表达')->maxLength(255),
                TextInput::make('content.vocabulary')->label('用词')->maxLength(255),
                TagsInput::make('content.banned_words')->label('禁用词'),
                Repeater::make('content.samples')->label('示范段落')->simple(Textarea::make('text')->rows(2)->required()->maxLength(500))->defaultItems(0)->columnSpanFull()
                    ->helperText('2–3 段原创示范，模型只学语气节奏。可以点列表里的“AI 写示范段落”让模型起草。'),
                Textarea::make('content.draft_fragment')->label('写法要求（可选，手写覆盖）')->rows(3)->columnSpanFull()
                    ->helperText('留空时按上面各项自动生成，写进正文要求。填写后以这里为准。'),
            ]),
        ];
    }

    /** 标识：小写字母开头，同类内唯一。 */
    private static function keyField(?string $kind): TextInput
    {
        return TextInput::make('key')->label('标识')->required()->maxLength(64)->regex('/\A[a-z][a-z0-9_]{1,63}\z/')
            ->unique(StoryPreset::class, 'key', modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('kind', $kind ?? $get('kind')))
            ->validationMessages(['regex' => '标识用小写字母开头，只含小写字母、数字和下划线。', 'unique' => '同一类别里已有这个标识。']);
    }

    /** 让模型按预设起草三段原创示范；按空行或换行切段，去掉编号与引号外的说明。
     * @return list<string>
     */
    private static function draftSamples(StoryPreset $record, string $model): array
    {
        $style = StoryProfile::normalize('style', $record->content);
        $prompt = '为网络小说的一种文风写3段原创示范段落。文风名称：'.$record->name.'。要求：'.StoryProfile::fragment($style)
            .'每段60到120字，三段分别是：一段叙述、一段带一句对白的冲突场面、一段人物情绪外露的场面。人物和情节自拟，不要使用任何已出版作品的人物、情节或原句。'
            .'只输出三段正文，每段一行，段与段之间空一行，不加编号、标题或解释。';
        $log = app(AiTextGenerationService::class)->generate($prompt, 'real', $model, promptVersion: 'style-samples-v1', options: TextRequestOptions::styleSamples());
        $lines = preg_split('/\R+/u', trim((string) $log->response_text)) ?: [];

        return array_values(array_slice(array_filter(array_map(fn (string $line): string => trim(preg_replace('/^\s*(?:\d+[.、．)]|[-*•])\s*/u', '', $line)), $lines),
            fn (string $line): bool => mb_strlen($line) >= 20), 0, 3));
    }
}
