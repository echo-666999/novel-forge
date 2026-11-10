<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\NovelExport;
use App\Services\Pipeline\NovelExportLifecycle;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 私有导出中心，队列文件与现有同步下载并存，任务按账号隔离。 */
class ExportCenter extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 小说资源的访问权限入口。 */
    protected static string $resource = NovelResource::class;

    /** 中文页面名称。 */
    protected static ?string $title = '正式导出中心';

    /** 查询限定当前可访问小说。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 排队不伪装成文件可用，明确显示冻结与保留规则。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('冻结清单与私有文件')->schema([
            TextEntry::make('scope')->label('导出规则')->state('提交时预检连续正式章并冻结书名、来源和文件摘要；文件由专用导出队列生成。完成后保留7天，过期拒绝下载；删除只移除派生文件，历史清单保留。重新生成沿用原书名和原正式版本，不改用当前候选。这里只显示本账号任务。'),
        ]), EmbeddedTable::make()]);
    }

    /** 列表状态、筛选及操作只作用于当前账号和小说。 */
    public function table(Table $table): Table
    {
        return $table->query(NovelExport::where('novel_id', $this->getRecord()->id)->where('actor_id', Filament::auth()->id()))->defaultSort('id', 'desc')->poll('5s')->columns([
            TextColumn::make('id')->label('任务编号'), TextColumn::make('end_chapter')->label('首章至章号'), TextColumn::make('format')->label('格式'),
            TextColumn::make('status')->label('文件状态')->formatStateUsing(fn ($state, NovelExport $record): string => $record->deleted_at !== null ? '派生文件已移除' : ($record->status === 'ready' && $record->expires_at?->isPast() ? '已过期，等待清理' : self::statuses()[$state])),
            TextColumn::make('byte_size')->label('文件字节数')->placeholder('未生成'), TextColumn::make('created_at')->label('申请时间')->dateTime(),
            TextColumn::make('expires_at')->label('保留截止')->dateTime()->placeholder('完成后起算'),
        ])->filters([
            SelectFilter::make('format')->label('格式')->options(['txt' => 'TXT', 'md' => 'Markdown']),
            SelectFilter::make('status')->label('状态')->options(self::statuses())
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'expired' => $query->where(fn (Builder $q): Builder => $q->where('status', 'expired')->orWhere(fn (Builder $ready): Builder => $ready->where('status', 'ready')->where('expires_at', '<=', now()))),
                    'ready' => $query->where('status', 'ready')->where('expires_at', '>', now()),
                    null, '' => $query, default => $query->where('status', $data['value']),
                }),
            Filter::make('date')->label('申请日期')->schema([DatePicker::make('from')->label('开始日期'), DatePicker::make('to')->label('结束日期')->afterOrEqual('from')])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))->when($data['to'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
        ])->recordActions([
            Action::make('inspect')->label('来源与执行记录')->modalSubmitAction(false)->modalCancelActionLabel('关闭')->schema(fn (NovelExport $record): array => [
                TextEntry::make('source')->label('冻结来源清单')->state(collect($record->snapshot['manifest'])->map(fn ($entry): string => '第'.$entry['number'].'章 · 正式正文记录 #'.$entry['version_id'].' · '.$entry['title'])->all())->bulleted(),
                TextEntry::make('hash')->label('文件SHA-256')->state($record->snapshot['content_hash']), TextEntry::make('error')->label('失败原因')->state($record->error)->placeholder('无'),
                TextEntry::make('history')->label('追加审计')->state(collect($record->history)->map(fn ($entry): string => ($entry['at'] ?? '').' · '.$entry['action'].' · 账号'.($entry['actor_id'] ?? '系统'))->all())->bulleted(),
            ]),
            Action::make('download')->label('下载文件')->visible(fn (NovelExport $record): bool => $record->status === 'ready' && $record->deleted_at === null && $record->expires_at?->isFuture())
                ->action(function (NovelExport $record): StreamedResponse {
                    $file = app(NovelExportLifecycle::class)->download($this->getRecord()->id, $record->id, Filament::auth()->user());

                    return response()->streamDownload(function () use ($file): void {
                        echo $file['content'];
                    }, $file['filename'], ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, no-store']);
                }),
            Action::make('regenerate')->label('按原清单重新生成')->visible(fn (NovelExport $record): bool => ! in_array($record->status, ['queued', 'building'], true))->requiresConfirmation()->modalDescription('创建新任务，沿用原书名、格式、正式版本和摘要；旧任务与失败记录保留，不调用AI。')->action(function (NovelExport $record): void {
                $next = app(NovelExportLifecycle::class)->regenerate($this->getRecord()->id, $record->id, Filament::auth()->user());
                $this->notifyQueued($next);
            }),
            Action::make('remove')->label('删除派生文件')->visible(fn (NovelExport $record): bool => ! in_array($record->status, ['queued', 'building'], true) && $record->deleted_at === null)
                ->requiresConfirmation()->modalDescription('只删除该任务的私有导出文件，正式正文和审计不会删除；以后可按原清单重新生成。')->action(function (NovelExport $record): void {
                    app(NovelExportLifecycle::class)->remove($this->getRecord()->id, $record->id, Filament::auth()->user());
                    Notification::make()->success()->title('派生文件已移除，来源清单仍保留')->send();
                }),
        ])->emptyStateHeading('尚无本账号导出任务')->emptyStateDescription('创建任务先检查正式来源，不能跳过缺失章节导出。');
    }

    /** 创建任务固定连续范围，缺章和损坏来源以可读提示返回。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('createExport')->label('创建导出任务')->schema([
            TextInput::make('end')->label('从第1章至正式章号')->integer()->minValue(1)->maxValue(fn (): int => max(1, $this->getRecord()->chapters_done))->required(),
            Select::make('format')->label('文件格式')->options(['txt' => 'UTF-8 TXT', 'md' => 'Markdown'])->required(),
        ])->action(function (array $data): void {
            try {
                $record = app(NovelExportLifecycle::class)->request($this->getRecord()->id, Filament::auth()->user(), (int) $data['end'], $data['format']);
                $this->notifyQueued($record);
            } catch (InvalidArgumentException $error) {
                Notification::make()->danger()->title('导出预检未通过')->body($error->getMessage())->send();
            }
        }), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** 入队失败不能显示成功或假定有活跃消费者。 */
    private function notifyQueued(NovelExport $record): void
    {
        $notification = Notification::make();
        if ($record->status === 'failed') {
            $notification->danger()->title('导出入队失败')->body($record->error)->send();
        } else {
            $notification->success()->title('导出任务已排队，等待专用消费者')->send();
        }
    }

    /** 失败和过期明确展示。 */
    private static function statuses(): array
    {
        return ['queued' => '排队中', 'building' => '生成中', 'ready' => '可下载', 'failed' => '失败', 'expired' => '已过期或移除'];
    }
}
