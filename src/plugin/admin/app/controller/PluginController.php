<?php

namespace plugin\admin\app\controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use plugin\admin\app\common\Util;
use app\process\Monitor;
use support\exception\BusinessException;
use support\Log;
use support\Request;
use support\Response;
use Exception;
use ZIPARCHIVE;
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
        $local_plugins = $this->getLocalPlugins(); // [name => version]

        $client = $this->httpClient();
        $query = $request->get();
        $query['version'] = $this->getAdminVersion();
        $response = $client->get('/api/app/list', ['query' => $query]);
        $content = $response->getBody()->getContents();
        $data = json_decode($content, true);
        if (!$data) {
            Log::error("/api/app/list return $content");
            return $this->json(1, '获取数据出错');
        }

        $disabled = is_phar();
        $remote_items = $data['data']['items'] ?? [];
        $result_items = [];

        // 先处理远程插件，同名则用本地版本覆盖
        foreach ($remote_items as $item) {
            $name = $item['name'];
            if (isset($local_plugins[$name])) {
                // 同名插件，优先显示本地版本信息
                $item['installed'] = $local_plugins[$name];
                $item['version'] = $local_plugins[$name]; // 使用本地版本号
                $item['local'] = true; // 标记为本地插件
            } else {
                $item['installed'] = 0;
                $item['local'] = false;
            }
            $item['disabled'] = $disabled;
            $result_items[] = $item;
            unset($local_plugins[$name]); // 从剩余本地插件中移除
        }

        // 再追加本地有但远程没有的插件
        foreach ($local_plugins as $name => $version) {
            $result_items[] = [
                'name' => $name,
                'author' => '本地',
                'price' => '0',
                'version' => $version,
                'intro' => '本地插件（不在官方市场）',
                'installed' => $version,
                'local' => true,
                'disabled' => $disabled,
            ];
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $result_items, 'count' => count($result_items)]);
    }

    /**
     * 安装
     * @param Request $request
     * @return Response
     * @throws GuzzleException|BusinessException
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
                $this->executeInstallOrUpdate($name, $installed_version, $version, function () use ($zip) {
                    $zip->extractTo(base_path() . '/plugin/');
                    unset($zip);
                });
            } else {
                $this->executeInstallOrUpdate($name, $installed_version, $version, function () use ($cmd) {
                    $this->unzipWithCmd($cmd);
                });
            }

            unlink($zip_file);
        } finally {
            Util::resumeFileMonitor();
        }

        Util::reloadWebman();

        return $this->json(0);
    }

    /**
     * 执行插件安装或更新（公共逻辑）
     * 流程：beforeUpdate → 解压 → install/update
     *
     * @param string $name 插件名
     * @param string|null $installed_version 已安装版本（null表示新安装）
     * @param string|null $new_version 新版本号
     * @param callable $extractCallback 解压回调
     * @throws BusinessException
     */
    protected function executeInstallOrUpdate(string $name, ?string $installed_version, ?string $new_version, callable $extractCallback): void
    {
        $install_class = "\\plugin\\$name\\api\\Install";
        $context = null;

        // 已安装时执行 beforeUpdate
        if ($installed_version) {
            if (class_exists($install_class) && method_exists($install_class, 'beforeUpdate')) {
                $context = call_user_func([$install_class, 'beforeUpdate'], $installed_version, $new_version);
                if (is_array($context) && isset($context['error'])) {
                    throw new BusinessException((string)$context['error']);
                }
            }
        }

        // 解压文件
        $extractCallback();

        // 执行 install 或 update
        if ($installed_version) {
            if (class_exists($install_class) && method_exists($install_class, 'update')) {
                call_user_func([$install_class, 'update'], $installed_version, $new_version, $context);
            }
        } else {
            if (class_exists($install_class) && method_exists($install_class, 'install')) {
                call_user_func([$install_class, 'install'], $new_version);
            }
        }
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

        Util::reloadWebman();

        return $this->json(0);
    }

    /**
     * 支付
     * @param Request $request
     * @return string|Response
     * @throws GuzzleException
     */
    public function pay(Request $request)
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
     * @throws GuzzleException
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
     * @throws GuzzleException
     */
    public function login(Request $request)
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
            $msg = "/api/user/login return $content";
            echo "msg\r\n";
            Log::error($msg);
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
     * @throws BusinessException
     * @throws GuzzleException
     */
    protected function getDownloadUrl($name, $version)
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
     * @throws BusinessException
     * @throws GuzzleException
     */
    protected function downloadZipFile($url, $file)
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
     * @return mixed|string|null
     */
    protected function getUnzipCmd($zip_file, $extract_to)
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
    protected function unzipWithCmd($cmd)
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
            if (is_dir(base_path() . "/plugin/$plugin_name") && $version = $this->getPluginVersion($plugin_name)) {
                $installed[$plugin_name] = $version;
            }
        }
        return $installed;
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

        return response()->file($zip_file)->withHeader('Content-Disposition', "attachment; filename={$name}.zip");
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

        $installed_version = $this->getPluginVersion($plugin_name);
        $new_version = $this->getVersionFromZip($tmp_name, $plugin_name);
        $extract_to = base_path() . '/plugin/';

        Util::pauseFileMonitor();
        try {
            $this->executeInstallOrUpdate($plugin_name, $installed_version, $new_version, function () use ($tmp_name, $extract_to) {
                $zip = new ZipArchive();
                $zip->open($tmp_name);
                $zip->extractTo($extract_to);
                $zip->close();
            });
        } finally {
            Util::resumeFileMonitor();
        }

        Util::reloadWebman();

        return $this->json(0, $installed_version ? '更新成功' : '导入成功', ['name' => $plugin_name, 'update' => (bool)$installed_version]);
    }

    /**
     * 从 ZIP 中读取插件版本号
     * @param string $zip_file ZIP 文件路径
     * @param string $plugin_name 插件名
     * @return string|null
     */
    protected function getVersionFromZip(string $zip_file, string $plugin_name): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            return null;
        }
        $entry = "$plugin_name/config/app.php";
        $content = $zip->getFromName($entry);
        $zip->close();
        if ($content === false) {
            return null;
        }
        // 从文件内容中提取版本号（避免直接 include）
        if (preg_match("/['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
            return $matches[1];
        }
        return null;
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
     * @return array|mixed|null
     */
    protected function getPluginVersion($name)
    {
        if (!is_file($file = base_path() . "/plugin/$name/config/app.php")) {
            return null;
        }
        $config = include $file;
        return $config['version'] ?? null;
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
    protected function rmDir($src)
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
    protected function findCmd(string $name, ?string $default = null, array $extraDirs = [])
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

}
