<?php
/**
 * 网站基础信息「保存后回退」诊断脚本（2.6.4）
 *
 * 用法：把这个文件上传到站点根目录（和 index.php 同级），命名成 _diag_site.php，
 *       浏览器访问 https://你的域名/_diag_site.php ，按页面提示点一次「继续」。
 *       看完立刻删掉它。
 *
 * 为什么分两步：
 *   「写完立刻读回来是对的」可能骗人 —— 同一个数据库连接能看见自己刚写、还没真正
 *   落盘的数据，两个 PHP 进程连的也可能是两份库。所以第 1 步写一个探针值，
 *   第 2 步（新的一次 HTTP 请求、新连接、可能还是另一台机器）再读它。
 *   只有第 2 步能读到，才算真的存进去了。
 *
 * 除探针（一个 diag_probe 键，第 2 步结束自动删除）之外，不改任何配置、不碰任何内容。
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$root = __DIR__;
$out = [];
function row(string $k, string $v, string $cls = ''): void
{
    global $out;
    $out[] = [$k, $v, $cls];
}

if (!is_file($root . '/config.php')) {
    exit('这个目录里没有 config.php，传错位置了。');
}

require $root . '/app/bootstrap.php';

$cfg = cfg();
$driver = (string)($cfg['db']['driver'] ?? 'sqlite');
$dbFile = (string)($cfg['db']['sqlite_path'] ?? '');
$phase = (string)($_GET['_diag_phase'] ?? '1');
$cookieName = 'diag_site_probe';
$cookieVal = (string)($_COOKIE[$cookieName] ?? '');

/** 库文件的「指纹」：大小 + md5 + mtime，用来判断两次请求读的是不是同一个文件 */
function db_fingerprint(string $dbFile, string $driver): string
{
    if ($driver !== 'sqlite' || !is_file($dbFile)) {
        return '（非 sqlite 或无文件）';
    }
    $bits = [];
    foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-journal'] as $f) {
        if (!is_file($f)) {
            continue;
        }
        clearstatcache(true, $f);
        $bits[] = basename($f) . ' ' . number_format((float)filesize($f)) . 'B '
            . 'md5:' . substr((string)@md5_file($f), 0, 8) . ' '
            . date('H:i:s', (int)filemtime($f));
    }
    return implode(' | ', $bits);
}

/* ================= 基础环境 ================= */
row('本次请求', '第 ' . $phase . ' 步');
row('站点版本', APP_VERSION . '（version.json: '
    . (json_decode((string)@file_get_contents($root . '/version.json'), true)['version'] ?? '?') . '）');
row('PHP 版本', PHP_VERSION . ' / ' . PHP_SAPI);
row('数据库', $driver . ($driver === 'sqlite' ? '  ' . $dbFile : ''));
row('表前缀', db_prefix() === '' ? '（无）' : db_prefix());
row('库文件指纹（进页面时）', db_fingerprint($dbFile, $driver));

/* ================= 1. 文件权限 ================= */
if ($driver === 'sqlite') {
    $exists = is_file($dbFile);
    row('sqlite 文件存在', $exists ? '是' : '否', $exists ? '' : 'bad');
    if ($exists) {
        row('sqlite 文件可写', is_writable($dbFile) ? '是' : '否', is_writable($dbFile) ? '' : 'bad');
        row('sqlite 文件大小', number_format((float)filesize($dbFile)) . ' 字节');
        row('sqlite 所在目录可写', is_writable(dirname($dbFile)) ? '是' : '否',
            is_writable(dirname($dbFile)) ? '' : 'bad');
        // WAL 模式下真正写的是 -wal 文件，它不可写等于写不进
        foreach (['-wal', '-shm'] as $suffix) {
            $f = $dbFile . $suffix;
            if (is_file($f)) {
                row('sqlite' . $suffix . ' 可写', is_writable($f) ? '是' : '否', is_writable($f) ? '' : 'bad');
            }
        }
        try {
            row('journal_mode', (string)val('PRAGMA journal_mode', [], '?'));
        } catch (Throwable $e) {
            row('journal_mode', '读取失败：' . $e->getMessage(), 'bad');
        }
    }
}
row('storage 目录可写', is_writable($root . '/storage') ? '是' : '否',
    is_writable($root . '/storage') ? '' : 'bad');
