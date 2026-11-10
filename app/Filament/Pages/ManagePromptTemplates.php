<?php

namespace App\Filament\Pages;

use App\Services\Ai\BuiltinPromptTemplates;
use App\Services\Ai\PromptTemplateManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/** 提示词版本后台；复制编辑永远产生新草稿，历史只读。 */
class ManagePromptTemplates extends Page implements HasTable
{
    use InteractsWithTable;

    /** 导航和页面名称。 */
    protected static ?string $title = 'Prompt 模板管理';

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

    /** 当前状态筛选，空值显示全部。 */
    public string $filterStatus = '';

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
            ->schema(fn (array $arguments): array => $this->detailFields((int) ($arguments['id'] ?? 0)));
    }

    /** 列表和独立详情动作共用同一只读内容，始终按记录编号重新读取。
     * @return array<TextEntry>
     */
    private function detailFields(int $id): array
    {
        $version = DB::table('prompt_template_versions')->find($id);
        abort_unless($version !== null, 404);
        $published = DB::table('prompt_template_routes as r')->join('prompt_template_versions as v', 'v.id', '=', 'r.version_id')->where('r.scope', $version->scope)->first(['v.id', 'v.body']);

        return [
            TextEntry::make('identity')->label('类型、阶段与版本')->state(([...BuiltinPromptTemplates::scopes(), ...PromptTemplateManager::SCOPES])[$version->scope].' · '.$this->versionLabel($version->id)),
            TextEntry::make('notes')->label('版本说明')->state($version->notes),
            TextEntry::make('body')->label('完整模板正文')->state($version->body)->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']),
            TextEntry::make('published')->label('当前发布版本')->state($published ? $this->versionLabel($published->id) : '无发布版本'),
            TextEntry::make('published_body')->label('当前发布正文（供比较）')->state($published?->body ?? '无发布正文')->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']),
            TextEntry::make('report')->label('本地回归结果')->state($version->test_report ?? '尚未测试')->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;']),
        ];
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
                        'id' => $version->id, 'identity' => $group['title'],
                        'version' => 'V'.$version->version_number, 'status' => $version->status,
                        'notes' => $version->notes, 'created_at' => $version->created_at,
                        'references' => $data['references'][$version->id] ?? 0,
                    ]);
                }
            }

            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->paginated([10, 25, 50])->defaultPaginationPageOption(10)->columns([
            TextColumn::make('identity')->label('模板类型与阶段')->wrap(),
            TextColumn::make('version')->label('版本'),
            TextColumn::make('id')->label('记录编号'),
            TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn (string $state): string => ['draft' => '草稿', 'tested' => '已测试', 'published' => '已发布', 'retired' => '已退役'][$state])
                ->color(fn (string $state): string => match ($state) {
                    'published' => 'success', 'tested' => 'info', 'retired' => 'gray', default => 'warning'
                }),
            TextColumn::make('references')->label('根运行引用'),
            TextColumn::make('notes')->label('版本说明')->limit(35)->wrap(),
            TextColumn::make('created_at')->label('创建时间')->dateTime('Y-m-d H:i:s'),
        ])->recordActions([
            Action::make('view')->label('详情')->modalHeading('Prompt 模板详情')->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                ->schema(fn (array $record): array => $this->detailFields($record['id'])),
            Action::make('copy')->label('复制编辑')->url(fn (array $record): string => CreatePromptTemplate::getUrl(['copy_from' => $record['id']])),
            Action::make('test')->label('测试')->visible(fn (array $record): bool => in_array($record['status'], ['draft', 'tested']))->action(function (array $record): void {
                $this->testVersion($record['id']);
                $this->resetTable();
            }),
            Action::make('publish')->label('发布')->visible(fn (array $record): bool => $record['status'] === 'tested')->requiresConfirmation()->action(function (array $record): void {
                $this->publishVersion($record['id']);
                $this->resetTable();
            }),
            Action::make('rollback')->label('回滚')->visible(fn (array $record): bool => $record['status'] === 'retired')->requiresConfirmation()->action(function (array $record): void {
                $this->publishVersion($record['id'], true);
                $this->resetTable();
            }),
            Action::make('retire')->label('退役')->visible(fn (array $record): bool => $record['status'] === 'published')->requiresConfirmation()->action(function (array $record): void {
                $scope = DB::table('prompt_template_versions')->where('id', $record['id'])->value('scope');
                $this->retireVersion($scope);
                $this->resetTable();
            }),
        ])->emptyStateHeading('当前筛选条件下没有模板版本');
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
            Select::make('filterType')->label('筛选模板类型')->options(['' => '全部类型', 'builtin' => '内置固定提示词', 'supplement' => '补充创作要求'])->selectablePlaceholder(false)->live()->afterStateUpdated(function (): void {
                // 切换类型时清除旧阶段，避免残留条件导致列表意外为空。
                $this->filterScope = '';
            }),
            Select::make('filterScope')->label('筛选阶段')->options(fn (): array => ['' => '全部阶段', ...($this->filterType !== 'supplement' ? BuiltinPromptTemplates::scopes() : []), ...($this->filterType !== 'builtin' ? PromptTemplateManager::SCOPES : [])])->selectablePlaceholder(false)->live(),
            Select::make('filterStatus')->label('筛选状态')->options(['' => '全部状态', 'draft' => '草稿', 'tested' => '已测试', 'published' => '已发布', 'retired' => '已退役'])->selectablePlaceholder(false)->live(),
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
        $this->form->getState();
        $id = app(PromptTemplateManager::class)->draft($this->scope, $this->body, $this->notes, auth()->id());
        $this->message = '已保存草稿 '.$this->versionLabel($id).'；发布前请执行变量与渲染回归。';
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
    }

    /** 发布经过回归的版本，或回滚到已发布过的历史版本。 */
    public function publishVersion(int $id, bool $rollback = false): void
    {
        $version = DB::table('prompt_template_versions')->find($id);
        abort_unless($version !== null, 404);
        app(PromptTemplateManager::class)->activate($id, $this->routes[$version->scope] ?? null, auth()->id(), $rollback);
        $this->refreshRoutes();
        $this->message = ($rollback ? '已回滚' : '已发布').$this->versionLabel($id).'，仅作用于此后创建的真实小说运行；旧小说及恢复流程保持冻结快照。';
    }

    /** 退役默认版本，未来运行恢复该阶段的程序契约。 */
    public function retireVersion(string $scope): void
    {
        abort_unless(isset($this->routes[$scope]), 422);
        app(PromptTemplateManager::class)->retire($scope, $this->routes[$scope], auth()->id());
        $this->refreshRoutes();
        $this->message = '已退役；旧运行快照不变。';
    }

    /** 版本按同一类型和阶段的完整历史计数，记录编号继续用于审计关联。 */
    private function versionLabel(int $id): string
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
        foreach ([...BuiltinPromptTemplates::scopes(), ...PromptTemplateManager::SCOPES] as $scope => $label) {
            $builtin = str_starts_with($scope, 'builtin_');
            if (($this->filterType === 'builtin' && ! $builtin) || ($this->filterType === 'supplement' && $builtin) || ($this->filterScope !== '' && $this->filterScope !== $scope)) {
                continue;
            }
            $versions = $allVersions->where('scope', $scope)->filter(fn ($version): bool => $this->filterStatus === '' || $version->status === $this->filterStatus)->sortByDesc('version_number');
            if ($versions->isNotEmpty()) {
                $stage = PromptTemplateManager::SCOPES[$builtin ? substr($scope, 8) : $scope];
                $groups[$scope] = ['title' => ($builtin ? '内置固定提示词' : '补充创作要求').' · '.$stage, 'versions' => $versions];
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

        return ['bindings' => $bindings, 'groups' => $groups, 'versionLabels' => $versionLabels, 'references' => $references, 'published' => DB::table('prompt_template_versions')->whereIn('id', array_filter($this->routes))->get()->keyBy('scope')];
    }
}
