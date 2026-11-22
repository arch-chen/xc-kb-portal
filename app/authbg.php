<?php
declare(strict_types=1);

/**
 * 登录 / 注册页的动态背景（2.6.0）
 *
 * 需求原话：「注册页面背景和登录页面背景都要有动态画面，还需要增加动态画面，
 * 一共做 10 个动态画面，并在管理后台里面可以选择切换，也要在管理后台自定义添加
 * 动态画面的文件和代码，也可以上传图片做背景，添加 url 做背景。」
 *
 * 四种来源，一种渲染管线：
 *   preset —— 内置 10 套 canvas 动效（代码在 assets/auth-bg.js，零依赖零请求）
 *   image  —— 上传的图片（存 storage/branding/，经 ?r=branding 输出，storage 被 deny）
 *   url    —— 远程图片/视频直链（管理后台填，前端当 <img>/<video> 用）
 *   code   —— 后台粘贴的自定义 HTML/CSS/JS，**跑在 sandbox iframe 里**
 *
 * 为什么 code 一定要 sandbox：这一段是管理员粘贴的任意代码，如果直接内联进页面，
 * 它能读到同源的 cookie / localStorage，等于拿到会话。放进 iframe（sandbox 只给
 * allow-scripts，不给 allow-same-origin）后它是个独立的不透明源，碰不到站点数据，
 * 也拿不到顶层 DOM。这是唯一安全的做法，不是「顺手加的保险」。
 */

/** 内置预设清单：key => [名称, 一句话说明]。顺序即后台展示顺序。 */
function auth_bg_presets(): array
{
    return [
        'digits'   => ['数字雨',   '0-9 与 A-F 从上方落下，最经典的数据感'],
        'stars'    => ['星域',     '缓慢漂移的星点，偶尔划过一颗流星'],
        'aurora'   => ['极光',     '青紫光带横向流动，柔和不抢眼'],
        'net'      => ['粒子连线', '最近邻连线，距离越近越亮'],
        'ripple'   => ['波纹',     '同心圆由中心向外扩散，像水面的涟漪'],
        'grid'     => ['矩阵网格', '透视网格向前推进，带扫描线'],
        'gradient' => ['渐变流光', '大面积色块缓慢呼吸，几乎不耗电'],
        'shapes'   => ['几何漂浮', '方块与三角缓慢旋转漂浮'],
        'stream'   => ['代码流',   '纵向滚动的字符流，密度比数字雨低'],
        'orbs'     => ['光斑呼吸', '几团模糊光斑明暗起伏'],
    ];
}

/** 默认背景（保持 2.5.0 的观感，老站升级后看不出变化） */
const AUTH_BG_DEFAULT = 'digits';

/** 哪些槽位可配：登录、注册各自独立 */
function auth_bg_slots(): array
{
    return [
        'login'    => '登录',
        'register' => '注册',
    ];
}

/**
 * 读某个槽位的背景配置。
 * 返回 ['type'=>'preset|image|url|code|none', 'value'=>..., 'preset'=>..., 'dim'=>0-90]
 *
 * 向后兼容：2.5.0 没有这些设置项，读不到时返回内置默认，行为与升级前一致。
 */
function auth_bg_get(string $slot = 'login'): array
{
    $slot = isset(auth_bg_slots()[$slot]) ? $slot : 'login';
    $type = (string)setting_get('auth_bg_' . $slot . '_type', 'preset');
    $value = (string)setting_get('auth_bg_' . $slot . '_value', '');
    $dim = (int)setting_get('auth_bg_' . $slot . '_dim', 0);

    if (!in_array($type, ['preset', 'image', 'url', 'code', 'none'], true)) {
        $type = 'preset';
    }
    if ($type === 'preset' && $value === '') {
        $value = AUTH_BG_DEFAULT;
    }
    if ($type === 'preset' && !isset(auth_bg_presets()[$value])) {
        $value = AUTH_BG_DEFAULT;
    }
    return [
        'type'   => $type,
        'value'  => $value,
        'preset' => $type === 'preset' ? $value : AUTH_BG_DEFAULT,
        'dim'    => max(0, min(90, $dim)),
    ];
}

/** 当前请求该用哪个槽位：注册页用 register，其余（登录/找回密码）用 login */
function auth_bg_slot_for_route(string $route): string
{
    return $route === 'auth_register' ? 'register' : 'login';
}

/** 压暗强度（0-90）的 CSS 值。遮罩画在背景层容器上，不是画在 canvas 上。 */
function auth_bg_dim_style(string $slot = 'login'): string
{
    $dim = (int)auth_bg_get($slot)['dim'];
    return '--auth-dim:' . ($dim / 100);
}

