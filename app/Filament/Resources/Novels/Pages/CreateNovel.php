<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\GenerationMode;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\FirstChapterRun;
use App\Models\Novel;
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\FirstChapterService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** 创建页只保存并入队，HTTP 内不执行阶段，也不允许连续重复建书。 */
class CreateNovel extends CreateRecord
{
    protected static string $resource = NovelResource::class;

    /** 应用服务自行提交事务后投递，禁止页面再包装外层事务。 */
    protected ?bool $hasDatabaseTransactions = false;

    /** 本页关闭“创建并继续”，避免把同一条创意误提交成多本小说。 */
    protected static bool $canCreateAnother = false;

    /** 本请求的投递确认状态不作为客户端可写的表单字段。 */
    private bool $startupConfirmed = false;

    /** 统一经过应用服务，所有运行字段由服务端生成。
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        if ($data['automatic_first_chapter'] ?? false) {
            $run = app(FirstChapterService::class)->start($data, Filament::auth()->user(), true, model: filled($data['automatic_model'] ?? null) ? $data['automatic_model'] : null);
            $this->startupConfirmed = $run->status === 'pending';

            return Novel::findOrFail($run->novel_id);
        }
        $result = app(CreateNovelService::class)->createAndStart($data);
        $this->startupConfirmed = $result->startupConfirmed;

        return $result->novel;
    }

    /** 明确区分保存成功与投递未确认，不丢弃作者已保存的创意。 */
    protected function getCreatedNotification(): ?Notification
    {
        if (! $this->startupConfirmed) {
            return Notification::make()->warning()->title('小说已保存，启动投递未确认')->body('请查看列表中的运行状态，不必重复创建。');
        }

        if (FirstChapterRun::where('novel_id', $this->record->id)->exists()) {
            return Notification::make()->success()->title('小说已创建，自动首章已启动')->body('已记录自动授权；可在运行详情查看进度、请求次数和失败恢复。');
        }

        return Notification::make()->success()->title('小说已创建，已提交启动请求')->body($this->record->generation_mode === GenerationMode::SettingReal ? '真实设定已入队，将产生模型调用费用；完成后暂停等待大纲阶段。' : '当前运行模拟流程，不生成正文或产生模型费用。');
    }

    /** 创建后返回带状态轮询的列表。 */
    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('index');
    }

    /** 提交按钮使用作者可理解的动作名称。 */
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('创建并启动');
    }
}
