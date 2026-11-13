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
