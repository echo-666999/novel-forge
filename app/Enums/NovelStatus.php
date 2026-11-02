<?php

namespace App\Enums;

/** 小说运行状态，完成状态不代表正式剧情提交。 */
enum NovelStatus: string
{
    /** 待运行。 */
    case Pending = 'pending';

    /** 运行中。 */
    case Running = 'running';

    /** 已暂停。 */
    case Paused = 'paused';

    /** 已完成。 */
    case Completed = 'completed';

    /** 运行异常。 */
    case Error = 'error';

    /** 返回面向作者的中文名称。 */
    public function label(): string
    {
        return match ($this) {
            self::Pending => '待运行',
            self::Running => '运行中',
            self::Paused => '已暂停',
            self::Completed => '已完成',
            self::Error => '运行异常',
        };
    }
}
