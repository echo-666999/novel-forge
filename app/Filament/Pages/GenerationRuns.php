<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\ChapterGenerationRun;
use App\Models\Novel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** 跨小说章节运行索引，恢复入口回到原书并沿用其冻结输入和授权。 */
class GenerationRuns extends Page implements HasTable
{
    use InteractsWithTable;

    /** 侧栏与页面标题明确只包含章节生成运行。 */
    protected static ?string $title = '章节生成运行中心';

    /** 侧栏图标。 */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '生产与质量';

    /** 在小说管理之后展示跨书运行索引。 */
    protected static ?int $navigationSort = 2;

    /** 不把历史异常折叠为当前健康，提示读者进入原运行核对。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /** 按书、状态和创建日期筛选，采用关联预加载及分页。 */
    public function table(Table $table): Table
    {
        return $table->query(ChapterGenerationRun::with('chapter.novel'))->defaultSort('id', 'desc')
            ->description('保留全部历史运行；暂停、恢复与生成范围在各书的运行台确认。')
            ->columns([
                TextColumn::make('chapter.novel.title')->label('小说')->searchable()->weight('bold')
                    ->description(fn (ChapterGenerationRun $record): string => '第'.$record->chapter->number.'章'),
                TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn (string $state): string => self::statuses()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success', 'running', 'pending' => 'info', 'paused' => 'warning', 'error', 'failed' => 'danger', default => 'gray'
                    }),
                TextColumn::make('next_step')->label('下一步骤')->placeholder('无')->formatStateUsing(fn (?string $state): string => match ($state) {
                    'fine' => '章节细纲', 'draft' => '正文生成', 'check' => '独立推进检查', 'revise' => '推进点修订', 'length_repair' => '定点扩写', default => (string) $state
                }),
                TextColumn::make('stop_reason')->label('停止原因')->placeholder('无')->wrap()->lineClamp(2),
                TextColumn::make('created_at')->label('创建时间')->since()->dateTimeTooltip('Y-m-d H:i:s')->sortable()->color('gray'),
                TextColumn::make('requests_used')->label('已用 / 授权请求')->formatStateUsing(fn (ChapterGenerationRun $record): string => $record->requests_used.' / '.$record->request_limit)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')->label('运行编号')->sortable()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('parent_run_id')->label('父运行')->placeholder('首次运行')->color('gray')->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('novel')->label('小说')->options(fn (): array => Novel::orderByDesc('id')->pluck('title', 'id')->all())->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, $id): Builder => $q->whereHas('chapter', fn (Builder $chapter): Builder => $chapter->where('novel_id', $id)))),
                SelectFilter::make('status')->label('状态')->options(self::statuses()),
                Filter::make('date')->label('创建日期')->schema([DatePicker::make('from')->label('起始日期'), DatePicker::make('to')->label('截至日期')->afterOrEqual('from')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))->when($data['to'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
            ])->recordActions([
                Action::make('run')->label('运行详情与恢复')->url(fn (ChapterGenerationRun $record): string => NovelResource::getUrl('run', ['record' => $record->chapter->novel_id])),
                Action::make('chapter')->label('正文与来源')->url(fn (ChapterGenerationRun $record): string => NovelResource::getUrl('content', ['record' => $record->chapter->novel_id, 'chapter' => $record->chapter->number])),
            ])->emptyStateHeading('没有符合条件的章节运行')->emptyStateDescription('可调整筛选，或从小说工作台创建章节候选。');
    }

    /** 状态名称不将候选检查通过误称为正式提交。
     * @return array<string, string>
     */
    private static function statuses(): array
    {
        return ['pending' => '等待运行', 'running' => '运行中', 'paused' => '已暂停', 'error' => '失败', 'completed' => '生成流程完成', 'awaiting_review' => '等待评审'];
    }
}
