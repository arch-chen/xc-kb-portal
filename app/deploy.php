<?php
declare(strict_types=1);

/**
 * 部署公共文案：Web 服务器安全规则、自检地址。
 *
 * 为什么单独拆一个文件：安装向导（install.php）在 bootstrap 之前就要渲染这些
 * 内容（那时还没有配置和数据库），所以这里只放纯函数，不依赖 cfg()/db()。
 * 后台「部署安全」面板与安装完成页共用同一份字符串，避免两处写法漂移。
 */

/** 必须加在 server { } 里的两条规则：屏蔽代码/数据目录与危险扩展名 */
function nginx_rules(): string
{
    return 'location ~ ^/(app|data|storage|migrations|tools|examples)/ { deny all; return 404; }' . "\n"
        . 'location ~* \.(sqlite|sqlite3|db|db-wal|db-shm|csv|log|lock|bak|zip|sql|md|conf)$ { deny all; return 404; }';
}

/** 可选：短地址。不配也能用 /api.php?path=…、/mcp.php、/llms.php */
function nginx_rewrites(): string
{
    return 'location = /llms.txt { rewrite ^ /llms.php last; }' . "\n"
        . 'location = /mcp      { rewrite ^ /mcp.php   last; }';
}

/** 完整片段（带注释，缩进已按 server{} 内层写好） */
function nginx_snippet(): string
{
    return "# ===== kb-portal 安全规则（必须加）=====\n"
        . "# storage/ 存 SQLite 库与备份，data/ 存种子 CSV。nginx 默认把它们当静态文件\n"
        . "# 直接发出去，一条 URL 就能下走整库——加密挡不住这一步。\n"
        . "# nginx 不读 .htaccess，只能在站点配置里加。\n"
        . nginx_rules() . "\n"
        . "\n"
        . "# ===== 可选：短地址（不配就用 /api.php?path=…、/mcp.php、/llms.php）=====\n"
        . nginx_rewrites();
}

/** 安装包里带的规则文件，可直接 include 进 server {} */
function deploy_conf_path(): string
{
    return dirname(__DIR__) . '/nginx.example.conf';
}

/** 站点根地址（安装向导用，配置还没写死域名时按请求现算） */
function deploy_self_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host;
}

/** 部署自检项：路径 => 期望（用于文案与表格） */
function deploy_self_checks(string $base): array
{
    $base = rtrim($base, '/');
    return [
        [$base . '/storage/kb.sqlite', '404', '数据库文件'],
        [$base . '/data/wayfair-qa.csv', '404', '种子数据'],
        [$base . '/app/db.php', '404', '源代码'],
        [$base . '/install.php', '已安装过了', '安装向导'],
        [$base . '/api.php?path=/v1/health', '200 JSON', '接口'],
    ];
}
