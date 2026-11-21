<?php
declare(strict_types=1);

/**
 * GitHub 在线更新：读取公开仓库里的 updates.json 清单 → 按 min_version 规划升级链
 * → 逐个下载更新包（sha256 校验）→ 复用 upgrade_apply() 落地。
 *
 * 安全约束（改动时别删）：
 *  1. 只走 https；默认域名白名单 raw.githubusercontent.com / githubusercontent.com，
 *     自定义镜像必须解析到公网地址（禁私网/回环/链路本地/云元数据）。
 *  2. 下载路径由清单里的「文件名」拼出，文件名只允许 [A-Za-z0-9._-]+.zip，防路径穿越。
 *  3. 每个包按清单 sha256 校验，防 CDN / 镜像 / 中间人投毒。
 *  4. 只把临时 zip 交给 upgrade_apply()，由它负责 config.php / storage / data 保护与 DB 备份。
 *  5. 全程只在 require_admin() + csrf_check() 之后调用（见 ctrl_admin_system）。
 */

function gh_default_repo(): string
{
    return 'arch-chen/xc-kb-portal';
}

function gh_default_branch(): string
{
    return 'main';
}

function gh_max_zip(): int
{
    return 12 * 1024 * 1024;
}

function gh_max_manifest(): int
{
    return 256 * 1024;
}

function gh_max_steps(): int
{
    return 30;
}

/** 读设置；空值回落到默认。设置项都存在 settings 表，不需要新表或迁移。 */
function gh_cfg(string $k, string $default = ''): string
{
    $v = trim((string)setting_get('gh_' . $k, ''));
    return $v !== '' ? $v : $default;
}

function gh_repo(): string
{
    $r = gh_cfg('repo', gh_default_repo());
    return preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $r) ? $r : gh_default_repo();
}

function gh_branch(): string
{
    $b = gh_cfg('branch', gh_default_branch());
    return preg_match('#^[A-Za-z0-9._/-]+$#', $b) ? trim($b, '/') : gh_default_branch();
}

/** 可选镜像基址，例如 https://mirror.example.com/https://raw.githubusercontent.com */
function gh_mirror(): string
{
    return gh_cfg('mirror', '');
}

function gh_verify_ssl(): bool
{
    return gh_cfg('ssl_verify', '1') !== '0';
}

/**
 * 更新源通道：
 *  - api     api.github.com contents API（默认）。国内实测可达，未认证 60 次/小时
 *  - raw     raw.githubusercontent.com，国外服务器直连、无限流，但国内常被墙
 *  - custom  自建镜像，按 raw 风格拼 {mirror}/{repo}/{branch}/{path}
 */
function gh_source(): string
{
    $s = gh_cfg('source', 'api');
    return in_array($s, ['api', 'raw', 'custom'], true) ? $s : 'api';
}

function gh_base(): string
{
    if (gh_source() === 'custom') {
        return rtrim(gh_mirror(), '/');
    }
    return gh_source() === 'raw' ? 'https://raw.githubusercontent.com' : 'https://api.github.com';
}

function gh_manifest_url(): string
{
    if (gh_source() === 'api') {
        return 'https://api.github.com/repos/' . gh_repo() . '/contents/updates.json?ref='
            . rawurlencode(gh_branch()) . '&t=' . time();
    }
    // raw / 自建镜像有 CDN 缓存，加时间戳拿刚 push 的清单
    return gh_base() . '/' . gh_repo() . '/' . gh_branch() . '/updates.json?t=' . time();
}

function gh_zip_url(string $file): string
{
    if (gh_source() === 'api') {
        return 'https://api.github.com/repos/' . gh_repo() . '/contents/updates/' . rawurlencode($file)
            . '?ref=' . rawurlencode(gh_branch());
    }
    return gh_base() . '/' . gh_repo() . '/' . gh_branch() . '/updates/' . $file;
}

/** filter_var 的 NO_PRIV_RANGE / NO_RES_RANGE 同时覆盖 IPv4 私网、IPv6 ULA 与 169.254 元数据段 */
function gh_is_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function gh_assert_public_host(string $host): void
{
    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = array_merge($ips, $v4);
    }
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = (string)$r['ipv6'];
            }
        }
    }
    if (!$ips) {
        throw new RuntimeException('更新源域名无法解析：' . $host);
    }
    foreach ($ips as $ip) {
        if (!gh_is_public_ip((string)$ip)) {
            throw new RuntimeException('更新源解析到内网/保留地址，已拒绝：' . $ip);
        }
    }
}

function gh_assert_url(string $url): void
{
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https') {
        throw new RuntimeException('更新源必须使用 https');
    }
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host === '' || isset($p['user']) || isset($p['pass'])) {
        throw new RuntimeException('更新源地址不合法');
    }
    if (isset($p['port']) && (int)$p['port'] !== 443) {
        throw new RuntimeException('更新源只允许 443 端口');
    }
    if (gh_source() !== 'custom') {
        $allow = [
            'raw.githubusercontent.com', 'github.com', 'api.github.com',
            'codeload.github.com', 'objects.githubusercontent.com',
        ];
        $ok = in_array($host, $allow, true) || str_ends_with($host, '.githubusercontent.com');
        if (!$ok) {
            throw new RuntimeException('更新源域名不在白名单内：' . $host);
        }
    } else {
        gh_assert_public_host($host);
    }
}

