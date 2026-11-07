<?php

namespace App\Filament\Resources\Novels;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\ListNovels;
use App\Filament\Resources\Novels\Pages\ViewChapterContent;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Filament\Resources\Novels\Pages\ViewOutlinePlan;
use App\Filament\Resources\Novels\Schemas\NovelForm;
use App\Filament\Resources\Novels\Schemas\NovelInfolist;
use App\Filament\Resources\Novels\Tables\NovelsTable;
use App\Models\Novel;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** 私有工作台的小说入口，仅允许创建和只读查看，通过独立运行台控制恢复。 */
class NovelResource extends Resource
{
    protected static ?string $model = Novel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = '小说';

    protected static ?string $pluralModelLabel = '小说';

    protected static ?string $navigationLabel = '小说管理';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    /** 复用创建表单结构，运行字段不进入可编辑表单。 */
    public static function form(Schema $schema): Schema
    {
        return NovelForm::configure($schema);
    }

    /** 完整展示保存的创意、篇幅与运行状态。 */
    public static function infolist(Schema $schema): Schema
    {
        return NovelInfolist::configure($schema);
    }

    /** 提供搜索、状态筛选与运行状态轮询。 */
    public static function table(Table $table): Table
    {
        return NovelsTable::configure($table);
    }

    /** 使用中文状态，并明确区分模拟流程完成与作品正式完成。 */
    public static function statusLabel(NovelStatus $status, ?Novel $record = null): string
    {
        if ($record?->generation_mode === GenerationMode::SettingReal) {
            if ($record->chapters_done > 0) {
                return '已正式提交'.$record->chapters_done.'章';
            }
            if ($record->chapters()->exists()) {
                return $record->chapters()->whereNotNull('progression_result')->where('progression_result->verdict', 'pass')->exists()
                    ? '首章候选已完成，等待后续评审' : ($status === NovelStatus::Paused ? '首章已暂停，请查看原因' : $status->label());
            }
            if ($status === NovelStatus::Paused && in_array($record->current_stage, [Stage::FineOutline, Stage::Content], true)) {
                return '大纲已生成，等待后续阶段';
            }
            if ($record->current_stage === Stage::Outline && $record->outlinePlans()->exists()) {
                return $status === NovelStatus::Paused ? '大纲已暂停' : $status->label();
            }

            return $status === NovelStatus::Paused && $record->current_stage === Stage::Outline ? '设定已生成，等待大纲阶段' : $status->label();
        }

        return $status === NovelStatus::Completed ? '模拟完成' : $status->label();
    }

    /** 为状态提供稳定颜色，不仅依靠颜色传达运行结果。 */
    public static function statusColor(NovelStatus $status): string
    {
        return match ($status) {
            NovelStatus::Pending => 'gray',
            NovelStatus::Running => 'info',
            NovelStatus::Paused => 'warning',
            NovelStatus::Completed => 'success',
            NovelStatus::Error => 'danger',
        };
    }

    /** 只注册列表、创建与详情路由，不暴露编辑或删除入口。
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListNovels::route('/'),
            'create' => CreateNovel::route('/create'),
            'view' => ViewNovel::route('/{record}'),
            // 所有小说共用的章节目录，避免只能从首章入口逐步寻找。
            'chapters' => Pages\ChapterDirectory::route('/{record}/chapters'),
            'reader' => Pages\ReadChapter::route('/{record}/reader'),
            'editor' => Pages\EditChapterDraft::route('/{record}/editor'),
            'requirements' => Pages\Requirements::route('/{record}/requirements'),
            'blueprints' => Pages\BlueprintVersions::route('/{record}/blueprints'),
            'entities' => Pages\EntityLibrary::route('/{record}/entities'),
            'scenes' => Pages\ScenePlans::route('/{record}/scenes'),
            'structure' => Pages\StoryStructurePlan::route('/{record}/structure'),
            'run' => Pages\ViewNovelRun::route('/{record}/run'),
            // 伏笔计划与证据使用独立维护页。
            // 检查器展示冻结请求及裁剪清单。
            'search' => Pages\SearchLab::route('/{record}/search'),
            'contexts' => Pages\ViewContextManifests::route('/{record}/contexts'),
            'foreshadows' => Pages\ViewForeshadows::route('/{record}/foreshadows'),
            'exports' => Pages\ExportCenter::route('/{record}/exports'),
            'acceptance' => Pages\AcceptanceWorkbench::route('/{record}/acceptance'),
            'knowledge' => Pages\ViewCharacterKnowledge::route('/{record}/knowledge'),
            'state' => Pages\ViewStoryState::route('/{record}/state'),
            'costs' => Pages\ViewNovelCosts::route('/{record}/costs'),
            'outline' => ViewOutlinePlan::route('/{record}/outline'),
            'content' => ViewChapterContent::route('/{record}/content'),
        ];
    }
}
