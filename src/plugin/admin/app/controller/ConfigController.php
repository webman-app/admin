<?php
declare(strict_types=1);

namespace plugin\admin\app\controller;

use plugin\admin\app\common\Util;
use plugin\admin\app\model\Option;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 系统设置
 */
class ConfigController extends Base
{
    /**
     * 不需要验证权限的方法
     * @var string[]
     */
    protected $noNeedAuth = ['get'];

    /**
     * 账户设置
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return view('config/index');
    }

    /**
     * 获取配置
     * @return Response
     */
    public function get(): Response
    {
        return json($this->getByDefault());
    }

    /**
     * 基于配置文件获取默认权限
     * @return array
     */
    protected function getByDefault(): array
    {
        $name = 'system_config';
        $config = Option::where('name', $name)->value('value');
        if (empty($config)) {
            $config = file_get_contents(base_path('plugin/admin/public/config/pear.config.json'));
            if ($config) {
                $option = new Option();
                $option->name = $name;
                $option->value = $config;
                $option->save();
            }
        }
        $decoded = is_string($config) ? json_decode($config, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 更改
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function update(Request $request): Response
    {
        $post = $request->post();
        $config = $this->getByDefault();
        if (!$config) {
            throw new BusinessException('系统配置为空');
        }
        $data = [];
        foreach ($post as $section => $items) {
            if (!isset($config[$section])) {
                continue;
            }
            if (!is_array($items)) {
                continue;
            }
            switch ($section) {
                case 'logo':
                    $data[$section]['title'] = htmlspecialchars($this->scalarConfigValue($items['title'] ?? ''));
                    $data[$section]['image'] = Util::filterUrlPath($this->scalarConfigValue($items['image'] ?? ''));
                    $data[$section]['icp'] = htmlspecialchars($this->scalarConfigValue($items['icp'] ?? ''));
                    $data[$section]['beian'] = htmlspecialchars($this->scalarConfigValue($items['beian'] ?? ''));
                    $data[$section]['footer_txt'] = htmlspecialchars($this->scalarConfigValue($items['footer_txt'] ?? ''));
                    break;
                case 'menu':
                    $data[$section]['data'] = htmlspecialchars($this->scalarConfigValue($items['data'] ?? ''));
                    $data[$section]['accordion'] = !empty($items['accordion']);
                    $data[$section]['collapse'] = !empty($items['collapse']);
                    $data[$section]['control'] = !empty($items['control']);
                    $data[$section]['controlWidth'] = (int)($items['controlWidth'] ?? 2000);
                    $data[$section]['select'] = (int)$this->scalarConfigValue($items['select'] ?? '0', '0');
                    $data[$section]['async'] = true;
                    break;
                case 'tab':
                    $index_config = is_array($items['index'] ?? null) ? $items['index'] : [];
                    $data[$section]['enable'] = true;
                    $data[$section]['keepState'] = !empty($items['keepState']);
                    $data[$section]['preload'] = !empty($items['preload']);
                    $data[$section]['session'] = !empty($items['session']);
                    $data[$section]['max'] = Util::filterNum($this->scalarConfigValue($items['max'] ?? '30', '30'));
                    $data[$section]['index']['id'] = Util::filterNum($this->scalarConfigValue($index_config['id'] ?? '0', '0'));
                    $data[$section]['index']['href'] = Util::filterUrlPath($this->scalarConfigValue($index_config['href'] ?? ''));
                    $data[$section]['index']['title'] = htmlspecialchars($this->scalarConfigValue($index_config['title'] ?? '首页', '首页'));
                    break;
                case 'theme':
                    $data[$section]['defaultColor'] = Util::filterNum($this->scalarConfigValue($items['defaultColor'] ?? '2', '2'));
                    $data[$section]['defaultMenu'] = $this->scalarConfigValue($items['defaultMenu'] ?? '') == 'dark-theme' ?  'dark-theme' : 'light-theme';
                    $data[$section]['defaultHeader'] = $this->scalarConfigValue($items['defaultHeader'] ?? '') == 'dark-theme' ?  'dark-theme' : 'light-theme';
                    $data[$section]['allowCustom'] = !empty($items['allowCustom']);
                    $data[$section]['banner'] = !empty($items['banner']);
                    break;
                case 'colors':
                    $colors = is_array($config['colors'] ?? null) ? $config['colors'] : [];
                    foreach ($colors as $index => $item) {
                        if (!isset($items[$index])) {
                            $config['colors'][$index] = $item;
                            continue;
                        }
                        $data_item = $items[$index];
                        if (!is_array($data_item)) {
                            $data_item = [];
                        }
                        $data[$section][$index]['id'] = $index + 1;
                        $data[$section][$index]['color'] = $this->filterColor($this->scalarConfigValue($data_item['color'] ?? ''));
                        $data[$section][$index]['second'] = $this->filterColor($this->scalarConfigValue($data_item['second'] ?? ''));
                    }
                    break;

            }
        }
        $config = array_merge($config, $data);
        $name = 'system_config';
        Option::where('name', $name)->update([
            'value' => json_encode($config)
        ]);
        return $this->json(0);
    }

    /**
     * 将配置值归一化为字符串
     * @param mixed $value 配置值
     * @param string $default 默认值
     * @return string
     */
    protected function scalarConfigValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * 颜色检查
     * @param string $color
     * @return string
     * @throws BusinessException
     */
    protected function filterColor(string $color): string
    {
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $color)) {
            throw new BusinessException('参数错误');
        }
        return $color;
    }

}