/** curl 错误 → 可操作的中文提示（国内服务器连不上 GitHub 是最常见的情况） */
function gh_net_hint(int $errno, string $err): string
{
    if ($errno === 6) {
        return '域名解析失败（DNS 不通），检查服务器能否访问 GitHub';
    }
    if ($errno === 7 || $errno === 28) {
        return '连接超时：服务器到 GitHub 不通或很慢。国内服务器常见，建议在下方配置「更新源镜像」，或改用右侧「上传更新包」手动升级';
    }
    if (in_array($errno, [35, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91], true)) {
        return 'TLS 证书校验失败：服务器可能缺少 CA 根证书。可关闭下方「TLS 证书校验」后重试（更新包仍有 sha256 完整性校验）';
    }
    if ($errno === 56) {
        return '连接被重置，可能被网络中间设备拦截';
    }
    return trim($err) !== '' ? $err : ('网络错误 errno=' . $errno);
}

/**
 * 只 https、手动跟跳转（每跳复检）、限制响应体大小。
 * 返回 ['ok'=>bool,'body'=>string,'error'=>string,'code'=>int,'url'=>string]
 */
function gh_http_get(string $url, int $limit, int $timeout = 30): array
{
    if (!empty($GLOBALS['__gh_http_mock']) && is_callable($GLOBALS['__gh_http_mock'])) {
        return (array)($GLOBALS['__gh_http_mock'])($url, $limit, $timeout);
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => '', 'error' => 'PHP 缺少 curl 扩展，无法在线更新', 'code' => 0, 'url' => $url];
    }

    $cur = $url;
    $hops = 0;
    while (true) {
        try {
            gh_assert_url($cur);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'body' => '', 'error' => $e->getMessage(), 'code' => 0, 'url' => $cur];
        }
        $ch = curl_init($cur);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => gh_verify_ssl(),
            CURLOPT_SSL_VERIFYHOST => gh_verify_ssl() ? 2 : 0,
            CURLOPT_USERAGENT      => 'XC-KB-Updater/' . (defined('APP_VERSION') ? APP_VERSION : '0'),
            CURLOPT_HTTPHEADER     => [gh_source() === 'api'
                ? 'Accept: application/vnd.github.raw'
                : 'Accept: application/json, application/octet-stream, */*'],
        ];
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = (int)curl_errno($ch);
        $err = (string)curl_error($ch);
        $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => '', 'error' => gh_net_hint($errno, $err), 'code' => $code, 'url' => $cur];
        }
        if (strlen($body) > $limit) {
            return ['ok' => false, 'body' => '', 'error' => '响应超过大小上限，疑似不是预期文件', 'code' => $code, 'url' => $cur];
        }
        if ($code >= 300 && $code < 400 && $redirect !== '') {
            if (++$hops > 3) {
                return ['ok' => false, 'body' => '', 'error' => '跳转次数过多，已中止', 'code' => $code, 'url' => $cur];
            }
            $cur = $redirect;
            continue;
        }
        if ($code !== 200) {
            if ($code === 403 || $code === 429) {
                return ['ok' => false, 'body' => '',
                    'error' => 'GitHub API 触发限流（未认证 60 次/小时）或权限不足，请稍后再试；也可在「更新源设置」里切到 raw 通道或配置自建镜像',
                    'code' => $code, 'url' => $cur];
            }
            return ['ok' => false, 'body' => '', 'error' => '更新源返回 HTTP ' . $code
                . ($code === 404 ? '（清单或更新包不存在，检查仓库/分支/通道设置）' : ''), 'code' => $code, 'url' => $cur];
        }
        return ['ok' => true, 'body' => (string)$body, 'error' => '', 'code' => $code, 'url' => $cur];
    }
}

/** 实时读 version.json，不用 APP_VERSION 常量（同进程链式升级时常量不会变） */
function gh_current_version(): string
{
    $j = json_decode((string)@file_get_contents(BASE_PATH . '/version.json'), true);
    $v = is_array($j) ? (string)($j['version'] ?? '') : '';
    return $v !== '' ? $v : APP_VERSION;
}

/** 拉取并规范化清单；过滤掉版本号/文件名不合法的条目 */
function gh_manifest(): array
{
    $r = gh_http_get(gh_manifest_url(), gh_max_manifest(), 20);
    if (!$r['ok']) {
        throw new RuntimeException('无法获取更新清单：' . $r['error']);
    }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || !isset($j['releases']) || !is_array($j['releases'])) {
        throw new RuntimeException('更新清单格式不对（缺少 releases）');
    }
    if (!empty($j['repo']) && strcasecmp((string)$j['repo'], gh_repo()) !== 0) {
        throw new RuntimeException('清单里的仓库与当前设置不一致，已拒绝更新');
    }
    $out = [];
    foreach ($j['releases'] as $x) {
        if (!is_array($x)) {
            continue;
        }
        $v = (string)($x['version'] ?? '');
        $f = (string)($x['zip'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $v) || !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $f)) {
            continue;
        }
        $out[] = [
            'version'     => $v,
            'min_version' => (string)($x['min_version'] ?? '0.0.0'),
            'released'    => (string)($x['released'] ?? ''),
            'notes'       => (string)($x['notes'] ?? ''),
            'zip'         => $f,
            'sha256'      => strtolower(trim((string)($x['sha256'] ?? ''))),
            'size'        => (int)($x['size'] ?? 0),
        ];
    }
    usort($out, fn($a, $b) => version_compare($a['version'], $b['version']));
    $latest = $out ? $out[count($out) - 1]['version'] : (string)($j['latest'] ?? '');
    return ['repo' => gh_repo(), 'latest' => $latest, 'releases' => $out];
}

