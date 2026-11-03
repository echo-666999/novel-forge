<?php

namespace App\Policies;

use App\Models\Novel;
use App\Models\User;

/** 单作者私有后台允许已登录用户建书与查看，禁止改写或删除运行历史。 */
class NovelPolicy
{
    /** 已通过后台身份验证的用户可查看小说列表。 */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** 当前私有工作台不按账户拆分作者，登录用户可查看保存的小说。 */
    public function view(User $user, Novel $novel): bool
    {
        return true;
    }

    /** 允许创建，所有运行字段仍由应用服务确定。 */
    public function create(User $user): bool
    {
        return true;
    }

    /** 创建后的原始创意与运行字段不通过 B01 直接修改。 */
    public function update(User $user, Novel $novel): bool
    {
        return false;
    }

    /** 禁止删除关联小说和阶段历史。 */
    public function delete(User $user, Novel $novel): bool
    {
        return false;
    }

    /** 归档恢复不在本任务范围。 */
    public function restore(User $user, Novel $novel): bool
    {
        return false;
    }

    /** 禁止绕过界面永久删除小说。 */
    public function forceDelete(User $user, Novel $novel): bool
    {
        return false;
    }

    /** 禁止批量删除。 */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** 禁止批量恢复。 */
    public function restoreAny(User $user): bool
    {
        return false;
    }

    /** 禁止批量永久删除。 */
    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /** 复制设定为新书属于后续增强，不作为绕过创建服务的入口。 */
    public function replicate(User $user, Novel $novel): bool
    {
        return false;
    }

    /** 小说列表采用固定排序，不开放运行记录重排。 */
    public function reorder(User $user): bool
    {
        return false;
    }
}
