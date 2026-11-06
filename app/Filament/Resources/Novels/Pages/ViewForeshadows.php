<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\Foreshadow;
use App\Models\ForeshadowEvidence;
use App\Services\Pipeline\ForeshadowLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

/** 伏笔计划与正式证据维护入口，状态只能经追加证据推进。 */
class ViewForeshadows extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 复用小说私有后台权限。 */
    protected static string $resource = NovelResource::class;

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
        return $schema->components([Section::make('计划与正式事实')->description('先登记计划，再依据正式正文登记埋设、强化或回收。已有未提交章节时暂停维护，避免改变冻结输入。')->schema([
            TextEntry::make('due')->label('下一章提醒')->state(function (): string {
                $number = $this->getRecord()->chapters_done + 1;
                $count = Foreshadow::where('novel_id', $this->getRecord()->id)->where('status', '!=', 'resolved')->where('target_chapter', '<=', $number)->count();

                return '第'.$number.'章：'.$count.'条伏笔到期或逾期，未回收项将持续注入后续上下文。';
            }),
        ]), EmbeddedTable::make()]);
    }

    /** 账本严格按小说隔离，证据弹窗提供正式章节及原文。 */
    public function table(Table $table): Table
    {
        return $table->query(Foreshadow::where('novel_id', $this->getRecord()->id))->defaultSort('id')->columns([
            TextColumn::make('title')->label('伏笔')->searchable(),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn (string $state): string => Foreshadow::statusLabel($state)),
            TextColumn::make('plant_chapter')->label('计划埋设章'),
            TextColumn::make('target_chapter')->label('最迟回收章'),
            TextColumn::make('revision')->label('证据条数'),
        ])->recordActions([
            Action::make('inspectEvidence')->label('查看证据')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->schema(fn (Foreshadow $record): array => [
                TextEntry::make('description')->label('计划说明')->state($record->description),
                ...$record->evidence->map(fn (ForeshadowEvidence $entry): Section => Section::make('第'.$entry->chapter_number.'章 · '.Foreshadow::statusLabel($entry->to_status))->schema([
                    TextEntry::make('quote'.$entry->id)->label('正式原文（第'.$entry->evidence['paragraph'].'段）')->state($entry->evidence['quote']),
                    TextEntry::make('reason'.$entry->id)->label('登记理由')->state($entry->reason),
                    TextEntry::make('source'.$entry->id)->label('正式来源')->state('正文版本 '.$entry->version_id.' / 提交 '.$entry->commit_id)->url(NovelResource::getUrl('state', ['record' => $record->novel_id, 'chapter' => $entry->chapter_number])),
                ]))->all(),
            ]),
            Action::make('recordEvidence')->label('登记进展')->visible(fn (Foreshadow $record): bool => $record->status !== 'resolved')->schema(fn (Foreshadow $record): array => [
                Hidden::make('revision')->default($record->revision), Hidden::make('key')->default(fn (): string => (string) Str::uuid7()),
                Select::make('status')->label('新状态')->options($record->status === 'planned' ? ['planted' => '已埋设'] : ['reinforced' => '已强化', 'resolved' => '已回收'])->required(),
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
            TextInput::make('plant')->label('计划埋设章')->integer()->minValue(1)->required(), TextInput::make('target')->label('最迟回收章')->integer()->minValue(1)->required(),
        ])->action(function (array $data): void {
            $this->perform(fn () => app(ForeshadowLedger::class)->plan($this->getRecord(), auth()->user(), $data['title'], $data['description'], (int) $data['plant'], (int) $data['target'], $data['key']));
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
