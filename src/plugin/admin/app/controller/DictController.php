<?php
declare(strict_types=1);

namespace plugin\admin\app\controller;

use plugin\admin\app\model\Dict;
use plugin\admin\app\model\Option;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 字典管理
 */
class DictController extends Base
{
    /**
     * 不需要授权的方法
     */
    protected $noNeedAuth = ['get'];

    /**
     * 浏览
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return view('dict/index');
    }

    /**
     * 查询
     * @param Request $request
     * @return Response
     */
    public function select(Request $request): Response
    {
        $name = $request->get('name', '');
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $offset = ($page-1)*$limit;
        if ($name && is_string($name)) {
            $items = Option::where('name', 'like', "dict_$name%")->limit($limit)->offset($offset)->get()->toArray();
        } else {
            $items = Option::where('name', 'like', 'dict_%')->limit($limit)->offset($offset)->get()->toArray();
        }
        $get_items = Option::where('name', 'like', "dict_$name%")->get()->toArray();
        $count = count($get_items);
        foreach ($items as &$item) {
            $item['name'] = Dict::optionNameTodictName($item['name']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'count' => $count, 'data' => $items]);
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
            $name = $request->post('name');
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                return $this->json(2, '字典名称只能是字母数字下划线的组合');
            }
            if (Dict::get($name)) {
                return $this->json(1, '字典已经存在');
            }
            $values = (array)$request->post('value', []);
            Dict::save($name, $values);
        }
        return view('dict/insert');
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
            $name = $request->post('name');
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                return $this->json(2, '字典名称只能是字母数字下划线的组合');
            }
            if (!Dict::get($name)) {
                return $this->json(1, '字典不存在');
            }
            Dict::save($name, $request->post('value'));
        }
        return view('dict/update');
    }

    /**
     * 删除
     * @param Request $request
     * @return Response
     */
    public function delete(Request $request): Response
    {
        $names = (array)$request->post('name');
        $names = array_filter($names, 'is_string');
        Dict::delete($names);
        return $this->json(0);
    }

    /**
     * 获取
     * @param Request $request
     * @param string $name
     * @return Response
     */
    public function get(Request $request, string $name): Response
    {
        return $this->json(0, 'ok', (array)Dict::get($name));
    }

}
