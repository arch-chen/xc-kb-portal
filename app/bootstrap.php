<?php
declare(strict_types=1);

/**
 * 引导文件：加载配置、数据库、工具函数与会话。
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

$verFile = BASE_PATH . '/version.json';
$verData = is_file($verFile) ? json_decode((string)file_get_contents($verFile), true) : [];
define('APP_VERSION', $verData['version'] ?? '0.0.0');

require BASE_PATH . '/app/db.php';
require BASE_PATH . '/app/text.php';
require BASE_PATH . '/app/entries.php';
require BASE_PATH . '/app/secret.php';
require BASE_PATH . '/app/smtp.php';
require BASE_PATH . '/app/mail.php';
require BASE_PATH . '/app/upsert.php';
require BASE_PATH . '/app/importer.php';
require BASE_PATH . '/app/auth.php';
require BASE_PATH . '/app/identity.php';
require BASE_PATH . '/app/register.php';
require BASE_PATH . '/app/perm.php';
require BASE_PATH . '/app/search.php';
require BASE_PATH . '/app/storage.php';
require BASE_PATH . '/app/files.php';
require BASE_PATH . '/app/ai/gateway.php';
require BASE_PATH . '/app/ai/billing.php';
require BASE_PATH . '/app/ai/service.php';
require BASE_PATH . '/app/ai/gate.php';
require BASE_PATH . '/app/ai/tools.php';
require BASE_PATH . '/app/ai/agent.php';
require BASE_PATH . '/app/pay/gateway.php';
require BASE_PATH . '/app/pay/epay.php';
require BASE_PATH . '/app/pay/orders.php';
require BASE_PATH . '/app/pay/ledger.php';
require BASE_PATH . '/app/site.php';
require BASE_PATH . '/app/ui.php';
require BASE_PATH . '/app/nav.php';
// install.php 在引导之前就要用这些部署文案，所以这里用 require_once 防止重复声明
require_once BASE_PATH . '/app/deploy.php';

date_default_timezone_set(cfg()['app']['timezone'] ?? 'Asia/Shanghai');

mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $sn = cfg()['security']['session_name'] ?? 'kbsid';
    session_name($sn);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/**
 * 基础安全响应头。放在会话之后、任何输出之前。
 * CSP 不在这里加：页面里有大量内联脚本与 style，硬上会把界面弄坏。
 * HSTS 交给 Web 服务器（nginx/Apache）配，避免在纯 HTTP 环境里把自己锁死。
 */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

/* ---------------- 基础助手 ---------------- */

/**
 * 站点名称（2.4.0 起统一走 site_info()，后台「网站基础信息」可改）。
 * 保留这个函数名是因为 20+ 处调用点、以及外部接口都用它。
 */
function app_name(): string
{
    return (string)site_info('name');
}

/** 站点根路径，例如 /kb 或 '' */
function base_path(): string
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $b = ($dir === '/' || $dir === '.') ? '' : $dir;
    return $b;
}

function base_url(): string
{
    $cfgUrl = trim((string)(cfg()['app']['url'] ?? ''));
    if ($cfgUrl !== '') {
        return rtrim($cfgUrl, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https://' : 'http://') . $host . base_path();
}

/** 站内路由链接 */
function u(string $route = '', array $params = []): string
{
    $url = base_path() . '/index.php';
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    if ($route !== '') {
        $params = ['r' => $route] + $params;
    }
    return $params ? $url . '?' . http_build_query($params) : $url;
}

function asset(string $path): string
{
    return base_path() . '/assets/' . ltrim($path, '/');
}

/** 静态资源版本号：带上文件修改时间，改了样式就自动失效，不用手动清缓存 */
function asset_ver(string $path): string
{
    $file = BASE_PATH . '/assets/' . ltrim($path, '/');
    $mtime = @filemtime($file) ?: 0;
    return APP_VERSION . '-' . $mtime;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function back(string $fallback = ''): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    redirect($ref !== '' ? $ref : ($fallback ?: u()));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || $t === '' || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('会话已过期，请返回重新提交。');
    }
}

function flash(?string $msg = null, string $type = 'ok')
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['m' => $msg, 't' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function post(string $k, $d = ''): string
{
    $v = $_POST[$k] ?? $d;
    return is_string($v) ? trim($v) : (string)$d;
}

function get_q(string $k, string $d = ''): string
{
    $v = $_GET[$k] ?? $d;
    return is_string($v) ? trim($v) : $d;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 渲染视图（含布局） */
function view(string $name, array $vars = [], bool $withLayout = true): void
{
    $file = BASE_PATH . '/app/views/' . $name . '.php';
    if (!is_file($file)) {
        http_response_code(500);
        exit('视图缺失：' . e($name));
    }
    extract($vars, EXTR_SKIP);
    if (!$withLayout) {
        require $file;
        return;
    }
    $content = (function () use ($file, $vars) {
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return ob_get_clean();
    })();
    require BASE_PATH . '/app/views/layout.php';
}

function page_title(string $t = ''): string
{
    return $t === '' ? app_name() : $t . ' · ' . app_name();
}

/** 当前请求是否 POST */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}
