<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\GenerationMode;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\SettingConfirmationForm;
use App\Models\FirstChapterRun;
use App\Models\Novel;
use App\Services\Pipeline\ConfirmSettingService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\FormalChapterExport;
use App\Services\Pipeline\OutlineGenerationService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 小说只读详情与明确规划动作；确认设定和发送付费请求相互独立。 */
class ViewNovel extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    /** 请求预算由已冻结的细纲开关和当前修订设置共同决定。 */
    private function contentRequestCap(): int
    {
        $plan = $this->getRecord()->outlinePlans()->where('status', 'completed')->latest('id')->first();

        return (data_get($plan?->config, 'enable_fine_outline', true) ? 3 : 2) + 2 * min(1, app(GeneralSettings::class)->max_retries_per_chapter);
    }

    /** 动作只调用应用服务，禁止直接编辑运行字段或已有产物。
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [
            // 正式导出对手动和自动生成小说均开放，只导出从第一章开始的连续正式版本。
            Action::make('exportFormal')->label('导出正式章节')->visible(fn (): bool => $this->getRecord()->chapters_done > 0)
                ->modalHeading('导出 TXT / Markdown')->modalDescription('从第1章导出至指定章，包含目录。仅使用正式提交版本，缺章或来源损坏时拒绝导出。不调用AI。')
                ->schema([
                    Select::make('format')->label('文件格式')->options(['txt' => 'TXT（UTF-8）', 'md' => 'Markdown（UTF-8）'])->default('txt')->required()->native(),
                    TextInput::make('end_chapter')->label('截至正式章号')->integer()->minValue(1)->maxValue(fn (): int => $this->getRecord()->chapters_done)->default(fn (): int => min(10, $this->getRecord()->chapters_done))->required(),
                ])->action(function (array $data): ?StreamedResponse {
                    try {
                        $export = app(FormalChapterExport::class)->build($this->getRecord()->id, (int) $data['end_chapter'], $data['format']);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->danger()->title('导出未执行')->body($exception->getMessage())->send();

                        return null;
                    }

                    // 闭包仅输出已冻结字节，不在响应流中重新读取数据库。
                    return response()->streamDownload(function () use ($export): void {
                        echo $export['content'];
                    }, $export['filename'], ['Content-Type' => $export['mime'], 'Cache-Control' => 'private, no-store']);
                }),
            // 状态回放仅查询正式历史，自动编排小说也保留入口。
            // 自动运行小说同样允许查看和维护伏笔账本。
            // 上下文清单同样向自动生成小说开放只读查看。
            // 作者检索只查正式来源，外部嵌入由实验室显式启用。
            Action::make('searchLab')->label('中文检索实验室')->url(fn (): string => NovelResource::getUrl('search', ['record' => $this->getRecord()])),
            Action::make('viewContexts')->label('上下文检查器')->url(fn (): string => NovelResource::getUrl('contexts', ['record' => $this->getRecord()])),
            Action::make('viewForeshadows')->label('伏笔账本')->url(fn (): string => NovelResource::getUrl('foreshadows', ['record' => $this->getRecord()])),
            Action::make('viewState')->label('故事状态回放')->url(fn (): string => NovelResource::getUrl('state', ['record' => $this->getRecord()])),
            Action::make('viewCosts')->label('调用成本明细')->url(fn (): string => NovelResource::getUrl('costs', ['record' => $this->getRecord()])),
            Action::make('viewRun')->label('运行详情与恢复')->url(fn (): string => NovelResource::getUrl('run', ['record' => $this->getRecord()])),
            Action::make('generateFirstChapter')->label('生成第1章')->modalHeading('生成首章候选并独立检查推进')
                ->visible(fn (): bool => $this->getRecord()->outlinePlans()->where('status', 'completed')->exists() && ! $this->getRecord()->chapters()->exists())
                ->modalDescription(fn (): string => '仅生成首章候选，不代表正式稿。模型'.app(GeneralSettings::class)->default_model.'，启动时冻结；本小说剩余执行额度'.max(0, $this->getRecord()->max_execution_count - $this->getRecord()->execution_count).'次。启用细纲时依次调用细纲、正文、独立推进检查；细纲和检查各4096输出Token、正文默认8192。请求总上限以下方数值为准，默认开启细纲最多5次、关闭最多4次，最多修订一次；全局设置为0时不修订。技术失败或额度不足立即停止。通用设置保持不变，金额未知不代表免费。')
                ->schema([
                    TextInput::make('tokens')->label('本次正文输出Token上限')->integer()->minValue(1)->maxValue(8192)->default(8192)->required(),
                    TextInput::make('request_limit')->label('本次请求总上限（含细纲、检查及修订）')->integer()->minValue(1)->maxValue(2147483647)->maxValue(fn (): int => $this->contentRequestCap())->default(fn (): int => $this->contentRequestCap())->required(),
                    TextInput::make('execution_limit')->label('确认本小说执行总上限')->integer()->minValue(1)->maxValue(2147483647)->default(fn (): int => max($this->getRecord()->max_execution_count, $this->getRecord()->execution_count + $this->contentRequestCap()))->required(),
                    Textarea::make('reason')->label('本次覆盖通用额度的原因')->default('首章2500至3500字，本次正文使用8192输出Token，不修改通用设置')->required(),
                    Checkbox::make('accept_request')->label('我确认按上述请求上限使用真实模型并产生费用；当前首章候选尚未获得正式稿批准。')->accepted(),
                ])->action(function (array $data): void {
                    try {
                        app(ContentGenerationService::class)->start($this->getRecord(), Filament::auth()->user(), (int) $data['tokens'], (int) $data['request_limit'], $data['reason'], (int) $data['execution_limit']);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->danger()->title('首章操作未执行')->body($exception->getMessage())->send();

                        return;
                    }
                    $this->redirect(NovelResource::getUrl('content', ['record' => $this->getRecord()]));
                }),
            Action::make('viewContent')->label('查看首章候选')->url(fn (): string => NovelResource::getUrl('content', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => $this->getRecord()->chapters()->exists()),
            Action::make('refreshStatus')->label('刷新状态')->action(fn () => $this->getRecord()->refresh()),
            Action::make('confirmSetting')->label('确认设定')->modalHeading('修订并确认设定版本')->modalSubmitActionLabel('保存确认版本')
                ->visible(fn (): bool => $this->canConfirmSetting())
                ->fillForm(fn (): array => app(ConfirmSettingService::class)->draft($this->getRecord()))
                ->schema(SettingConfirmationForm::components())
                ->action(function (array $data): void {
                    try {
                        $confirmation = app(ConfirmSettingService::class)->confirm($this->getRecord(), Filament::auth()->user(), $data);
                    } catch (InvalidArgumentException) {
                        throw ValidationException::withMessages(['setting' => '设定结构或必填内容不完整，请检查后再确认。']);
                    }
                    Notification::make()->success()->title('设定版本已确认')->body('确认编号：'.$confirmation->id.'。尚未调用模型，请另行点击“生成大纲”。')->send();
                    $this->getRecord()->refresh();
                }),
            Action::make('generateOutline')->label('生成大纲')->modalHeading('启动真实大纲生成')
                ->visible(fn (): bool => $this->canStartOutline())
                ->modalDescription(fn (): string => $this->startDescription())
                ->schema([
                    Select::make('confirmation_id')->label('确认设定版本')->required()->native()
                        ->options(fn (): array => $this->getRecord()->settingConfirmations()->orderByDesc('id')->get()->mapWithKeys(fn ($confirmation): array => [$confirmation->id => '版本 '.$confirmation->id.' · '.mb_substr($confirmation->content_hash, 0, 12)])->all())
                        ->default(fn (): ?int => $this->getRecord()->settingConfirmations()->max('id')),
                    Checkbox::make('accept_request')->label('我确认按上述批次数和剩余额度调用真实模型，产生费用；失败即停。')->accepted(),
                ])
                ->action(function (array $data): void {
                    $plan = app(OutlineGenerationService::class)->start($this->getRecord(), (int) $data['confirmation_id'], Filament::auth()->user());
                    Notification::make()->title('计划状态：'.$plan->statusLabel())->body('每批最多10章；投递未确认时请检查状态，不要重复创建。')->send();
                    $this->redirect(NovelResource::getUrl('outline', ['record' => $this->getRecord()]));
                }),
            Action::make('viewOutline')->label('查看大纲')->url(fn (): string => NovelResource::getUrl('outline', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => $this->getRecord()->outlinePlans()->exists()),
            Action::make('continueOutline')->label('继续大纲')->modalHeading('显式继续现有大纲')
                ->visible(fn (): bool => $this->getRecord()->outlinePlans()->whereIn('status', ['paused', 'error'])->exists())
                ->modalDescription('已成功章节不会重跑。未知结果须先核查费用；提高上限仅作用于本小说并记录原因。')
                ->schema([
                    Checkbox::make('confirm_unknown')->label('已核查未知调用，接受可能重复计费。')->default(false),
                    TextInput::make('max_execution_count')->label('本小说执行上限（不自动增加）')->numeric()->integer()->minValue(1)->maxValue(2147483647)->required()->default(fn (): int => $this->getRecord()->max_execution_count),
                    Textarea::make('reason')->label('提高上限的原因')->rows(2),
                ])
                ->action(function (array $data): void {
                    $plan = $this->getRecord()->outlinePlans()->latest('id')->firstOrFail();
                    $result = app(OutlineGenerationService::class)->continue($plan->id, (bool) ($data['confirm_unknown'] ?? false), (int) $data['max_execution_count'], $data['reason'] ?? null, Filament::auth()->id());
                    Notification::make()->title('计划状态：'.$result->statusLabel())->send();
                    $this->getRecord()->refresh();
                }),
        ];
        // 自动小说同样保留检索实验室及只读产物入口，推进与恢复仍经独占编排器。
        if (FirstChapterRun::where('novel_id', $this->getRecord()->id)->exists()) {
            return array_values(array_filter($actions, fn (Action $action): bool => in_array($action->getName(), ['exportFormal', 'searchLab', 'viewContexts', 'viewForeshadows', 'viewState', 'viewCosts', 'viewRun', 'viewContent', 'refreshStatus', 'viewOutline'], true)));
        }

        return $actions;
    }

    /** 仅成功的真实设定且尚未建立大纲时显示确认入口。 */
    private function canConfirmSetting(): bool
    {
        $novel = $this->getRecord();

        return $novel->generation_mode === GenerationMode::SettingReal && ! $novel->outlinePlans()->exists()
            && $novel->stages()->where('stage', Stage::Setting->value)->where('status', StageStatus::Completed->value)->exists();
    }

    /** 确认版本存在不等于已经同意发送模型请求。 */
    private function canStartOutline(): bool
    {
        return $this->canConfirmSetting() && $this->getRecord()->settingConfirmations()->exists();
    }

    /** 启动预览明确展示范围、调用次数和额度，不猜测未知模型价格。 */
    private function startDescription(): string
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        return '目标'.$novel->target_chapters.'章，共'.(int) ceil($novel->target_chapters / 10).'批；模型'.app(GeneralSettings::class)->default_model.'，在启动时冻结。每批最多8192输出Token，剩余执行额度'.max(0, $novel->max_execution_count - $novel->execution_count).'次；不足则暂停，不自动增加。未配置价格时金额保持未知。';
    }
}
