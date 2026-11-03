<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\GenerationMode;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\SettingConfirmationForm;
use App\Models\Novel;
use App\Services\Pipeline\ConfirmSettingService;
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

/** 小说只读详情与明确规划动作；确认设定和发送付费请求相互独立。 */
class ViewNovel extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    /** 动作只调用应用服务，禁止直接编辑运行字段或已有产物。
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
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
