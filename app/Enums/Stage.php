<?php

namespace App\Enums;

/** 固定生产阶段，顺序由后续流水线服务执行。 */
enum Stage: string
{
    /** 设定。 */
    case Setting = 'setting';

    /** 大纲。 */
    case Outline = 'outline';

    /** 细纲。 */
    case FineOutline = 'fine_outline';

    /** 正文。 */
    case Content = 'content';

    /** 润色。 */
    case Polish = 'polish';

    /** 导出。 */
    case Export = 'export';

    /** 返回面向作者的中文名称。 */
    public function label(): string
    {
        return match ($this) {
            self::Setting => '设定',
            self::Outline => '大纲',
            self::FineOutline => '细纲',
            self::Content => '正文',
            self::Polish => '润色',
            self::Export => '导出',
        };
    }
}
