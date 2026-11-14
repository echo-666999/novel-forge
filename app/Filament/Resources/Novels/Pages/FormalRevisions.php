<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Support\HelpHint;
use App\Models\ChapterVersion;
use App\Models\FormalRevision;
use App\Models\FormalRevisionChapter;
use App\Services\Pipeline\BodyVersionDiff;
use App\Services\Pipeline\FormalRevisionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnitEnum;

/** 正式稿修订：对已提交正文只改文字、事实不变。逐章生成建议 → 作者取舍 → 事实核对 → 提交候选基线 → 切换。 */
class FormalRevisions extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 复用小说私有后台权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '正式稿修订';

    /** 子导航排序，放在提交与基线之前。 */
    protected static ?int $navigationSort = 17;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '交付';

    /** 中文页面标题。 */
    protected static ?string $title = '正式稿修订';

    /** 解析当前小说并验证查看权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 当前修订任务；没有进行中的任务时显示最近一次。 */
    public function revision(): ?FormalRevision
    {
        return FormalRevision::where('novel_id', $this->getRecord()->id)->orderByRaw("case when status in ('open', 'ready') then 0 else 1 end")->latest('id')->first();
    }

    /** 任务概况。 */
    public function content(Schema $schema): Schema
    {
        $help = '模型按规则信号给出逐条润色建议，你逐条接受、拒绝或标为有意保留；程序只替换被接受的原句，其余逐字不变。每章改动后由模型核对事实等价（事件、伏笔证据、限制与线索），通过后才能在候选基线提交；切换前正式正文不变，切换后旧基线只读保留。修订期间不能写新章。';

        return $schema->components([
            Section::make('只改文字、事实不变')->compact()->description('逐条取舍润色建议，改动后由模型核对事实。')
                ->headerActions([HelpHint::action($help)])
                ->schema([
                    Flex::make([
                        TextEntry::make('status')->hiddenLabel()->badge()->grow(false)
                            ->state(fn (): string => $this->revision() === null ? '尚未发起' : FormalRevision::statusLabel($this->revision()->status))
                            ->color(fn (): string => match ($this->revision()?->status) {
                                'open' => 'info', 'ready' => 'warning', 'switched' => 'success', default => 'gray'
                            }),
                        TextEntry::make('range')->hiddenLabel()->badge()->grow(false)->color('primary')->visible(fn (): bool => $this->revision() !== null)
                            ->state(fn (): string => '第'.$this->revision()?->from_chapter.'～'.$this->revision()?->to_chapter.'章'),
                        TextEntry::make('baseline')->hiddenLabel()->color('gray')->size('sm')->visible(fn (): bool => $this->revision() !== null)
                            ->state(fn (): string => '任务 #'.$this->revision()?->id.' · 候选基线 #'.$this->revision()?->baseline_id),
                    ])->verticallyAlignCenter()->extraAttributes(['style' => 'flex-wrap: wrap']),
                    TextEntry::make('intent')->label('修订意图')->inlineLabel()->state(fn (): ?string => $this->revision()?->intent)->visible(fn (): bool => filled($this->revision()?->intent)),
                ]),
            EmbeddedTable::make(),
            Section::make('请求与费用记录')->collapsible()->collapsed()->compact()->visible(fn (): bool => $this->revision() !== null)
                ->description(fn (): string => '已用请求 '.$this->revision()?->requests_used.' / '.$this->revision()?->request_limit.' 次')
                ->schema([
                    TextEntry::make('requests')->label('已用 / 上限')->state(fn (): string => $this->revision()?->requests_used.' / '.$this->revision()?->request_limit),
                    TextEntry::make('request_rule')->hiddenLabel()->color('gray')->size('sm')->state('每章约2次请求：建议1次、核对1次；不同意某章时可重新核对，会多用请求。'),
                ]),
        ]);
    }

    /** 逐章记录与操作。 */
    public function table(Table $table): Table
    {
        return $table->query(FormalRevisionChapter::where('formal_revision_id', $this->revision()?->id ?? 0))->defaultSort('chapter_number')->paginated([10, 25, 50, 100])->columns([
            TextColumn::make('chapter_number')->label('章')->prefix('第')->suffix('章'),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn (string $state): string => FormalRevisionChapter::statusLabel($state))->badge()
                ->color(fn (string $state): string => match ($state) {
                    'verified', 'committed' => 'success', 'failed' => 'danger', 'suggested', 'decided' => 'warning', default => 'gray',
                })
                // 错误优先，其次事实核对结论；完整内容在“查看改动”里。
                ->description(fn (FormalRevisionChapter $record): ?string => filled($record->error) ? '错误：'.Str::limit($record->error, 40)
                    : (filled($record->equivalence['summary'] ?? null) ? Str::limit($record->equivalence['summary'], 40) : null)),
            TextColumn::make('suggestion_count')->label('建议（合格 / 全部）')->state(fn (FormalRevisionChapter $record): string => $record->suggestions === null ? '—'
                : count(array_filter($record->suggestions, fn (array $item): bool => $item['valid'])).' / '.count($record->suggestions)),
            TextColumn::make('accepted')->label('接受')->state(fn (FormalRevisionChapter $record): int => count(array_filter($record->decisions ?? [], fn (string $choice): bool => $choice === 'accept'))),
        ])->recordActions([
            Action::make('suggest')->label('生成建议')->visible(fn (FormalRevisionChapter $record): bool => $this->open() && in_array($record->status, ['pending', 'suggested', 'unchanged'], true))
                ->requiresConfirmation()->modalDescription('发送1次请求，重新生成会清空本章已有取舍。')
                ->action(fn (FormalRevisionChapter $record) => $this->perform(fn () => app(FormalRevisionService::class)->dispatch([$record->id], 'suggest'), '已排队生成建议')),
            Action::make('decide')->label('取舍')->visible(fn (FormalRevisionChapter $record): bool => $this->open() && $record->suggestions !== null && ! in_array($record->status, ['committed'], true))
                ->modalWidth('5xl')->schema(fn (FormalRevisionChapter $record): array => $this->decisionFields($record))
                ->action(fn (FormalRevisionChapter $record, array $data) => $this->perform(fn () => app(FormalRevisionService::class)->decide($record->id, auth()->user(), $data), '已保存取舍')),
            Action::make('verify')->label('事实核对')->visible(fn (FormalRevisionChapter $record): bool => $this->open() && in_array($record->status, ['decided', 'failed'], true) && $record->candidate_version_id !== null)
                ->requiresConfirmation()->modalDescription('发送1次请求，核对润色前后事实是否等价。')
                ->action(fn (FormalRevisionChapter $record) => $this->perform(fn () => app(FormalRevisionService::class)->dispatch([$record->id], 'verify'), '已排队事实核对')),
            Action::make('confirmFactChanges')->label('确认事实变化')->color('warning')
                ->visible(fn (FormalRevisionChapter $record): bool => $this->open() && $record->status === 'failed' && ($record->equivalence['failed_events'] ?? null) === []
                    && ($record->equivalence['unrelocated'] ?? null) === [] && [...($record->equivalence['new_facts'] ?? []), ...($record->equivalence['lost_constraints'] ?? [])] !== [])
                ->modalDescription(fn (FormalRevisionChapter $record): string => '核对列出的事实变化——新增：'.(implode('；', $record->equivalence['new_facts'] ?? []) ?: '无')
                    .'。改变或删去的约束：'.(implode('；', $record->equivalence['lost_constraints'] ?? []) ?: '无').'。确认后本章视为核对通过，不重新抽取事件；确认人、理由和每一条会留档。')
                ->schema([Textarea::make('reason')->label('确认原因')->required(), Checkbox::make('confirm')->label('这些变化都是我有意修改的，不影响已提交的事件与状态')->accepted()])
                ->action(fn (FormalRevisionChapter $record, array $data) => $this->perform(fn () => app(FormalRevisionService::class)->confirmFactChanges($record->id, auth()->user(), $data['reason']), '已确认事实变化')),
            Action::make('diff')->label('查看改动')->visible(fn (FormalRevisionChapter $record): bool => $record->candidate_version_id !== null)->modalSubmitAction(false)->modalCancelActionLabel('关闭')->modalWidth('5xl')
                ->schema(fn (FormalRevisionChapter $record): array => [
                    // 事实核对分项展示：新增事实与丢失约束是需要处理的问题，单独列出并标红。
                    TextEntry::make('check')->label('事实核对结论')->state($record->equivalence['summary'] ?? '尚未核对'),
                    TextEntry::make('new_facts')->label('新增事实')->state($record->equivalence['new_facts'] ?? [])->listWithLineBreaks()->bulleted()->color('danger')->visible(($record->equivalence['new_facts'] ?? []) !== []),
                    TextEntry::make('lost_constraints')->label('丢失约束')->state($record->equivalence['lost_constraints'] ?? [])->listWithLineBreaks()->bulleted()->color('danger')->visible(($record->equivalence['lost_constraints'] ?? []) !== []),
                    TextEntry::make('literary_issues')->label('文字问题')->state($record->equivalence['literary_issues'] ?? [])->listWithLineBreaks()->bulleted()->color('warning')->visible(($record->equivalence['literary_issues'] ?? []) !== []),
                    ViewComponent::make('filament.resources.novels.partials.body-diff')->viewData(fn (): array => [
                        'diff' => BodyVersionDiff::paragraphs(ChapterVersion::findOrFail($record->source_version_id)->body, ChapterVersion::findOrFail($record->candidate_version_id)->body),
                        'oldLabel' => '正式稿 #'.$record->source_version_id, 'newLabel' => '润色稿 #'.$record->candidate_version_id]),
                ]),
        ]);
    }

    /** 发起、批量执行、提交候选、切换与放弃。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label('发起正式稿修订')->visible(fn (): bool => ! in_array($this->revision()?->status, ['open', 'ready'], true) && $this->getRecord()->chapters_done > 0)
                ->schema([
                    TextInput::make('from')->label('起始章')->integer()->minValue(1)->maxValue(fn (): int => $this->getRecord()->chapters_done)->default(1)->required(),
                    TextInput::make('to')->label('结束章')->integer()->minValue(1)->maxValue(fn (): int => $this->getRecord()->chapters_done)->default(fn (): int => min(10, $this->getRecord()->chapters_done))->required(),
                    Textarea::make('intent')->label('修订意图')->placeholder('例如：去掉反复出现的“按双人流程请小林到场”和清场倒计时式结尾')->required(),
                    TextInput::make('request_limit')->label('本次最多请求次数')->integer()->minValue(1)->helperText('每章约2次（建议1次、核对1次）；不同意某章时可重新核对，会多用请求。')->required()->default(20),
                    Checkbox::make('accept')->label('我知道修订期间不能写新章，请求按模型价格计费')->accepted(),
                ])->action(fn (array $data) => $this->perform(fn () => app(FormalRevisionService::class)->start($this->getRecord(), auth()->user(), (int) $data['from'], (int) $data['to'], $data['intent'], (int) $data['request_limit']), '已发起正式稿修订')),
            Action::make('suggestAll')->label('为待建议章节生成建议')->visible(fn (): bool => $this->open())->requiresConfirmation()
                ->modalDescription(fn (): string => '将为 '.$this->pending('pending').' 章各发送1次请求。')
                ->action(fn () => $this->perform(fn () => app(FormalRevisionService::class)->dispatch($this->ids(['pending']), 'suggest'), '已排队')),
            Action::make('verifyAll')->label('核对全部待核对章节')->visible(fn (): bool => $this->open())->requiresConfirmation()
                ->modalDescription(fn (): string => '将为 '.$this->pending('decided').' 章各发送1次请求。')
                ->action(fn () => $this->perform(fn () => app(FormalRevisionService::class)->dispatch($this->ids(['decided']), 'verify'), '已排队')),
            Action::make('commit')->label('提交候选基线')->visible(fn (): bool => $this->open())->requiresConfirmation()
                ->modalDescription('各章已核对或无改动后，写入候选基线并原样重挂下游章节，再回放验证整条候选链。不调用AI。')
                ->action(fn () => $this->perform(fn () => app(FormalRevisionService::class)->commitCandidate($this->revision(), auth()->user()), '候选基线已提交并验证')),
            Action::make('switch')->label('切换为正式稿')->color('danger')->visible(fn (): bool => $this->revision()?->status === 'ready')
                ->schema([Textarea::make('reason')->label('切换原因')->required(), Checkbox::make('confirm')->label('我已查看各章改动，确认切换；旧基线只读保留')->accepted()])
                ->action(fn (array $data) => $this->perform(fn () => app(FormalRevisionService::class)->switchTo($this->revision(), auth()->user(), $data['reason']), '已切换为修订后的正式稿')),
            Action::make('abandon')->label('放弃修订')->color('gray')->visible(fn (): bool => in_array($this->revision()?->status, ['open', 'ready'], true))
                ->schema([Textarea::make('reason')->label('放弃原因')->required()])
                ->action(fn (array $data) => $this->perform(fn () => app(FormalRevisionService::class)->abandon($this->revision(), $data['reason']), '已放弃修订')),
            Action::make('baselines')->label('提交与基线')->color('gray')->url(fn (): string => NovelResource::getUrl('baselines', ['record' => $this->getRecord()])),
        ];
    }

    /** 每条建议一个取舍选择；不合格的建议只能拒绝。
     * @return list<Section>
     */
    private function decisionFields(FormalRevisionChapter $record): array
    {
        return array_map(fn (array $item): Section => Section::make($item['id'].' · 第'.$item['paragraph'].'段 · '.$item['problem'])->compact()->schema([
            TextEntry::make('quote_'.$item['id'])->label('原句')->state($item['quote']),
            TextEntry::make('replacement_'.$item['id'])->label('改为')->state($item['replacement']),
            Select::make($item['id'])->label($item['valid'] ? '取舍' : '不合格：'.$item['invalid_reason'])->native()->required()
                ->options($item['valid'] ? ['accept' => '接受', 'reject' => '拒绝', 'exception' => '有意保留'] : ['reject' => '拒绝'])
                ->default($record->decisions[$item['id']] ?? 'reject'),
        ]), $record->suggestions ?? []);
    }

    /** 当前任务是否进行中。 */
    private function open(): bool
    {
        return $this->revision()?->status === 'open';
    }

    /** 某状态的章数。 */
    private function pending(string $status): int
    {
        return count($this->ids([$status]));
    }

    /** 当前任务中某些状态的章节记录编号。
     * @param  list<string>  $statuses
     * @return list<int>
     */
    private function ids(array $statuses): array
    {
        return FormalRevisionChapter::where('formal_revision_id', $this->revision()?->id ?? 0)->whereIn('status', $statuses)->orderBy('chapter_number')->pluck('id')->all();
    }

    /** 业务错误以中文通知展示；成功后刷新页面。 */
    private function perform(callable $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->success()->title($success)->send();
            $this->redirect(NovelResource::getUrl('formalRevisions', ['record' => $this->getRecord()]), navigate: false);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
