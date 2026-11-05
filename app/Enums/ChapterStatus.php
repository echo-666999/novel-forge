<?php

namespace App\Enums;

/** 章节生产及正式提交状态。 */
enum ChapterStatus: string
{
    /** 已通过原子提交成为正式稿。 */
    case Committed = 'committed';
    /** 待生成。 */
    case Pending = 'pending';

    /** 生成中。 */
    case Generating = 'generating';

    /** 草稿。 */
    case Draft = 'draft';

    /** 正文已保存，等待独立推进检查。 */
    case Checking = 'checking';

    /** 推进不足，候选及检查证据保留。 */
    case RevisionRequired = 'revision_required';

    /** 生成失败。 */
    case Failed = 'failed';

    /** 返回面向作者的中文名称。 */
    public function label(): string
    {
        return match ($this) {
            self::Committed => '正式稿',
            self::Pending => '待生成',
            self::Generating => '生成中',
            self::Draft => '草稿',
            self::Checking => '待推进检查',
            self::RevisionRequired => '推进不足，待修订',
            self::Failed => '生成失败',
        };
    }
}
