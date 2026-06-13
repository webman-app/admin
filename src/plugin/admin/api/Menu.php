<?php

declare(strict_types=1);

namespace plugin\admin\api;

use plugin\admin\app\model\Rule;

/**
 * 对外提供的菜单接口
 */
class Menu
{

    /**
     * 根据key获取菜单
     * @param string $key 菜单 key
     * @return array|null
     */
    public static function get(string $key): ?array
    {
        $menu = Rule::where('key', $key)->first();
        return $menu?->toArray();
    }

    /**
     * 根据id获得菜单
     * @param int $id 菜单 ID
     * @return array|null
     */
    public static function find(int $id): ?array
    {
        return Rule::find($id)?->toArray();
    }

    /**
     * 添加菜单
     * @param array $menu
     * @return int
     */
    public static function add(array $menu): int
    {
        $item = new Rule;
        foreach ($menu as $key => $value) {
            $item->$key = $value;
        }
        $item->save();
        return $item->id;
    }

    /**
     * 导入菜单
     * @param array $menu_tree
     * @return void
     */
    public static function import(array $menu_tree): void
    {
        if (is_numeric(key($menu_tree)) && !isset($menu_tree['key'])) {
            foreach ($menu_tree as $item) {
                static::import($item);
            }
            return;
        }
        $children = $menu_tree['children'] ?? [];
        unset($menu_tree['children']);
        if ($old_menu = Menu::get($menu_tree['key'])) {
            $pid = $old_menu['id'];
            Rule::where('key', $menu_tree['key'])->update($menu_tree);
        } else {
            $pid = static::add($menu_tree);
        }
        foreach ($children as $menu) {
            $menu['pid'] = $pid;
            static::import($menu);
        }
    }

    /**
     * 删除菜单
     * @param string $key 菜单 key
     * @return void
     */
    public static function delete(string $key): void
    {
        $item = Rule::where('key', $key)->first();
        if (!$item) {
            return;
        }
        // 子规则一起删除
        $delete_ids = $children_ids = [$item['id']];
        while ($children_ids) {
            $children_ids = Rule::whereIn('pid', $children_ids)->pluck('id')->toArray();
            $delete_ids = array_merge($delete_ids, $children_ids);
        }
        Rule::whereIn('id', $delete_ids)->delete();
    }


    /**
     * 获取菜单中某个(些)字段的值
     * @param array $menu
     * @param array|string|null $column
     * @param ?string $index
     * @return array
     */
    public static function column(array $menu, array|string|null $column = null, ?string $index = null): array
    {
        $values = [];
        if (is_numeric(key($menu)) && !isset($menu['key'])) {
            foreach ($menu as $item) {
                $values = array_merge($values, static::column($item, $column, $index));
            }
            return $values;
        }

        $children = $menu['children'] ?? [];
        unset($menu['children']);
        if ($index && !isset($menu[$index])) {
            foreach ($children as $child) {
                $values = array_merge($values, static::column($child, $column, $index));
            }
            return $values;
        }
        if ($column === null) {
            if ($index) {
                $index_value = $menu[$index];
                $values[$index_value] = $menu;
            } else {
                $values[] = $menu;
            }
        } else {
            if (is_array($column)) {
                $item = [];
                foreach ($column as $f) {
                    $item[$f] = $menu[$f] ?? null;
                }
                if ($index) {
                    $index_value = $menu[$index];
                    $values[$index_value] = $item;
                } else {
                    $values[] = $item;
                }
            } else {
                $value = $menu[$column] ?? null;
                if ($index) {
                    $index_value = $menu[$index];
                    $values[$index_value] = $value;
                } else {
                    $values[] = $value;
                }
            }
        }
        foreach ($children as $child) {
            $values = array_merge($values, static::column($child, $column, $index));
        }
        return $values;
    }

}
