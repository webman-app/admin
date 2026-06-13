<?php

declare(strict_types=1);

/**
 * Here is your custom functions.
 */

use plugin\admin\app\model\User;
use support\Response;

/**
 * session 中需要保存的用户字段
 * @return string[]
 */
function admin_session_user_fields(): array
{
    return ['id', 'password', 'role', 'status'];
}

/**
 * 格式化 session 用户信息
 * @param array $user
 * @param int|null $session_last_update_time
 * @return array
 */
function format_admin_session_user(array $user, ?int $session_last_update_time = null): array
{
    $result = [];
    foreach (admin_session_user_fields() as $field) {
        $result[$field] = $user[$field] ?? null;
    }
    $result['password'] = md5((string)$result['password']);
    if ($session_last_update_time !== null) {
        $result['session_last_update_time'] = $session_last_update_time;
    }
    return $result;
}

/**
 * 当前用户id
 * @return integer|null
 * @throws Exception
 */
function admin_id(): ?int
{
    $admin_id = session('user.id');
    if (!is_scalar($admin_id) && $admin_id !== null) {
        return null;
    }
    if ($admin_id === null || $admin_id === '') {
        return null;
    }
    return (int)$admin_id;
}

/**
 * 当前用户
 * @param array|string|null $fields
 * @return array|mixed|null
 * @throws Exception
 */
function admin(array|string|null $fields = null): mixed
{
    refresh_admin_session();
    if (!$admin = session('user')) {
        return null;
    }
    if ($fields === null) {
        return $admin;
    }
    if (is_array($fields)) {
        $results = [];
        foreach ($fields as $field) {
            if (!is_scalar($field)) {
                continue;
            }
            $results[$field] = $admin[$field] ?? null;
        }
        return $results;
    }
    if (!is_scalar($fields)) {
        return null;
    }
    return $admin[$fields] ?? null;
}


/**
 * 刷新当前用户session
 * @param bool $force
 * @return void
 * @throws Exception
 */
function refresh_admin_session(bool $force = false)
{
    $user_session = session('user');
    if (!$user_session || !is_array($user_session)) {
        return null;
    }
    if (!isset($user_session['id']) || !is_scalar($user_session['id'])) {
        return null;
    }
    $user_id = $user_session['id'];
    $time_now = time();
    // session在2秒内不刷新
    $session_ttl = 2;
    $session_last_update_time = session('user.session_last_update_time', 0);
    if (!$force && $time_now - $session_last_update_time < $session_ttl) {
        return null;
    }
    $session = request()->session();
    $user = User::select(admin_session_user_fields())->find($user_id);
    if (!$user) {
        $session->forget('user');
        return null;
    }
    $user = method_exists($user, 'toArray') ? $user->toArray() : (array)$user;
    $user_session['password'] = $user_session['password'] ?? '';
    $user = format_admin_session_user($user, $time_now);
    if ($user['password'] != $user_session['password']) {
        $session->forget('user');
        return null;
    }
    // 账户被禁用
    if ($user['status'] != 0) {
        $session->forget('user');
        return;
    }
    $session->set('user', $user);
}

function admin_error_401_script(): Response
{
  return response(<<<EOF
<script>top.location.href = '/app/admin';</script>
EOF
  );
}
