<?php

namespace App\Filament\Support;

use Filament\Actions\Action;

/** 区块标题旁的「?」提示：完整说明放进悬停提示，区块描述只保留一句结论。 */
final class HelpHint
{
    /** 只显示图标并提供悬停说明，点击不执行任何操作；同页多个区块用不同名称区分。 */
    public static function action(string $text, string $name = 'help'): Action
    {
        return Action::make($name)->label('说明')->hiddenLabel()->icon('heroicon-o-question-mark-circle')->iconButton()->color('gray')->tooltip($text);
    }
}
