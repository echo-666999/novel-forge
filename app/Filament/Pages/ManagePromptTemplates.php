<?php

namespace App\Filament\Pages;

use App\Services\Ai\PromptTemplateManager;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/** 提示词版本后台；复制编辑永远产生新草稿，历史只读。 */
class ManagePromptTemplates extends Page
{
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

    /** 使用后台自带表单组件，避免未加载自定义 Tailwind 样式导致字段挤成一行。 */
    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('scope')->label('阶段')->options(PromptTemplateManager::SCOPES)->selectablePlaceholder(false)->required(),
            Textarea::make('body')->label('模板正文')->rows(8)->maxLength(12000)->required(),
            Textarea::make('notes')->label('版本说明')->rows(3)->maxLength(2000)->required(),
        ]);
    }

    /** 筛选同样复用后台原生组件，窄屏自动堆叠并保留即时刷新。 */
    public function filters(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 2])->components([
            Select::make('filterScope')->label('筛选阶段')->options(['' => '全部阶段', ...PromptTemplateManager::SCOPES])->selectablePlaceholder(false)->live(),
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
        $this->message = '已保存草稿 #'.$id.'；发布前请执行变量与渲染回归。';
    }

    /** 从历史载入编辑区；后续保存仍是新版本。 */
    public function copyVersion(int $id): void
    {
        $version = DB::table('prompt_template_versions')->find($id);
        abort_unless($version !== null, 404);
        $this->scope = $version->scope;
        $this->body = $version->body;
        $this->notes = '基于版本 #'.$id.'：';
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
        $this->message = ($rollback ? '已回滚' : '已发布').'版本 #'.$id.'，仅作用于此后创建的真实小说运行；旧小说及恢复流程保持冻结快照。';
    }

    /** 退役默认版本，未来运行恢复该阶段的程序契约。 */
    public function retireVersion(string $scope): void
    {
        abort_unless(isset($this->routes[$scope]), 422);
        app(PromptTemplateManager::class)->retire($scope, $this->routes[$scope], auth()->id());
        $this->refreshRoutes();
        $this->message = '已退役；旧运行快照不变。';
    }

    /** 查询版本、当前发布正文及冻结引用数，历史正文只读显示。 */
    protected function getViewData(): array
    {
        $versions = DB::table('prompt_template_versions')->when($this->filterScope !== '', fn ($q) => $q->where('scope', $this->filterScope))->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))->orderByDesc('id')->get();
        $references = [];
        $bindings = [];
        foreach (DB::table('novels')->whereNotNull('generation_config')->orderByDesc('id')->get(['id', 'title', 'generation_config']) as $novel) {
            $config = json_decode($novel->generation_config, true);
            $bindings[] = ['id' => $novel->id, 'title' => $novel->title, 'snapshot' => $config['prompt_templates'] ?? null];
            foreach (data_get($config, 'prompt_templates.items', []) as $item) {
                $references[$item['id']] = ($references[$item['id']] ?? 0) + 1;
            }
        }

        return ['bindings' => $bindings, 'versions' => $versions, 'references' => $references, 'published' => DB::table('prompt_template_versions')->whereIn('id', array_filter($this->routes))->get()->keyBy('scope')];
    }
}
