<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\EntityDefinitionVersion;
use App\Models\StoryEntity;
use App\Services\Pipeline\EntityDefinitions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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

/** 作者设定、别名候选及关系版本管理，与正式动态状态分离。 */
class EntityLibrary extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 沿用小说权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '人物与实体库';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 6;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '人物与世界';

    /** 页面中文名称。 */
    protected static ?string $title = '人物、关系与世界实体库';

    /** 校验所属小说访问权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 直接提供正式状态入口，不在作者编辑表单伪装为动态事实修改。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('作者设定版本')->description('新增和修订先保存候选，批准另存版本。既有实体不能改名或删除，可提交别名合并候选。关系、人物特征和规则为作者约束，不直接改变正式事件、存亡、位置或角色知识。新运行冻结批准版本，历史运行保留原输入。')->schema([
            TextEntry::make('catalog')->label('既有稳定实体编号与名称')->state(fn (): string => StoryEntity::where('novel_id', $this->getRecord()->id)->get()->map(fn ($e): string => '#'.$e->id.' '.$e->name.'（'.$e->type.'）')->implode('；')),
        ]), EmbeddedTable::make()]);
    }

    /** 不可变版本可筛选、比较，批准需要显示完整定义并确认。 */
    public function table(Table $table): Table
    {
        return $table->query(EntityDefinitionVersion::where('novel_id', $this->getRecord()->id))->defaultSort('id', 'desc')->columns([
            // 来源编号明确版本链，不以同名候选或空实体编号推断同一对象。
            TextColumn::make('id')->label('设定记录编号'), TextColumn::make('parent_id')->label('来源记录编号')->placeholder('首次建立'), TextColumn::make('entity_id')->label('实体')->placeholder('新增候选'),
            TextColumn::make('definition.name')->label('名称')->searchable(), TextColumn::make('definition.type')->label('类型'),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn (string $state): string => $state === 'approved' ? '已批准' : '候选'),
            TextColumn::make('effective_from')->label('生效起始章'), TextColumn::make('reason')->label('原因')->wrap(), TextColumn::make('created_at')->label('时间')->dateTime(),
        ])->filters([SelectFilter::make('status')->label('状态')->options(['candidate' => '候选', 'approved' => '已批准'])])->recordActions([
            Action::make('inspect')->label('定义与版本比较')->modalSubmitAction(false)->schema(fn (EntityDefinitionVersion $record): array => [
                TextEntry::make('current')->label('本版本完整定义')->state(json_encode($record->definition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->prose(),
                TextEntry::make('parent')->label('来源版本完整定义')->state(json_encode(EntityDefinitionVersion::where('novel_id', $this->getRecord()->id)->find($record->parent_id)?->definition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))->prose(),
                TextEntry::make('hash')->label('内容摘要')->state($record->content_hash),
            ]),
            Action::make('revise')->label('编辑新候选')->fillForm(fn (EntityDefinitionVersion $record): array => ['definition' => $record->definition])->schema(fn (): array => [...$this->fields(), Textarea::make('reason')->label('修订原因')->required()])->action(function (EntityDefinitionVersion $record, array $data): void {
                app(EntityDefinitions::class)->propose($this->getRecord()->id, Filament::auth()->user(), $data['definition'], $data['reason'], $record->entity_id, $record->id);
                Notification::make()->success()->title('已保存实体候选')->send();
            }),
            Action::make('approve')->label('批准此候选')->visible(fn (EntityDefinitionVersion $record): bool => $record->status === 'candidate' && ! EntityDefinitionVersion::where('parent_id', $record->id)->exists())->schema(fn (EntityDefinitionVersion $record): array => [
                TextEntry::make('definition')->label('待批准完整定义')->state(json_encode($record->definition, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)), Textarea::make('reason')->label('批准原因')->required(),
                Checkbox::make('acknowledged')->label('我批准此作者设定；它不覆盖正式历史事实。')->accepted()->default(false),
            ])->action(function (EntityDefinitionVersion $record, array $data): void {
                app(EntityDefinitions::class)->approve($this->getRecord()->id, $record->id, Filament::auth()->user(), $data['reason'], (bool) ($data['acknowledged'] ?? false));
                Notification::make()->success()->title('实体设定新版本已批准')->send();
            }),
        ])->emptyStateHeading('尚无独立作者版本')->emptyStateDescription('既有正式实体仍可在状态页查看；可选择既有实体建立修订候选。');
    }

    /** 新实体与既有实体都从候选入口开始，不隐式批准。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('新增候选')->schema(fn (): array => [...$this->fields(), Textarea::make('reason')->label('新增原因')->required()])->action(function (array $data): void {
            app(EntityDefinitions::class)->propose($this->getRecord()->id, Filament::auth()->user(), $data['definition'], $data['reason']);
            Notification::make()->success()->title('已保存实体候选')->send();
        }), Action::make('existing')->label('修订既有实体 / 别名')->schema([Select::make('entity_id')->label('实体')->options(fn (): array => $this->entities())->required(), Textarea::make('reason')->label('建立修订草稿原因')->required()])->action(function (array $data): void {
            $entity = StoryEntity::where('novel_id', $this->getRecord()->id)->findOrFail($data['entity_id']);
            $definition = app(EntityDefinitions::class)->effective($entity, $this->getRecord()->chapters_done + 1);
            $definition += ['traits' => '', 'rules' => '', 'scope' => '本书作者设定，非正式动态事实', 'relationships' => []];
            app(EntityDefinitions::class)->propose($this->getRecord()->id, Filament::auth()->user(), $definition, $data['reason'], $entity->id);
            Notification::make()->success()->title('已建立修订候选，请编辑新候选')->send();
        }), Action::make('state')->label('正式状态与来源')->url(fn (): string => NovelResource::getUrl('state', ['record' => $this->getRecord()])), Action::make('knowledge')->label('角色知识与经历')->url(fn (): string => NovelResource::getUrl('knowledge', ['record' => $this->getRecord()])), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** 编辑字段不包含存亡或位置等正式投影属性。 */
    private function fields(): array
    {
        return [TextInput::make('definition.name')->label('稳定名称')->required()->maxLength(200),
            Select::make('definition.type')->label('实体类型')->options(['character' => '人物', 'group' => '群体', 'location' => '地点', 'item' => '物品', 'organization' => '组织', 'animal' => '动物', 'rule' => '世界规则', 'ability' => '能力'])->required(),
            TagsInput::make('definition.aliases')->label('别名（经批准合并到此身份）')->default([]), Textarea::make('definition.description')->label('描述')->required()->maxLength(10000),
            Textarea::make('definition.traits')->label('身份、外貌、价值观、目标、底线与口吻')->default('')->maxLength(10000), Textarea::make('definition.rules')->label('规则与能力限制')->default('')->maxLength(10000),
            Textarea::make('definition.scope')->label('适用范围及引入策略')->required()->maxLength(2000),
            Repeater::make('definition.relationships')->label('初始关系 / 未来关系约束')->schema([Select::make('target_id')->label('同书实体')->options(fn (): array => $this->entities())->required(), Textarea::make('description')->label('关系描述及限制')->required()->maxLength(2000)])->default([])];
    }

    /** 所有关系选项按小说隔离。 */
    private function entities(): array
    {
        return StoryEntity::where('novel_id', $this->getRecord()->id)->pluck('name', 'id')->all();
    }
}
