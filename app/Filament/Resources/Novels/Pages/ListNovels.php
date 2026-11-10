<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/** 私有小说书架，创建入口与运行状态集中呈现。 */
class ListNovels extends ListRecords
{
    protected static string $resource = NovelResource::class;

    /** 仅提供创建动作，运行恢复在小说的运行详情页处理。
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('创建小说'),
        ];
    }
}
