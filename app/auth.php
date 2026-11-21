<?php
declare(strict_types=1);

/** 会话认证、登录限速、权限守卫 */

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid <= 0) {
        return $cache = null;
    }
    $u = one('SELECT * FROM ' . tbl('users') . ' WHERE id = ? AND status = 1', [$uid]);
    if (!$u) {
        unset($_SESSION['uid']);
        return $cache = null;
    }
    return $cache = $u;
}

function is_admin(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(u('login'));
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!is_admin($u)) {
        http_response_code(403);
        exit('403 需要管理员权限');
    }
    return $u;
}

/**
 * 登录失败限速：会话一份、IP 一份。
 * 只按会话计数的话，攻击者丢 cookie 就绕过了，所以必须再按 IP 记一份。
 */
const LOGIN_FAIL_WINDOW = 900;   // 统计窗口：15 分钟
const LOGIN_IP_LIMIT    = 20;    // 同一 IP 窗口内最多失败次数

/** 同一 IP 失败太多次时，返回还需等待的秒数 */
function login_ip_locked_for(): int
{
    $n = throttle_count('login_fail', LOGIN_FAIL_WINDOW);
    if ($n < LOGIN_IP_LIMIT) {
        return 0;
    }
    // 从最近一次失败起再冷却 5 分钟，避免「刚好卡在第 20 次」时立刻又能试
    $last = (string)val(
        'SELECT MAX(ts) FROM ' . tbl('throttle') . ' WHERE bucket = ?',
        [throttle_key('login_fail')],
        ''
    );
    $ts = $last !== '' ? (int)strtotime($last) : time();
    return max(0, ($ts + 300) - time());
}

/** 登录失败计数（会话 + IP，取更严的那个） */
function login_locked_for(): int
{
    $a = $_SESSION['login_fail'] ?? ['n' => 0, 'at' => 0];
    $session = 0;
    if ((int)($a['n'] ?? 0) >= 6) {
        $wait = 60 * min(15, (int)$a['n'] - 5);
        $session = max(0, ((int)$a['at'] + $wait) - time());
    }
    return max($session, login_ip_locked_for());
}

/** 记一次失败：会话计数 + IP 维度计数（IP 只存哈希，不落明文） */
function login_record_fail(): void
{
    $a = $_SESSION['login_fail'] ?? ['n' => 0, 'at' => 0];
    $_SESSION['login_fail'] = ['n' => (int)$a['n'] + 1, 'at' => time()];
    // 到上限就不再写行，避免被刷爆 throttle 表
    throttle_hit('login_fail', LOGIN_IP_LIMIT * 5, LOGIN_FAIL_WINDOW);
}

/** 登录成功：清掉这个 IP 的失败计数，别让正常用户被自己刚才的手滑连累 */
function login_clear_fail(): void
{
    unset($_SESSION['login_fail']);
    q('DELETE FROM ' . tbl('throttle') . ' WHERE bucket = ?', [throttle_key('login_fail')]);
}

function login_attempt(string $username, string $password): bool
{
    if (login_locked_for() > 0) {
        return false;
    }
    $u = one('SELECT * FROM ' . tbl('users') . ' WHERE username = ?', [$username]);
    if (!$u || (int)$u['status'] !== 1 || !password_verify($password, $u['password_hash'])) {
        login_record_fail();
        return false;
    }
    // 顺便升级旧哈希
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        update_row('users', (int)$u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    }
    login_clear_fail();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    update_row('users', (int)$u['id'], ['last_login_at' => now_str()]);
    audit('login', '登录成功');
    return true;
}

function logout(): void
{
    audit('logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function create_user(string $username, string $password, string $displayName, string $role = 'user'): int
{
    $id = insert_row('users', [
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name'  => $displayName,
        'role'          => $role,
        'status'        => 1,
        'created_at'    => now_str(),
    ]);
    return $id;
}

/** API Key 哈希（带 pepper），Web 与 API 共用 */
function key_hash(string $rawKey): string
{
    $pepper = (string)(cfg()['security']['pepper'] ?? '');
    return hash_hmac('sha256', $rawKey, $pepper);
}
