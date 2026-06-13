<?php
declare(strict_types=1);

namespace plugin\admin\app\controller;

use DateTimeInterface;
use Exception;
use Throwable;
use plugin\admin\app\common\Auth;
use plugin\admin\app\common\Util;
use plugin\admin\app\model\Role;
use plugin\admin\app\model\User;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Webman\Captcha\CaptchaBuilder;
use Webman\Captcha\PhraseBuilder;

/**
 * 管理员账户
 */
class AccountController extends Crud
{
    /**
     * 不需要登录的方法
     * @var string[]
     */
    protected $noNeedLogin = ['login', 'logout', 'captcha'];

    /**
     * 不需要鉴权的方法
     * @var string[]
     */
    protected $noNeedAuth = ['info'];

    /**
     * @var User
     */
    protected $model = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new User;
    }

    /**
     * 账户设置
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return view('account/index');
    }

    /**
     * 登录
     * @param Request $request
     * @return Response
     * @throws BusinessException|Exception
     */
    public function login(Request $request): Response
    {
        $this->checkDatabaseAvailable();
        $captcha = $request->post('captcha', '');
        if (!is_string($captcha) || strtolower($captcha) !== session('captcha-login')) {
            return $this->json(1, '验证码错误');
        }
        $request->session()->forget('captcha-login');
        $username = $request->post('username', '');
        $password = $request->post('password', '');
        if (!is_string($username) || !is_string($password)) {
            return $this->json(1, '参数错误');
        }
        if (!$username) {
            return $this->json(1, '用户名不能为空');
        }
        $this->checkLoginLimit($username);
        $admin = User::where('username', $username)->first();
        if (!$admin || !Util::passwordVerify($password, $admin->password)) {
            return $this->json(1, '账户不存在或密码错误');
        }
        if ($admin->status != 0) {
            return $this->json(1, '当前账户暂时无法登录');
        }
        $admin->last_time = date('Y-m-d H:i:s');
        $admin->last_ip = $request->getRealIp();
        $admin->save();
        $this->removeLoginLimit($username);
        $admin = $admin->toArray();
        $nickname = $admin['nickname'];
        $session = $request->session();
        $admin = format_admin_session_user($admin);
        $session->set('user', $admin);
        return $this->json(0, '登录成功', [
            'nickname' => $nickname,
            'token' => $request->sessionId(),
        ]);
    }

    /**
     * 退出
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function logout(Request $request): Response
    {
        $request->session()->delete('user');
        return $this->json(0);
    }

    /**
     * 获取登录信息
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function info(Request $request): Response
    {
        $admin = admin();
        if (!$admin) {
            return $this->json(1);
        }
        $user = User::select([
            'id',
            'username',
            'nickname',
            'sex',
            'avatar',
            'email',
            'mobile',
            'level',
            'birthday',
            'bio',
            'money',
            'score',
            'last_time',
            'last_ip',
            'role',
            'status',
            'created_at',
            'updated_at',
        ])->find(admin_id());
        if (!$user) {
            return $this->json(1, '用户不存在');
        }
        $role_name = Role::where('id', $user->role)->value('name') ?: (string)$user->role;
        $info = [
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'sex' => $user->sex,
            'avatar' => $user->avatar,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'level' => $user->level,
            'birthday' => $user->birthday,
            'bio' => $user->bio,
            'money' => $user->money,
            'score' => $user->score,
            'last_time' => $user->last_time,
            'last_ip' => $user->last_ip,
            'role' => $user->role,
            'role_name' => $role_name,
            'status' => $user->status,
            'status_text' => $user->status ? '禁用' : '正常',
            'created_at' => $this->formatDateTime($user->created_at),
            'updated_at' => $this->formatDateTime($user->updated_at),
            'isSuperAdmin' => Auth::isSuperAdmin(),
            'token' => $request->sessionId(),
        ];
        return $this->json(0, 'ok', $info);
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     */
    public function update(Request $request): Response
    {
        $allow_column = [
            'nickname' => 'nickname',
            'avatar' => 'avatar',
            'sex' => 'sex',
            'email' => 'email',
            'mobile' => 'mobile',
            'birthday' => 'birthday',
            'bio' => 'bio',
        ];

        $data = $request->post();
        $update_data = [];
        foreach ($allow_column as $key => $column) {
            if (isset($data[$key])) {
                if (!is_string($data[$key])) {
                    return $this->json(1, '参数错误');
                }
                $update_data[$column] = $data[$key];
            }
        }
        if (isset($update_data['password'])) {
            $update_data['password'] = Util::passwordHash($update_data['password']);
        }
        if (array_key_exists('birthday', $update_data) && $update_data['birthday'] === '') {
            $update_data['birthday'] = null;
        }
        if (array_key_exists('sex', $update_data) && $update_data['sex'] === '') {
            $update_data['sex'] = '0';
        }
        $update_data = $this->normalizeUniqueContactFields($update_data);
        if ($msg = $this->checkUniqueContactFields($update_data, admin_id())) {
            return $this->json(1, $msg);
        }
        User::where('id', admin_id())->update($update_data);
        return $this->json(0);
    }

    /**
     * 唯一联系方式字段为空时存 null，避免多个空字符串触发唯一索引冲突
     * @param array $data 账户表单数据
     * @return array
     */
    protected function normalizeUniqueContactFields(array $data): array
    {
        foreach (['email', 'mobile'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        return $data;
    }

    /**
     * 检查唯一联系方式字段
     * @param array $data 账户表单数据
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

    protected function formatDateTime(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * 修改密码
     * @param Request $request
     * @return Response
     */
    public function password(Request $request): Response
    {
        $user = User::find(admin_id());
        if (!$user) {
            return $this->json(1, '用户不存在');
        }
        $hash = $user['password'];
        $old_password = $request->post('old_password');
        $password = $request->post('password');
        $password_confirm = $request->post('password_confirm');
        if (!is_string($old_password) || !is_string($password) || !is_string($password_confirm)) {
            return $this->json(1, '参数错误');
        }
        if (!$password) {
            return $this->json(2, '密码不能为空');
        }
        if ($password_confirm !== $password) {
            return $this->json(3, '两次密码输入不一致');
        }
        if (!Util::passwordVerify($old_password, $hash)) {
            return $this->json(1, '原始密码不正确');
        }
        $update_data = [
            'password' => Util::passwordHash($password),
        ];
        User::where('id', admin_id())->update($update_data);
        return $this->json(0);
    }

    /**
     * 验证码
     * @param Request $request
     * @param string $type
     * @return Response
     * @throws Exception
     */
    public function captcha(Request $request, string $type = 'login'): Response
    {
        $builder = new PhraseBuilder(4, 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ');
        $captcha = new CaptchaBuilder(null, $builder);
        $captcha->build(120);
        $request->session()->set("captcha-$type", strtolower($captcha->getPhrase()));
        $img_content = $captcha->get();
        return response($img_content, 200, ['Content-Type' => 'image/jpeg']);
    }

    /**
     * 检查登录频率限制
     * @param string $username
     * @return void
     * @throws BusinessException
     */
    protected function checkLoginLimit(string $username): void
    {
        $limit_log_path = runtime_path() . '/login';
        if (!is_dir($limit_log_path)) {
            mkdir($limit_log_path, 0755, true);
        }
        $limit_file = $limit_log_path . '/' . md5($username) . '.limit';
        $time = date('YmdH') . ceil(date('i')/5);
        $limit_info = [];
        if (is_file($limit_file)) {
            $json_str = file_get_contents($limit_file);
            $decoded = json_decode($json_str, true);
            $limit_info = is_array($decoded) ? $decoded : [];
        }

        if (!isset($limit_info['time']) || $limit_info['time'] != $time) {
            $limit_info = [
                'username' => $username,
                'count' => 0,
                'time' => $time
            ];
        }
        $limit_info['count']++;
        file_put_contents($limit_file, json_encode($limit_info));
        if ($limit_info['count'] >= 5) {
            throw new BusinessException('登录失败次数过多，请5分钟后再试');
        }
    }

    /**
     * 解除登录频率限制
     * @param string $username
     * @return void
     */
    protected function removeLoginLimit(string $username): void
    {
        $limit_log_path = runtime_path() . '/login';
        $limit_file = $limit_log_path . '/' . md5($username) . '.limit';
        if (is_file($limit_file)) {
            unlink($limit_file);
        }
    }

    protected function checkDatabaseAvailable(): void
    {
        if (!config('plugin.admin.database')) {
            throw new BusinessException('请重启webman');
        }
    }

}
