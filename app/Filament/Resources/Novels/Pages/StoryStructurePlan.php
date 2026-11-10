<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\StoryStructureVersion;
use App\Services\Pipeline\StoryStructure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
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

/** 作者卷弧规划编辑入口，草稿与生成可用冻结版本分开。 */
class StoryStructurePlan extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 小说资源权限及面包屑。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '卷与故事弧';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 3;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '规划';

    /** 页面名称。 */
    protected static ?string $title = '卷与故事弧规划';

    /** 解析授权小说。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 清楚说明草稿未生效及旧运行不自动更新。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('作者结构约束')->description('编辑、调整顺序和章范围均保存新版本。冻结必须覆盖目标章数，弧范围须覆盖所属卷。冻结卷弧会进入之后新建章节的输入快照，旧运行恢复不追补；涉及已提交章位的变动须走历史变更流程。')->schema([]), EmbeddedTable::make()]);
    }

    /** 版本和层级可展开核对，历史记录没有原地编辑或删除按钮。 */
    public function table(Table $table): Table
    {
        return $table->query(StoryStructureVersion::where('novel_id', $this->getRecord()->id))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('结构记录编号'), TextColumn::make('parent_id')->label('来源记录编号')->placeholder('首次'),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn (string $state): string => $state === 'frozen' ? '已冻结' : '草稿'),
            TextColumn::make('reason')->label('原因')->wrap(), TextColumn::make('created_at')->label('保存时间')->dateTime(),
        ])->filters([SelectFilter::make('status')->label('状态')->options(['draft' => '草稿', 'frozen' => '已冻结'])])->recordActions([
            Action::make('inspect')->label('查看卷弧树')->modalSubmitAction(false)->schema(fn (StoryStructureVersion $record): array => [
                RepeatableEntry::make('volumes')->label('卷')->state($record->structure)->schema([
                    ...$this->displayFields(),
                    RepeatableEntry::make('arcs')->label('故事弧')->schema($this->displayFields())->columnSpanFull(),
                ]),
            ]),
        ])->emptyStateHeading('尚无作者卷弧规划')->emptyStateDescription('可建立独立草稿，只有明确冻结且通过范围校验的版本进入未来章节输入。');
    }

    /** 表单支持增加、细化、拖动顺序；服务端最终校验章范围和并发版本。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('edit')->label('编辑并保存新版本')->fillForm(function (): array {
            $latest = StoryStructureVersion::where('novel_id', $this->getRecord()->id)->latest('id')->first();

            return ['expected_id' => $latest?->id, 'volumes' => $latest?->structure ?? [], 'freeze' => false, 'acknowledged' => false];
        })->schema([
            Hidden::make('expected_id'),
            Repeater::make('volumes')->label('卷')->minItems(1)->maxItems(100)->schema([
                ...$this->editFields(),
                Repeater::make('arcs')->label('故事弧')->minItems(1)->maxItems(100)->schema($this->editFields())->columns(2)->columnSpanFull(),
            ])->columns(2),
            Textarea::make('reason')->label('修订原因')->required()->maxLength(2000),
            Checkbox::make('freeze')->label('冻结为未来新章节运行使用的作者规划')->default(false),
            Checkbox::make('acknowledged')->label('我确认此版本；冻结不改写旧运行或已正式提交章节。')->accepted(),
        ])->action(function (array $data): void {
            app(StoryStructure::class)->save($this->getRecord()->id, Filament::auth()->user(), array_values($data['volumes']), (bool) ($data['freeze'] ?? false), $data['reason'], isset($data['expected_id']) ? (int) $data['expected_id'] : null);
            Notification::make()->success()->title('卷弧新版本已保存')->send();
        }), Action::make('outline')->label('查看章节大纲')->url(fn (): string => NovelResource::getUrl('outline', ['record' => $this->getRecord()])),
            Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** 卷弧共用范围、目标、转折、高潮和结束状态字段。 */
    private function editFields(): array
    {
        return [TextInput::make('title')->label('标题')->required()->maxLength(2000),
            TextInput::make('start')->label('起始章')->integer()->minValue(1)->required(), TextInput::make('end')->label('结束章')->integer()->minValue(1)->required(),
            Textarea::make('goal')->label('目标')->required()->maxLength(2000), Textarea::make('turning_point')->label('转折')->required()->maxLength(2000),
            Textarea::make('climax')->label('高潮')->required()->maxLength(2000), Textarea::make('ending_state')->label('结束状态')->required()->maxLength(2000)];
    }

    /** 同一字段结构用于只读层级展示。 */
    private function displayFields(): array
    {
        return array_map(fn (string $key, string $label): TextEntry => TextEntry::make($key)->label($label),
            ['title', 'start', 'end', 'goal', 'turning_point', 'climax', 'ending_state'], ['标题', '起始章', '结束章', '目标', '转折', '高潮', '结束状态']);
    }
}
