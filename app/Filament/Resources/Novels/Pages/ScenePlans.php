<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Support\HelpHint;
use App\Models\ChapterGenerationRun;
use App\Models\NovelOutlineChapter;
use App\Models\ScenePlanVersion;
use App\Services\Pipeline\FieldDiff;
use App\Services\Pipeline\ScenePlanning;
use App\Services\Pipeline\SettingVersionDiff;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** 场景草稿编辑、覆盖预检及明确冻结，正式正文没有编辑入口。 */
class ScenePlans extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    /** 沿用小说资源权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '场景规划';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 5;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '规划';

    /** 中文页面标题。 */
    protected static ?string $title = '章节与场景规划';

    /** 加载小说并检查访问权限。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 作者通过新增、删除与重排列表表达拆分或合并，保存形成新版本。 */
    public function content(Schema $schema): Schema
    {
        return $schema->components([Section::make('章节场景规划（可选）')->compact()->description('默认列出已有场景规划或已经写作的章节；章纲的目标和推进点请到“章节大纲”查看。场景规划是可选步骤：不规划时，生成会在细纲阶段自行拆分场景；只有冻结的版本才会进入以后的新运行，草稿不会。每次保存产生新版本；已写作的章节只读，可在“版本与覆盖”里查看写作时实际使用的场景。')->headerActions([HelpHint::action('新增场景或拆分时填写不同目标；合并时将事件与推进点合入保留场景再移除旧项。每次保存产生新版本。冻结检查推进点覆盖和总字数，并进入以后新运行的细纲、正文和检查请求；已经开始的章节只能查看。')])->schema([]), EmbeddedTable::make()]);
    }

    /** 按章列出场景规划版本概况与编辑入口；章纲内容在章节大纲页查看。 */
    public function table(Table $table): Table
    {
        // 先集中同一大纲，再按章序展示，避免多个大纲的同章号交错。
        return $table->query(NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $this->getRecord()->id)))->defaultSort(fn ($query) => $query->orderByDesc('outline_plan_id')->orderBy('chapter_number')->orderBy('id'))->columns([
            // 相同章号可能来自不同大纲，始终展示来源记录以便区分。
            TextColumn::make('outline_plan_id')->label('所属大纲记录编号')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('chapter_number')->label('章号')->sortable(), TextColumn::make('title')->label('章名')->searchable()->wrap(),
            TextColumn::make('scene_status')->label('场景规划')->badge()->state(fn (NovelOutlineChapter $record): string => $this->sceneStatus($record))
                ->color(fn (NovelOutlineChapter $record): string => match (true) {
                    $this->latest($record)?->status === 'frozen' => 'success',
                    $this->started($record) && $this->autoScenes($record) !== null => 'info',
                    $this->latest($record)?->status === 'draft' => 'warning',
                    default => 'gray',
                }),
            // 以下各列描述实际进入生成的场景：作者规划优先，已写作且没有作者冻结版本的章节取细纲自动拆分的场景。
            TextColumn::make('scene_count')->label('场景数')->placeholder('—')
                ->state(fn (NovelOutlineChapter $record): ?string => ($scenes = $this->displayScenes($record)) === null ? null : count($scenes).' 个'),
            TextColumn::make('scene_words')->label('目标字数合计')->placeholder('—')
                ->state(fn (NovelOutlineChapter $record): ?string => ($scenes = $this->displayScenes($record)) === null || ($words = array_sum(array_map(fn (mixed $scene): int => (int) data_get($scene, 'words', 0), $scenes))) === 0 ? null : $words.' 字'),
            TextColumn::make('point_coverage')->label('推进点覆盖')->placeholder('—')->state(fn (NovelOutlineChapter $record): ?string => $this->coverage($record)),
            TextColumn::make('scene_versions')->label('版本数')->placeholder('—')
                ->state(fn (NovelOutlineChapter $record): ?int => ($count = ScenePlanVersion::where('outline_chapter_id', $record->id)->count()) === 0 ? null : $count),
            TextColumn::make('scene_saved_at')->label('最近保存')->placeholder('—')
                ->state(fn (NovelOutlineChapter $record): ?string => $this->latest($record)?->created_at?->format('Y-m-d H:i')),
        ])->filters([
            SelectFilter::make('outline_plan_id')->label('大纲记录')->options(fn (): array => $this->getRecord()->outlinePlans()->orderByDesc('id')->pluck('id', 'id')->map(fn ($id): string => '大纲记录 #'.$id)->all()),
            // 默认只列有场景规划版本的章节，清空筛选即逐章查看全部大纲章节。
            SelectFilter::make('scene_plan')->label('场景规划')->options(['planned' => '已规划或已写作', 'frozen' => '有冻结版本', 'none' => '未规划'])->default('planned')->placeholder('全部章节')
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'planned' => $query->where(fn (Builder $query): Builder => $query->whereIn('id', ScenePlanVersion::select('outline_chapter_id'))->orWhereIn('id', ChapterGenerationRun::select('outline_chapter_id')->whereNotNull('plan_data'))),
                    'frozen' => $query->whereIn('id', ScenePlanVersion::select('outline_chapter_id')->where('status', 'frozen')),
                    'none' => $query->whereNotIn('id', ScenePlanVersion::select('outline_chapter_id')),
                    default => $query,
                }),
        ])->recordActions([
            Action::make('history')->label('版本与覆盖')->modalHeading(fn (NovelOutlineChapter $record): string => '第'.$record->chapter_number.'章 · 推进点与场景规划版本')
                ->modalWidth('4xl')->modalAutofocus(false)->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                ->modalContent(fn (NovelOutlineChapter $record): View => view('filament.resources.novels.pages.scene-plan-history', $this->historyViewData($record))),
            Action::make('edit')->label('编辑 / 拆分 / 合并')->visible(fn (NovelOutlineChapter $record): bool => ! $this->started($record))
                ->fillForm(fn (NovelOutlineChapter $record): array => ['expected_id' => $this->latest($record)?->id, 'scenes' => $this->latest($record)?->scenes ?? [], 'freeze' => false, 'acknowledged' => false])
                ->schema(fn (NovelOutlineChapter $record): array => $this->fields($record))
                ->action(function (NovelOutlineChapter $record, array $data): void {
                    app(ScenePlanning::class)->save($this->getRecord()->id, $record->id, Filament::auth()->user(), array_values($data['scenes']), (bool) ($data['freeze'] ?? false), $data['reason'], isset($data['expected_id']) ? (int) $data['expected_id'] : null);
                    $this->latestVersions = [];
                    Notification::make()->success()->title('场景规划新版本已保存')->send();
                }),
        ])->emptyStateHeading(fn (): string => ScenePlanVersion::where('novel_id', $this->getRecord()->id)->exists() ? '没有符合筛选条件的章节' : '尚无场景规划')
            ->emptyStateDescription('场景规划是可选步骤，不规划时生成会在细纲阶段自行拆分场景。可点击“新建场景规划”选择章节，或把“场景规划”筛选切换为“全部章节”逐章查看。');
    }

    /** 新建入口只列出本书尚未开始写作、也还没有场景规划版本的章节；已有版本的章节在列表中继续编辑。
     * @return array<int, string>
     */
    private function creatableChapters(): array
    {
        $plans = $this->getRecord()->outlinePlans()->count();

        return NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $this->getRecord()->id))
            ->whereNotIn('id', ScenePlanVersion::select('outline_chapter_id'))
            ->whereNotIn('chapter_number', $this->getRecord()->chapters()->pluck('number'))
            ->orderByDesc('outline_plan_id')->orderBy('chapter_number')->get()
            ->mapWithKeys(fn (NovelOutlineChapter $chapter): array => [$chapter->id => ($plans > 1 ? '大纲记录 #'.$chapter->outline_plan_id.' · ' : '').'第'.$chapter->chapter_number.'章 · '.$chapter->title])->all();
    }

    /** 按编号读取本书的大纲章节，其他书的编号返回 null。 */
    private function outlineChapter(mixed $id): ?NovelOutlineChapter
    {
        return filled($id) ? NovelOutlineChapter::whereHas('plan', fn ($q) => $q->where('novel_id', $this->getRecord()->id))->find((int) $id) : null;
    }

    /** 新建场景规划：先选章节，再按该章推进点填写场景。 */
    private function createAction(): Action
    {
        return Action::make('create')->label('新建场景规划')->icon('heroicon-o-plus')->modalWidth('4xl')
            ->visible(fn (): bool => $this->creatableChapters() !== [])
            ->fillForm(['scenes' => [], 'freeze' => false, 'acknowledged' => false])
            ->schema(fn (): array => [Select::make('outline_chapter_id')->label('章节')->options(fn (): array => $this->creatableChapters())->searchable()->required()->live()
                ->helperText('只列出尚未开始写作、也还没有场景规划的章节；已有版本的章节请在列表中编辑。'), ...$this->fields(null)])
            ->action(function (array $data): void {
                abort_unless(array_key_exists((int) $data['outline_chapter_id'], $this->creatableChapters()), 422);
                app(ScenePlanning::class)->save($this->getRecord()->id, (int) $data['outline_chapter_id'], Filament::auth()->user(), array_values($data['scenes']), (bool) ($data['freeze'] ?? false), $data['reason'], null);
                $this->latestVersions = [];
                Notification::make()->success()->title('场景规划新版本已保存')->send();
            });
    }

    /** 控件明确列出推进点；冻结和确认默认关闭。 */
    private function fields(?NovelOutlineChapter $record): array
    {
        $fields = [];
        foreach (['title' => '场景标题', 'goal' => '场景目标', 'pov' => '视角人物', 'time' => '故事时间', 'place' => '地点', 'participants' => '参与者', 'conflict' => '冲突', 'change' => '人物及状态变化', 'transition' => '前后衔接', 'prohibited' => '禁止变更'] as $key => $label) {
            $fields[] = Textarea::make($key)->label($label)->required()->maxLength(in_array($key, ['title', 'pov']) ? 200 : (in_array($key, ['time', 'place']) ? 500 : ($key === 'participants' ? 1000 : 2000)));
        }
        $fields[] = TextInput::make('words')->label('目标字数')->integer()->minValue(1)->required();
        // 新建时章节由表单选择，推进点选项随所选章节变化。
        $fields[] = Select::make('point_ids')->label('必要事件 / 推进点')->multiple()->required()->options(function (Get $get) use ($record): array {
            $ids = array_column(($record ?? $this->outlineChapter($get('../../outline_chapter_id')))?->progression_points ?? [], 'id');

            return array_combine($ids, $ids);
        });

        return [Hidden::make('expected_id'), Repeater::make('scenes')->label('按发生顺序排列场景')->schema($fields)->columns(2)->minItems(1)->maxItems(20),
            Textarea::make('reason')->label('新增、拆分或合并原因')->required()->maxLength(2000), Checkbox::make('freeze')->label('通过覆盖和字数检查后冻结为新运行输入')->default(false),
            Checkbox::make('acknowledged')->label('我确认以上场景规划，不修改已开始运行或正式历史。')->accepted()->default(false)];
    }

    /** 推进点变化类型的中文名，与大纲页一致。 */
    private const CHANGE_TYPES = ['information' => '信息', 'decision' => '决定', 'relationship' => '关系', 'resource' => '资源', 'position' => '位置', 'risk' => '风险'];

    /** 场景字段的中文名与展示顺序，与编辑表单一致；标题单独作为卡片标题。 */
    private const SCENE_FIELDS = ['goal' => '场景目标', 'pov' => '视角人物', 'time' => '故事时间', 'place' => '地点', 'participants' => '参与者', 'conflict' => '冲突', 'change' => '人物及状态变化', 'transition' => '前后衔接', 'prohibited' => '禁止变更', 'words' => '目标字数', 'point_ids' => '必要推进点'];

    /** 细纲自动拆分场景的字段中文名。 */
    private const AUTO_SCENE_FIELDS = ['time' => '故事时间', 'place' => '地点', 'present' => '在场人物', 'obstacle' => '阻碍', 'action' => '行动', 'change' => '人物及状态变化', 'key_dialogue' => '关键对白', 'emotional_beat' => '情绪节拍', 'props' => '道具', 'point_ids' => '必要推进点'];

    /** 场景字段逐项转成中文行，推进点编号换成序号，未知字段按原键名列出。
     * @param  array<string, mixed>  $scene
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $numbers
     * @return list<array{label: string, value: string}>
     */
    private function sceneRows(array $scene, array $labels, array $numbers): array
    {
        $rows = [];
        foreach ([...$labels, ...array_fill_keys(array_diff(array_keys($scene), ['title', 'goal', ...array_keys($labels)]), null)] as $key => $label) {
            $value = $key === 'point_ids'
                ? implode('、', array_map(fn (mixed $id): string => $numbers[(string) $id] ?? (string) $id, FieldDiff::listOf($scene[$key] ?? null)))
                : (is_array($scene[$key] ?? null) ? implode('；', SettingVersionDiff::lines($scene[$key])) : FieldDiff::text($scene[$key] ?? null));
            if (filled($value)) {
                $rows[] = ['label' => $label ?? (string) $key, 'value' => $value];
            }
        }

        return $rows;
    }

    /** “版本与覆盖”弹窗数据：推进点结构化展示，场景规划版本按时间倒序并附预检结果。
     * @return array{points: list<array<string, ?string>>, versions: list<array<string, mixed>>, auto_scenes: list<array<string, mixed>>}
     */
    private function historyViewData(NovelOutlineChapter $record): array
    {
        $points = [];
        $numbers = [];
        foreach (FieldDiff::listOf($record->progression_points) as $index => $point) {
            $point = (array) $point;
            $numbers[(string) ($point['id'] ?? '')] = '推进点 '.($index + 1);
            $points[] = ['subject' => FieldDiff::text($point['subject'] ?? null), 'type' => self::CHANGE_TYPES[$point['change_type'] ?? ''] ?? FieldDiff::text($point['change_type'] ?? null),
                'before' => FieldDiff::text($point['before'] ?? null), 'action' => FieldDiff::text($point['action'] ?? null), 'after' => FieldDiff::text($point['after'] ?? null),
                'verification' => FieldDiff::text($point['verification'] ?? null)];
        }
        $versions = ScenePlanVersion::where('outline_chapter_id', $record->id)->latest('id')->get()->map(function (ScenePlanVersion $version) use ($record, $numbers): array {
            $scenes = [];
            foreach (FieldDiff::listOf($version->scenes) as $scene) {
                $scene = (array) $scene;
                $scenes[] = ['title' => FieldDiff::text($scene['title'] ?? null), 'rows' => $this->sceneRows($scene, self::SCENE_FIELDS, $numbers)];
            }

            return ['id' => $version->id, 'frozen' => $version->status === 'frozen', 'status' => $version->status === 'frozen' ? '已冻结' : '草稿',
                'created_at' => $version->created_at?->format('Y-m-d H:i'), 'reason' => FieldDiff::text($version->reason),
                'issues' => app(ScenePlanning::class)->issues($this->getRecord(), $record, $version->scenes), 'scenes' => $scenes];
        })->all();

        $auto = $this->started($record) ? $this->autoScenes($record) : null;
        $autoScenes = $auto === null ? [] : array_map(fn (array $scene): array => ['title' => FieldDiff::text($scene['goal'] ?? null), 'rows' => $this->sceneRows($scene, self::AUTO_SCENE_FIELDS, $numbers)], $auto);

        return ['points' => $points, 'versions' => $versions, 'auto_scenes' => $autoScenes];
    }

    /** 按大纲章节读取最新编辑版本，不混入其他书或其他大纲。 */
    private function latest(NovelOutlineChapter $record): ?ScenePlanVersion
    {
        if (! array_key_exists($record->id, $this->latestVersions)) {
            $this->latestVersions[$record->id] = ScenePlanVersion::where('outline_chapter_id', $record->id)->latest('id')->first();
        }

        return $this->latestVersions[$record->id];
    }

    /** 同一次渲染内各列共用的最新版本，保存后清空。
     * @var array<int, ?ScenePlanVersion>
     */
    private array $latestVersions = [];

    /** 实际进入生成的场景覆盖了本章多少个推进点。 */
    private function coverage(NovelOutlineChapter $record): ?string
    {
        $scenes = $this->displayScenes($record);
        if ($scenes === null) {
            return null;
        }
        $points = array_map('strval', array_column($record->progression_points, 'id'));
        $covered = array_map('strval', array_merge([], ...array_map(fn (mixed $scene): array => (array) data_get($scene, 'point_ids', []), $scenes)));

        return count(array_intersect($points, array_unique($covered))).' / '.count($points);
    }

    /** 本书已开始写作的章号，同一次渲染内只查询一次。
     * @var list<int>|null
     */
    private ?array $startedNumbers = null;

    /** 已有章节记录的章号不再允许编辑场景规划。 */
    private function started(NovelOutlineChapter $record): bool
    {
        $this->startedNumbers ??= $this->getRecord()->chapters()->pluck('number')->map(fn ($number): int => (int) $number)->all();

        return in_array((int) $record->chapter_number, $this->startedNumbers, true);
    }

    /** 列表状态：说明场景规划是否会进入生成；已写作的章节标明实际按哪份场景写成。 */
    private function sceneStatus(NovelOutlineChapter $record): string
    {
        $status = $this->latest($record)?->status;
        if (! $this->started($record)) {
            return match ($status) {
                'frozen' => '已冻结',
                'draft' => '草稿（未冻结，不进入生成）',
                null => '未规划（可选）',
                default => (string) $status,
            };
        }
        if ($status === 'frozen') {
            return '已写作 · 按作者规划';
        }
        $auto = $this->autoScenes($record);
        $used = $auto === null ? '未拆分场景' : '细纲自动拆分 '.count($auto).' 场';

        return $status === 'draft' ? '已写作 · '.$used.'（作者草稿未采用）' : '已写作 · '.$used;
    }

    /** 作者冻结版本优先，其次是已写作章节的细纲自动场景，最后是作者草稿。
     * @return list<mixed>|null
     */
    private function displayScenes(NovelOutlineChapter $record): ?array
    {
        $latest = $this->latest($record);
        if ($latest?->status === 'frozen') {
            return $latest->scenes;
        }

        return ($this->started($record) ? $this->autoScenes($record) : null) ?? $latest?->scenes;
    }

    /** 本章最近一次生成在细纲阶段自动拆分的场景；没有细纲时为 null。
     * @return list<array<string, mixed>>|null
     */
    private function autoScenes(NovelOutlineChapter $record): ?array
    {
        if (! array_key_exists($record->id, $this->autoSceneCache)) {
            $run = ChapterGenerationRun::where('outline_chapter_id', $record->id)->whereHas('chapter', fn (Builder $query): Builder => $query->where('novel_id', $this->getRecord()->id))
                ->whereNotNull('plan_data')->latest('id')->first();
            $scenes = $run === null ? [] : array_values(array_filter((array) data_get($run->plan_data, 'scenes', []), 'is_array'));
            $this->autoSceneCache[$record->id] = $scenes === [] ? null : $scenes;
        }

        return $this->autoSceneCache[$record->id];
    }

    /** 同一次渲染内各列共用的细纲自动场景。
     * @var array<int, list<array<string, mixed>>|null>
     */
    private array $autoSceneCache = [];

    /** 返回小说详情。 */
    protected function getHeaderActions(): array
    {
        return [$this->createAction(), Action::make('back')->label('返回小说')->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->getRecord()]))];
    }
}
