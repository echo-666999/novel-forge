<?php

namespace App\Filament\Pages;

use App\Services\Ai\BuiltinPromptTemplates;
use App\Services\Ai\PromptTemplateManager;
use App\Services\Pipeline\BodyVersionDiff;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use UnitEnum;

/** 提示词版本后台；复制编辑永远产生新草稿，历史只读。 */
class ManagePromptTemplates extends Page implements HasTable
{
    use InteractsWithTable;

    /** 导航和页面名称。 */
    protected static ?string $title = 'Prompt 模板管理';

    /** 侧栏图标。 */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '配置与运维';

    /** 模板编辑与历史视图。 */
    protected string $view = 'filament.pages.manage-prompt-templates';

    /** 当前编辑阶段。 */
    public string $scope = 'common';

    /** 待保存的模板正文。 */
    public string $body = '';

    /** 本次版本说明。 */
    public string $notes = '';

    /** 模板类型筛选与阶段独立，空值显示两种类型。 */
    public string $filterType = '';

    /** 当前阶段筛选，空值显示全部。 */
    public string $filterScope = '';

    /** 当前状态筛选：默认隐藏已退役旧版本，空值显示全部。 */
    public string $filterStatus = 'active';

    /** 用户最近看到的发布指针，禁止客户端伪造并发令牌。 */
    #[Locked]
    public array $routes = [];

    /** 明确展示本地测试与真实请求的区别。 */
    public string $message = '';

    /** 初始化可比较的发布状态。 */
    public function mount(): void
    {
        $this->refreshRoutes();
    }

    /** 列表仅提供新增入口，编辑表单放在独立页面。
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('新增模板')->url(CreatePromptTemplate::getUrl())];
    }

    /** 详情按服务端记录读取完整正文，默认转义文本，避免模板内容成为页面代码。 */
    public function detailsAction(): Action
    {
        return Action::make('details')->label('详情')->modalHeading('Prompt 模板详情')->modalWidth('5xl')
            ->modalSubmitAction(false)->modalCancelActionLabel('关闭')
            ->schema(fn (array $arguments): array => $this->detailFields((int) ($arguments['id'] ?? 0)))
            ->extraModalFooterActions(fn (array $arguments): array => $this->detailFooterActions((int) ($arguments['id'] ?? 0)));
    }

    /** 详情弹窗底部按版本状态给出下一步操作；执行后关闭弹窗并刷新列表，确认框仍按原规则弹出。
     * @return array<Action>
     */
    private function detailFooterActions(int $id): array
    {
        $status = DB::table('prompt_template_versions')->where('id', $id)->value('status');
        $scope = DB::table('prompt_template_versions')->where('id', $id)->value('scope');

        return [
            Action::make('detailTestAndPublish')->label('测试并发布')->color('success')->visible($status === 'draft')->requiresConfirmation()->cancelParentActions()
                ->modalDescription('先做本地变量与渲染回归（不发送 AI 请求），通过后立即发布；仅影响此后新建的小说。')
                ->action(function () use ($id): void {
                    $this->testVersion($id);
                    $this->publishVersion($id);
                    $this->resetTable();
                }),
            Action::make('detailPublish')->label('发布')->color('success')->visible($status === 'tested')->requiresConfirmation()->cancelParentActions()
                ->modalDescription('仅影响此后新建的小说；已有小说保持创建时冻结的模板。')
                ->action(function () use ($id): void {
                    $this->publishVersion($id);
                    $this->resetTable();
                }),
            Action::make('detailRollback')->label('回滚到此版')->visible($status === 'retired')->requiresConfirmation()->cancelParentActions()
                ->modalDescription('重新发布此历史版本；仅影响此后新建的小说。')
                ->action(function () use ($id): void {
                    $this->publishVersion($id, true);
                    $this->resetTable();
                }),
            Action::make('detailCopy')->label('复制为新草稿')->color('gray')->url(CreatePromptTemplate::getUrl(['copy_from' => $id])),
            Action::make('detailRetire')->label('退役（恢复程序默认）')->color('danger')->link()->visible($status === 'published')->requiresConfirmation()->cancelParentActions()
                ->action(function () use ($scope): void {
                    $this->retireVersion($scope);
                    $this->resetTable();
                }),
        ];
    }

