<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use plugin\admin\app\model\Role;
use plugin\admin\app\model\User;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 用户管理
 */
class UserController extends Crud
{
    /**
     * @var User
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new User;
    }

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return view('user/index');
    }

    /**
     * 插入
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            $data = $this->insertInput($request);
            $data = $this->normalizeUniqueContactFields($data);
            if ($msg = $this->checkUniqueContactFields($data)) {
                return $this->json(1, $msg);
            }
            $id = $this->doInsert($data);
            return $this->json(0, 'ok', ['id' => $id]);
        }
        return view('user/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function update(Request $request): Response
    {
        if ($request->method() === 'POST') {
            [$id, $data] = $this->updateInput($request);
            $data = $this->normalizeUniqueContactFields($data);
            if ($msg = $this->checkUniqueContactFields($data, (int)$id)) {
                return $this->json(1, $msg);
            }
            $this->doUpdate($id, $data);
            return $this->json(0);
        }
        return view('user/update');
    }

    /**
     * 删除
     * @param Request $request
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function delete(Request $request): Response
    {
        $ids = $this->deleteInput($request);
        $role_ids = User::whereIn('id', $ids)->pluck('role')->unique()->toArray();
        if ($role_ids && Role::whereIn('id', $role_ids)->where('rules', '*')->exists()) {
            return $this->json(1, '无法删除超级管理员用户');
        }
        $this->doDelete($ids);
        return $this->json(0);
    }

    /**
     * 唯一联系方式字段为空时存 null，避免多个空字符串触发唯一索引冲突
     * @param array $data 用户表单数据
     * @return array
     * @throws BusinessException
     */
    protected function normalizeUniqueContactFields(array $data): array
    {
        foreach (['email', 'mobile'] as $field) {
            if (array_key_exists($field, $data) && !is_string($data[$field]) && $data[$field] !== null) {
                throw new BusinessException('联系方式字段参数错误');
            }
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        return $data;
    }

    /**
     * 检查唯一联系方式字段
     * @param array $data 用户表单数据
     * @param int|null $ignore_id 需要排除的用户 ID
     * @return string
     */
    protected function checkUniqueContactFields(array $data, ?int $ignore_id = null): string
    {
        $labels = ['email' => '邮箱', 'mobile' => '手机号'];
        foreach ($labels as $field => $label) {
            if (empty($data[$field])) {
                continue;
            }
            $query = User::where($field, $data[$field]);
            if ($ignore_id !== null) {
                $query->where('id', '<>', $ignore_id);
            }
            if ($query->exists()) {
                return "{$label}已存在";
            }
        }
        return '';
    }

}
