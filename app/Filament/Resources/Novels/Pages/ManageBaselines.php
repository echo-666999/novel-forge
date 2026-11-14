<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Support\HelpHint;
use App\Models\CanonicalBaseline;
use App\Models\CanonicalCommit;
use App\Models\ChapterVersion;
use App\Services\Pipeline\CanonicalBaselines;
use App\Services\Pipeline\FormalChapterExport;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** UI15 提交与基线：查看各基线的提交链，回放和导出任一基线，切换到已验证的候选基线或放弃候选。 */
class ManageBaselines extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 复用小说私有后台权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '提交与基线';

    /** 子导航排序，放在故事状态回放之前。 */
    protected static ?int $navigationSort = 17;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '交付';

    /** 中文页面标题。 */
    protected static ?string $title = '提交与基线';

    /** 解析当前小说并验证查看权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 说明基线含义，并列出活动基线概况。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('正式基线')->compact()->description('基线是从第1章起连续的正式提交链。')->headerActions([HelpHint::action('正式稿修订在候选基线中进行，验证通过后才能切换；切换后旧基线只读保留，仍可回放和导出。切换后请在系统健康页确认检索投影，必要时重建索引。')])->schema([
            TextEntry::make('active')->label('活动基线')->state(function (): string {
                $active = app(CanonicalBaselines::class)->current($this->getRecord());

                return $active === null ? '尚无正式提交' : '#'.$active->id.' · '.$this->getRecord()->chapters_done.' 章 · 全书状态序号 '.$this->getRecord()->state_version;
            }),
        ]), EmbeddedTable::make()]);
    }

    /** 本书全部基线；切换与放弃只对候选开放，界面不提供绕过验证的切换。 */
    public function table(Table $table): Table
    {
        return $table->query(CanonicalBaseline::where('novel_id', $this->getRecord()->id))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('编号')->prefix('#'),
            TextColumn::make('status')->label('状态')->formatStateUsing(fn (string $state): string => CanonicalBaseline::statusLabel($state))->badge()
                ->color(fn (string $state): string => match ($state) {
                    'active' => 'success', 'verified' => 'info', 'candidate' => 'warning', default => 'gray',
                }),
            TextColumn::make('purpose')->label('用途')->formatStateUsing(fn (string $state): string => $state === 'initial' ? '初始' : '正式稿修订'),
            TextColumn::make('fork_chapter')->label('起始章')->prefix('第')->suffix('章'),
            TextColumn::make('parent_id')->label('父基线')->prefix('#')->placeholder('无'),
            TextColumn::make('changed')->label('改动章节')->state(fn (CanonicalBaseline $record): string => implode('、', $record->commits()->where('origin', 'revision')->orderBy('chapter_number')->pluck('chapter_number')->all()) ?: '无'),
            TextColumn::make('reason')->label('原因')->limit(40),
            TextColumn::make('activated_at')->label('启用时间')->dateTime('Y-m-d H:i')->placeholder('未启用'),
        ])->recordActions([
            Action::make('chain')->label('提交链')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->schema(fn (CanonicalBaseline $record): array => [
                RepeatableEntry::make('commits')->hiddenLabel()->state($this->chainRows($record))
                    ->table([TableColumn::make('章'), TableColumn::make('来源'), TableColumn::make('提交'), TableColumn::make('正文版本'), TableColumn::make('状态序号'), TableColumn::make('章名')])
                    ->schema([TextEntry::make('chapter'), TextEntry::make('origin'), TextEntry::make('commit'), TextEntry::make('version'), TextEntry::make('state'), TextEntry::make('title')]),
            ]),
            Action::make('export')->label('导出')->schema([
                Select::make('format')->label('文件格式')->options(['txt' => 'TXT（UTF-8）', 'md' => 'Markdown（UTF-8）'])->default('txt')->required()->native(),
            ])->action(function (CanonicalBaseline $record, array $data): ?StreamedResponse {
                try {
                    abort_unless($record->novel_id === $this->getRecord()->id, 403);
                    $export = app(FormalChapterExport::class)->build($this->getRecord()->id, app(CanonicalBaselines::class)->length($record), $data['format'], $record->id);
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->danger()->title('导出未执行')->body($exception->getMessage())->send();

                    return null;
                }

                return response()->streamDownload(function () use ($export): void {
                    echo $export['content'];
                }, 'baseline-'.$record->id.'-'.$export['filename'], ['Content-Type' => $export['mime'], 'Cache-Control' => 'private, no-store']);
            }),
            Action::make('verify')->label('验证')->visible(fn (CanonicalBaseline $record): bool => $record->status === 'candidate')
                ->requiresConfirmation()->modalDescription('回放候选基线全部正式事件、核对伏笔证据，不调用AI。')
                ->action(fn (CanonicalBaseline $record) => $this->perform(fn () => app(CanonicalBaselines::class)->verify($record), '候选基线已验证')),
            Action::make('switch')->label('切换为活动基线')->color('danger')->visible(fn (CanonicalBaseline $record): bool => $record->status === 'verified')
                ->modalHeading('切换正式基线')->modalDescription(fn (CanonicalBaseline $record): string => '切换后第'.$record->fork_chapter.'章起的正式正文改用此基线（改动章节：'
                    .(implode('、', $record->verification['changed_chapters'] ?? []) ?: '无').'）。当前活动基线只读保留，可回放和导出。切换前会重新验证。')
                ->schema([Textarea::make('reason')->label('切换原因')->required(), Checkbox::make('confirm')->label('我已查看逐章差异，确认切换')->accepted()])
                ->action(fn (CanonicalBaseline $record, array $data) => $this->perform(fn () => app(CanonicalBaselines::class)->switchTo($record, auth()->user(), $data['reason']), '已切换正式基线')),
            Action::make('abandon')->label('放弃')->color('gray')->visible(fn (CanonicalBaseline $record): bool => in_array($record->status, ['candidate', 'verified'], true))
                ->schema([Textarea::make('reason')->label('放弃原因')->required()])
                ->action(fn (CanonicalBaseline $record, array $data) => $this->perform(fn () => app(CanonicalBaselines::class)->abandon($record, $data['reason']), '已放弃候选基线')),
        ]);
    }

    /** 基线提交链的展示行。
     * @return list<array<string, mixed>>
     */
    private function chainRows(CanonicalBaseline $baseline): array
    {
        abort_unless($baseline->novel_id === $this->getRecord()->id, 403);

        return array_map(fn (CanonicalCommit $commit): array => ['chapter' => '第'.$commit->chapter_number.'章',
            'origin' => match ($commit->origin) {
                'revision' => '正式稿修订', 'rebase' => '原样重挂', default => '评审提交',
            }, 'commit' => '#'.$commit->id.($commit->baseline_id === $baseline->id ? '' : '（沿用父基线）'), 'version' => '#'.$commit->version_id,
            'state' => $commit->state_version, 'title' => ChapterVersion::whereKey($commit->version_id)->value('title')], app(CanonicalBaselines::class)->chain($baseline));
    }

    /** 业务错误以中文通知展示；成功后刷新页面。 */
    private function perform(callable $operation, string $success): void
    {
        try {
            $operation();
            Notification::make()->success()->title($success)->send();
            $this->redirect(NovelResource::getUrl('baselines', ['record' => $this->getRecord()]), navigate: false);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
