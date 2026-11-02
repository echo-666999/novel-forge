<?php

namespace App\Enums;

/** 章节候选生产状态，不包含正式提交状态。 */
enum ChapterStatus: string
{
    /** 待生成。 */
    case Pending = 'pending';

    /** 生成中。 */
    case Generating = 'generating';

    /** 草稿。 */
    case Draft = 'draft';

    /** 生成失败。 */
    case Failed = 'failed';

    /** 返回面向作者的中文名称。 */
    public function label(): string
    {
        return match ($this) {
            self::Pending => '待生成',
            self::Generating => '生成中',
            self::Draft => '草稿',
            self::Failed => '生成失败',
        };
    }
}