/**
 * 从 $cur 出发，每次挑「可升的最小版本」（min_version <= 当前），
 * 保证按发布顺序逐级升级，不跳过中间包的迁移。
 */
function gh_plan_chain(array $releases, string $cur): array
{
    $chain = [];
    $v = $cur;
    while (count($chain) < gh_max_steps()) {
        $next = null;
        foreach ($releases as $r) {
            if (version_compare($r['version'], $v, '<=')) {
                continue;
            }
            if (version_compare($v, $r['min_version'], '<')) {
                continue;
            }
            if ($next === null || version_compare($r['version'], $next['version'], '<')) {
                $next = $r;
            }
        }
        if ($next === null) {
            break;
        }
        $chain[] = $next;
        $v = $next['version'];
    }
    return $chain;
}

/** 只检查、不下载 */
function gh_check(): array
{
    $m = gh_manifest();
    $cur = gh_current_version();
    $chain = gh_plan_chain($m['releases'], $cur);
    return [
        'ok'         => true,
        'current'    => $cur,
        'latest'     => $m['latest'],
        'repo'       => $m['repo'],
        'up_to_date' => count($chain) === 0,
        'chain'      => $chain,
        'broken'     => $m['latest'] !== '' && version_compare($m['latest'], $cur, '>') && count($chain) === 0,
        'checked_at' => date('Y-m-d H:i:s'),
    ];
}

/** 下载单个包并做 sha256 校验，返回临时文件路径 */
function gh_download_zip(array $r): string
{
    $res = gh_http_get(gh_zip_url($r['zip']), gh_max_zip(), 90);
    if (!$res['ok']) {
        throw new RuntimeException('下载 ' . $r['zip'] . ' 失败：' . $res['error']);
    }
    $body = $res['body'];
    if ($r['sha256'] !== '') {
        $actual = hash('sha256', $body);
        if (!hash_equals($r['sha256'], $actual)) {
            throw new RuntimeException('更新包 sha256 校验失败，已中止（可能是镜像缓存了旧文件，或内容被篡改）');
        }
    }
    if (substr($body, 0, 2) !== 'PK') {
        throw new RuntimeException('下载内容不是 zip，已中止');
    }
    $dir = BASE_PATH . '/storage/tmp';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建 storage/tmp 临时目录');
    }
    $path = $dir . '/gh-' . bin2hex(random_bytes(6)) . '.zip';
    if (@file_put_contents($path, $body) === false) {
        throw new RuntimeException('无法写入临时更新包（检查 storage/tmp 权限）');
    }
    return $path;
}

/** 执行完整的一键更新：按链依次下载 → 校验 → upgrade_apply() */
function gh_update_run(): array
{
    @set_time_limit(300);
    $cur = gh_current_version();
    $m = gh_manifest();
    $chain = gh_plan_chain($m['releases'], $cur);
    if (!$chain) {
        return [
            'ok'      => true,
            'message' => '当前已是最新版本 v' . $cur . '，无需更新',
            'steps'   => [],
            'from'    => $cur,
            'to'      => $cur,
        ];
    }

    $steps = [];
    foreach ($chain as $r) {
        $tmp = '';
        try {
            $tmp = gh_download_zip($r);
            $res = upgrade_apply($tmp, $cur);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            if ($tmp !== '' && is_file($tmp)) {
                @unlink($tmp);
            }
        }
        if (!$res['ok']) {
            audit('gh_upgrade_fail', "v{$cur} → v{$r['version']} 失败：" . mb_substr($res['message'], 0, 200, 'UTF-8'));
            return [
                'ok'      => false,
                'message' => "升级到 v{$r['version']} 失败：" . $res['message'],
                'steps'   => $steps,
                'from'    => $cur,
                'to'      => $cur,
            ];
        }
        $steps[] = ['version' => $r['version'], 'message' => $res['message']];
        $cur = $r['version'];
    }

    audit('gh_upgrade', 'GitHub 一键更新：' . implode(' → ', array_column($steps, 'version')));
    return [
        'ok'      => true,
        'message' => '已从 GitHub 自动升级 ' . count($steps) . ' 步，当前 v' . $cur
            . '。请刷新页面确认新版本（升级不会改动 config.php / storage / data）',
        'steps'   => $steps,
        'from'    => $chain[0]['min_version'] ?? '',
        'to'      => $cur,
    ];
}
