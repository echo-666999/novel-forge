<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Services\Pipeline\CharacterKnowledge;
use App\Services\Pipeline\StoryStateReplay;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

/** 按章查看明确的角色认知和叙述事件，不将事件参与自动视为知情。 */
class ViewCharacterKnowledge extends Page
{
    use InteractsWithRecord;

    /** 所属小说资源。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '角色知识与时序';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 7;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '人物与世界';

    /** 中文页面标题。 */
    protected static ?string $title = '角色知识与事件时序';

    /** 截止正式章号，每次请求均重新核验。 */
    #[Url(as: 'chapter')]
    public int $chapterNumber = 1;

    /** 可选角色编号，不能越过当前小说和截止章。 */
    #[Url(as: 'actor')]
    public ?int $actorId = null;

    /** 沿用资源访问控制。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 认知、信念与事件分开显示；空记录不推断为角色不知道。 */
    public function content(Schema $schema): Schema
    {
        try {
            $data = app(CharacterKnowledge::class)->atChapter($this->getRecord(), $this->chapterNumber, $this->actorId);
        } catch (InvalidArgumentException|ModelNotFoundException $error) {
            return $schema->components([TextEntry::make('unavailable')->label('暂不可查询')->state($error instanceof ModelNotFoundException ? '正式来源缺失' : $error->getMessage())]);
        }

        $actorName = $data['characters'][$this->actorId] ?? null;

        return $schema->components([
            Section::make('截至第'.$this->chapterNumber.'章'.($actorName === null ? '' : ' · '.$actorName))->compact()->schema([
                Flex::make([
                    TextEntry::make('event_count')->hiddenLabel()->badge()->color('primary')->state(($actorName === null ? '正式事件 ' : $actorName.'主导的事件 ').count($data['events']).' 条'),
                    TextEntry::make('character_count')->hiddenLabel()->badge()->color('gray')->state('已登场人物 '.count($data['characters']).' 人'),
                    TextEntry::make('row_count')->hiddenLabel()->badge()->color('gray')->state('认知与信念 '.count($data['rows']).' 条')->visible($actorName !== null),
                ])->extraAttributes(['style' => 'flex-wrap: wrap']),
                TextEntry::make('rule')->hiddenLabel()->color('gray')->size('sm')
                    ->state('只列明确记录：没有记录不代表角色不知道；传闻和信念不会当作客观事实。'),
            ]),
            Section::make('角色认知与信念')->compact()->visible($actorName !== null)->schema([
                RepeatableEntry::make('knowledge')->hiddenLabel()->state($this->knowledgeRows($data['rows']))
                    ->placeholder('未记录明确认知；当前提取协议不生成认知投影，不能据此推断角色知情或不知情。')->schema([
                        Flex::make([
                            TextEntry::make('text')->hiddenLabel()->weight('bold'),
                            TextEntry::make('kind')->hiddenLabel()->badge()->grow(false)->color('info'),
                            TextEntry::make('category')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => self::categoryColor($state)),
                        ])->verticallyAlignCenter(),
                        TextEntry::make('source')->hiddenLabel()->color('gray')->size('sm')->url(fn (Get $get): string => $this->readerUrl($get('version_id'))),
                        TextEntry::make('evidence')->hiddenLabel()->listWithLineBreaks()->limitList(1)->expandableLimitedList()->color('gray')->size('sm'),
                    ]),
            ]),
            Section::make('叙述事件时序')->compact()->description($actorName === null ? '按章节与叙述顺序排列；选择角色可只看其主导的事件。' : '只列该角色作为行为主体的事件，不代表完整知情范围。')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->state($this->eventRows($data['events'], $data['characters']))->placeholder('截至本章没有正式事件')->schema([
                    Flex::make([
                        TextEntry::make('heading')->hiddenLabel()->weight('bold')->color('gray')->url(fn (Get $get): string => $this->readerUrl($get('version_id'))),
                        TextEntry::make('time_scope')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => $state === '当前' ? 'gray' : 'info'),
                        TextEntry::make('category')->hiddenLabel()->badge()->grow(false)->color(fn (string $state): string => self::categoryColor($state)),
                    ])->verticallyAlignCenter(),
                    TextEntry::make('summary')->hiddenLabel(),
                    TextEntry::make('evidence')->hiddenLabel()->listWithLineBreaks()->limitList(1)->expandableLimitedList()->color('gray')->size('sm'),
                ]),
            ]),
        ]);
    }

    /** 认知行：内容作标题，来源压成一行小字，证据只露第一条。
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function knowledgeRows(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'text' => $row['text'], 'kind' => $row['kind'], 'category' => $row['category'], 'version_id' => $row['version_id'],
            'source' => '第'.$row['chapter'].'章获得 · 来源事件 #'.$row['event_id'].' · 打开正文',
            'evidence' => $this->evidenceLines($row['evidence']),
        ], $rows);
    }

    /** 事件行：章号与主体作小标题，事件摘要为正文，证据默认只露第一条。
     * @param  list<array<string, mixed>>  $events
     * @param  array<int, string>  $characters
     * @return list<array<string, mixed>>
     */
    private function eventRows(array $events, array $characters): array
    {
        return array_map(fn (array $event): array => [
            'heading' => '第'.$event['chapter'].'章'.(isset($characters[$event['actor_id'] ?? null]) ? ' · '.$characters[$event['actor_id']] : ''),
            'time_scope' => $event['time_scope'], 'category' => $event['category'], 'summary' => $event['summary'], 'version_id' => $event['version_id'],
            'evidence' => $this->evidenceLines($event['evidence']),
        ], $events);
    }

    /** 证据压成“第N段：原文”单行。
     * @param  list<array{paragraph?: int, quote: string}>  $evidence
     * @return list<string>
     */
    private function evidenceLines(array $evidence): array
    {
        return array_map(fn (array $item): string => (isset($item['paragraph']) ? '第'.$item['paragraph'].'段：' : '').$item['quote'], $evidence);
    }

    /** 事实类别配色：客观为绿，传闻、谎言等需警惕的类别为橙或红。 */
    private static function categoryColor(string $category): string
    {
        return match ($category) {
            '客观已确认' => 'success', '谎言' => 'danger', '传闻', '推测' => 'warning', default => 'gray',
        };
    }

    /** 正式正文阅读页链接。 */
    private function readerUrl(mixed $versionId): string
    {
        return NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'version' => $versionId]);
    }

    /** 切换章号清空角色，防止未来人物选择留在过去章位。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('chapter')->label('选择截止章')->schema([
            Select::make('number')->label('正式章')->options(fn (): array => app(StoryStateReplay::class)->chapters($this->getRecord()))->required(),
        ])->action(fn (array $data) => $this->redirect(NovelResource::getUrl('knowledge', ['record' => $this->getRecord(), 'chapter' => (int) $data['number']]))),
            Action::make('actor')->label('选择角色')->schema([
                Select::make('id')->label('截至本章的人物')->options(function (): array {
                    try {
                        return app(CharacterKnowledge::class)->atChapter($this->getRecord(), $this->chapterNumber, null)['characters'];
                    } catch (InvalidArgumentException|ModelNotFoundException) {
                        return [];
                    }
                })->placeholder('全部主体事件'),
            ])->action(fn (array $data) => $this->redirect(NovelResource::getUrl('knowledge', ['record' => $this->getRecord(), 'chapter' => $this->chapterNumber, 'actor' => ($data['id'] ?? null) ?: null]))),
            Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
