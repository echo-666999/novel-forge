<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Services\Pipeline\ForeshadowLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnitEnum;

/** 伏笔计划与正式证据维护入口，状态只能经追加证据推进。 */
class ViewForeshadows extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 复用小说私有后台权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '伏笔账本';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 8;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '人物与世界';

    /** 中文页面标题。 */
    protected static ?string $title = '伏笔账本';

    /** 解析当前小说并验证查看权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 到期提示基于下一章，计划不会被当作已经发生的事实。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('计划与正式事实')->description('先登记计划。带回收窗口的伏笔由正式提交时的正文自动流转（埋设、强化、回收），必回收伏笔在窗口末章未回收时会阻塞提交；旧伏笔仍可依据正式正文手工登记。已有未提交章节时暂停维护，避免改变冻结输入。')->schema([
            TextEntry::make('due')->label('下一章提醒')->state(function (): string {
                $number = $this->getRecord()->chapters_done + 1;
                $count = Foreshadow::where('novel_id', $this->getRecord()->id)->where('status', '!=', 'resolved')->where('target_chapter', '<=', $number)->count();

                return '第'.$number.'章：'.$count.'条伏笔到期或逾期，未回收项将持续注入后续上下文。';
            }),
            RepeatableEntry::make('readiness')->label('回收准备检查')->state(fn (): array => array_map(fn (array $row): array => [...$row, 'must' => $row['must'] ? '是' : '否',
                'last' => $row['last'] === null ? '尚无' : '第'.$row['last'].'章', 'warnings' => $row['warnings'] === [] ? '无' : implode('；', $row['warnings'])], app(ForeshadowLedger::class)->readiness($this->getRecord())))
                ->placeholder('尚无伏笔')
                ->table([TableColumn::make('伏笔'), TableColumn::make('状态'), TableColumn::make('回收窗口'), TableColumn::make('必回收'), TableColumn::make('强化次数'), TableColumn::make('最近证据'), TableColumn::make('距窗口'), TableColumn::make('提醒')])
                ->schema([TextEntry::make('title'), TextEntry::make('status'), TextEntry::make('window'), TextEntry::make('must'), TextEntry::make('reinforced'), TextEntry::make('last'),
                    TextEntry::make('remaining')->suffix('章'), TextEntry::make('warnings')]),
        ]), EmbeddedTable::make()]);
    }

    /** 账本严格按小说隔离，证据弹窗提供正式章节及原文。 */
    public function table(Table $table): Table
    {
        return $table->query(Foreshadow::where('novel_id', $this->getRecord()->id))->defaultSort('id')->columns([
            TextColumn::make('title')->label('伏笔')->searchable(),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn (string $state, Foreshadow $record): string => Foreshadow::statusLabel($record->displayStatus($this->getRecord()->chapters_done))),
            TextColumn::make('plant_chapter')->label('计划埋设章'),
            TextColumn::make('target_chapter')->label('回收窗口')->formatStateUsing(fn (Foreshadow $record): string => $record->hasWindow() ? '第'.$record->payoff_from.'～'.$record->payoff_to.'章' : '最迟第'.$record->target_chapter.'章'),
            TextColumn::make('must_payoff')->label('必回收')->formatStateUsing(fn (bool $state): string => $state ? '是' : '否'),
            TextColumn::make('revision')->label('证据条数'),
        ])->recordActions([
            Action::make('inspectEvidence')->label('查看证据')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->schema(fn (Foreshadow $record): array => [
                TextEntry::make('description')->label('计划说明')->state($record->description),
                TextEntry::make('contract')->label('长程契约')->visible($record->hasWindow())->listWithLineBreaks()->state(array_values(array_filter([
                    $record->surface_clue ? '表面线索：'.$record->surface_clue : null, $record->hidden_meaning ? '隐藏含义：'.$record->hidden_meaning : null,
                    $record->payoff_effect ? '回收作用：'.$record->payoff_effect : null, $record->knowledge_constraint ? '知情约束：'.$record->knowledge_constraint : null,
                    $record->planned_before_generation ? '在第1章生成前登记' : '在生成开始后登记',
                ]))),
                ...$record->evidence->map(fn (ForeshadowEvidence $entry): Section => Section::make('第'.$entry->chapter_number.'章 · '.Foreshadow::statusLabel($entry->to_status))->schema([
                    TextEntry::make('quote'.$entry->id)->label('正式原文（第'.$entry->evidence['paragraph'].'段）')->state($entry->evidence['quote']),
                    TextEntry::make('reason'.$entry->id)->label($entry->source === 'commit_extraction' ? '自动流转说明（正式提交时）' : '登记理由')->state($entry->reason),
                    TextEntry::make('source'.$entry->id)->label('正式来源')->state('正文记录 #'.$entry->version_id.' / 提交 '.$entry->commit_id)->url(NovelResource::getUrl('state', ['record' => $record->novel_id, 'chapter' => $entry->chapter_number])),
                ]))->all(),
            ]),
            Action::make('recordEvidence')->label('登记进展')->visible(fn (Foreshadow $record): bool => $record->status !== 'resolved')->schema(fn (Foreshadow $record): array => [
                Hidden::make('revision')->default($record->revision), Hidden::make('key')->default(fn (): string => (string) Str::uuid7()),
                Select::make('status')->label('新状态')->options($record->status === 'planned' ? ['planted' => '已埋设'] : ($record->must_payoff ? ['reinforced' => '已强化'] : ['reinforced' => '已强化', 'resolved' => '已回收']))->required()
                    ->helperText($record->must_payoff ? '必回收伏笔只能由正式提交的正文自动回收。' : null),
                Select::make('version')->label('正式正文')->options(fn (): array => Chapter::where('novel_id', $this->getRecord()->id)->whereNotNull('formal_version_id')->orderBy('number')->get()->mapWithKeys(fn (Chapter $chapter): array => [$chapter->formal_version_id => '第'.$chapter->number.'章 · '.$chapter->title])->all())->required(),
                TextInput::make('paragraph')->label('段落编号')->integer()->minValue(1)->required(),
                Textarea::make('quote')->label('逐字原文证据')->required(), Textarea::make('reason')->label('登记理由')->required(),
            ])->action(function (Foreshadow $record, array $data): void {
                $this->perform(function () use ($record, $data): void {
                    abort_unless($record->novel_id === $this->getRecord()->id, 403);
                    app(ForeshadowLedger::class)->record($record, auth()->user(), (int) $data['revision'], $data['status'], (int) $data['version'], (int) $data['paragraph'], $data['quote'], $data['reason'], $data['key']);
                });
            }),
        ]);
    }

    /** 新计划和证据操作都使用可重放请求编号，不提供覆盖和删除入口。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('createPlan')->label('登记计划')->schema([
            Hidden::make('key')->default(fn (): string => (string) Str::uuid7()),
            TextInput::make('title')->label('伏笔名称')->maxLength(255)->required(), Textarea::make('description')->label('埋设内容与回收目标')->required(),
            TextInput::make('plant')->label('计划埋设章（最晚）')->integer()->minValue(1)->required(), TextInput::make('target')->label('最迟回收章')->integer()->minValue(1)->required(),
            Section::make('长程契约（推荐）')->description('填写回收窗口后，埋设、强化和回收都由正式提交时的正文自动流转；窗口末章必须等于最迟回收章。')->columns(2)->schema([
                Textarea::make('surface_clue')->label('表面线索')->rows(2), Textarea::make('hidden_meaning')->label('隐藏含义（回收前不得揭示）')->rows(2),
                Textarea::make('payoff_effect')->label('回收作用')->rows(2), Textarea::make('knowledge_constraint')->label('知情约束')->rows(2),
                TextInput::make('payoff_from')->label('回收窗口起始章')->integer()->minValue(1), TextInput::make('payoff_to')->label('回收窗口末章')->integer()->minValue(1),
                Checkbox::make('must_payoff')->label('必须在窗口内回收（窗口末章未回收时阻塞提交）'),
            ]),
        ])->action(function (array $data): void {
            $contract = filled($data['payoff_to'] ?? null) ? ['surface_clue' => $data['surface_clue'] ?? null, 'hidden_meaning' => $data['hidden_meaning'] ?? null, 'payoff_effect' => $data['payoff_effect'] ?? null,
                'knowledge_constraint' => $data['knowledge_constraint'] ?? null, 'payoff_from' => (int) $data['payoff_from'], 'payoff_to' => (int) $data['payoff_to'], 'must_payoff' => (bool) ($data['must_payoff'] ?? false)] : [];
            $this->perform(fn () => app(ForeshadowLedger::class)->plan($this->getRecord(), auth()->user(), $data['title'], $data['description'], (int) $data['plant'], (int) $data['target'], $data['key'], $contract));
        }), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** 可预期业务错误展示中文通知；成功后完整刷新避免嵌套组件保留旧状态。 */
    private function perform(callable $operation): void
    {
        try {
            $operation();
            $this->redirect(NovelResource::getUrl('foreshadows', ['record' => $this->getRecord()]), navigate: false);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
