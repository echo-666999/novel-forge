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
use Filament\Schemas\Components\Section;
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

        return [
            Section::make('第'.$this->chapterNumber.'章提交后')->description('事件回放与正式快照一致。未记录表示没有已提交的状态事实，不能推断为存活、死亡或未知地点。')->schema([
                RepeatableEntry::make('entities')->label('实体状态')->state($replay['entities'])->schema([
                    TextEntry::make('name')->label('实体'), TextEntry::make('facts')->label('状态与来源')->columnSpanFull(),
                ]),
            ]),
            Section::make('截至本章的历史事件')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->state($replay['events'])->schema([
                    TextEntry::make('id')->label('事件编号'), TextEntry::make('chapter')->label('来源章号'),
                    TextEntry::make('version_id')->label('正式正文记录编号'), TextEntry::make('category')->label('事实类别'),
                    TextEntry::make('time_scope')->label('时间范围'), TextEntry::make('summary')->label('事件')->columnSpanFull(),
                    RepeatableEntry::make('evidence')->label('正文证据')->schema([
                        TextEntry::make('paragraph')->label('段落'), TextEntry::make('quote')->label('原文')->columnSpanFull(),
                    ])->columnSpanFull(),
                ]),
            ]),
            Section::make('所选章正式正文')->collapsible()->collapsed()->schema([TextEntry::make('body')->hiddenLabel()->state($replay['body'])]),
        ];
    }

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
