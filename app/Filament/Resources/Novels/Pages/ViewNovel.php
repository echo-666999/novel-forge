<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/** 只读小说详情，刷新仅重新读取记录，不触发流水线。 */
class ViewNovel extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    /** 使用显式刷新查看队列执行结果，不暴露编辑或恢复入口。
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshStatus')->label('刷新状态')->action(fn () => $this->getRecord()->refresh()),
        ];
    }
}
