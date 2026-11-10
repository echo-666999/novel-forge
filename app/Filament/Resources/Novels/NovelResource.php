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
use App\Filament\Resources\Novels\Schemas\NovelInfolist;
use App\Filament\Resources\Novels\Tables\NovelsTable;
use App\Models\Novel;
use BackedEnum;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** 私有工作台的小说入口，仅允许创建和只读查看，通过独立运行台控制恢复。 */
class NovelResource extends Resource
{
    protected static ?string $model = Novel::class;

    /** 侧栏分组。 */
    protected static string|UnitEnum|null $navigationGroup = '创作工作台';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = '小说';

    protected static ?string $pluralModelLabel = '小说';

    protected static ?string $navigationLabel = '小说书架';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    /** 创建页使用向导步骤（NovelForm::steps），小说创建后不提供编辑表单。 */
    public static function form(Schema $schema): Schema
    {
        return $schema;
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

    /** 单本小说页面的子导航放在左侧，按规划、人物与世界、正文、生产、交付分组。 */
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    /** 进入任意一本书后显示的子导航；需要额外参数的页面（编辑草稿、验收阅读）从对应页面进入。
     * @return array<NavigationItem|NavigationGroup>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            ViewNovel::class,
            Pages\Requirements::class, Pages\BlueprintVersions::class, Pages\StoryStructurePlan::class, ViewOutlinePlan::class, Pages\ScenePlans::class,
            Pages\EntityLibrary::class, Pages\ViewCharacterKnowledge::class, Pages\ViewForeshadows::class,
            Pages\ChapterDirectory::class, ViewChapterContent::class, Pages\ReadChapter::class,
            Pages\ViewNovelRun::class, Pages\ViewContextManifests::class, Pages\SearchLab::class,
            Pages\AcceptanceWorkbench::class, Pages\ExportCenter::class, Pages\ViewNovelCosts::class, Pages\ViewStoryState::class,
        ]);
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
            // 逐章验收阅读：冻结正文与评分同屏，批次编号随路由校验归属。
            'acceptanceReading' => Pages\AcceptanceReading::route('/{record}/acceptance/{batch}/read'),
            'knowledge' => Pages\ViewCharacterKnowledge::route('/{record}/knowledge'),
            'state' => Pages\ViewStoryState::route('/{record}/state'),
            'costs' => Pages\ViewNovelCosts::route('/{record}/costs'),
            'outline' => ViewOutlinePlan::route('/{record}/outline'),
            'content' => ViewChapterContent::route('/{record}/content'),
        ];
    }
}
