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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Livewire\Attributes\Url;

/** 按章查看明确的角色认知和叙述事件，不将事件参与自动视为知情。 */
class ViewCharacterKnowledge extends Page
{
    use InteractsWithRecord;

    /** 所属小说资源。 */
    protected static string $resource = NovelResource::class;

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

        return $schema->components([
            Section::make('截至第'.$this->chapterNumber.'章 · '.($data['characters'][$this->actorId] ?? '未选择角色'))
                ->description('只展示明确的正式认知或信念补丁；当前提取协议不生成这两类投影，历史为空是未记录，不能据此推断角色知情或不知情。传闻、信念不升级为客观事实。')->schema([
                    RepeatableEntry::make('knowledge')->label('角色视角记录')->state($data['rows'])->placeholder('未记录明确认知；可选择角色查询历史证据。')->schema([
                        TextEntry::make('kind')->label('记录类型'), TextEntry::make('category')->label('事实类别'),
                        TextEntry::make('text')->label('内容')->columnSpanFull(), TextEntry::make('chapter')->label('获得章位'),
                        TextEntry::make('event_id')->label('来源事件'), TextEntry::make('version_id')->label('正式正文记录编号')->url(fn (Get $get): string => NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'version' => $get('version_id')])),
                        RepeatableEntry::make('evidence')->label('正文证据')->schema([TextEntry::make('quote')->label('原文')])->columnSpanFull(),
                    ]),
                ]),
            Section::make('叙述事件时序')->description('按正式章节与叙述顺序展示；当前、回忆、预测分列。没有精确故事日期时不伪造时间。选择角色后仅显示其作为行为主体的事件，不代表完整知情范围。')->schema([
                RepeatableEntry::make('events')->label('正式事件')->state($data['events'])->schema([
                    TextEntry::make('chapter')->label('章号'), TextEntry::make('time_scope')->label('时间类别'), TextEntry::make('category')->label('事实类别'),
                    TextEntry::make('summary')->label('事件')->columnSpanFull(),
                    TextEntry::make('version_id')->label('打开正式正文')->url(fn (Get $get): string => NovelResource::getUrl('reader', ['record' => $this->getRecord(), 'version' => $get('version_id')])),
                    RepeatableEntry::make('evidence')->label('原文证据')->schema([TextEntry::make('quote')->label('原文')])->columnSpanFull(),
                ]),
            ]),
        ]);
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
