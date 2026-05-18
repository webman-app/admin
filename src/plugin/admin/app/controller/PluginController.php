<?php

namespace plugin\admin\app\controller;

use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Installer;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use plugin\admin\app\common\Util;
use app\process\Monitor;
use support\exception\BusinessException;
use support\Log;
use support\Plugin;
use support\Request;
use support\Response;
use Throwable;
use ZipArchive;
use function array_diff;
use function ini_get;
use function scandir;
use const DIRECTORY_SEPARATOR;
use const PATH_SEPARATOR;

class PluginController extends Base
{
    /**
     * 不需要鉴权的方法
     * @var string[]
     */
    protected $noNeedAuth = ['schema', 'captcha'];

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        // 返回本地视图，覆盖远程页面
        return view('admin/plugin/index');
    }

    /**
     * 列表（合并本地和远程插件）
     * @param Request $request
     * @return Response
     * @throws GuzzleException
     */
    public function list(Request $request): Response
    {
        $local_plugins = $this->getLocalPlugins(); // [name => ['version' => ..., 'title' => ..., 'url' => ...]]

        // 缓存文件
        $cache_file = runtime_path('plugin/app.json');
        $cache_ttl = 86400; // 1天

        // 检查缓存
        $all_items = null;
        if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
            $cached = json_decode(file_get_contents($cache_file), true);
            if ($cached && isset($cached['items'])) {
                $all_items = $cached['items'];
            }
        }

        // 缓存不存在或过期，重新获取并合并
        if ($all_items === null) {
            $remote_items = $this->fetchRemoteApps();
            $all_items = $this->mergePlugins($local_plugins, $remote_items);

            // 保存缓存
            $cache_dir = dirname($cache_file);
            if (!is_dir($cache_dir)) {
                mkdir($cache_dir, 0755, true);
            }
            file_put_contents($cache_file, json_encode([
                'items' => $all_items,
                'updated_at' => time(),
            ], JSON_UNESCAPED_UNICODE));
        } else {
            // 缓存命中，重新标记本地状态（本地插件可能已变化）
            $all_items = $this->mergePlugins($local_plugins, $all_items);
        }

        // 搜索过滤
        $keyword = trim($request->get('name', ''));
        if ($keyword !== '') {
            $all_items = array_filter($all_items, function ($item) use ($keyword) {
                $name = $item['name'] ?? '';
                $title = $item['title'] ?? '';
                return stripos($name, $keyword) !== false
                    || stripos($title, $keyword) !== false;
            });
            $all_items = array_values($all_items); // 重置索引
        }

        // 分页
        $total = count($all_items);
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 20);
        $offset = max(0, ($page - 1) * $limit);
        $page_items = array_slice($all_items, $offset, $limit);

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => $page_items,
            'count' => $total,
        ]);
    }

    /**
     * 获取全部远程官方应用（limit=9999 一次性获取）
     * @return array
     * @throws GuzzleException|Exception
     */
    protected function fetchRemoteApps(): array
    {
        $client = $this->httpClient();
        $response = $client->get('/api/app/list', [
            'query' => [
                'version' => $this->getAdminVersion(),
                'limit' => 9999,
            ]
        ]);
        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        if (!$data || !isset($data['data']['items'])) {
            return [];
        }
        $items = $data['data']['items'];
        // 关联数组转索引数组
        if (!empty($items) && !isset($items[0])) {
            $items = array_values($items);
        }
        return $items;
    }

    /**
     * 合并本地和远程插件列表，本地优先
     * @param array $local_plugins 本地插件列表，格式为 [name => ['version' => ..., 'title' => ..., 'url' => ...]]
     * @param array $remote_items 远程插件列表，格式为 [name => ..., 'title' => ..., 'url' => ...]
     * @return array 合并后的插件列表
     */
    protected function mergePlugins(array $local_plugins, array $remote_items): array
    {
        $result = [];

        // 远程插件建立索引，避免 O(n²) 查找
        $remote_map = [];
        foreach ($remote_items as $remote) {
            $name = $remote['name'] ?? '';
            if ($name) {
                $remote_map[$name] = $remote;
            }
        }

        // 先处理本地插件，优先显示
        foreach ($local_plugins as $name => $info) {
            $version = $info['version'] ?? null;
            $local_title = $info['title'] ?? $name;
            $local_url = $info['url'] ?? '';

            if (isset($remote_map[$name])) {
                // 有远程信息，合并（官方优先）
                $item = array_merge($remote_map[$name], [
                    'version' => $version,
                    'installed' => $version,
                    'local' => true,
                ]);
                if (empty($item['title'])) {
                    $item['title'] = $local_title;
                }
                if (empty($item['url'])) {
                    $item['url'] = $local_url;
                }
            } else {
                // 纯本地插件
                $item = [
                    'name' => $name,
                    'title' => $local_title,
                    'url' => $local_url,
                    'version' => $version,
                    'installed' => $version,
                    'author' => '本地',
                    'price' => '0',
                    'local' => true,
                ];
            }
            $result[] = $item;
        }

        // 追加远程有但本地没有的插件
        foreach ($remote_items as $item) {
            $name = $item['name'] ?? '';
            if ($name && !isset($local_plugins[$name])) {
                $item['title'] = $item['title'] ?? $name;
                $item['installed'] = 0;
                $item['local'] = false;
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * 清除应用列表缓存
     * @return void
     */
    protected function clearAppListCache(): void
    {
        $cache_file = runtime_path('plugin/app.json');
        if (is_file($cache_file)) {
            @unlink($cache_file);
        }
    }

    /**
     * 安装
     * @param Request $request
     * @return Response
     * @throws GuzzleException|BusinessException|Exception
     */
    public function install(Request $request): Response
    {
        $name = $request->post('name');
        $version = $request->post('version');
        $installed_version = $this->getPluginVersion($name);
        if (!$name || !$version) {
            return $this->json(1, '缺少参数');
        }

        $user = session('app-plugin-user');
        if (!$user) {
            return $this->json(-1, '请登录');
        }

        // 获取下载zip文件url
        $data = $this->getDownloadUrl($name, $version);
        if ($data['code'] != 0) {
            return $this->json($data['code'], $data['msg'], $data['data'] ?? []);
        }

        // 下载zip文件
        $base_path = base_path() . "/plugin/$name";
        $zip_file = "$base_path.zip";
        $extract_to = base_path() . '/plugin/';
        $this->downloadZipFile($data['data']['url'], $zip_file);

        $has_zip_archive = class_exists(ZipArchive::class, false);
        if (!$has_zip_archive) {
            $cmd = $this->getUnzipCmd($zip_file, $extract_to);
            if (!$cmd) {
                throw new BusinessException('请给php安装zip模块或者给系统安装unzip命令');
            }
            if (!function_exists('proc_open')) {
                throw new BusinessException('请解除proc_open函数的禁用或者给php安装zip模块');
            }
        }

        Util::pauseFileMonitor();
        try {
            // 解压zip到plugin目录
            if ($has_zip_archive) {
                $zip = new ZipArchive;
                $zip->open($zip_file);
            }

            $context = null;
            $install_class = "\\plugin\\$name\\api\\Install";
            if ($installed_version) {
                // 执行beforeUpdate
                if (class_exists($install_class) && method_exists($install_class, 'beforeUpdate')) {
                    $context = call_user_func([$install_class, 'beforeUpdate'], $installed_version, $version);
                }
            }

            if (!empty($zip)) {
                $zip->extractTo(base_path() . '/plugin/');
                unset($zip);
            } else {
                $this->unzipWithCmd($cmd);
            }

            unlink($zip_file);

            // 安装 composer 依赖包
            $packages = $this->getPluginPackages($name);
            if (!empty($packages)) {
                $result = $this->syncComposerPackages($packages, true, '', $installed_version !== null);
                if (!$result['success']) {
                    $this->rmDir($base_path);
                    throw new BusinessException("Plugin $name require packages failed: " . $result['message']);
                }
            }

            if ($installed_version) {
                // 执行update更新
                if (class_exists($install_class) && method_exists($install_class, 'update')) {
                    call_user_func([$install_class, 'update'], $installed_version, $version, $context);
                }
            } else {
                // 执行install安装
                if (class_exists($install_class) && method_exists($install_class, 'install')) {
                    call_user_func([$install_class, 'install'], $version);
                }
            }
        } finally {
            Util::resumeFileMonitor();
        }

        Util::reloadWebman();

        return $this->json(0);
    }

    /**
     * 卸载
     * @param Request $request
     * @return Response
     */
    public function uninstall(Request $request): Response
    {
        $name = $request->post('name');
        $version = $request->post('version');
        if (!$name || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            return $this->json(1, '参数错误');
        }

        // 获得插件路径
        clearstatcache();
        $path = get_realpath(base_path() . "/plugin/$name");
        if (!$path || !is_dir($path)) {
            return $this->json(1, '已经删除');
        }

        // 获取 packages 用于卸载后移除依赖
        $packages = $this->getPluginPackages($name);

        // 执行uninstall卸载
        $install_class = "\\plugin\\$name\\api\\Install";
        if (class_exists($install_class) && method_exists($install_class, 'uninstall')) {
            call_user_func([$install_class, 'uninstall'], $version);
        }

        // 删除目录
        clearstatcache();
        if (is_dir($path)) {
            $monitor_support_pause = method_exists(Monitor::class, 'pause');
            if ($monitor_support_pause) {
                Monitor::pause();
            }
            try {
                $this->rmDir($path);
            } finally {
                if ($monitor_support_pause) {
                    Monitor::resume();
                }
            }
        }
        clearstatcache();

        // 清除应用列表缓存
        $this->clearAppListCache();

        // 移除 composer 依赖包
        if (!empty($packages)) {
            $this->syncComposerPackages($packages, false, $name);
        }

        Util::reloadWebman();

        return $this->json(0);
    }

    /**
     * 支付
     * @param Request $request
     * @return string|Response
     * @throws GuzzleException|Exception
     */
    public function pay(Request $request): Response|string
    {
        $app = $request->get('app');
        if (!$app) {
            return response('app not found');
        }
        $token = session('app-plugin-token');
        if (!$token) {
            return 'Please login workerman.net';
        }
        $client = $this->httpClient();
        $response = $client->get("/payment/app/$app/$token");
        return (string)$response->getBody();
    }

    /**
     * 登录验证码
     * @param Request $request
     * @return Response
     * @throws GuzzleException|Exception
     */
    public function captcha(Request $request): Response
    {
        $client = $this->httpClient();
        $response = $client->get('/user/captcha?type=login');
        $sid_str = $response->getHeaderLine('Set-Cookie');
        if (preg_match('/PHPSID=([a-zA-Z_0-9]+?);/', $sid_str, $match)) {
            $sid = $match[1];
            session()->set('app-plugin-token', $sid);
        }
        return response($response->getBody()->getContents())->withHeader('Content-Type', 'image/jpeg');
    }

    /**
     * 登录官网
     * @param Request $request
     * @return Response|string
     * @throws GuzzleException|Exception
     */
    public function login(Request $request): Response|string
    {
        $client = $this->httpClient();
        if ($request->method() === 'GET') {
            $response = $client->get("/webman-admin/login");
            return (string)$response->getBody();
        }

        $response = $client->post('/api/user/login', [
            'form_params' => [
                'email' => $request->post('username'),
                'password' => $request->post('password'),
                'captcha' => $request->post('captcha')
            ]
        ]);
        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        if (!$data) {
            Log::error("/api/user/login return $content");
            return $this->json(1, '发生错误');
        }
        if ($data['code'] != 0) {
            return $this->json($data['code'], $data['msg']);
        }
        session()->set('app-plugin-user', [
            'uid' => $data['data']['uid']
        ]);
        return $this->json(0);
    }

    /**
     * 获取zip下载url
     * @param $name
     * @param $version
     * @return mixed
     * @throws BusinessException|GuzzleException|Exception
     */
    protected function getDownloadUrl($name, $version): mixed
    {
        $client = $this->httpClient();
        $response = $client->get("/app/download/$name?version=$version");

        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        if (!$data) {
            $msg = "/api/app/download return $content";
            Log::error($msg);
            throw new BusinessException('访问官方接口失败 ' . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
        }
        if ($data['code'] && $data['code'] != -1 && $data['code'] != -2) {
            throw new BusinessException($data['msg']);
        }
        if ($data['code'] == 0 && !isset($data['data']['url'])) {
            throw new BusinessException('官方接口返回数据错误');
        }
        return $data;
    }

    /**
     * 下载zip
     * @param $url
     * @param $file
     * @return void
     * @throws BusinessException|GuzzleException|Exception
     */
    protected function downloadZipFile($url, $file): void
    {
        $client = $this->downloadClient();
        $response = $client->get($url);
        $body = $response->getBody();
        $status = $response->getStatusCode();
        if ($status == 404) {
            throw new BusinessException('安装包不存在');
        }
        $zip_content = $body->getContents();
        if (empty($zip_content)) {
            throw new BusinessException('安装包不存在');
        }
        file_put_contents($file, $zip_content);
    }

    /**
     * 获取系统支持的解压命令
     * @param $zip_file
     * @param $extract_to
     * @return mixed
     */
    protected function getUnzipCmd($zip_file, $extract_to): mixed
    {
        if ($cmd = $this->findCmd('unzip')) {
            $cmd = "$cmd -o -qq $zip_file -d $extract_to";
        } else if ($cmd = $this->findCmd('7z')) {
            $cmd = "$cmd x -bb0 -y $zip_file -o$extract_to";
        } else if ($cmd = $this->findCmd('7zz')) {
            $cmd = "$cmd x -bb0 -y $zip_file -o$extract_to";
        }
        return $cmd;
    }

    /**
     * 使用解压命令解压
     * @param $cmd
     * @return void
     * @throws BusinessException
     */
    protected function unzipWithCmd($cmd): void
    {
        $desc = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $handler = proc_open($cmd, $desc, $pipes);
        if (!is_resource($handler)) {
            throw new BusinessException("解压zip时出错:proc_open调用失败");
        }
        $err = fread($pipes[2], 1024);
        fclose($pipes[2]);
        proc_close($handler);
        if ($err) {
            throw new BusinessException("解压zip时出错:$err");
        }
    }

    /**
     * 获取已安装的插件列表
     * @return array
     */
    protected function getLocalPlugins(): array
    {
        clearstatcache();
        $installed = [];
        $plugin_names = array_diff(scandir(base_path() . '/plugin/'), array('.', '..')) ?: [];
        foreach ($plugin_names as $plugin_name) {
            if (is_dir(base_path() . "/plugin/$plugin_name")) {
                $info = $this->getPluginInfo($plugin_name);
                if ($info['version']) {
                    $installed[$plugin_name] = $info;
                }
            }
        }
        return $installed;
    }

    /**
     * 获取插件信息（版本、标题、链接）
     * @param string $name
     * @return array
     */
    protected function getPluginInfo(string $name): array
    {
        $config_file = base_path() . "/plugin/$name/config/app.php";
        if (!is_file($config_file)) {
            return ['version' => null, 'title' => $name, 'url' => ''];
        }
        $config = include $config_file;
        return [
            'version' => $config['version'] ?? null,
            'title' => $config['title'] ?? $name,
            'url' => $config['url'] ?? '',
        ];
    }

    /**
     * 获取已安装的插件列表
     * @param Request $request
     * @return Response
     */
    public function getInstalledPlugins(Request $request): Response
    {
        return $this->json(0, 'ok', $this->getLocalPlugins());
    }

    /**
     * 导出插件为 ZIP
     * @param Request $request
     * @return Response
     */
    public function export(Request $request): Response
    {
        $name = $request->get('name');
        if (!$name || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            return $this->json(1, '参数错误');
        }

        $plugin_path = base_path() . "/plugin/$name";
        if (!is_dir($plugin_path)) {
            return $this->json(1, '插件目录不存在');
        }

        // 临时目录
        $temp_dir = base_path() . "/runtime/plugin";
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }

        $zip_file = $temp_dir . "/$name.zip";
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->json(1, '无法创建 ZIP 文件');
        }

        $this->addDirToZip($zip, $plugin_path, $name);
        $zip->close();

        if (!is_file($zip_file)) {
            return $this->json(1, 'ZIP 文件生成失败');
        }

        // 发送文件（不要删除，workerman 的 response()->file() 在发送时才读取文件）
        return response()->file($zip_file)->withHeader('Content-Disposition', "attachment; filename=$name.zip");
    }

    /**
     * 导入插件（上传 ZIP）
     * @param Request $request
     * @return Response
     */
    public function import(Request $request): Response
    {
        $file = current($request->file());
        if (!$file || !$file->isValid()) {
            return $this->json(1, '请选择要导入的 ZIP 文件');
        }

        // 检查 zip 扩展
        if (!class_exists(ZipArchive::class, false)) {
            return $this->json(1, '服务器未安装 zip 扩展');
        }

        $tmp_name = $file->getRealPath();
        $zip = new ZipArchive();
        if ($zip->open($tmp_name) !== true) {
            return $this->json(1, '无效的 ZIP 文件');
        }

        // 获取 ZIP 内的插件目录名（第一个目录）
        $plugin_name = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (str_contains($name, '/')) {
                $parts = explode('/', $name);
                if (count($parts) >= 2 && $parts[0] && !str_contains($parts[0], '.')) {
                    $plugin_name = $parts[0];
                    break;
                }
            }
        }
        $zip->close();

        if (!$plugin_name || !preg_match('/^[a-zA-Z0-9_]+$/', $plugin_name)) {
            return $this->json(1, 'ZIP 内未找到有效的插件目录');
        }

        // 解压到 plugin 目录
        $extract_to = base_path() . '/plugin/';
        $plugin_path = base_path() . "/plugin/$plugin_name";
        Util::pauseFileMonitor();
        try {
            // 插件已存在时，先执行旧版本的卸载函数再覆盖安装
            if (is_dir($plugin_path)) {
                $old_version = $this->getPluginVersion($plugin_name);
                $install_class = "\\plugin\\$plugin_name\\api\\Install";
                if (class_exists($install_class) && method_exists($install_class, 'uninstall')) {
                    try {
                        call_user_func([$install_class, 'uninstall'], $old_version);
                    } catch (Throwable $e) {
                        Log::warning("Import plugin $plugin_name: old uninstall failed: " . $e->getMessage());
                    }
                }
                // 删除旧插件目录
                clearstatcache();
                if (is_dir($plugin_path)) {
                    $this->rmDir($plugin_path);
                }
                clearstatcache();
            }

            // 解压 ZIP 到 plugin 目录
            $zip = new ZipArchive();
            $zip->open($tmp_name);
            $zip->extractTo($extract_to);
            $zip->close();

            // 安装 composer 依赖包
            $packages = $this->getPluginPackages($plugin_name);
            if (!empty($packages)) {
                $result = $this->syncComposerPackages($packages, true, '', true);
                if (!$result['success']) {
                    $this->rmDir($plugin_path);
                    throw new BusinessException("Plugin $plugin_name require packages failed: " . $result['message']);
                }
            }

            // 执行安装
            $version = $this->getPluginVersion($plugin_name);
            $install_class = "\\plugin\\$plugin_name\\api\\Install";
            if (class_exists($install_class) && method_exists($install_class, 'install')) {
                call_user_func([$install_class, 'install'], $version);
            }
        } finally {
            Util::resumeFileMonitor();
        }

        Util::reloadWebman();

        // 清除应用列表缓存
        $this->clearAppListCache();

        return $this->json(0, '导入成功', ['name' => $plugin_name]);
    }

    /**
     * 递归添加目录到 ZIP
     * @param ZipArchive $zip
     * @param string $folder
     * @param string $parent_folder
     */
    protected function addDirToZip(ZipArchive $zip, string $folder, string $parent_folder): void
    {
        $files = scandir($folder);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $full_path = $folder . DIRECTORY_SEPARATOR . $file;
            $entry = $parent_folder . '/' . $file;
            if (is_dir($full_path)) {
                $this->addDirToZip($zip, $full_path, $entry);
            } else {
                $zip->addFile($full_path, $entry);
            }
        }
    }


    /**
     * 获取本地插件版本
     * @param $name
     * @return string|null
     */
    protected function getPluginVersion($name): ?string
    {
        return $this->getPluginInfo($name)['version'];
    }

    /**
     * 获取webman/admin版本
     * @return string
     */
    protected function getAdminVersion(): string
    {
        return config('plugin.admin.app.version', '');
    }

    /**
     * 删除目录
     * @param $src
     * @return void
     */
    protected function rmDir($src): void
    {
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                $full = $src . '/' . $file;
                if (is_dir($full)) {
                    $this->rmDir($full);
                } else {
                    unlink($full);
                }
            }
        }
        closedir($dir);
        rmdir($src);
    }

    /**
     * 获取httpclient
     * @return Client
     * @throws Exception
     */
    protected function httpClient(): Client
    {
        // 下载zip
        $options = [
            'base_uri' => config('plugin.admin.app.plugin_market_host'),
            'timeout' => 60,
            'connect_timeout' => 5,
            'verify' => false,
            'http_errors' => false,
            'headers' => [
                'Referer' => \request()->fullUrl(),
                'User-Agent' => 'webman-app-plugin',
                'Accept' => 'application/json;charset=UTF-8',
            ]
        ];
        if ($token = session('app-plugin-token')) {
            $options['headers']['Cookie'] = "PHPSID=$token;";
        }
        return new Client($options);
    }

    /**
     * 获取下载httpclient
     * @return Client
     * @throws Exception
     */
    protected function downloadClient(): Client
    {
        // 下载zip
        $options = [
            'timeout' => 59,
            'connect_timeout' => 5,
            'verify' => false,
            'http_errors' => false,
            'headers' => [
                'Referer' => \request()->fullUrl(),
                'User-Agent' => 'webman-app-plugin',
            ]
        ];
        if ($token = session('app-plugin-token')) {
            $options['headers']['Cookie'] = "PHPSID=$token;";
        }
        return new Client($options);
    }

    /**
     * 查找系统命令
     * @param string $name
     * @param string|null $default
     * @param array $extraDirs
     * @return mixed|string|null
     */
    protected function findCmd(string $name, ?string $default = null, array $extraDirs = []): mixed
    {
        if (ini_get('open_basedir')) {
            $searchPath = array_merge(explode(PATH_SEPARATOR, ini_get('open_basedir')), $extraDirs);
            $dirs = [];
            foreach ($searchPath as $path) {
                if (@is_dir($path)) {
                    $dirs[] = $path;
                } else {
                    if (basename($path) == $name && @is_executable($path)) {
                        return $path;
                    }
                }
            }
        } else {
            $dirs = array_merge(
                explode(PATH_SEPARATOR, getenv('PATH') ?: getenv('Path')),
                $extraDirs
            );
        }

        $suffixes = [''];
        if ('\\' === DIRECTORY_SEPARATOR) {
            $pathExt = getenv('PATHEXT');
            $suffixes = array_merge($pathExt ? explode(PATH_SEPARATOR, $pathExt) : ['.exe', '.bat', '.cmd', '.com'], $suffixes);
        }
        foreach ($suffixes as $suffix) {
            foreach ($dirs as $dir) {
                if (@is_file($file = $dir . DIRECTORY_SEPARATOR . $name . $suffix) && ('\\' === DIRECTORY_SEPARATOR || @is_executable($file))) {
                    return $file;
                }
            }
        }

        return $default;
    }

    /**
     * 获取插件的 packages 配置
     * @param string $name 插件名
     * @return array
     */
    protected function getPluginPackages(string $name): array
    {
        $config_file = base_path() . "/plugin/$name/config/app.php";
        if (!is_file($config_file)) {
            return [];
        }
        $config = include $config_file;
        $packages = $config['packages'] ?? [];
        if (!is_array($packages)) {
            return [];
        }
        // 规范化版本约束：没有星号的默认加上星号
        $normalized = [];
        foreach ($packages as $key => $value) {
            if (is_int($key)) {
                // 格式: ["vendor/package"]
                $package = $value;
                $version = '*';
            } else {
                // 格式: ["vendor/package" => "version"]
                $package = $key;
                $version = $value ?: '*';
            }
            $normalized[$package] = $version;
        }
        return $normalized;
    }

    /**
     * 获取所有已安装插件依赖的包列表（排除指定插件）
     * @param string $excludePlugin 要排除的插件名
     * @return array [package => true]
     */
    protected function getAllPluginsPackages(string $excludePlugin = ''): array
    {
        $packages = [];
        $plugin_names = array_diff(scandir(base_path() . '/plugin/'), array('.', '..')) ?: [];
        foreach ($plugin_names as $plugin_name) {
            if ($plugin_name === $excludePlugin) {
                continue;
            }
            if (is_dir(base_path() . "/plugin/$plugin_name")) {
                $packages = $this->getPluginPackages($plugin_name);
                foreach ($packages as $package => $version) {
                    $packages[$package] = true;
                }
            }
        }
        return $packages;
    }

    /**
     * 检查 composer 包是否已安装
     * @param string $package 包名
     * @return bool
     */
    protected function isPackageInstalled(string $package): bool
    {
        $vendor_dir = base_path() . '/vendor/' . $package;
        return is_dir($vendor_dir);
    }

    /**
     * 同步 composer 依赖包（安装或移除）
     * @param array $packages 包列表 [package => version]
     * @param bool $isInstall true=安装, false=移除
     * @param string $excludePlugin 移除时要排除的插件名
     * @param bool $isUpdate 是否为更新场景
     * @return array 结果 ['success' => bool, 'message' => string]
     */
    protected function syncComposerPackages(array $packages, bool $isInstall, string $excludePlugin = '', bool $isUpdate = false): array
    {
        if (empty($packages)) {
            return ['success' => true, 'message' => '无需处理依赖包'];
        }

        // 过滤需要处理的包
        if ($isInstall) {
            $to_process = array_filter($packages, function ($package) {
                return !$this->isPackageInstalled($package);
            }, ARRAY_FILTER_USE_KEY);
            if (empty($to_process)) {
                return ['success' => true, 'message' => '所有依赖包已安装'];
            }
        } else {
            // 获取所有其他插件依赖的包（排除当前要卸载的插件）
            $otherPluginsPackages = $this->getAllPluginsPackages($excludePlugin);
            $to_process = [];
            foreach ($packages as $package => $version) {
                if (!$this->isPackageInstalled($package)) {
                    continue;
                }
                // 检查其他插件是否依赖此包
                if (isset($otherPluginsPackages[$package])) {
                    continue;
                }
                $to_process[] = $package;
            }
            if (empty($to_process)) {
                return ['success' => true, 'message' => '没有需要移除的依赖包'];
            }
        }

        try {
            // 更新 composer.json
            $composerJsonPath = base_path() . '/composer.json';
            $composerConfig = json_decode(file_get_contents($composerJsonPath), true);
            if (!$composerConfig || !isset($composerConfig['require'])) {
                return ['success' => false, 'message' => '无法读取 composer.json'];
            }

            if ($isInstall) {
                foreach ($to_process as $package => $version) {
                    $composerConfig['require'][$package] = $version;
                }
            } else {
                foreach ($to_process as $package) {
                    unset($composerConfig['require'][$package]);
                }
            }

            file_put_contents(
                $composerJsonPath,
                json_encode($composerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            );

            // 执行 composer install/update
            $io = new BufferIO();
            $composer = Factory::create($io, $composerJsonPath);

            $installer = Installer::create($io, $composer);
            $installer->setDryRun(false);
            $installer->setDownloadOnly(false);
            $installer->setUpdate(true);
            $installer->setPreferDist();

            if ($isInstall) {
                // 安装时只更新指定包
                $installer->setUpdateAllowList(array_keys($to_process));
            } else {
                // 移除时指定包，并处理传递依赖（自动清理孤儿依赖）
                $installer->setUpdateAllowList($to_process);
                $installer->setUpdateAllowTransitiveDependencies(\Composer\DependencyResolver\Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE);
            }

            $result = $installer->run();

            if ($result !== 0) {
                return [
                    'success' => false,
                    'message' => ($isInstall ? '安装' : '移除') . '依赖包失败: ' . $io->getOutput()
                ];
            }

            $this->triggerPluginCallback(array_keys($to_process), $isInstall ? ($isUpdate ? 'update' : 'install') : 'uninstall');

            return [
                'success' => true,
                'message' => ($isInstall ? '成功安装' : '成功移除') . '依赖包: ' . implode(', ', $isInstall ? array_keys($to_process) : $to_process)
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => ($isInstall ? '安装' : '移除') . '依赖包异常: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 触发插件的 Install 回调（install/update/uninstall）
     * 读取已安装包的 composer.json 获取 PSR-4 命名空间，
     * 手动调用对应插件的 Install 方法
     * @param array $packages 包名列表
     * @param string $action 动作：install / update / uninstall
     */
    protected function triggerPluginCallback(array $packages, string $action): void
    {
        foreach ($packages as $package) {
            try {
                $installedJson = base_path() . '/vendor/' . $package . '/composer.json';
                if (!is_file($installedJson)) {
                    continue;
                }
                $config = json_decode(file_get_contents($installedJson), true);
                if (!$config) {
                    continue;
                }
                $psr4 = $config['autoload']['psr-4'] ?? [];
                foreach ($psr4 as $namespace => $path) {
                    $pluginConst = "\\{$namespace}Install::WEBMAN_PLUGIN";
                    if (!defined($pluginConst)) {
                        continue;
                    }
                    $installClass = "\\{$namespace}Install";

                    if ($action === 'uninstall') {
                        $function = "$installClass::uninstall";
                        if (is_callable($function)) {
                            $function('');
                        }
                    } elseif ($action === 'update') {
                        $function = "$installClass::uninstall";
                        if (is_callable($function)) {
                            $function('');
                        }
                        $function = "$installClass::install";
                        if (is_callable($function)) {
                            $function(false);
                        }
                    } else {
                        $function = "$installClass::install";
                        if (is_callable($function)) {
                            $function(false);
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Plugin $action callback failed for $package: " . $e->getMessage());
            }
        }
    }

}