/**
 * 输出背景层 HTML（不含 canvas 脚本）。
 *
 * 所有分支都保证：pointer-events:none（绝不拦输入框）、aria-hidden（读屏跳过）。
 * 最终的可视强度由 --auth-dim 控制，交给 CSS 做压暗，不在 PHP 里算颜色。
 */
function auth_bg_html(string $slot = 'login'): string
{
    $bg = auth_bg_get($slot);
    $dim = (int)$bg['dim'];
    $style = ' style="--auth-dim:' . ($dim / 100) . '"';
    $attr = ' class="auth-bg" aria-hidden="true"' . $style;

    switch ($bg['type']) {
        case 'none':
            return '<div class="auth-bg auth-bg-plain"' . $style . ' aria-hidden="true"></div>';

        case 'image':
        case 'url':
            $src = $bg['type'] === 'image'
                ? site_brand_src_value((string)$bg['value'])
                : (string)$bg['value'];
            if ($src === '') {
                return auth_bg_html_fallback($slot);
            }
            // 视频直链给 <video>，其余一律 <img>（外链是图片还是视频由扩展名判）
            // 注意定界符用 ~ 不用 #：这条正则里本来就要匹配 URL 的 #hash，
            // 用 # 当定界符会被 PHP 当成「模式结束」，报 Unknown modifier 并且匹配永远失败，
            // 于是一个 .mp4 会被当成 <img> 输出、背景直接不出来。
            if (preg_match('~\.(mp4|webm|ogv|ogg)(\?|#|$)~i', $src)) {
                return '<div' . $attr . '><video class="auth-bg-media" src="' . e($src)
                    . '" autoplay muted loop playsinline preload="metadata"></video></div>';
            }
            return '<div' . $attr . '><img class="auth-bg-media" src="' . e($src)
                . '" alt="" loading="eager" decoding="async"></div>';

        case 'code':
            return auth_bg_code_html((string)$bg['value'], $style);

        case 'preset':
        default:
            return '<canvas class="auth-bg auth-bg-canvas" aria-hidden="true"'
                . ' data-auth-bg="' . e($bg['preset']) . '"' . $style . '></canvas>';
    }
}

/** 配置指向的图挂了时，退回内置预设，别让页面变成一片纯色 */
function auth_bg_html_fallback(string $slot): string
{
    return '<canvas class="auth-bg auth-bg-canvas" aria-hidden="true"'
        . ' data-auth-bg="' . e(AUTH_BG_DEFAULT) . '" style="--auth-dim:0"></canvas>';
}

/**
 * 自定义代码：包进 sandbox iframe。
 *
 * sandbox="allow-scripts" 但**不给 allow-same-origin** —— 两个都给等于没沙箱
 * （同源 iframe 里能直接 parent.document 操作顶层页面）。只给 scripts 时它是一个
 * 不透明源：能跑自己的动画，但读不到 cookie、localStorage，也碰不到父页面。
 *
 * 用 srcdoc 而不是写文件：避免在站点目录里落地可执行的 .html，那样一旦被直链
 * 访问就是同源 XSS。srcdoc 的内容只在这个受限 iframe 里活着。
 */
function auth_bg_code_html(string $code, string $style): string
{
    $code = trim($code);
    if ($code === '') {
        return auth_bg_html_fallback('login');
    }
    // 只夹带动画，外壳（背景透明、无边框、铺满）由我们写死，管理员不用操心
    $doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
        . 'html,body{margin:0;padding:0;height:100%;background:transparent;overflow:hidden}'
        . '*{box-sizing:border-box}'
        . '</style></head><body>' . $code . '</body></html>';
    return '<div class="auth-bg auth-bg-code"' . $style . ' aria-hidden="true">'
        . '<iframe class="auth-bg-frame" title="" aria-hidden="true" tabindex="-1"'
        . ' sandbox="allow-scripts" scrolling="no" referrerpolicy="no-referrer"'
        . ' srcdoc="' . e($doc) . '"></iframe></div>';
}

/** 把 settings 里存的图片名/外链解析成真实 src（复用品牌图那套路由） */
function site_brand_src_value(string $v): string
{
    if ($v === '') {
        return '';
    }
    if (site_brand_is_file($v)) {
        // 文件没了就当没设置：返回空串，调用方会回落到预设动画，
        // 总比给浏览器一个 404 的 <img>（一片空白）强。
        if (!is_file(site_brand_dir() . '/' . $v)) {
            return '';
        }
        return u('branding', ['f' => $v]);
    }
    return $v;
}

/**
 * 保存背景设置。返回 ['ok'=>bool,'message'=>string,'error'=>string]。
 * $files 传 $_FILES 进来（bg_image_login / bg_image_register）。
 */
