<?php
declare(strict_types=1);

/**
 * 网站基础信息（2.4.0）
 *
 * 以前后台只能改 site_name 一项，副标题、logo、页脚、备案号全散在视图里硬编码，
 * 换个站点名等于要改代码（登录页甚至还写死了「XC知识库系统」）。
 * 这里把「站点身份」收成一份配置：全部落在 settings 表，模板统一读 site_info()。
 *
 * 只做展示层，不涉及权限、不改任何接口行为。
 * 图片（logo / favicon）存 storage/branding/，经 index.php?r=branding 输出——
 * 因为 storage/ 在 nginx/Apache 上是被 deny 的，直接给路径会 404。
 */

/** logo / favicon 存放目录 */
function site_brand_dir(): string
{
    return BASE_PATH . '/storage/branding';
}

/** 读全部站点信息（static 缓存；保存后传 true 刷掉） */
function site_info(string $key = '', bool $flush = false)
{
    static $info = null;
    if ($info === null || $flush) {
        $info = site_load();
    }
    if ($key === '') {
        return $info;
    }
    return $info[$key] ?? '';
}

/** 保存站点信息后清缓存：同一请求内再渲染就能看到新值 */
function site_reset(): void
{
    site_info('', true);
}

function site_load(): array
{
    $cfgName = trim((string)(cfg()['app']['name'] ?? ''));
    if ($cfgName === '') {
        $cfgName = 'XC知识库系统';
    }
    $g = static function (string $k, string $d = ''): string {
        $v = setting_get($k, null);
        if ($v === null || $v === '') {
            return $d;
        }
        return (string)$v;
    };

    return [
        'name'       => $g('site_name', $cfgName),
        'subtitle'   => $g('site_subtitle'),
        'desc'       => $g('site_desc'),
        'logo'       => $g('site_logo'),
        'favicon'    => $g('site_favicon'),
        'footer'     => $g('site_footer'),
        'icp'        => $g('site_icp'),
        'icp_url'    => $g('site_icp_url', 'https://beian.miit.gov.cn/'),
        'police'     => $g('site_police'),
        'police_url' => $g('site_police_url'),
        'contact'    => $g('site_contact'),
        'announce'   => $g('site_announce'),
    ];
}

/** 本地文件名 vs 外链/站内绝对路径 */
function site_brand_is_file(string $v): bool
{
    return $v !== '' && (bool)preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*$#', $v) && !str_contains($v, '..');
}

/**
 * 图片地址：上传的走 r=branding 输出，外链/站内路径原样返回。
 * $slot 为 'logo' | 'favicon' | 'bg'。
 *
 * 2.6.0 加一道存在性校验：settings 里记着文件名、磁盘上却没有这个文件时返回空串，
 * 让调用方回落到默认值（图标回落内置那套、背景回落预设动画）。
 * 起因是实测碰到一个站：换过几次图标，最后一张被清掉但 site_favicon 还指着它，
 * 于是每个页面都带一个 404 的 <link rel="icon">，浏览器标签一片空白还没人知道为什么。
 * 背景图同理——丢了图如果不回落，登录页背景就是一整片黑。
 */
function site_brand_src(string $slot): string
{
    $v = (string)site_info($slot);
    if ($v === '') {
        return '';
    }
    if (site_brand_is_file($v)) {
        if (!is_file(site_brand_dir() . '/' . $v)) {
            return '';
        }
        return u('branding', ['f' => $v]);
    }
    return $v;
}

/** 品牌方块：有 logo 用图，没有就用站点名首字（原来是写死的「知」） */
function site_brand_mark(string $cls = 'brand-mark'): string
{
    $src = site_brand_src('logo');
    $inner = $src !== ''
        ? '<img src="' . e($src) . '" alt="">'
        : e(mb_substr((string)site_info('name'), 0, 1, 'UTF-8'));
    return '<span class="' . e($cls) . '">' . $inner . '</span>';
}

/**
 * <link rel="icon"> 等一整套图标标签。
 *
 * 2.5.0 起「开通即有图标」：没上传自己的图标时回落到 assets/ 里的内置图标
 * （XC 蓝色圆角方块，由 tools/make_icon.php 生成）。
 * 上传过就只输出上传的那一张——用户自己的品牌优先，不能两套混着打架。
 *
 * 顺序讲究：SVG 放最前，现代浏览器优先取矢量（任何 DPI 都锐利）；
 * apple-touch-icon 给「添加到主屏」，必须是不透明 PNG（透明底会被填黑）。
 */
