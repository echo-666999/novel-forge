<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Services\Pipeline\StoryStateReplay;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

/** 按正式章节回放状态并展示正文证据，不开放任何历史写入。 */
class ViewStoryState extends Page
{
    use InteractsWithRecord;

    /** 资源负责小说访问权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '故事状态回放';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 18;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '交付';

    /** 后台中文标题。 */
    protected static ?string $title = '故事状态回放';

    /** 地址保存章号，所有查询仍限定当前小说。 */
    #[Url(as: 'chapter')]
    public int $chapterNumber = 1;

    /** 首次访问沿用小说的只读权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 每次请求重算所选正式状态，遇到损坏只展示错误，不展示旧缓存。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components(fn (): array => $this->replayComponents());
    }

    /** 延迟读取组件状态，确保同一交互中切换章节后不会复用旧页面内容。
     * @return array<Component>
     */
    private function replayComponents(): array
    {
        try {
            $replay = app(StoryStateReplay::class)->atChapter($this->getRecord(), $this->chapterNumber);
        } catch (InvalidArgumentException|ModelNotFoundException $exception) {
            return [TextEntry::make('unavailable')->label('暂不可回放')->state($exception instanceof ModelNotFoundException ? '正式来源记录缺失' : $exception->getMessage())];
        }

        $entities = collect($replay['entities'])->sortByDesc(fn (array $entity): bool => $entity['recorded'] !== [])->values();
        $recordedCount = $entities->filter(fn (array $entity): bool => $entity['recorded'] !== [])->count();

        return [
            Section::make('第'.$this->chapterNumber.'章提交后')->compact()->schema([
                Flex::make([
                    TextEntry::make('state_version')->hiddenLabel()->badge()->color('primary')->state('状态版本 #'.$replay['state_version']),
                    TextEntry::make('entity_count')->hiddenLabel()->badge()->color('gray')->state('实体 '.$entities->count().' 个，有状态记录 '.$recordedCount.' 个'),
                    TextEntry::make('event_count')->hiddenLabel()->badge()->color('gray')->state('累计事件 '.count($replay['events']).' 条'),
                ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                TextEntry::make('rule')->hiddenLabel()->color('gray')->size('sm')
                    ->state('事件回放与正式快照一致。未记录只表示没有已提交的状态事实，不能推断为存活、死亡或未知地点。'),
            ]),
            Section::make('实体状态')->compact()->schema([
                RepeatableEntry::make('entities')->hiddenLabel()->grid(['default' => 1, 'md' => 2, 'xl' => 3])->state($entities->map(fn (array $entity): array => [
                    'name' => $entity['name'], 'type' => self::ENTITY_TYPES[$entity['type']] ?? $entity['type'], 'recorded' => $entity['recorded'],
                    'missing' => $entity['missing'] === [] ? null : '未记录：'.implode('、', $entity['missing']),
                ])->all())->placeholder('截至本章没有实体')->schema([
                    Flex::make([
                        TextEntry::make('name')->hiddenLabel()->weight('bold'),
                        TextEntry::make('type')->hiddenLabel()->badge()->grow(false)->color('gray'),
                    ])->verticallyAlignCenter(),
                    TextEntry::make('recorded')->hiddenLabel()->listWithLineBreaks()->placeholder('暂无已提交的状态事实')->extraAttributes(['style' => 'line-height: 1.7']),
                    TextEntry::make('missing')->hiddenLabel()->color('gray')->size('sm'),
                ]),
            ]),
            Section::make('截至本章的历史事件')->compact()->collapsible()->description('按章节与叙述顺序排列；点击小标题打开对应正式正文。')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->state(array_map(fn (array $event): array => [
                    'heading' => '第'.$event['chapter'].'章 · 事件 #'.$event['id'], 'version_id' => $event['version_id'],
                    'time_scope' => $event['time_scope'], 'category' => $event['category'], 'summary' => $event['summary'],
                    'evidence' => array_map(fn (array $item): string => '第'.$item['paragraph'].'段：'.$item['quote'], $event['evidence']),
                ], $replay['events']))->placeholder('截至本章没有正式事件')->schema([
                    Flex::make([
                        TextEntry::make('heading')->hiddenLabel()->weight('bold')->color('gray')
                            ->url(fn (Get $get): string => NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'version' => $get('version_id')])),
                        TextEntry::make('time_scope')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => $state === '当前' ? 'gray' : 'info'),
                        TextEntry::make('category')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => match ($state) {
                            '客观已确认' => 'success', '谎言' => 'danger', '传闻', '推测' => 'warning', default => 'gray',
                        }),
                    ])->verticallyAlignCenter(),
                    TextEntry::make('summary')->hiddenLabel(),
                    TextEntry::make('evidence')->hiddenLabel()->listWithLineBreaks()->limitList(1)->expandableLimitedList()->color('gray')->size('sm'),
                ]),
            ]),
            Section::make('所选章正式正文')->compact()->collapsible()->collapsed()->schema([
                TextEntry::make('body')->hiddenLabel()->state($replay['body'])->extraAttributes(['style' => 'white-space: pre-wrap; line-height: 1.9']),
            ]),
        ];
    }

    /** 实体类型中文名。 */
    private const ENTITY_TYPES = ['character' => '人物', 'group' => '群体', 'location' => '地点', 'item' => '物品', 'organization' => '组织', 'animal' => '动物', 'rule' => '世界规则', 'ability' => '能力'];

    /** 只允许选择当前小说的正式章号，不触发生成和提交。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('selectChapter')->label('选择回放章节')->schema([
            Select::make('number')->label('正式章节')->options(fn (): array => app(StoryStateReplay::class)->chapters($this->getRecord()))->required()->default($this->chapterNumber),
        ])->action(function (array $data): void {
            $this->chapterNumber = (int) $data['number'];
            // 完整导航重建章节视图，避免浏览器保留上一章的嵌套Schema节点。
            $this->redirect(NovelResource::getUrl('state', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber]), navigate: false);
        }), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }
}