    /** 列表和独立详情动作共用同一只读内容，始终按记录编号重新读取。
     * @return array<TextEntry>
     */
    private function detailFields(int $id): array
    {
        $version = DB::table('prompt_template_versions')->find($id);
        abort_unless($version !== null, 404);
        $published = DB::table('prompt_template_routes as r')->join('prompt_template_versions as v', 'v.id', '=', 'r.version_id')->where('r.scope', $version->scope)->first(['v.id', 'v.body']);

        $novels = collect($this->getViewData()['bindings'])->filter(fn (array $binding): bool => collect($binding['snapshot']['items'] ?? [])->contains('id', $version->id))
            ->map(fn (array $binding): string => '#'.$binding['id'].' '.$binding['title'])->values()->all();

        $isPublished = $published !== null && $published->id === $version->id;

        return [
            Flex::make([
                TextEntry::make('identity')->hiddenLabel()->weight('bold')->size('lg')->state(self::scopeTitle($version->scope).' · '.$this->versionLabel($version->id)),
                TextEntry::make('status')->hiddenLabel()->badge()->grow(false)->state(self::STATUSES[$version->status])->color(self::statusColor($version->status)),
            ])->verticallyAlignCenter(),
            TextEntry::make('notes')->hiddenLabel()->color('gray')->state($version->notes),
            // 生效、回归与使用情况压成一排标签，重点一眼可见。
            Flex::make([
                TextEntry::make('published')->hiddenLabel()->badge()->grow(false)
                    ->state($isPublished ? '当前生效版本' : ($published ? '当前生效：'.$this->versionLabel($published->id) : '本阶段使用程序默认'))
                    ->color($isPublished ? 'success' : 'gray'),
                TextEntry::make('report')->hiddenLabel()->badge()->grow(false)->state($version->test_report === null ? '尚未测试' : '本地回归通过')
                    ->color($version->test_report === null ? 'warning' : 'success')->tooltip('本地变量与渲染回归，不发送真实 AI 请求，不代表文学质量评测。'),
                TextEntry::make('usage_count')->hiddenLabel()->badge()->grow(false)->color('gray')->state(count($novels) === 0 ? '暂无小说使用' : count($novels).' 本小说冻结使用'),
            ])->extraAttributes(['style' => 'flex-wrap: wrap']),
            TextEntry::make('usage')->hiddenLabel()->state($novels)->badge()->color('gray')->visible($novels !== []),
            // 与发布版本按段落对照，只突出改动；本身就是发布版本或尚无发布版本时直接显示全文。
            ViewComponent::make('filament.resources.novels.partials.body-diff')->visible($published !== null && ! $isPublished)
                ->viewData(fn (): array => ['diff' => BodyVersionDiff::paragraphs((string) $published?->body, (string) $version->body),
                    'oldLabel' => '当前发布 '.($published ? $this->versionLabel($published->id) : ''), 'newLabel' => '本版本 '.$this->versionLabel($version->id)]),
            // 正文放进浅色框；换行保留只作用于框内，避免模板缩进被当成正文空白。
            TextEntry::make('body')->label('模板正文')->state($version->body)->visible($published === null || $isPublished)
                ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<div style="white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.8;padding:.75rem 1rem;border-radius:.5rem;border:1px solid rgba(127,127,127,.25);background:rgba(127,127,127,.06)">'.e($state).'</div>')),
        ];
    }

    /** 状态中文名。 */
    private const STATUSES = ['draft' => '草稿', 'tested' => '已测试', 'published' => '已发布', 'retired' => '已退役'];

    /** 状态配色：生效为绿，待发布为蓝，草稿为橙。 */
    private static function statusColor(string $status): string
    {
        return match ($status) {
            'published' => 'success', 'tested' => 'info', 'retired' => 'gray', default => 'warning'
        };
    }

    /** 模板所属阶段与类型，例如“正文 · 内置固定提示词”。 */
    private static function scopeTitle(string $scope): string
    {
        $builtin = str_starts_with($scope, 'builtin_');

        return PromptTemplateManager::SCOPES[$builtin ? substr($scope, 8) : $scope].' · '.($builtin ? '内置固定提示词' : '补充创作要求');
    }