function site_favicon_tag(): string
{
    $src = site_brand_src('favicon');
    if ($src !== '') {
        $v = asset_ver('icon.svg');
        $out = '<link rel="icon" href="' . e($src) . '">' . "\n";
        // 上传的多半是 png/ico，补一张 svg 没意义；只给 apple-touch 兜底同一张图
        $out .= '<link rel="apple-touch-icon" href="' . e($src) . '">';
        return $out;
    }

    $q = '?v=' . e(asset_ver('icon.svg'));
    $q32 = '?v=' . e(asset_ver('icon-32.png'));
    $q180 = '?v=' . e(asset_ver('icon-180.png'));
    $out = [];
    $out[] = '<link rel="icon" type="image/svg+xml" href="' . e(asset('icon.svg')) . $q . '">';
    $out[] = '<link rel="icon" type="image/png" sizes="32x32" href="' . e(asset('icon-32.png')) . $q32 . '">';
    $out[] = '<link rel="alternate icon" href="' . e(asset('favicon.ico')) . '">';
    $out[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . e(asset('icon-180.png')) . $q180 . '">';
    return implode("\n", $out);
}

/** 内置图标地址：落地页 / 未登录页做品牌方块时用，永远有形 */
function site_default_icon(): string
{
    return asset('icon.svg') . '?v=' . asset_ver('icon.svg');
}

/**
 * llms.txt 的真实地址（2.5.0 修）。
 *
 * `/llms.txt` 靠 .htaccess / nginx 的 rewrite 把 .txt 转到 llms.php，
 * 但那是「可选的短地址」——线上实测没配 rewrite 的站，/llms.txt 是 404，
 * 只有 /llms.php 能通。文档页原本直接写 /llms.txt，等于在没配 rewrite 的站上
 * 挂了一堆死链。
 *
 * 这里不猜：装了就是 llms.php，静态文件存在才用 /llms.txt。
 */
function llms_url(): string
{
    if (is_file(BASE_PATH . '/llms.txt')) {
        return base_path() . '/llms.txt';
    }
    return base_path() . '/llms.php';
}

/**
 * 页脚。四项都可空；全都空就返回空串，不再强行渲染。
 *
 * 2.6.0 起页脚不再带 GitHub 入口——按需求「每个页面只需要在右上角预留一个即可」，
 * 入口统一收敛到右上角（github_corner_link()），页脚只管备案与版权。
 * 所以这里恢复成「无内容则不输出」，登录页也不用为了容纳入口而给页脚做特殊处理。
 */
function site_footer_html(): string
{
    $i = site_info();
    $bits = [];
    if ($i['footer'] !== '') {
        $bits[] = '<div class="site-foot-copy">' . nl2br(e($i['footer'])) . '</div>';
    }
    $links = [];
    if ($i['icp'] !== '') {
        $links[] = '<a href="' . e($i['icp_url'] !== '' ? $i['icp_url'] : 'https://beian.miit.gov.cn/') . '"'
            . ' target="_blank" rel="noopener noreferrer">' . e($i['icp']) . '</a>';
    }
    if ($i['police'] !== '') {
        $url = $i['police_url'] !== '' ? $i['police_url'] : 'https://beian.mps.gov.cn/';
        $links[] = '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($i['police']) . '</a>';
    }
    if ($i['contact'] !== '') {
        $links[] = '<span class="site-foot-contact">' . e($i['contact']) . '</span>';
    }
    if ($links) {
        $bits[] = '<div class="site-foot-links">' . implode('<span class="site-foot-dot">·</span>', $links) . '</div>';
    }
    if (!$bits) {
        return '';
    }
    return '<footer class="site-foot"><div class="site-foot-in">' . implode('', $bits) . '</div></footer>';
}

/**
 * 保存站点信息。返回 ['ok'=>bool,'message'=>string,'error'=>string]。
 * $files 传 $_FILES 进来即可（logo_file / favicon_file）。
 */
function site_save(array $post, array $files = []): array
{
    $limits = [
        'site_name'       => 60,
        'site_subtitle'   => 120,
        'site_desc'       => 300,
        'site_footer'     => 300,
        'site_icp'        => 60,
        'site_icp_url'    => 300,
        'site_police'     => 60,
        'site_police_url' => 300,
        'site_contact'    => 200,
        'site_announce'   => 300,
    ];
    $in = [];
    foreach ($limits as $k => $n) {
        $in[$k] = mb_substr(trim((string)($post[$k] ?? '')), 0, $n, 'UTF-8');
    }
    if ($in['site_name'] === '') {
        return ['ok' => false, 'message' => '', 'error' => '站点名称不能为空'];
    }
    foreach (['site_icp_url', 'site_police_url'] as $k) {
        if ($in[$k] !== '' && !preg_match('#^https?://#i', $in[$k])) {
            return ['ok' => false, 'message' => '', 'error' => '备案链接必须以 http:// 或 https:// 开头'];
        }
    }

    // 图片：先处理清除，再看提交的是文件还是地址
    $slots = [
        ['logo', 'site_logo', 'logo_file', 'logo_url', 512],
        ['favicon', 'site_favicon', 'favicon_file', 'favicon_url', 256],
    ];
    $notes = [];
    foreach ($slots as [$slot, $setKey, $fileKey, $urlKey, $maxKb]) {
        if (!empty($post[$slot . '_clear'])) {
            site_brand_delete((string)setting_get($setKey, ''));
            setting_set($setKey, '');
            $notes[] = $slot;
            continue;
        }
        $f = $files[$fileKey] ?? null;
        if (is_array($f) && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $r = site_brand_upload($f, $slot, $maxKb);
            if (!$r['ok']) {
                return ['ok' => false, 'message' => '', 'error' => $r['error']];
            }
            site_brand_delete((string)setting_get($setKey, ''));
            setting_set($setKey, $r['file']);
            $notes[] = $slot;
            continue;
        }
        $url = trim((string)($post[$urlKey] ?? ''));
        if ($url !== '') {
            if (!preg_match('#^(https?://|//|/)#i', $url)) {
                return ['ok' => false, 'message' => '', 'error' => '图片地址必须以 http(s):// 或 / 开头'];
            }
            setting_set($setKey, mb_substr($url, 0, 300, 'UTF-8'));
            $notes[] = $slot;
        }
    }

    foreach (array_diff_key($limits, ['site_name' => 1]) as $k => $_) {
        setting_set($k, $in[$k]);
    }
    setting_set('site_name', $in['site_name']);
    site_reset();

    $msg = '站点信息已保存';
    if ($notes) {
        $msg .= '（已更新：' . implode('、', array_map(fn($s) => $s === 'logo' ? '站点图标' : '浏览器小图标', $notes)) . '）';
    }
    return ['ok' => true, 'message' => $msg, 'error' => ''];
}

/**
 * 存一张品牌图。只允许位图，明确拒绝 svg——
 * svg 直接被浏览器打开时会执行脚本，放个 <img> 里看不见，但直链访问就是 XSS。
 *
 * $slot: 'logo' | 'favicon' | 'bg'（登录页背景图，2.6.0）
 */
function site_brand_upload(array $f, string $slot, int $maxKb): array
{
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'file' => '', 'error' => '图片超过 PHP 的 upload_max_filesize（当前 ' . ini_get('upload_max_filesize') . '）'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'file' => '', 'error' => '上传失败（错误码 ' . $err . '）'];
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        return ['ok' => false, 'file' => '', 'error' => '临时文件不存在'];
    }
    if ((int)filesize($tmp) > $maxKb * 1024) {
        return ['ok' => false, 'file' => '', 'error' => '图片不能超过 ' . $maxKb . ' KB'];
    }
    $ext = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    $allow = $slot === 'favicon'
        ? ['png', 'ico', 'jpg', 'jpeg', 'gif', 'webp']
        : ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    if (!in_array($ext, $allow, true)) {
        return ['ok' => false, 'file' => '', 'error' => '只支持 ' . implode(' / ', $allow) . ' 格式'];
    }
    $info = @getimagesize($tmp);
    if (!$info && $ext !== 'ico') {
        return ['ok' => false, 'file' => '', 'error' => '这不是一张能识别的图片'];
    }

    $dir = site_brand_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'file' => '', 'error' => '无法创建 storage/branding，检查 storage 目录权限'];
    }
    site_brand_guard($dir);

    $name = $slot . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    // move_uploaded_file 只认真实的 $_FILES 上传；命令行/自动化测试里没有这个概念，
    // 退回 rename。上面已经用 getimagesize 验过内容与后缀白名单，这里不放松校验。
    $moved = @move_uploaded_file($tmp, $dest);
    if (!$moved && PHP_SAPI === 'cli') {
        $moved = @rename($tmp, $dest);
    }
    if (!$moved) {
        return ['ok' => false, 'file' => '', 'error' => '写入失败，检查 storage/branding 权限'];
    }
    @chmod($dest, 0644);
    return ['ok' => true, 'file' => $name, 'error' => ''];
}

/** 删掉旧的品牌图（只删本地文件，外链不动） */
function site_brand_delete(string $v): void
{
    if (!site_brand_is_file($v)) {
        return;
    }
    $p = site_brand_dir() . '/' . $v;
    if (is_file($p)) {
        @unlink($p);
    }
}

/** 目录兜底：万一用户没配 nginx 规则，至少 Apache 下不能直链下载 */
function site_brand_guard(string $dir): void
{
    $h = $dir . '/.htaccess';
    if (!is_file($h)) {
        @file_put_contents($h, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

/** 输出品牌图（免登录，登录页也要用） */
function site_brand_send(string $file): void
{
    $file = basename($file);
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|gif|webp|ico)$#i', $file)) {
        site_brand_404();
    }
    $path = site_brand_dir() . '/' . $file;
    if (!is_file($path)) {
        site_brand_404();
    }

    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $mime = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
    ][$ext] ?? 'application/octet-stream';

    $etag = '"' . md5_file($path) . '"';
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    // 文件名带随机串，内容变了文件名就变，可以放心长缓存
    header('Cache-Control: public, max-age=2592000, immutable');
    header('ETag: ' . $etag);
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function site_brand_404(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 图片不存在');
}
