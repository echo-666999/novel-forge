<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\ContextManifest;
use App\Models\CostLog;
use App\Services\Pipeline\PlanningJson;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

/** 只读上下文检查器：展示当时冻结清单，不使用当前状态重建历史请求。 */
class ViewContextManifests extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 使用小说资源的后台权限。 */
    protected static string $resource = NovelResource::class;

    /** 作者可见标题。 */
    protected static ?string $title = '上下文检查器';

    /** 当前清单仅由地址选择，并在读取时校验小说归属。 */
    #[Locked]
    public ?int $manifestId = null;

    /** 严格绑定当前小说的查看权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->manifestId = request()->filled('manifest') ? request()->integer('manifest') : null;
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 说明历史兼容及计量边界，避免把估算伪称精确Token数。 */
    public function content(Schema $schema): Schema
    {
        // 长清单使用独立页面导航，避免大型嵌套弹窗的客户端渲染问题。
        if ($this->manifestId !== null) {
            $manifest = ContextManifest::where('novel_id', $this->getRecord()->id)->findOrFail($this->manifestId);

            return $schema->components($this->details($manifest));
        }

        return $schema->components([Section::make('请求上下文清单')->description('仅新运行保存清单，旧请求不追补。就绪表示组装通过，是否调用请看关联流水。Token使用保守估算，未校准模型分词误差。')->schema([]), EmbeddedTable::make()]);
    }

    /** 按章筛选当前小说清单，阻塞项也保留且不会产生模型调用。 */
    public function table(Table $table): Table
    {
        return $table->query(ContextManifest::where('novel_id', $this->getRecord()->id))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('清单编号'), TextColumn::make('chapter_number')->label('章号'),
            TextColumn::make('purpose')->label('用途')->formatStateUsing(fn (string $state): string => match ($state) {
                'preview' => '验收预览（未发送）', 'fine' => '细纲', 'draft' => '正文', 'revise' => '修订', 'check' => '检查', 'review' => '评审', 'extract' => '提取', default => $state
            }),
            TextColumn::make('status')->label('组装状态')->formatStateUsing(fn (string $state): string => $state === 'ready' ? '就绪（不代表已发送）' : '容量阻塞'),
            TextColumn::make('document.required_capacity')->label('总预算需求（估算）'), TextColumn::make('created_at')->label('组装时间')->dateTime(),
        ])->filters([SelectFilter::make('chapter_number')->label('目标章节')->options(fn (): array => ContextManifest::where('novel_id', $this->getRecord()->id)->distinct()->orderBy('chapter_number')->pluck('chapter_number', 'chapter_number')->all())])
            ->recordActions([Action::make('inspectContext')->label('查看入选与裁剪')->url(fn (ContextManifest $record): string => NovelResource::getUrl('contexts', ['record' => $record->novel_id, 'manifest' => $record->id]))]);
    }

    /** 先验证摘要，再呈现条目及原始提示；不暴露任何认证配置。
     * @return array<Component>
     */
    private function details(ContextManifest $record): array
    {
        if ($record->document_hash !== PlanningJson::hash($record->document)) {
            return [TextEntry::make('error')->label('清单校验失败')->state('清单摘要不一致，拒绝展示可信上下文')];
        }
        $document = $record->document;
        $logs = CostLog::where('novel_id', $record->novel_id)->where('request_parameters->context_manifest_id', $record->id)->pluck('id')->all();

        return [
            TextEntry::make('calls')->label('关联调用流水')->state($logs === [] ? '无关联调用；不得推断为已发送' : implode('、', $logs)),
            TextEntry::make('budget')->label('冻结预算')->state('输入估算 '.$document['input_tokens_estimated'].' + 最大输出 '.$document['output_reserve'].' + 安全余量 '.$document['policy']['safety'].'；本地上限 '.$document['policy']['capacity']),
            TextEntry::make('reason')->label('阻塞原因')->state($document['blocked_reason'] ?? '无'),
            ...array_map(fn (array $entry): Section => Section::make($entry['id'].' · '.($entry['selected'] ? '入选' : '已裁剪'))->collapsed(! in_array($entry['id'], ['confirmed_setting', 'foreshadow_context', 'candidate_roadmap'], true))->schema([
                TextEntry::make('summary_'.$entry['id'])->label('条目内容')->state($entry['summary'] ?? '详见冻结用户输入'),
                TextEntry::make('category_'.$entry['id'])->label('类别与顺序')->state($entry['category'].' / '.($entry['final_order'] ?? '不发送')),
                TextEntry::make('protected_'.$entry['id'])->label('裁剪保护')->state($entry['protected'] ? '不可裁剪' : '可裁剪补充项'),
                TextEntry::make('source_'.$entry['id'])->label('冻结来源版本')->state(PlanningJson::encode($entry['source_version'])),
                TextEntry::make('hash_'.$entry['id'])->label('文本摘要')->state($entry['text_hash']),
                TextEntry::make('tokens_'.$entry['id'])->label('Token估算')->state($entry['tokens_estimated']),
                TextEntry::make('score_'.$entry['id'])->label('检索分数')->state($entry['retrieval_score'] ?? '未使用检索器'),
                TextEntry::make('decision_'.$entry['id'])->label('入选或裁剪原因')->state($entry['selection_reason'] ?? $entry['trim_reason']),
            ]), $document['entries']),
            Section::make('冻结用户输入')->collapsed()->schema([TextEntry::make('prompt')->hiddenLabel()->state($document['user_prompt'])]),
        ];
    }

    /** 返回小说，不触发任何请求。
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('listContexts')->label('清单列表')->url(fn (): string => NovelResource::getUrl('contexts', ['record' => $this->getRecord()])), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }
}