    /** 复用小说列表的原生表格、状态徽标与分页，编号先于分页及筛选计算。 */
    public function table(Table $table): Table
    {
        return $table->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
            $data = $this->getViewData();
            $rows = collect();
            foreach ($data['groups'] as $scope => $group) {
                foreach ($group['versions'] as $version) {
                    $rows->put($version->id, [
                        'id' => $version->id,
                        'identity' => $group['stage'],
                        'kind' => $group['kind'],
                        'version' => 'V'.$version->version_number,
                        'status' => $version->status,
                        'notes' => $version->notes,
                        'created_at' => $version->created_at,
                        'references' => $data['references'][$version->id] ?? 0,
                    ]);
                }
            }

            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginated([10, 25, 50])->defaultPaginationPageOption(10)->recordAction('view')->columns([
            TextColumn::make('identity')->label('模板')->weight('bold')->description(fn (array $record): string => $record['kind']),
            TextColumn::make('version')->label('版本')->color('gray'),
            TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state])
                ->color(fn (string $state): string => self::statusColor($state)),
            TextColumn::make('notes')->label('版本说明')->limit(40)->wrap(),
            TextColumn::make('references')->label('使用中')->formatStateUsing(fn (int $state): string => $state.' 本小说')->placeholder('—')->color('gray'),
            TextColumn::make('created_at')->label('创建时间')->since()->dateTimeTooltip('Y-m-d H:i:s')->color('gray'),
        ])->recordActions([
            // 每个状态只露出下一步主操作，其余收进菜单。
            Action::make('testAndPublish')->label('测试并发布')->color('success')->visible(fn (array $record): bool => $record['status'] === 'draft')
                ->requiresConfirmation()->modalDescription('先做本地变量与渲染回归（不发送 AI 请求），通过后立即发布；仅影响此后新建的小说。')
                ->action(function (array $record): void {
                    $this->testVersion($record['id']);
                    $this->publishVersion($record['id']);
                    $this->resetTable();
                }),
            Action::make('publish')->label('发布')->color('success')->visible(fn (array $record): bool => $record['status'] === 'tested')->requiresConfirmation()
                ->modalDescription('仅影响此后新建的小说；已有小说保持创建时冻结的模板。')->action(function (array $record): void {
                    $this->publishVersion($record['id']);
                    $this->resetTable();
                }),
            Action::make('rollback')->label('回滚到此版')->visible(fn (array $record): bool => $record['status'] === 'retired')->requiresConfirmation()->action(function (array $record): void {
                $this->publishVersion($record['id'], true);
                $this->resetTable();
            }),
            ActionGroup::make([
                Action::make('view')->label('详情与对照')->icon('heroicon-m-eye')->modalHeading('Prompt 模板详情')->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                    ->schema(fn (array $record): array => $this->detailFields($record['id']))
                    ->extraModalFooterActions(fn (array $record): array => $this->detailFooterActions($record['id'])),
                Action::make('copy')->label('复制为新草稿')->icon('heroicon-m-document-duplicate')->url(fn (array $record): string => CreatePromptTemplate::getUrl(['copy_from' => $record['id']])),
                Action::make('test')->label('仅测试')->icon('heroicon-m-beaker')->visible(fn (array $record): bool => $record['status'] === 'draft')->action(function (array $record): void {
                    $this->testVersion($record['id']);
                    $this->resetTable();
                }),
                Action::make('retire')->label('退役（恢复程序默认）')->icon('heroicon-m-archive-box')->color('danger')->visible(fn (array $record): bool => $record['status'] === 'published')->requiresConfirmation()->action(function (array $record): void {
                    $scope = DB::table('prompt_template_versions')->where('id', $record['id'])->value('scope');
                    $this->retireVersion($scope);
                    $this->resetTable();
                }),
            ]),
        ])->description(function (): ?string {
            $hidden = $this->filterStatus === 'active' ? $this->getViewData()['hiddenRetired'] : 0;

            return $hidden === 0 ? null : '已收起 '.$hidden.' 个已退役旧版本，状态选“全部状态”可查看。';
        })->emptyStateHeading('当前筛选条件下没有模板版本');
    }

    /** 概览行：每个阶段的固定文本与补充要求，带当前生效版本号、编号与编辑入口；发布或退役后随渲染重算。
     * @return list<array{stage: string, items: list<array{kind: string, label: string, id: ?int, edit: string}>}>
     */
    public function overviewRows(): array
    {
        $labels = $this->getViewData()['versionLabels'];
        $rows = [];
        foreach (PromptTemplateManager::SCOPES as $scope => $stage) {
            $items = [];
            foreach ($scope === 'common' ? [$scope => '补充'] : ['builtin_'.$scope => '固定', $scope => '补充'] as $key => $kind) {
                $id = $this->routes[$key] ?? null;
                $items[] = ['kind' => $kind, 'id' => $id, 'edit' => CreatePromptTemplate::getUrl(['scope' => $key]),
                    'label' => $id === null ? ($kind === '固定' ? '固定：程序默认' : '无补充') : $kind.' '.strtok($labels[$id] ?? '', '（')];
            }
            $rows[] = ['stage' => $stage, 'items' => $items];
        }

        return $rows;
    }

    /** 外部筛选改变后返回第一页，防止旧页码产生空白列表。 */
    public function updatedFilterType(): void
    {
        $this->resetPage();
    }

    /** 阶段筛选独立重置分页。 */
    public function updatedFilterScope(): void
    {
        $this->resetPage();
    }

    /** 状态筛选独立重置分页。 */
    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    /** 使用后台自带表单组件，避免未加载自定义 Tailwind 样式导致字段挤成一行。 */
    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('scope')->label('模板类型与阶段')->options(['内置固定提示词' => BuiltinPromptTemplates::scopes(), '补充创作要求' => PromptTemplateManager::SCOPES])->selectablePlaceholder(false)->required()->live()
                ->helperText(fn (): string => str_starts_with($this->scope, 'builtin_') ? '当前类型：内置固定提示词。发布后替换该阶段的固定文本，不进行变量替换。' : '当前类型：补充创作要求。追加到固定提示词之后；公共要求用于所有阶段，其余仅用于对应阶段。'),
            Textarea::make('body')->label('模板正文')->rows(8)->maxLength(12000)->required(),
            Textarea::make('notes')->label('版本说明')->rows(3)->maxLength(2000)->required(),
        ]);
    }

    /** 筛选同样复用后台原生组件，窄屏自动堆叠并保留即时刷新。 */
    public function filters(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 3])->components([
            Select::make('filterType')->hiddenLabel()->options(['' => '全部类型', 'builtin' => '内置固定提示词', 'supplement' => '补充创作要求'])->selectablePlaceholder(false)->live()->afterStateUpdated(function (): void {
                // 切换类型时清除旧阶段，避免残留条件导致列表意外为空。
                $this->filterScope = '';
            }),
            Select::make('filterScope')->hiddenLabel()->options(fn (): array => ['' => '全部阶段', ...($this->filterType !== 'supplement' ? BuiltinPromptTemplates::scopes() : []), ...($this->filterType !== 'builtin' ? PromptTemplateManager::SCOPES : [])])->selectablePlaceholder(false)->live(),
            Select::make('filterStatus')->hiddenLabel()->options(['active' => '未退役版本', '' => '全部状态', 'draft' => '草稿', 'tested' => '已测试', 'published' => '已发布', 'retired' => '已退役'])->selectablePlaceholder(false)->live(),
        ]);
    }

    /** 更新发布指针视图。 */
    private function refreshRoutes(): void
    {
        $this->routes = DB::table('prompt_template_routes')->pluck('version_id', 'scope')->all();
    }

    /** 创建草稿，不修改来源版本。 */
    public function saveDraft(): void
    {
        // 原生字段校验先返回就地提示，服务层继续执行独立业务校验。
        $id = $this->createDraft();
        $this->message = '已保存草稿 '.$this->versionLabel($id).'；发布前请执行变量与渲染回归。';
    }

    /** 校验表单并创建新草稿，返回记录编号；编辑页的“保存并发布”复用。 */
    protected function createDraft(): int
    {
        $this->form->getState();

        return app(PromptTemplateManager::class)->draft($this->scope, $this->body, $this->notes, auth()->id());
    }

    /** 从历史载入编辑区；后续保存仍是新版本。 */
    public function copyVersion(int $id): void
    {
        $version = DB::table('prompt_template_versions')->find($id);
        abort_unless($version !== null, 404);
        $this->scope = $version->scope;
        $this->body = $version->body;
        $this->notes = '基于'.$this->versionLabel($id).'：';
    }

    /** 执行本地回归而非模型生成，样例随版本保存。 */
    public function testVersion(int $id): void
    {
        app(PromptTemplateManager::class)->test($id);
        $this->message = '本地变量与渲染回归通过；真实 AI 请求 0 次。此结果不代表文学质量评测。';
        Notification::make()->success()->title('测试通过')->body($this->message)->send();
    }

    /** 发布经过回归的版本，或回滚到已发布过的历史版本。 */
    public function publishVersion(int $id, bool $rollback = false): void
    {
        $version = DB::table('prompt_template_versions')->find($id);
        abort_unless($version !== null, 404);
        app(PromptTemplateManager::class)->activate($id, $this->routes[$version->scope] ?? null, auth()->id(), $rollback);
        $this->refreshRoutes();
        $this->message = ($rollback ? '已回滚' : '已发布').$this->versionLabel($id).'，仅作用于此后创建的真实小说运行；旧小说及恢复流程保持冻结快照。';
        Notification::make()->success()->title($rollback ? '已回滚' : '已发布')->body($this->message)->send();
    }

    /** 退役默认版本，未来运行恢复该阶段的程序契约。 */
    public function retireVersion(string $scope): void
    {
        abort_unless(isset($this->routes[$scope]), 422);
        app(PromptTemplateManager::class)->retire($scope, $this->routes[$scope], auth()->id());
        $this->refreshRoutes();
        $this->message = '已退役；旧运行快照不变。';
        Notification::make()->success()->title('已退役')->body('该阶段恢复程序默认；旧运行快照不变。')->send();
    }

    /** 版本按同一类型和阶段的完整历史计数，记录编号继续用于审计关联。 */
    protected function versionLabel(int $id): string
    {
        $version = DB::table('prompt_template_versions')->find($id);
        abort_unless($version !== null, 404);
        $number = DB::table('prompt_template_versions')->where('scope', $version->scope)->where('id', '<=', $id)->count();

        return 'V'.$number.'（记录 #'.$id.'）';
    }

    /** 查询版本、当前发布正文及冻结引用数，历史正文只读显示。 */
    protected function getViewData(): array
    {
        // 先按完整不可删除历史编号，再筛选；切换状态或发布回滚不会改变版本号。
        $allVersions = DB::table('prompt_template_versions')->orderBy('id')->get();
        $numbers = [];
        $versionLabels = [];
        foreach ($allVersions as $version) {
            $version->version_number = $numbers[$version->scope] = ($numbers[$version->scope] ?? 0) + 1;
            $versionLabels[$version->id] = 'V'.$version->version_number.'（记录 #'.$version->id.'）';
        }
        // 固定类型在前，补充类型在后；各类型按业务阶段排序，组内新版本在前。
        $groups = [];
        $hiddenRetired = 0;
        foreach ([...BuiltinPromptTemplates::scopes(), ...PromptTemplateManager::SCOPES] as $scope => $label) {
            $builtin = str_starts_with($scope, 'builtin_');
            if (($this->filterType === 'builtin' && ! $builtin) || ($this->filterType === 'supplement' && $builtin) || ($this->filterScope !== '' && $this->filterScope !== $scope)) {
                continue;
            }
            $versions = $allVersions->where('scope', $scope)->filter(fn ($version): bool => match ($this->filterStatus) {
                '' => true, 'active' => $version->status !== 'retired', default => $version->status === $this->filterStatus,
            })->sortByDesc('version_number');
            $hiddenRetired += $this->filterStatus === 'active' ? $allVersions->where('scope', $scope)->where('status', 'retired')->count() : 0;
            if ($versions->isNotEmpty()) {
                $stage = PromptTemplateManager::SCOPES[$builtin ? substr($scope, 8) : $scope];
                $groups[$scope] = ['title' => ($builtin ? '内置固定提示词' : '补充创作要求').' · '.$stage, 'stage' => $stage, 'kind' => $builtin ? '内置固定提示词' : '补充创作要求', 'versions' => $versions];
            }
        }
        $references = [];
        $bindings = [];
        foreach (DB::table('novels')->whereNotNull('generation_config')->orderByDesc('id')->get(['id', 'title', 'generation_config']) as $novel) {
            $config = json_decode($novel->generation_config, true);
            $bindings[] = ['id' => $novel->id, 'title' => $novel->title, 'snapshot' => $config['prompt_templates'] ?? null];
            foreach (data_get($config, 'prompt_templates.items', []) as $item) {
                $references[$item['id']] = ($references[$item['id']] ?? 0) + 1;
            }
        }

        return ['bindings' => $bindings, 'groups' => $groups, 'hiddenRetired' => $hiddenRetired, 'versionLabels' => $versionLabels, 'references' => $references, 'published' => DB::table('prompt_template_versions')->whereIn('id', array_filter($this->routes))->get()->keyBy('scope')];
    }
}
