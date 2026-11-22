<?php
declare(strict_types=1);

/**
 * 源码仓库入口（2.5.0）
 *
 * 需求原话：「用户使用了本系统并安装成了网站以后，每个页面都要显示有连接 github 仓库的入口，
 * 并且这个入口不可隐藏。」
 *
 * 所以这里有一处刻意的不设计：**没有开关、不读 settings、不做权限判断**。
 * 后台也没有对应表单项——装了就是 MIT 开源版，出处必须一直看得见。
 * 谁想拿掉得改代码，改了就违反 MIT 的署名要求，责任在改的人身上。
 *
 * 展示位置：
 *   - 已登录：侧栏最上方（品牌行下面），一眼可见
 *   - 未登录：登录 / 注册 / 找回密码 / 落地页，各自渲染一份
 *   - 页脚：site_footer_html() 里也会带一个，微信里分享出去照样能点回仓库
 */

/** 仓库地址。写死常量而不是配置项——配置项意味着可关可改，那就不是「不可隐藏」了。 */
const KB_REPO_URL = 'https://github.com/arch-chen/xc-kb-portal';

/** GitHub 的 Octocat 标记（内联 SVG，不额外请求图片，也不受 CSP img-src 影响） */
function github_mark_svg(int $size = 16): string
{
    return '<svg class="gh-mark" viewBox="0 0 16 16" width="' . $size . '" height="' . $size . '"'
        . ' aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59'
        . '.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13'
        . '-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07'
        . '-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82'
        . '.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12'
        . '.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2'
        . ' 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>';
}

/**
 * 侧栏版入口：带文字、星标角标。
 * 放在侧栏顶部而不是折叠菜单里——折叠等于可隐藏。
 */
function github_side_link(): string
{
    return '<a class="gh-side" href="' . e(KB_REPO_URL) . '" target="_blank" rel="noopener noreferrer"'
        . ' title="本系统 MIT 开源，源码与问题反馈都在 GitHub">'
        . github_mark_svg(15)
        . '<span class="gh-side-text">GitHub 开源</span>'
        . '<span class="gh-side-tag">MIT</span>'
        . '</a>';
}

/**
 * 行内版入口：未登录页与落地页用。
 * $variant: 'pill'（默认，胶囊按钮）| 'text'（纯文字链接，页脚用）
 */
function github_inline_link(string $variant = 'pill', string $label = 'GitHub 开源仓库'): string
{
    $cls = $variant === 'text' ? 'gh-text' : 'gh-pill';
    return '<a class="' . $cls . '" href="' . e(KB_REPO_URL) . '" target="_blank" rel="noopener noreferrer">'
        . github_mark_svg($variant === 'text' ? 14 : 15)
        . '<span>' . e($label) . '</span>'
        . '</a>';
}