row('磁盘剩余', @disk_free_space($root) !== false
    ? round((float)@disk_free_space($root) / 1048576, 1) . ' MB'
    : '读不到');

/* ================= 2. settings 表结构是否正常 ================= */
try {
    $cols = all('PRAGMA table_info(' . tbl('settings') . ')');
    if (!$cols && $driver !== 'sqlite') {
        $cols = all('SHOW COLUMNS FROM ' . tbl('settings'));
    }
    row('settings 表字段', $cols
        ? implode('、', array_map(fn($c) => (string)($c['name'] ?? $c['Field'] ?? '?')
            . ((int)($c['pk'] ?? 0) === 1 ? '(主键)' : ''), $cols))
        : '（读不到 —— 表可能不存在，这会直接导致写入异常）', $cols ? '' : 'bad');
    $cnt = (int)val('SELECT COUNT(*) FROM ' . tbl('settings'), [], 0);
    row('settings 行数', (string)$cnt);
    $dup = all('SELECT k, COUNT(*) c FROM ' . tbl('settings') . ' GROUP BY k HAVING c > 1');
    if ($dup) {
        row('⚠ 重复的键', implode('、', array_map(fn($r) => $r['k'] . '×' . $r['c'], $dup))
            . '（读的时候可能取到旧的那一行）', 'bad');
    }
} catch (Throwable $e) {
    row('settings 表检查', '失败：' . $e->getMessage(), 'bad');
}