function auth_bg_save(array $post, array $files = []): array
{
    $presets = auth_bg_presets();
    $notes = [];

    foreach (array_keys(auth_bg_slots()) as $slot) {
        $p = 'auth_bg_' . $slot . '_';

        // 清除：连同上传的图片文件一起删，不留垃圾
        if (!empty($post[$slot . '_clear'])) {
            site_brand_delete((string)setting_get($p . 'value', ''));
            setting_set($p . 'type', 'preset');
            setting_set($p . 'value', AUTH_BG_DEFAULT);
            setting_set($p . 'dim', '0');
            $notes[] = auth_bg_slots()[$slot] . '已恢复默认';
            continue;
        }

        $type = (string)($post[$slot . '_type'] ?? 'preset');
        if (!in_array($type, ['preset', 'image', 'url', 'code', 'none'], true)) {
            return ['ok' => false, 'message' => '', 'error' => '背景类型不合法'];
        }

        $dim = (int)($post[$slot . '_dim'] ?? 0);
        $dim = max(0, min(90, $dim));

        if ($type === 'preset') {
            $val = (string)($post[$slot . '_preset'] ?? AUTH_BG_DEFAULT);
            if (!isset($presets[$val])) {
                return ['ok' => false, 'message' => '', 'error' => '没有这个内置背景'];
            }
            setting_set($p . 'value', $val);
        } elseif ($type === 'none') {
            setting_set($p . 'value', '');
        } elseif ($type === 'image') {
            $f = $files['bg_image_' . $slot] ?? null;
            if (is_array($f) && (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $r = site_brand_upload($f, 'bg', 4096);
                if (!$r['ok']) {
                    return ['ok' => false, 'message' => '', 'error' => auth_bg_slots()[$slot] . '背景图：' . $r['error']];
                }
                // 换了新图才删旧图；外链（非本地文件）不会被误删
                $old = (string)setting_get($p . 'value', '');
                if (site_brand_is_file($old)) {
                    site_brand_delete($old);
                }
                setting_set($p . 'value', $r['file']);
            } else {
                // 没传新文件：沿用已有值（本地文件名或外链）
                $cur = (string)setting_get($p . 'value', '');
                $url = trim((string)($post[$slot . '_image_url'] ?? ''));
                if ($url !== '') {
                    if (!preg_match('~^(https?://|//|/)~i', $url)) {
                        return ['ok' => false, 'message' => '', 'error' => '背景图地址必须以 http(s):// 或 / 开头'];
                    }
                    if (site_brand_is_file($cur)) {
                        site_brand_delete($cur);
                    }
                    setting_set($p . 'value', mb_substr($url, 0, 500, 'UTF-8'));
                } elseif ($cur === '') {
                    return ['ok' => false, 'message' => '', 'error' => auth_bg_slots()[$slot] . '选了图片背景，但既没上传也没填地址'];
                } elseif (site_brand_is_file($cur) && !is_file(site_brand_dir() . '/' . $cur)) {
                    // 记着文件名、盘上却没这文件（换过服务器/清过 storage），
                    // 这时「沿用旧值」等于留一条死链，不如让管理员重新选。
                    return ['ok' => false, 'message' => '', 'error' => auth_bg_slots()[$slot] . '背景图已丢失，请重新上传或填地址'];
                }
            }
        } elseif ($type === 'url') {
            $url = trim((string)($post[$slot . '_url'] ?? ''));
            if ($url === '') {
                return ['ok' => false, 'message' => '', 'error' => auth_bg_slots()[$slot] . '地址不能为空'];
            }
            if (!preg_match('~^(https?://|//|/)~i', $url)) {
                return ['ok' => false, 'message' => '', 'error' => '背景地址必须以 http(s):// 或 / 开头'];
            }
            setting_set($p . 'value', mb_substr($url, 0, 500, 'UTF-8'));
        } elseif ($type === 'code') {
            $code = (string)($post[$slot . '_code'] ?? '');
            if (trim($code) === '') {
                return ['ok' => false, 'message' => '', 'error' => auth_bg_slots()[$slot] . '自定义代码不能为空'];
            }
            if (strlen($code) > 60000) {
                return ['ok' => false, 'message' => '', 'error' => '自定义代码过长（上限 60 KB）'];
            }
            setting_set($p . 'value', $code);
        }

        setting_set($p . 'type', $type);
        setting_set($p . 'dim', (string)$dim);
    }

    site_reset();
    $msg = '登录 / 注册外观已保存';
    if ($notes) {
        $msg .= '（' . implode('；', $notes) . '）';
    }
    return ['ok' => true, 'message' => $msg, 'error' => ''];
}
