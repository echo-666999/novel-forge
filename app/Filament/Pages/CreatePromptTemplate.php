<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** 独立新增页复用模板校验；复制历史只能创建新草稿，不能覆盖原版本。 */
class CreatePromptTemplate extends ManagePromptTemplates
{
    /** 新增页从列表按钮进入，不重复占用侧边导航。 */
    protected static bool $shouldRegisterNavigation = false;

    /** 新增页标题与表单视图。 */
    protected static ?string $title = '新增 Prompt 模板';

    protected string $view = 'filament.pages.create-prompt-template';

    /** 可选来源只预填文本，保存仍经过新草稿服务。 */
    public function mount(): void
    {
        parent::mount();
        if (request()->has('copy_from')) {
            $this->copyVersion(request()->integer('copy_from'));
        }
    }

    /** 返回列表不会产生发布或保存操作。
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('返回模板列表')->url(ManagePromptTemplates::getUrl())];
    }

    /** 保存成功后返回列表，草稿需要另行测试和发布。 */
    public function saveDraft(): void
    {
        parent::saveDraft();
        Notification::make()->success()->title($this->message)->send();
        $this->redirect(ManagePromptTemplates::getUrl());
    }

    /** 新增页仅呈现表单，不加载全量版本历史。 */
    protected function getViewData(): array
    {
        return [];
    }
}
