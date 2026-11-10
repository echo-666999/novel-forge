<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Services\Search\HybridSearch;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Validation\Rule;
use UnitEnum;

/** 作者检索实验室，显式操作才查询，刷新页面不自动产生嵌入费用。 */
class SearchLab extends Page
{
    use InteractsWithRecord;

    /** 沿用小说资源访问权限。 */
    protected static string $resource = NovelResource::class;

    /** 单本小说子导航中的名称。 */
    protected static ?string $navigationLabel = '检索实验室';

    /** 子导航排序。 */
    protected static ?int $navigationSort = 14;

    /** 子导航分组。 */
    protected static string|UnitEnum|null $navigationGroup = '生产';

    /** 后台标题。 */
    protected static ?string $title = '中文检索实验室';

    /** 原生表单避免隐式实时查询产生外部调用。 */
    protected string $view = 'filament.resources.novels.pages.search-lab';

    /** 用户输入查询，提交时再次校验。 */
    public string $query = '';

    /** 正式章号必须明确选择，空值不能默认为全书范围。 */
    public ?int $throughChapter = null;

    /** 默认仅词法，用户勾选后才允许一次查询嵌入。 */
    public bool $semantic = false;

    /** 本次检索结果，每次操作覆盖，不作为故事事实保存。
     * @var array<string,mixed>|null
     */
    public ?array $result = null;

    /** 检索未完成时显示明确错误，禁止保留旧结果冒充成功。 */
    public ?string $searchError = null;

    /** 绑定小说与权限，不预选全书范围。 */
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(NovelResource::canView($this->getRecord()), 403);
    }

    /** 只提供本书已正式提交的章号，避免候选章节成为范围选项。
     * @return array<int,string>
     */
    public function chapterOptions(): array
    {
        return $this->getRecord()->chapters()->whereNotNull('formal_version_id')->whereNotNull('canonical_commit_id')->orderBy('number')->pluck('number')
            ->mapWithKeys(fn (int $number): array => [$number => '截至第'.$number.'章'])->all();
    }

    /** 条件变化即清除旧结果，防止将上一次的全书结果误认成本次范围。 */
    public function updated(string $property): void
    {
        if (in_array($property, ['query', 'throughChapter', 'semantic'], true)) {
            $this->result = null;
            $this->searchError = null;
        }
    }

    /** 点击后执行；角色视角不开放，失败不能继续展示旧结果。 */
    public function search(): void
    {
        abort_unless(NovelResource::canView($this->getRecord()), 403);
        $this->result = null;
        $this->searchError = null;
        $this->validate(['query' => 'required|string|max:200', 'throughChapter' => ['required', 'integer', 'min:1', Rule::in(array_keys($this->chapterOptions()))]]);
        try {
            $this->result = app(HybridSearch::class)->search($this->getRecord(), $this->query, $this->throughChapter, $this->semantic);
        } catch (\InvalidArgumentException $exception) {
            $this->searchError = $exception->getMessage();
        } catch (\RuntimeException) {
            $this->searchError = '检索未完成，请检查PostgreSQL检索扩展与正式来源索引；未展示旧结果。';
        }
    }
}
