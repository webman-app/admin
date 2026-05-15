<?php

namespace plugin\admin\app\controller;

use app\process\Monitor;
use Composer\Command\RemoveCommand;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Installer;
use FilesystemIterator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use plugin\admin\app\common\Util;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use support\exception\BusinessException;
use support\Log;
use support\Request;
use support\Response;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Exception;
use Throwable;
use ZIPARCHIVE;
use function array_diff;
use function ini_get;
use function scandir;
use function escapeshellarg;
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
     * @param Request $request 请求
     * @return Response
     */
    public function index(Request $request): Response
    {
        // 返回本地视图，覆盖远程页面
        return view('admin/plugin/index');
    }

    /**
     * 列表（合并本地和远程插件）
     * @param Request $request 请求
     * @return Response
     * @throws Exception|GuzzleException
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
     * @param Request $request 请求
     * @return Response
     * @throws GuzzleException|BusinessException|Exception|ExceptionInterface
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
            // 解压zip到临时目录，然后执行安装流程
            if ($has_zip_archive) {
                $zip = new ZipArchive;
                $zip->open($zip_file);
                $this->executeInstallOrUpdate($name, $installed_version, $version, function ($temp_dir) use ($zip) {
                    $zip->extractTo($temp_dir);
                    $zip->close();
                });
            } else {
                $this->executeInstallOrUpdate($name, $installed_version, $version, function ($temp_dir) use ($cmd) {
                    // 修改命令解压到临时目录
                    $temp_cmd = str_replace(base_path() . '/plugin/', $temp_dir . '/', $cmd);
                    $this->unzipWithCmd($temp_cmd);
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
     * 流程：beforeUpdate → 临时解压 → 安装composer依赖 → 移动到plugin目录 → install/update
     *
     * @param string $name 插件名
     * @param string|null $installed_version 已安装版本（null表示新安装）
     * @param string|null $new_version 新版本号
     * @param callable $extractCallback 解压回调（解压到临时目录）
     * @throws BusinessException|ExceptionInterface|Exception
     */
    protected function executeInstallOrUpdate(string $name, ?string $installed_version, ?string $new_version, callable $extractCallback): void
    {
        $install_class = "\\plugin\\$name\\api\\Install";
        $context = null;

        // 读取旧 pack_list（在解压前，从已安装的插件目录读取）
        $old_pack_list = [];
        $plugin_dir = base_path() . "/plugin/$name";
        if ($installed_version && is_dir($plugin_dir)) {
            $old_pack_list = $this->getPackList($plugin_dir);
        }

        // 已安装时执行 beforeUpdate
        if ($installed_version) {
            if (class_exists($install_class) && method_exists($install_class, 'beforeUpdate')) {
                $context = call_user_func([$install_class, 'beforeUpdate'], $installed_version, $new_version);
                if (is_array($context) && isset($context['error'])) {
                    throw new BusinessException((string)$context['error']);
                }
            }
        }

        // 临时解压目录
        $temp_dir = base_path() . "/runtime/plugin/$name";

        // 清理并创建临时目录
        if (is_dir($temp_dir)) {
            $this->removeDir($temp_dir);
        }
        $this->ensureDirectory($temp_dir);

        try {
            // 解压到临时目录
            $extractCallback($temp_dir);

            // 从临时目录读取新版本号（如果传入的为null）
            if ($new_version === null) {
                $new_version = $this->getPluginVersionFromPath($temp_dir);
            }

            // 读取新 pack_list
            $new_pack_list = $this->getPackList($temp_dir);

            // 对比新旧 pack_list，处理 composer 依赖
            $this->syncComposerDependencies($old_pack_list, $new_pack_list);

            // 移动到 plugin 目录
            if (is_dir($plugin_dir)) {
                $this->removeDir($plugin_dir);
            }
            $this->ensureDirectory(dirname($plugin_dir));
            rename($temp_dir, $plugin_dir);

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
        } finally {
            // 清理临时目录（如果还在）
            if (is_dir($temp_dir)) {
                $this->removeDir($temp_dir);
            }
        }
    }

    /**
     * 从指定路径读取插件的 pack_list
     * 支持两种格式：
     * - 索引数组: ['package/name', ...] → 转换为 ['package/name' => '*']
     * - 关联数组: ['package/name' => '^1.0', ...]
     * - 混合数组: ['package/name', 'package/name2' => '^1.0']
     *
     * @param string $plugin_path 插件目录路径
     * @return array 包名 => 版本约束（或 '*')
     */
    protected function getPackList(string $plugin_path): array
    {
        $app_config_file = $plugin_path . '/config/app.php';
        if (!is_file($app_config_file)) {
            return [];
        }
        $config = include $app_config_file;
        $pack_list = $config['pack_list'] ?? [];

        // 统一转换为关联数组格式
        $normalized = [];
        foreach ($pack_list as $key => $value) {
            if (is_int($key)) {
                // 纯包名格式 ['package/name']
                if (is_string($value) && str_contains($value, '/')) {
                    $normalized[$value] = '*';
                }
            } else {
                // 包名 => 版本格式 ['package/name' => '^1.0']
                if (is_string($key) && str_contains($key, '/')) {
                    $normalized[$key] = is_string($value) ? $value : '*';
                }
            }
        }

        return $normalized;
    }

    /**
     * 从指定路径读取插件版本
     *
     * @param string $path 插件目录路径
     * @return string|null 插件版本号（或 null）
     */
    protected function getPluginVersionFromPath(string $path): ?string
    {
        $app_file = $path . '/config/app.php';
        if (!is_file($app_file)) {
            return null;
        }
        $config = include $app_file;
        return $config['version'] ?? null;
    }

    /**
     * 同步 composer 依赖包（对比新旧 pack_list）
     * pack_list 格式（已由 getPackList 统一处理）：
     * - ['package/name' => 'version_constraint']  指定版本
     * - ['package/name' => '*']                    最新版本
     *
     * - 新增的包：安装
     * - 移除的包：删除
     * - 都有的包：更新版本约束后执行 composer update
     *
     * @param array $old_pack_list 旧 pack_list（已安装插件）
     * @param array $new_pack_list 新 pack_list（新版本插件）
     * @return void
     * @throws Exception
     */
    protected function syncComposerDependencies(array $old_pack_list, array $new_pack_list): void
    {
        $basePath = base_path();
        $composerJsonPath = $basePath . '/composer.json';

        if (!is_file($composerJsonPath)) {
            throw new BusinessException('项目根目录缺少 composer.json');
        }

        $composerConfig = json_decode(file_get_contents($composerJsonPath), true);
        if (!$composerConfig) {
            throw new BusinessException('composer.json 解析失败');
        }

        if (!isset($composerConfig['require'])) {
            $composerConfig['require'] = [];
        }

        // 1. 找出需要删除的包（旧有新无）
        $to_remove = [];
        foreach ($old_pack_list as $package => $version) {
            if (!is_string($package) || !str_contains($package, '/')) {
                continue;
            }
            if (!isset($new_pack_list[$package])) {
                $to_remove[] = $package;
            }
        }

        // 2. 找出需要安装的包（新有旧无）和需要更新的包（新旧都有但版本不同）
        $to_install = [];
        $to_update = [];
        foreach ($new_pack_list as $package => $version) {
            if (!is_string($package) || !str_contains($package, '/')) {
                continue;
            }
            if (!isset($old_pack_list[$package])) {
                // 新增的包
                $to_install[$package] = $version;
            } elseif (isset($composerConfig['require'][$package]) && $composerConfig['require'][$package] !== $version) {
                // 版本约束变化
                $to_update[$package] = $version;
            }
        }

        // 3. 执行删除
        if (!empty($to_remove)) {
            $this->removeComposerDependencies($to_remove);
        }

        // 4. 合并需要安装和更新的包，统一写入 composer.json
        $need_composer_run = false;

        foreach ($to_install as $package => $version) {
            $composerConfig['require'][$package] = $version;
            $need_composer_run = true;
        }

        foreach ($to_update as $package => $version) {
            $composerConfig['require'][$package] = $version;
            $need_composer_run = true;
        }

        // 5. 如果有变更，执行 composer install/update
        if ($need_composer_run) {
            // 写回 composer.json
            file_put_contents(
                $composerJsonPath,
                json_encode($composerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );

            Log::info("Composer: updating composer.json with packages: " . json_encode(array_merge(array_keys($to_install), array_keys($to_update))));

            $io = new BufferIO();
            $composer = Factory::create($io, $composerJsonPath);

            // 执行 composer 安装/更新
            if (!empty($to_install) || !empty($to_update)) {
                // 对于新包，需要设置白名单强制安装
                if (!empty($to_install)) {
                    $installer = Installer::create($io, $composer);
                    $installer->setDryRun(false);
                    $installer->setDownloadOnly(false);
                    $installer->setUpdate(true);
                    $installer->setPreferDist();
                    // 设置要安装的包白名单
                    $installer->setUpdateAllowList(array_keys($to_install));

                    Log::info("Composer: running install for new packages: " . json_encode(array_keys($to_install)));

                    $exit_code = $installer->run();

                    $output = $io->getOutput();
                    Log::info("Composer install output: " . $output);

                    if ($exit_code !== 0) {
                        Log::error("Composer install failed with exit code $exit_code: " . $output);
                        throw new BusinessException("Composer 安装依赖失败，请检查日志");
                    }
                }

                // 对于版本更新的包
                if (!empty($to_update)) {
                    $installer = Installer::create($io, $composer);
                    $installer->setDryRun(false);
                    $installer->setDownloadOnly(false);
                    $installer->setUpdate(true);
                    $installer->setPreferDist();
                    $installer->setUpdateAllowList(array_keys($to_update));

                    Log::info("Composer: running update for packages: " . json_encode(array_keys($to_update)));

                    $exit_code = $installer->run();

                    $output = $io->getOutput();
                    Log::info("Composer update output: " . $output);

                    if ($exit_code !== 0) {
                        Log::error("Composer update failed with exit code $exit_code: " . $output);
                        throw new BusinessException("Composer 更新依赖失败，请检查日志");
                    }
                }
            }

            Log::info("Composer sync result: success");
        } else {
            Log::info("Composer: no packages need to be installed or updated");
        }
    }

    /**
     * 移除 composer 依赖包
     * 使用 Composer RemoveCommand（纯 PHP API，不需要 shell 命令）
     * 会自动检查其他包是否依赖，有依赖则不删除
     *
     * @param array $packages 要移除的包名列表
     * @return void
     */
    protected function removeComposerDependencies(array $packages): void
    {
        if (empty($packages)) {
            return;
        }

        $basePath = base_path();
        $composerJsonPath = $basePath . '/composer.json';

        if (!is_file($composerJsonPath)) {
            return;
        }

        $composerConfig = json_decode(file_get_contents($composerJsonPath), true);
        if (!$composerConfig) {
            return;
        }

        // 过滤出确实在 require 中的包
        $to_remove = [];
        foreach ($packages as $package) {
            if (!is_string($package) || !str_contains($package, '/')) {
                continue;
            }
            if (isset($composerConfig['require'][$package])) {
                $to_remove[] = $package;
            }
        }

        if (empty($to_remove)) {
            return;
        }

        try {
            $io = new BufferIO();
            $composer = Factory::create($io, $composerJsonPath);

            $removeCommand = new RemoveCommand();
            $removeCommand->setComposer($composer);
            $removeCommand->setIO($io);

            $input = new ArrayInput([
                'packages' => $to_remove,
                '--update-with-dependencies' => true,
            ]);
            $output = new BufferedOutput();

            $exit_code = $removeCommand->run($input, $output);

            if ($exit_code !== 0) {
                $error_output = $output->fetch();
                Log::warning("Composer remove warning: " . $error_output);
            } else {
                Log::info("Composer removed packages: " . json_encode($to_remove));
            }
        } catch (Throwable $e) {
            Log::warning("Composer remove failed (non-blocking): " . $e->getMessage());
        }
    }

    /**
     * 卸载
     *
     * @param Request $request 请求
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

        // 读取 pack_list（在删除插件目录前）
        $pack_list = $this->getPackList($path);

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
                $this->removeDir($path);
            } finally {
                if ($monitor_support_pause) {
                    Monitor::resume();
                }
            }
        }

        // 移除 composer 依赖包
        if (!empty($pack_list)) {
            $this->removeComposerDependencies(array_keys($pack_list));
        }

        clearstatcache();

        Util::reloadWebman();

        return $this->json(0);
    }

    /**
     * 支付
     *
     * @param Request $request 请求
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
     *
     * @param Request $request 请求
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
     *
     * @param Request $request 请求
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
     *
     * @param string $name 插件名称
     * @param string $version 插件版本
     * @return mixed 下载url和文件名
     * @throws GuzzleException|Exception
     */
    protected function getDownloadUrl(string $name, string $version): mixed
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
     *
     * @param string $url 下载url
     * @param string $file 文件路径
     * @return void
     * @throws BusinessException|GuzzleException|Exception
     */
    protected function downloadZipFile(string $url, string $file): void
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
     *
     * @param string $zip_file zip文件路径
     * @param string $extract_to 解压路径
     * @return string|null 解压命令
     */
    protected function getUnzipCmd(string $zip_file, string $extract_to): ?string
    {
        $safe_zip = escapeshellarg($zip_file);
        $safe_extract = escapeshellarg($extract_to);
        if ($cmd = $this->findCmd('unzip')) {
            $cmd = "$cmd -o -qq $safe_zip -d $safe_extract";
        } else if ($cmd = $this->findCmd('7z')) {
            $cmd = "$cmd x -bb0 -y $safe_zip -o$safe_extract";
        } else if ($cmd = $this->findCmd('7zz')) {
            $cmd = "$cmd x -bb0 -y $safe_zip -o$safe_extract";
        }
        return $cmd;
    }

    /**
     * 使用解压命令解压
     *
     * @param string $cmd 解压命令
     * @return void
     * @throws BusinessException
     */
    protected function unzipWithCmd(string $cmd): void
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
     *
     * @return array 插件名称和版本
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
     *
     * @param Request $request 请求
     * @return Response
     */
    public function getInstalledPlugins(Request $request): Response
    {
        return $this->json(0, 'ok', $this->getLocalPlugins());
    }

    /**
     * 导出插件为 ZIP
     *
     * @param Request $request 请求
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
     *
     * @param Request $request 请求
     * @return Response
     * @throws ExceptionInterface
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

        Util::pauseFileMonitor();
        try {
            $this->executeInstallOrUpdate($plugin_name, $installed_version, $new_version, function ($temp_dir) use ($tmp_name, $plugin_name) {
                $zip = new ZipArchive();
                $zip->open($tmp_name);
                $zip->extractTo($temp_dir);
                $zip->close();

                // 如果 ZIP 包含顶层目录（如 pluginname/xxx），需要把内容移到 temp_dir 根目录
                $nested_dir = $temp_dir . '/' . $plugin_name;
                if (is_dir($nested_dir)) {
                    // 把 nested_dir 里的内容移到 temp_dir
                    $this->moveDirectoryContents($nested_dir, $temp_dir);
                    $this->removeDir($nested_dir);
                }
            });
        } finally {
            Util::resumeFileMonitor();
        }

        Util::reloadWebman();

        return $this->json(0, $installed_version ? '更新成功' : '导入成功', ['name' => $plugin_name, 'update' => (bool)$installed_version]);
    }

    /**
     * 确保目录存在
     *
     * @param string $dir 目录路径
     * @return void
     */
    protected function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * 递归删除目录
     *
     * @param string $dir 目录路径
     * @return void
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($dir);
    }

    /**
     * 移动目录内容到目标目录
     *
     * @param string $source 源目录路径
     * @param string $destination 目标目录路径
     * @return void
     */
    protected function moveDirectoryContents(string $source, string $destination): void
    {
        if (!is_dir($source) || !is_dir($destination)) {
            return;
        }
        $items = scandir($source);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $source . '/' . $item;
            $destPath = $destination . '/' . $item;
            if (is_dir($srcPath)) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
                $this->moveDirectoryContents($srcPath, $destPath);
                @rmdir($srcPath);
            } else {
                rename($srcPath, $destPath);
            }
        }
    }

    /**
     * 从 ZIP 中读取插件版本号
     *
     * @param string $zip_file ZIP 文件路径
     * @param string $plugin_name 插件名
     * @return string|null 版本号或 null
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
     *
     * @param ZipArchive $zip ZIP 实例
     * @param string $folder 目录路径
     * @param string $parent_folder 父目录路径
     * @return void
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
     * @param string $name 插件名
     * @return string|null
     */
    protected function getPluginVersion(string $name): ?string
    {
        return $this->getPluginVersionFromPath(base_path() . "/plugin/$name");
    }

    /**
     * 获取webman/admin版本
     *
     * @return string 版本号
     */
    protected function getAdminVersion(): string
    {
        return config('plugin.admin.app.version', '');
    }

    /**
     * 获取 HTTP 公共配置
     *
     * @param array $overrides 重写配置
     * @return array HTTP 公共配置
     * @throws Exception
     */
    protected function httpClientOptions(array $overrides = []): array
    {
        $options = [
            'timeout' => 60,
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
        return array_merge($options, $overrides);
    }

    /**
     * 获取httpclient（访问插件市场）
     *
     * @return Client httpclient实例
     * @throws Exception
     */
    protected function httpClient(): Client
    {
        return new Client($this->httpClientOptions([
            'base_uri' => config('plugin.admin.app.plugin_market_host'),
            'headers' => [
                'Accept' => 'application/json;charset=UTF-8',
            ],
        ]));
    }

    /**
     * 获取下载httpclient
     *
     * @return Client 下载httpclient实例
     * @throws Exception
     */
    protected function downloadClient(): Client
    {
        return new Client($this->httpClientOptions([
            'timeout' => 59,
        ]));
    }

    /**
     * 查找系统命令
     *
     * @param string $name 命令名
     * @param string|null $default 默认路径
     * @param array $extraDirs 额外目录路径
     * @return mixed|string|null 命令路径或 null 如果未找到
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

}
