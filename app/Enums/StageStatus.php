<?php

namespace App\Enums;

/** 单次阶段执行记录的状态。 */
enum StageStatus: string
{
    /** 待执行。 */
    case Pending = 'pending';

    /** 执行中。 */
    case Running = 'running';

    /** 已完成。 */
    case Completed = 'completed';

    /** 执行失败。 */
    case Failed = 'failed';

    /** 返回面向作者的中文名称。 */
    public function label(): string
    {
        return match ($this) {
            self::Pending => '待执行',
            self::Running => '执行中',
            self::Completed => '已完成',
            self::Failed => '执行失败',
        };
    }
}
