<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Support\HelpHint;
use App\Models\AcceptanceBatch;
use App\Models\AcceptanceEvidence;
use App\Services\Pipeline\AcceptanceWorkbench as Workbench;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** 验收批次入口，机器核验、作者评分和明确认可分开操作。 */
class AcceptanceWorkbench extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 小说权限入口。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '成品验收';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 15;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '交付';

    /** 中文后台标题。 */
    protected static ?string $title = '验收工作台';

    /** 记录归属校验。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 说明既有正式提交和文学认可之间的边界。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('来源冻结与人工结论')->schema([
            TextEntry::make('scope')->label('验收规则')->state('批次冻结当前连续正式版本，不是生成前契约。机器只核验可计算项目；完整验收还需要基础外部证据、类别专项证据及逐章七项人工评分。每项至少3分、均分至少3.8，低分须填写修复期待，通过项也要原文依据。仅负责人能写入，历史不可删除。'),
        ]), ...$this->progressSections(), EmbeddedTable::make()]);
    }

    /** 当前未关闭的最新批次：章节评分矩阵与外部证据待办，只读展示，写入仍走各自动作。
     * @return list<Section>
     */
    private function progressSections(): array
    {
        $batch = $this->openBatch();
        if ($batch === null) {
            return [];
        }
        $progress = app(Workbench::class)->progress($batch);
        $status = fn (?bool $pass): string => $pass === null ? '○ 待处理' : ($pass ? '✓ 通过' : '✗ 未通过');
        $rows = array_map(fn (array $chapter): array => [
            'chapter' => '第'.$chapter['number'].'章 · '.$chapter['title'],
            ...collect(Workbench::DIMENSIONS)->mapWithKeys(fn (string $label, string $key): array => [$key => $chapter['scores'][$key] ?? '-'])->all(),
            'sum' => $chapter['sum'] ?? '-', 'result' => $chapter['pass'] === null ? '○ 未评分' : ($chapter['pass'] ? '✓ 达标' : '✗ 未达标'),
            'url' => NovelResource::getUrl('acceptanceReading', ['record' => $this->getRecord(), 'batch' => $batch->id, 'chapter' => $chapter['number']]),
        ], $progress['chapters']);

        return [
            Section::make('批次 #'.$batch->id.' 逐章评分进度')
                ->description('已评分 '.$progress['rated'].'/'.$progress['total'].' 章，达标 '.$progress['passed'].' 章；机器核验：'.$status($progress['machine']).'。点击章节进入左文右表的阅读评分。')
                ->headerActions([Action::make('startReading')->label($progress['rated'] === 0 ? '开始逐章阅读评分' : '继续逐章阅读评分')
                    ->url(NovelResource::getUrl('acceptanceReading', ['record' => $this->getRecord(), 'batch' => $batch->id]))])
                ->schema([RepeatableEntry::make('matrix')->hiddenLabel()->state($rows)->table([
                    TableColumn::make('章节'), ...array_map(fn (string $label): TableColumn => TableColumn::make($label), array_values(Workbench::DIMENSIONS)),
                    TableColumn::make('合计'), TableColumn::make('结论'), TableColumn::make('操作'),
                ])->schema([
                    TextEntry::make('chapter'),
                    ...array_map(fn (string $key): TextEntry => TextEntry::make($key), array_keys(Workbench::DIMENSIONS)),
                    TextEntry::make('sum'), TextEntry::make('result'),
                    TextEntry::make('url')->url(fn (?string $state): ?string => $state)->formatStateUsing(fn (): string => '阅读评分'),
                ])]),
            Section::make('外部证据待办')->compact()->description('在系统外核验后，用“导入外部证据”记录。')->headerActions([HelpHint::action('以下项目需要你在系统外核验后，通过表格中的“导入外部证据”记录；系统不会代为判定。', 'evidenceHelp')])
                ->schema([RepeatableEntry::make('cases')->hiddenLabel()->state(array_map(fn (array $case): array => [...$case, 'status' => $status($case['pass'])], $progress['cases']))->schema([
                    TextEntry::make('label')->label('用例'), TextEntry::make('status')->label('状态'),
                    TextEntry::make('guidance')->label('如何取证')->columnSpanFull(),
                ])->columns(2)]),
        ];
    }

    /** 当前小说最新一个未关闭批次。 */
    private function openBatch(): ?AcceptanceBatch
    {
        return AcceptanceBatch::where('novel_id', $this->getRecord()->id)->where('status', '!=', 'accepted')->latest('id')->first();
    }

    /** 表格按书隔离，关闭后保留只读详情和证据下载。 */
    public function table(Table $table): Table
    {
        $editable = fn (AcceptanceBatch $record): bool => $record->actor_id === Filament::auth()->id() && $record->status !== 'accepted';

        return $table->query(AcceptanceBatch::where('novel_id', $this->getRecord()->id))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('验收编号')->prefix('#')->color('gray'), TextColumn::make('kind')->label('类别')->formatStateUsing(fn ($state) => self::kinds()[$state]),
            TextColumn::make('end_chapter')->label('首章至章号'), TextColumn::make('status')->label('结论状态')->formatStateUsing(fn ($state) => self::statuses()[$state]),
            TextColumn::make('rating_progress')->label('人工评分')->state(function (AcceptanceBatch $record): string {
                $progress = app(Workbench::class)->progress($record);

                return '已评 '.$progress['rated'].'/'.$progress['total'].' · 达标 '.$progress['passed'];
            }),
            TextColumn::make('actor_id')->label('负责人'), TextColumn::make('created_at')->label('冻结时间')->since()->dateTimeTooltip('Y-m-d H:i:s')->color('gray'),
        ])->filters([SelectFilter::make('kind')->label('类别')->options(self::kinds()), SelectFilter::make('status')->label('状态')->options(self::statuses())])
            ->recordActions([
                Action::make('inspect')->label('缺口与证据')->modalSubmitAction(false)->schema(function (AcceptanceBatch $record): array {
                    try {
                        $gaps = app(Workbench::class)->gaps($record);
                    } catch (InvalidArgumentException|ValidationException|ModelNotFoundException $error) {
                        $gaps = ['来源核验失败，请检查正式版本与证据记录'];
                    }

                    return [TextEntry::make('hash')->label('冻结摘要')->state($record->snapshot_hash),
                        TextEntry::make('gaps')->label('未通过项目')->state($gaps ?: ['必需证据已齐；仍需负责人明确关闭通过'])->bulleted(),
                        RepeatableEntry::make('evidence')->label('完整历史')->state(AcceptanceEvidence::where('batch_id', $record->id)->orderBy('id')->get()->map(fn ($row): array => ['id' => $row->id, 'kind' => $row->kind, 'key' => $row->case_key, 'actor' => $row->actor_id, 'payload' => json_encode($row->payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)])->all())->schema([
                            TextEntry::make('id')->label('证据号'), TextEntry::make('kind')->label('证据类型')->formatStateUsing(fn ($state): string => ['machine' => '机器核验', 'human' => '人工评分', 'external' => '外部证据', 'close' => '明确认可'][$state] ?? $state), TextEntry::make('key')->label('用例'), TextEntry::make('actor')->label('记录人'), TextEntry::make('payload')->label('原始结论与证据')->columnSpanFull(),
                        ])];
                }),
                Action::make('machine')->label('执行机器核验')->visible($editable)->requiresConfirmation()->modalDescription('只读取冻结正式版本并检查导出、回放、质量规则和评审引用，不调用AI，不自动转为成品认可。')->action(function (AcceptanceBatch $record): void {
                    app(Workbench::class)->machine($this->getRecord()->id, $record->id, Filament::auth()->user());
                    Notification::make()->success()->title('机器结果已追加，请查看缺口')->send();
                }),
                Action::make('readAndRate')->label('逐章阅读评分')->url(fn (AcceptanceBatch $record): string => NovelResource::getUrl('acceptanceReading', ['record' => $this->getRecord(), 'batch' => $record->id])),
                Action::make('rate')->label('弹窗评分（备用）')->visible($editable)->schema(fn (AcceptanceBatch $record): array => $this->ratingFields($record))->action(function (AcceptanceBatch $record, array $data, Schema $schema): void {
                    try {
                        app(Workbench::class)->rate($this->getRecord()->id, $record->id, Filament::auth()->user(), $data);
                    } catch (ValidationException $error) {
                        // 业务字段错误必须归入弹窗状态路径；通知让长表单底部也能立即看见失败原因。
                        Notification::make()->danger()->title('人工评分未保存')->body(implode('；', $error->validator->errors()->all()))->send();
                        $messages = [];
                        foreach ($error->errors() as $field => $errors) {
                            $messages[$schema->getStatePath().'.'.$field] = $errors;
                        }
                        throw ValidationException::withMessages($messages);
                    }
                    Notification::make()->success()->title('人工评分已追加，未自动认可')->send();
                }),
                Action::make('import')->label('导入外部证据')->visible($editable)->schema([
                    Select::make('case_key')->label('必需用例')->options(fn (AcceptanceBatch $record): array => $record->snapshot['manual_cases'])->required()->live()
                        ->helperText(fn (Get $get): ?string => Workbench::CASE_GUIDANCE[$get('case_key')] ?? '选择用例后显示取证说明。'), Select::make('result')->label('核验结论')->options(['pass' => '通过', 'fail' => '失败'])->required(),
                    TextInput::make('source')->label('证据出处及执行时间')->required()->maxLength(2000),
                    Textarea::make('report')->label('脱敏测试或核验报告（勿粘贴密钥）')->required()->maxLength(100000), Textarea::make('reason')->label('核验说明')->required()->maxLength(10000),
                    Checkbox::make('verified')->label('我已核验此证据对应本批次来源；这不是后台自动执行结果')->accepted(),
                ])->action(function (AcceptanceBatch $record, array $data): void {
                    app(Workbench::class)->import($this->getRecord()->id, $record->id, Filament::auth()->user(), $data);
                    Notification::make()->success()->title('外部证据已追加')->send();
                }),
                Action::make('close')->label('确认关闭通过')->visible($editable)->schema([
                    Textarea::make('reason')->label('最终验收结论')->required()->maxLength(10000), Checkbox::make('acknowledged')->label('我确认已完成该范围人工验收，明确认可；关闭后不可改写')->accepted(),
                ])->action(function (AcceptanceBatch $record, array $data): void {
                    app(Workbench::class)->close($this->getRecord()->id, $record->id, Filament::auth()->user(), $data['reason'], (bool) ($data['acknowledged'] ?? false));
                    Notification::make()->success()->title('验收批次已由负责人认可')->send();
                }),
                Action::make('export')->label('下载证据包')->requiresConfirmation()->modalDescription('JSON包含冻结正式正文、原始输入、全部评分与外部证据，请按私有作品保管。')->action(function (AcceptanceBatch $record): StreamedResponse {
                    $json = json_encode(app(Workbench::class)->export($this->getRecord()->id, $record->id), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                    return response()->streamDownload(function () use ($json): void {
                        echo $json;
                    }, 'acceptance-'.$record->id.'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
                }),
            ])->emptyStateHeading('尚无验收批次')->emptyStateDescription('创建批次只冻结正式来源，不宣称通过任何人工验收。');
    }

    /** 批次创建不发送模型请求，缺正式章时由来源校验明确拒绝。 */
    protected function getHeaderActions(): array
    {
        return [Action::make('createBatch')->label('创建验收批次')->schema([
            Select::make('kind')->label('验收类别')->options(self::kinds())->required(), TextInput::make('end')->label('从第1章至正式章号')->integer()->minValue(1)->maxValue(1000)->required(),
            Textarea::make('reason')->label('验收目的与范围')->required()->maxLength(10000), Checkbox::make('acknowledged')->label('确认冻结当前来源，不追认生成前契约，也不自动通过')->accepted(),
        ])->action(function (array $data): void {
            try {
                app(Workbench::class)->create($this->getRecord()->id, Filament::auth()->user(), $data['kind'], (int) $data['end'], $data['reason']);
                Notification::make()->success()->title('验收批次已冻结')->send();
            } catch (InvalidArgumentException $error) {
                Notification::make()->danger()->title('无法建立批次')->body($error->getMessage())->send();
            }
        }), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }

    /** 评分不预填通过分数或阅读确认，避免无意认可。 */
    private function ratingFields(AcceptanceBatch $record): array
    {
        $fields = [Select::make('chapter')->label('冻结章节')->options(collect($record->snapshot['manifest'])->mapWithKeys(fn ($entry): array => [$entry['number'] => '第'.$entry['number'].'章 · '.$entry['title']])->all())->required()];
        foreach (Workbench::DIMENSIONS as $key => $label) {
            $fields[] = TextInput::make('scores.'.$key)->label($label.'（1～5）')->integer()->minValue(1)->maxValue(5)->required();
        }

        return [...$fields, Textarea::make('quote')->label('该章正式原文依据')->helperText('支持多处摘录，用空行分隔；忽略复制产生的空格和换行，文字与标点须匹配所选章的冻结版本。')->required()->maxLength(10000), Textarea::make('reason')->label('评分原因')->required()->maxLength(10000),
            Textarea::make('repair')->label('修复期待（低于3分必填）')->maxLength(10000), Select::make('blocking')->label('是否存在严重冲突、知情越界、因果断裂或重复拼接')->options([0 => '没有发现', 1 => '存在阻塞'])->required(),
            Checkbox::make('read_confirmed')->label('我已完整阅读本章，并明确记录本人评分')->accepted()];
    }

    /** 类别仅组织证据，不因类别名称自动判定专项通过。 */
    private static function kinds(): array
    {
        return ['ten' => '前十章', 'hundred' => '前百章', 'recovery' => '恢复', 'security' => '安全'];
    }

    /** 状态明确区分失败、待人工和明确认可。 */
    private static function statuses(): array
    {
        return ['not_run' => '未执行', 'human_pending' => '机器通过，待人工证据', 'system_pass' => '证据齐全，待明确认可', 'rejected' => '存在未通过证据', 'accepted' => '负责人已认可'];
    }
}
