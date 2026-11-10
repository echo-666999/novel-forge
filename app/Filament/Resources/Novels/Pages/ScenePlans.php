<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\NovelOutlineChapter;
use App\Models\ScenePlanVersion;
use App\Services\Pipeline\ScenePlanning;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** 场景草稿编辑、覆盖预检及明确冻结，正式正文没有编辑入口。 */
class ScenePlans extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 沿用小说资源权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '场景规划';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 5;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '规划';

    /** 中文页面标题。 */
    protected static ?string $title = '章节与场景规划';

    /** 加载小说并检查访问权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 作者通过新增、删除与重排列表表达拆分或合并，保存形成新版本。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('未开始章节的场景计划')->description('新增场景或拆分时填写不同目标；合并时将事件与推进点合入保留场景再移除旧项。每次保存产生新版本。冻结检查推进点覆盖和总字数，并进入以后新运行的细纲、正文和检查请求；已经开始的章节只能查看。')->schema([]), EmbeddedTable::make()]);
    }

    /** 章节大纲列表提供首版编辑，历史场景独立展示。 */
    public function table(Table $table): Table
    {
        // 先集中同一大纲，再按章序展示，避免多个大纲的同章号交错。
        return $table->query(NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $this->getRecord()->id)))->defaultSort(fn ($query) => $query->orderByDesc('outline_plan_id')->orderBy('chapter_number')->orderBy('id'))->columns([
            // 相同章号可能来自不同大纲，始终展示来源记录以便区分。
            TextColumn::make('outline_plan_id')->label('所属大纲记录编号'),
            TextColumn::make('chapter_number')->label('章号')->sortable(), TextColumn::make('title')->label('标题')->searchable()->wrap(),
            TextColumn::make('objective')->label('目标')->wrap(),
            TextColumn::make('scene_status')->label('最新场景状态')->state(fn (NovelOutlineChapter $record): string => $this->latest($record)?->status ?? '未规划'),
        ])->filters([SelectFilter::make('outline_plan_id')->label('大纲记录')->options(fn (): array => $this->getRecord()->outlinePlans()->orderByDesc('id')->pluck('id', 'id')->map(fn ($id): string => '大纲记录 #'.$id)->all())])->recordActions([
            Action::make('history')->label('版本与覆盖')->modalSubmitAction(false)->schema(fn (NovelOutlineChapter $record): array => [
                TextEntry::make('points')->label('必须覆盖的推进点')->state(json_encode($record->progression_points, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)),
                TextEntry::make('versions')->label('全部版本及预检')->state(fn (): string => ScenePlanVersion::where('outline_chapter_id', $record->id)->latest('id')->get()->map(fn ($v): string => '场景记录 #'.$v->id.' '.$v->status.' '.$v->reason."\n".implode('；', app(ScenePlanning::class)->issues($this->getRecord(), $record, $v->scenes))."\n".json_encode($v->scenes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->implode("\n\n"))->prose(),
            ]),
            Action::make('edit')->label('编辑 / 拆分 / 合并')->visible(fn (NovelOutlineChapter $record): bool => ! $this->getRecord()->chapters()->where('number', $record->chapter_number)->exists())
                ->fillForm(fn (NovelOutlineChapter $record): array => ['expected_id' => $this->latest($record)?->id, 'scenes' => $this->latest($record)?->scenes ?? [], 'freeze' => false, 'acknowledged' => false])
                ->schema(fn (NovelOutlineChapter $record): array => $this->fields($record))
                ->action(function (NovelOutlineChapter $record, array $data): void {
                    app(ScenePlanning::class)->save($this->getRecord()->id, $record->id, Filament::auth()->user(), array_values($data['scenes']), (bool) ($data['freeze'] ?? false), $data['reason'], isset($data['expected_id']) ? (int) $data['expected_id'] : null);
                    Notification::make()->success()->title('场景规划新版本已保存')->send();
                }),
        ])->emptyStateHeading('尚无可用章节大纲');
    }

    /** 控件明确列出推进点；冻结和确认默认关闭。 */
    private function fields(NovelOutlineChapter $record): array
    {
        $fields = [];
        foreach (['title' => '场景标题', 'goal' => '场景目标', 'pov' => '视角人物', 'time' => '故事时间', 'place' => '地点', 'participants' => '参与者', 'conflict' => '冲突', 'change' => '人物及状态变化', 'transition' => '前后衔接', 'prohibited' => '禁止变更'] as $key => $label) {
            $fields[] = Textarea::make($key)->label($label)->required()->maxLength(in_array($key, ['title', 'pov']) ? 200 : (in_array($key, ['time', 'place']) ? 500 : ($key === 'participants' ? 1000 : 2000)));
        }
        $fields[] = TextInput::make('words')->label('目标字数')->integer()->minValue(1)->required();
        $fields[] = Select::make('point_ids')->label('必要事件 / 推进点')->multiple()->options(array_combine(array_column($record->progression_points, 'id'), array_column($record->progression_points, 'id')))->required();

        return [Hidden::make('expected_id'), Repeater::make('scenes')->label('按发生顺序排列场景')->schema($fields)->columns(2)->minItems(1)->maxItems(20),
            Textarea::make('reason')->label('新增、拆分或合并原因')->required()->maxLength(2000), Checkbox::make('freeze')->label('通过覆盖和字数检查后冻结为新运行输入')->default(false),
            Checkbox::make('acknowledged')->label('我确认以上场景规划，不修改已开始运行或正式历史。')->accepted()->default(false)];
    }

    /** 按大纲章节读取最新编辑版本，不混入其他书或其他大纲。 */
    private function latest(NovelOutlineChapter $record): ?ScenePlanVersion
    {
        return ScenePlanVersion::where('outline_chapter_id', $record->id)->latest('id')->first();
    }

    /** 返回小说详情。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }
}
