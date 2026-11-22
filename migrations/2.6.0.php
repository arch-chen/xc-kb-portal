<?php
declare(strict_types=1);

/**
 * 2.6.0：清理被 auth.php / auth-bg.js 取代的旧认证页文件。
 *
 * 背景：2.5.0 及之前登录页是 app/views/login.php + app/views/register.php
 * 两个独立页面（切换 = 换页），背景是 assets/login-bg.js 里的数字雨。
 * 2.6.0 换成 app/views/auth.php 一页两 tab + assets/auth-bg.js 十套背景。
 *
 * 覆盖式升级只写不删，老站升级后这三个文件会一直留着，直链还能打开一个
 * 孤立的旧登录页（它的背景脚本已被新引擎取代，行为不再受支持）。
 *
 * 为什么用迁移而不是 delete.txt：
 * 升级时执行的是「站点上已有的那份 app/upgrade.php」。2.5.0 的升级器里
 * 没有 delete.txt 逻辑（那是 2.6.0 才加的），所以第一次引入删除能力的版本
 * 永远跑不到自己的删除代码——包里的删除清单读都没人读。
 * 迁移文件不受这个限制：升级器把它从包里解压到 storage/tmp 再 require，
 * 跑的一定是包里的新代码，跟站点上的旧升级器无关。
 *
 * 安全：只删这三个写死的相对路径，不读包、不读配置、不接受外部输入。
 * 幂等：文件不存在就跳过；已删过的站再跑一遍也只是空转。
 *
 * 升级时跑的是旧代码，这里不调用 2.6.0 新增的任何函数。
 */
return function (PDO $pdo): void {
    if (!defined('BASE_PATH')) {
        return;
    }

    $doomed = [
        'app/views/login.php',
        'app/views/register.php',
        'assets/login-bg.js',
    ];

    foreach ($doomed as $rel) {
        $p = BASE_PATH . '/' . $rel;
        if (is_file($p)) {
            @unlink($p);
        }
    }

    // 旧登录页可能还留着入口开关：清掉 setting 里的残留项（如果存在）。
    // 用 try/catch 兜住——迁移不该因为一个可选 setting 的表结构差异而整段失败。
    // 列名是 k / v（见 app/db.php 的 setting_get），不是 skey / svalue。
    try {
        $T = function_exists('db_prefix') ? db_prefix() : 'kb_';
        $pdo->prepare("DELETE FROM {$T}settings WHERE k IN ('login_style', 'login_bg')")
            ->execute();
    } catch (Throwable $e) {
        // 没有这两项、或表名不同，都不影响主目的
    }
};
