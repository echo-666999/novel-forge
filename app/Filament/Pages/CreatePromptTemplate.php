<?php

namespace App\Filament\Pages;

use App\Services\Ai\BuiltinPromptTemplates;
use App\Services\Ai\PromptTemplateManager;
use App\Services\Pipeline\BodyVersionDiff;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 独立编辑页复用模板校验；选择阶段自动带出当前生效正文，可保存草稿或一步保存并发布，历史版本始终不被覆盖。 */
class CreatePromptTemplate extends ManagePromptTemplates
{
    /** 新增页从列表按钮进入，不重复占用侧边导航。 */
    protected static bool $shouldRegisterNavigation = false;

    /** 新增页标题与表单视图。 */
    protected static ?string $title = '编辑 Prompt 模板';

    protected string $view = 'filament.pages.create-prompt-template';

    /** 最近一次自动带出的正文；作者改过正文后切换阶段不再覆盖。 */
    public string $loadedBody = '';

    /** 编辑公共补充时，拼接预览以哪个阶段为例。 */
    public string $assemblyStage = 'draft';

    /** 可选来源只预填文本；指定阶段时带出该阶段当前生效版本，保存仍经过新草稿服务。 */
    public function mount(): void
    {
        parent::mount();
        if (request()->has('copy_from')) {
            $this->copyVersion(request()->integer('copy_from'));
            $this->loadedBody = $this->body;
        } elseif (array_key_exists((string) request()->query('scope'), self::scopeOptions())) {
            $this->scope = (string) request()->query('scope');
            $this->loadPublished();
        } else {
            $this->loadPublished();
        }
    }

