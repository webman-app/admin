<?php

declare(strict_types=1);

namespace plugin\admin\app\common;

use Exception;
use plugin\admin\app\model\Role;
use plugin\admin\app\model\User;

class Auth
{
    /**
     * 获取当前用户的角色信息
     * @return array|null
     * @throws Exception
     */
    public static function getAdminRole(): ?array
    {
        if (!$admin = admin()) {
            return null;
        }
        $role_id = $admin['role'] ?? 0;
        if (!$role_id) {
            return null;
        }
        if (!is_scalar($role_id)) {
            return null;
        }
        return Role::find((int)$role_id)?->toArray();
    }

    /**
     * 获取权限范围内的所有角色 ID
     * @param bool $with_self 是否包含当前角色
     * @return array
     * @throws Exception
     */
    public static function getScopeRoleIds(bool $with_self = false): array
    {
        $role = static::getAdminRole();
        if (!$role) {
            return [];
        }
        $role_id = $role['id'] ?? null;
        $rules = $role['rules'] ?? '';
        if (!is_scalar($role_id)) {
            return [];
        }
        if ($rules === '*') {
            return Role::pluck('id')->toArray();
        }

        $roles = Role::get();
        $tree = new Tree($roles);
        $descendants = $tree->getDescendant([$role_id], $with_self);
        return array_column($descendants, 'id');
    }

    /**
     * 获取权限范围内的所有管理员 ID
     * @param bool $with_self 是否包含当前管理员
     * @return array
     * @throws Exception
     */
    public static function getScopeAdminIds(bool $with_self = false): array
    {
        $role_ids = static::getScopeRoleIds();
        $admin_ids = User::whereIn('role', $role_ids)->pluck('id')->toArray();
        if ($with_self) {
            $admin_id = admin_id();
            if ($admin_id !== null) {
                $admin_ids[] = $admin_id;
            }
        }
        return array_unique($admin_ids);
    }

    /**
     * 兼容旧版本
     * @param int $admin_id
     * @return bool
     * @throws Exception
     * @deprecated
     */
    public static function isSupperAdmin(int $admin_id = 0): bool
    {
        return static::isSuperAdmin($admin_id);
    }

    /**
     * 是否是超级管理员
     * @param int $admin_id
     * @return bool
     * @throws Exception
     */
    public static function isSuperAdmin(int $admin_id = 0): bool
    {
        if (!$admin_id) {
            $role = static::getAdminRole();
        } else {
            $admin = User::find($admin_id);
            if (!$admin) {
                return false;
            }
            $role = Role::find($admin['role'])?->toArray();
        }
        return is_array($role) && ($role['rules'] ?? null) === '*';
    }

}