/* ================= 3. 两段式探针 ================= */
if ($phase === '2') {
    /* ---- 第 2 步：新连接 / 可能另一台机器，看第 1 步写的还在不在 ---- */
    $dbVal = setting_get('diag_probe', null);
    row('第 2 步：从库里读到的探针', $dbVal === null ? '（没有 —— 第 1 步的写入没有留下来）' : (string)$dbVal,
        $dbVal === null ? 'bad' : '');
    row('第 2 步：浏览器带回来的探针', $cookieVal === '' ? '（没有，浏览器没带 Cookie）' : $cookieVal);
    if ($dbVal !== null && $cookieVal !== '' && (string)$dbVal === $cookieVal) {
        row('★ 结论', '写入确实落盘了 —— 两次不同请求读到同一个值，数据库这一环是好的。'
            . '那「保存后回退」就不是写不进去，而是另有原因（下面几行给了线索）。');
    } elseif ($dbVal === null) {
        row('★ 结论', '写入没落盘。同一请求里读得到、下一次请求就没了 —— 这是典型的'
            . '「库文件不可写 / 写进了发布包的只读层 / 每次请求连的其实是另一份库」。'
            . '看上面红色的权限行和下面的库文件指纹。', 'bad');
    } else {
        row('★ 结论', '库里有一个探针值，但不是第 1 步写的那个。'
            . '说明两次请求命中的不是同一份数据库（多节点、多目录、或库被还原过）。', 'bad');
    }
    // 收尾：删干净
    try {
        q('DELETE FROM ' . tbl('settings') . ' WHERE k = ?', ['diag_probe']);
    } catch (Throwable $e) {
    }
    setcookie($cookieName, '', time() - 3600, '/');
} else {
    /* ---- 第 1 步：写探针 ---- */
    $tok = 'p1-' . date('His') . '-' . bin2hex(random_bytes(3));
    $writeOk = null;
    $readBack = null;
    $errMsg = '';
    try {
        setting_set('diag_probe', $tok);
        $readBack = setting_get('diag_probe', '(读不到)');
        $writeOk = ($readBack === $tok);
    } catch (Throwable $e) {
        $errMsg = get_class($e) . ': ' . $e->getMessage();
        $writeOk = false;
    }
    row('第 1 步：写入探针值', $tok);
    row('同一请求内读回', $writeOk === true ? '一致' : '不一致', $writeOk ? '' : 'bad');
    if ($writeOk === false) {
        row('写入异常', $errMsg !== '' ? $errMsg : '读回的是 ' . (string)$readBack, 'bad');
    }
    row('库文件指纹（写完以后）', db_fingerprint($dbFile, $driver));
    row('说明', '同一请求内读回一致并不能证明存住了 —— 这个连接看得见自己刚写的数据。'
        . '必须再发一次请求才算数。');
    setcookie($cookieName, $tok, [
        'expires'  => time() + 600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* ================= 4. 站点信息各项的实际存值 ================= */
$info = site_info();
$map = [
    'name' => '站点名称', 'subtitle' => '副标题', 'desc' => '站点简介',
    'footer' => '页脚版权', 'icp' => 'ICP 备案号', 'icp_url' => 'ICP 链接',
    'police' => '公安备案号', 'police_url' => '公安链接',
    'contact' => '联系方式', 'announce' => '登录页公告',
    'logo' => '站点图标', 'favicon' => '浏览器图标',
];
foreach ($map as $k => $label) {
    $v = (string)($info[$k] ?? '');
    row($label . '（site_' . $k . '）', $v === '' ? '（空）' : htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
}
// 原始表行，绕开 site_load() 的默认值填充，看清库里到底存了什么
try {
    $raw = all('SELECT k, v FROM ' . tbl('settings') . ' WHERE k LIKE ? ORDER BY k', ['site_%']);
    row('settings 里所有 site_* 原始行', $raw
        ? implode('<br>', array_map(fn($r) => htmlspecialchars(
            $r['k'] . ' = ' . (($r['v'] === null || $r['v'] === '') ? '（空串）' : (string)$r['v']),
            ENT_QUOTES, 'UTF-8'), $raw))
        : '（一行都没有 —— 这些项从来没被写进库）', $raw ? '' : 'bad');
} catch (Throwable $e) {
    row('原始行', '读取失败：' . $e->getMessage(), 'bad');
}

/* ================= 5. 有没有第二份 config / 多个库文件 ================= */
$cfgs = glob($root . '/config*.php') ?: [];
row('config 文件', implode('、', array_map('basename', $cfgs)));
if ($driver === 'sqlite') {
    /**
     * 只统计「主库文件」。以前用 glob('*.sqlite*')，把 kb.sqlite-wal / kb.sqlite-shm
     * 也当成第二个库，每次都误报「有多个 .sqlite 文件」——这个告警一旦常年红着，
     * 真出问题时反而没人信。WAL/SHM 是主库的附属文件，不算独立库。
     */
    $dbs = [];
    foreach (glob(dirname($dbFile) . '/*') ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        if (!preg_match('/\.(sqlite|sqlite3|db)$/i', basename($f))) {
            continue;
        }
        $dbs[] = $f;
    }
    sort($dbs);

    $mainReal = realpath($dbFile) ?: $dbFile;
    $rows = [];
    foreach ($dbs as $f) {
        $isMain = realpath($f) === $mainReal;
        $rows[] = ($isMain ? '<b>' : '<span style="color:#b91c1c">')
            . basename($f) . ($isMain ? '</b> ← config.php 用的是这个' : '（不是当前用的那个）</span>')
            . ' · ' . number_format((float)filesize($f)) . 'B · '
            . date('Y-m-d H:i', (int)filemtime($f));
    }
    if (is_file($dbFile . '-wal')) {
        $rows[] = '（WAL 附属文件 ' . number_format((float)filesize($dbFile . '-wal'))
            . 'B，属正常，不算第二个库）';
    }
    row('库文件清单', $rows ? implode('<br>', $rows) : '（无 —— 连主库文件都没找到）',
        $dbs && count($dbs) > 1 ? 'bad' : '');

    if (count($dbs) > 1) {
        $others = array_filter($dbs, fn($f) => realpath($f) !== $mainReal);
        row('⚠ 提示', '除了当前在用的库，还发现 ' . count($others) . ' 个其它 sqlite 文件（'
            . implode('、', array_map('basename', $others)) . '）。'
            . '如果那些是旧站残留，直接删掉或改名即可；如果 config.php 指的才是旧的，'
            . '就会出现「改了看不到」——后台写进 A 库，前台读的是 B 库。', 'bad');
    }
}

/* ================= 6. 最近一次改站点信息的审计记录 ================= */
try {
    $logs = all('SELECT * FROM ' . tbl('audit_log') . ' WHERE action = ? ORDER BY id DESC LIMIT 5',
        ['update_site_info']);
    if ($logs) {
        $lines = [];
        foreach ($logs as $l) {
            $lines[] = htmlspecialchars((string)$l['created_at'] . ' · ' . (string)$l['detail']
                . ' · ip=' . (string)($l['ip'] ?? ''), ENT_QUOTES, 'UTF-8');
        }
        row('最近改站点信息的记录', implode('<br>', $lines));
        row('说明', '有记录说明保存请求确实进到了处理逻辑，并且当时 site_save() 返回了成功 —— '
            . '所以问题在「写进去了但没留住」或「读的不是同一份数据」，不在表单或权限。');
    } else {
        row('最近改站点信息的记录', '（没有 —— 说明保存请求可能压根没进到处理逻辑）', 'bad');
    }
} catch (Throwable $e) {
    row('审计记录', '读取失败：' . $e->getMessage(), 'bad');
}

/* ================= 7. PHP 上传限制（表单是 multipart，超限会导致 $_POST 全空） ================= */
row('post_max_size', (string)ini_get('post_max_size'));
row('upload_max_filesize', (string)ini_get('upload_max_filesize'));
row('session 保存路径可写', is_writable((string)session_save_path()) ? '是' : '否（' . session_save_path() . '）');

/* ================= 8. 多节点 / 反代 / 多目录线索 ================= */
row('本机 hostname', (string)@gethostname());
row('SCRIPT_FILENAME', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
row('DOCUMENT_ROOT', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
row('config 里的 sqlite_path', $dbFile);
row('__DIR__', $root);
row('config.php 修改时间', is_file($root . '/config.php')
    ? date('Y-m-d H:i:s', (int)filemtime($root . '/config.php')) : '读不到');
// OPcache 状态：开了且不校验时间戳，升级后改的代码可能压根没生效
if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    if (is_array($st)) {
        $ini = (string)ini_get('opcache.validate_timestamps');
        row('OPcache', '已启用，validate_timestamps=' . $ini
            . ($ini === '0' ? '（⚠ 改了 PHP 文件不会自动生效，必须重启 php-fpm）' : ''),
            $ini === '0' ? 'bad' : '');
    } else {
        row('OPcache', '未启用');
    }
}
if ($dbFile !== '' && realpath($dbFile) !== false
    && !str_starts_with(str_replace('\\', '/', (string)realpath($dbFile)), str_replace('\\', '/', $root))) {
    row('⚠ 提示', 'config.php 里的 sqlite_path 不在当前站点目录下。'
        . '如果你有测试站或旧目录残留，可能读写的不是同一份库。', 'bad');
}

?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8">
<title>站点信息诊断</title>
<style>
body{font:14px/1.7 -apple-system,"Segoe UI","Microsoft YaHei",sans-serif;background:#f6f7f9;color:#18181b;margin:0;padding:28px}
.wrap{max-width:960px;margin:0 auto;background:#fff;border-radius:12px;padding:24px 28px;box-shadow:0 4px 20px rgba(15,23,42,.08)}
h1{font-size:20px;margin:0 0 6px}
p.lead{color:#71717a;margin:0 0 18px;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:13px}
td{padding:8px 10px;border-bottom:1px solid #f1f1f3;vertical-align:top}
td:first-child{width:250px;color:#52525b;font-weight:600}
td.bad{color:#b91c1c;font-weight:600;background:#fef2f2}
code{background:#f4f4f5;padding:1px 5px;border-radius:4px;font-size:12px}
.warn{margin-top:18px;padding:14px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;color:#92400e}
.next{margin-top:16px;padding:16px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px}
.next a{display:inline-block;margin-top:8px;background:#4f46e5;color:#fff;text-decoration:none;padding:9px 18px;border-radius:8px;font-weight:600}
</style></head><body><div class="wrap">
<h1>站点信息诊断</h1>
<p class="lead">两步：先写一个探针值，再用新的一次请求去读它。只有第二步读到了，才算真的存进数据库。</p>
<table>
<?php foreach ($out as [$k, $v, $cls]): ?>
  <tr><td><?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?></td>
      <td class="<?= $cls === 'bad' ? 'bad' : '' ?>"><?= $v ?></td></tr>
<?php endforeach; ?>
</table>

<?php if ($phase !== '2'): ?>
<div class="next">
  <b>下一步：</b>点下面的按钮（会发一次全新的请求，等于模拟你「保存完刷新」那一刻）。
  <br><a href="?<?= htmlspecialchars(http_build_query(['_diag_phase' => '2', '_t' => time()]), ENT_QUOTES, 'UTF-8') ?>">继续 →</a>
</div>
<?php else: ?>
<div class="warn">
  <b>看「★ 结论」那一行就够了。</b>
  探针已经删掉，不留痕迹。<b>看完请把这个文件从服务器上删掉。</b>
</div>
<?php endif; ?>
</div></body></html>