    /** 返回列表不会产生发布或保存操作。
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('返回模板列表')->color('gray')->url(ManagePromptTemplates::getUrl())];
    }

    /** 编辑表单：阶段切换即时带出生效正文，正文支持变量快捷插入并显示字数。 */
    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('scope')->label('模板类型与阶段')->options(['内置固定提示词' => BuiltinPromptTemplates::scopes(), '补充创作要求' => PromptTemplateManager::SCOPES])->selectablePlaceholder(false)->required()->live()
                ->afterStateUpdated(fn () => $this->loadPublished())
                ->helperText(fn (): string => str_starts_with($this->scope, 'builtin_') ? '当前类型：内置固定提示词。发布后替换该阶段的固定文本，不进行变量替换。' : '当前类型：补充创作要求。追加到固定提示词之后；公共要求用于所有阶段，其余仅用于对应阶段。'),
            Textarea::make('body')->label('模板正文')->rows(16)->maxLength(12000)->required()->live(debounce: 600)
                ->helperText(fn (Get $get): string => mb_strlen((string) $get('body')).' / 12000 字')
                ->hintActions(str_starts_with($this->scope, 'builtin_') ? [] : collect(['title' => '书名', 'genre' => '题材', 'stage' => '阶段'])
                    ->map(fn (string $label, string $variable): Action => Action::make('insert_'.$variable)->label('插入'.$label)->link()->size('sm')
                        ->tooltip('在正文末尾插入 {{ '.$variable.' }}')
                        ->action(fn (Get $get, Set $set) => $set('body', rtrim((string) $get('body')).'{{ '.$variable.' }}')))->values()->all()),
            Textarea::make('notes')->label('版本说明')->rows(2)->maxLength(2000)->required()->placeholder('例如：正文阶段增加对白占比要求'),
        ]);
    }

    /** 保存成功后返回列表，草稿需要另行测试和发布。 */
    public function saveDraft(): void
    {
        parent::saveDraft();
        Notification::make()->success()->title($this->message)->send();
        $this->redirect(ManagePromptTemplates::getUrl());
    }

    /** 一步完成保存、本地回归与发布；回归失败时草稿保留，可在列表继续处理。 */
    public function saveAndPublish(): void
    {
        $id = $this->createDraft();
        try {
            $this->testVersion($id);
            $this->publishVersion($id);
        } catch (ValidationException $error) {
            Notification::make()->danger()->title('已保存为草稿，但未发布')->body(implode('；', $error->validator->errors()->all()))->send();
            $this->redirect(ManagePromptTemplates::getUrl());

            return;
        }
        Notification::make()->success()->title('已保存并发布 '.$this->versionLabel($id))->body('仅影响此后新建的小说；已有小说保持创建时冻结的模板。')->send();
        $this->redirect(ManagePromptTemplates::getUrl());
    }

    /** 带出所选阶段当前生效的正文；作者已改动正文时不覆盖，没有生效版本时清空自动内容。 */
    public function loadPublished(): void
    {
        if ($this->body !== '' && $this->body !== $this->loadedBody) {
            return;
        }
        $id = DB::table('prompt_template_routes')->where('scope', $this->scope)->value('version_id');
        $this->body = $this->loadedBody = $id === null ? '' : (string) DB::table('prompt_template_versions')->where('id', $id)->value('body');
        // 只替换自动生成的说明前缀，作者写过的说明保留。
        if ($this->notes === '' || str_starts_with($this->notes, '基于V')) {
            $this->notes = $id === null ? '' : '基于'.$this->versionLabel($id).'：';
        }
    }

    /** 右侧预览：样例变量渲染结果与相对当前生效版本的段落对照。
     * @return array{rendered: ?string, error: ?string, diff: ?array<string, mixed>, publishedLabel: ?string}
     */
    public function preview(): array
    {
        $rendered = null;
        $error = null;
        try {
            $rendered = $this->body === '' ? null : app(PromptTemplateManager::class)->preview($this->scope, $this->body);
        } catch (ValidationException $exception) {
            $error = implode('；', $exception->validator->errors()->all());
        }
        $id = DB::table('prompt_template_routes')->where('scope', $this->scope)->value('version_id');
        $published = $id === null ? null : (string) DB::table('prompt_template_versions')->where('id', $id)->value('body');

        return ['rendered' => $rendered, 'error' => $error, 'publishedLabel' => $id === null ? null : $this->versionLabel($id),
            'diff' => $published === null || $published === $this->body ? null : BodyVersionDiff::paragraphs($published, $this->body)];
    }

    /** 拼接预览：按运行时顺序列出固定文本与补充要求，编辑中的一段替换为当前输入，其余取当前生效版本。
     * @return array{stage: string, parts: list<array{source: string, label: string, body: ?string, editing: bool, error: ?string}>, hasSupplement: bool}
     */
    public function assembly(): array
    {
        $stage = match (true) {
            str_starts_with($this->scope, 'builtin_') => substr($this->scope, 8),
            $this->scope === 'common' => array_key_exists($this->assemblyStage, BuiltinPromptTemplates::defaults()) ? $this->assemblyStage : 'draft',
            default => $this->scope,
        };
        $parts = [];
        foreach (['builtin_'.$stage => '固定文本', 'common' => '公共补充', $stage => PromptTemplateManager::SCOPES[$stage].'补充'] as $key => $source) {
            $editing = $key === $this->scope;
            $id = $editing ? null : DB::table('prompt_template_routes')->where('scope', $key)->value('version_id');
            $body = $editing ? $this->body : ($id === null ? null : (string) DB::table('prompt_template_versions')->where('id', $id)->value('body'));
            // 未发布的补充不参与拼接；固定文本未发布时运行沿用程序内置文本。
            if ($body === null && $key !== 'builtin_'.$stage) {
                continue;
            }
            $error = null;
            if ($body !== null && $body !== '') {
                try {
                    $body = app(PromptTemplateManager::class)->preview($key, $body, $stage);
                } catch (ValidationException $exception) {
                    $error = implode('；', $exception->validator->errors()->all());
                }
            }
            $parts[] = ['source' => $source, 'editing' => $editing, 'body' => $body, 'error' => $error,
                'label' => $editing ? '编辑中' : ($id === null ? '程序默认' : strtok($this->versionLabel($id), '（'))];
        }

        return ['stage' => PromptTemplateManager::SCOPES[$stage], 'parts' => $parts, 'hasSupplement' => count($parts) > 1];
    }

    /** 拼接预览可选的示例阶段（公共补充作用于这些阶段）。
     * @return array<string, string>
     */
    public function assemblyStages(): array
    {
        return array_intersect_key(PromptTemplateManager::SCOPES, BuiltinPromptTemplates::defaults());
    }

    /** 新增页仅呈现表单，不加载全量版本历史。 */
    protected function getViewData(): array
    {
        return [];
    }

    /** 全部可选阶段键。
     * @return array<string, string>
     */
    private static function scopeOptions(): array
    {
        return [...BuiltinPromptTemplates::scopes(), ...PromptTemplateManager::SCOPES];
    }
}
