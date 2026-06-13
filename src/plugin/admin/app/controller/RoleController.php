<?php
declare(strict_types=1);

namespace plugin\admin\app\controller;

use Exception;
use plugin\admin\app\common\Auth;
use plugin\admin\app\common\Tree;
use plugin\admin\app\model\Role;
use plugin\admin\app\model\Rule;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 角色管理
 */
class RoleController extends Crud
{
    /**
     * 不需要鉴权的方法
     * @var array
     */
    protected $noNeedAuth = ['select'];

    /**
     * @var Role
     */
    protected $model = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new Role;
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return view('role/index');
    }

    /**
     * 查询
     * @param Request $request
     * @return Response
     * @throws BusinessException|Exception
     */
    public function select(Request $request): Response
    {
        $id = $request->get('id');
        [$where, $format, , $field, $order] = $this->selectInput($request);
        $limit = 100000;
        $role_ids = Auth::getScopeRoleIds(true);
        if (!$id) {
            $where['id'] = ['in', $role_ids];
        } else {
            if (!is_scalar($id)) {
                throw new BusinessException('角色参数错误');
            }
            if (!in_array($id, $role_ids)) {
                throw new BusinessException('无权限');
            }
        }
        $query = $this->doSelect($where, $field, $order);
        return $this->doFormat($query, $format, $limit);
    }

    /**
     * 插入
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @throws Throwable
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            $data = $this->insertInput($request);
            try {
                $pid = $this->normalizeRolePid($data['pid'] ?? null);
            } catch (BusinessException $e) {
                return $this->json(1, $e->getMessage());
            }
            $data['pid'] = $pid;
            if (!Auth::isSuperAdmin() && !in_array($pid, Auth::getScopeRoleIds(true))) {
                return $this->json(1, '父级角色组超出权限范围');
            }
            $this->checkRules($pid, $data['rules'] ?? '');

            $id = $this->doInsert($data);
            return $this->json(0, 'ok', ['id' => $id]);
        }
        return view('role/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function update(Request $request): Response
    {
        if ($request->method() === 'GET') {
            return view('role/update');
        }
        [$id, $data] = $this->updateInput($request);
        $is_supper_admin = Auth::isSuperAdmin();
        $descendant_role_ids = Auth::getScopeRoleIds();
        if (!$is_supper_admin && !in_array($id, $descendant_role_ids)) {
            return $this->json(1, '无数据权限');
        }

        $role = Role::find($id);
        if (!$role) {
            return $this->json(1, '数据不存在');
        }
        $is_supper_role = $role->rules === '*';

        // 超级角色组不允许更改rules pid 字段
        if ($is_supper_role) {
            unset($data['rules'], $data['pid']);
        }

        if (key_exists('pid', $data)) {
            try {
                $pid = $this->normalizeRolePid($data['pid']);
            } catch (BusinessException $e) {
                return $this->json(1, $e->getMessage());
            }
            $data['pid'] = $pid;
            if ($pid == $id) {
                return $this->json(1, '父级不能是自己');
            }
            if (!$is_supper_admin && !in_array($pid, Auth::getScopeRoleIds(true))) {
                return $this->json(1, '父级超出权限范围');
            }
        } else {
            $pid = $role->pid;
        }

        if (!$is_supper_role) {
            $this->checkRules($pid, $data['rules'] ?? '');
        }

        $this->doUpdate($id, $data);

        // 删除所有子角色组中已经不存在的权限
        if (!$is_supper_role) {
            $tree = new Tree(Role::select(['id', 'pid'])->get());
            $descendant_roles = $tree->getDescendant([$id]);
            $descendant_role_ids = array_column($descendant_roles, 'id');
            $rule_id_string = $data['rules'] ?? $role->rules ?? '';
            $rule_ids = $rule_id_string ? explode(',', $rule_id_string) : [];
            foreach ($descendant_role_ids as $role_id) {
                $tmp_role = Role::find($role_id);
                $tmp_rule_ids = $tmp_role->getRuleIds();
                $tmp_rule_ids = array_intersect($rule_ids, $tmp_rule_ids);
                $tmp_role->rules = implode(',', $tmp_rule_ids);
                $tmp_role->save();
            }
        }

        return $this->json(0);
    }

    /**
     * 归一化父级角色 ID
     * @param mixed $pid
     * @return int|null
     * @throws BusinessException
     */
    protected function normalizeRolePid(mixed $pid): ?int
    {
        if (is_array($pid)) {
            $pid = array_values(array_filter($pid, static function ($item) {
                return is_scalar($item) && $item !== '';
            }));
            $pid = $pid[0] ?? null;
        }
        if ($pid === null || $pid === '' || $pid === '0' || $pid === 0) {
            return null;
        }
        if (!is_scalar($pid)) {
            throw new BusinessException('父级角色参数错误');
        }
        return (int)$pid;
    }

    /**
     * 删除
     * @param Request $request
     * @return Response
     * @throws BusinessException|Exception
     */
    public function delete(Request $request): Response
    {
        $ids = $this->deleteInput($request);
        if (Role::whereIn('id', $ids)->where('rules', '*')->exists()) {
            return $this->json(1, '无法删除超级管理员角色');
        }
        if (!Auth::isSuperAdmin() && array_diff($ids, Auth::getScopeRoleIds())) {
            return $this->json(1, '无删除权限');
        }
        $tree = new Tree(Role::get());
        $descendants = $tree->getDescendant($ids);
        if ($descendants) {
            $ids = array_merge($ids, array_column($descendants, 'id'));
        }
        $this->doDelete($ids);
        return $this->json(0);
    }

    /**
     * 获取角色权限
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function rules(Request $request): Response
    {
        $role_id = $request->get('id');
        if (empty($role_id)) {
            return $this->json(0);
        }
        if (!is_scalar($role_id)) {
            return $this->json(1, '角色组参数错误');
        }
        if (!Auth::isSuperAdmin() && !in_array($role_id, Auth::getScopeRoleIds(true))) {
            return $this->json(1, '角色组超出权限范围');
        }
        $rule_id_string = Role::where('id', $role_id)->value('rules');
        if (empty($rule_id_string)) {
            return $this->json(0);
        }
        $rules = Rule::get();
        $include = [];
        if ($rule_id_string !== '*') {
            $include = explode(',', $rule_id_string);
        }
        $items = [];
        foreach ($rules as $item) {
            $items[] = [
                'name' => $item->title ?? $item->name ?? $item->id,
                'value' => (string)$item->id,
                'id' => $item->id,
                'pid' => $item->pid,
            ];
        }
        $tree = new Tree($items);
        return $this->json(0, 'ok', $tree->getTree($include));
    }

    /**
     * 检查权限字典是否合法
     * @param int|null $role_id
     * @param $rule_ids
     * @return void
     * @throws BusinessException
     */
    protected function checkRules(?int $role_id, $rule_ids): void
    {
        if ($rule_ids) {
            if (!is_scalar($rule_ids)) {
                throw new BusinessException('权限参数错误');
            }
            $rule_ids = array_filter(explode(',', (string)$rule_ids), 'strlen');
            if (in_array('*', $rule_ids, true)) {
                throw new BusinessException('非法数据');
            }
            $rule_exists = Rule::whereIn('id', $rule_ids)->pluck('id');
            if (count($rule_exists) != count($rule_ids)) {
                throw new BusinessException('权限不存在');
            }
            // 顶级角色(role_id为null)不检查父级权限范围
            if ($role_id === null) {
                return;
            }
            $rule_id_string = Role::where('id', $role_id)->value('rules');
            if (!$rule_id_string) {
                throw new BusinessException('数据超出权限范围');
            }
            if ($rule_id_string === '*') {
                return;
            }
            $legal_rule_ids = explode(',', (string)$rule_id_string);
            if (array_diff($rule_ids, $legal_rule_ids)) {
                throw new BusinessException('数据超出权限范围');
            }
        }
    }


}
