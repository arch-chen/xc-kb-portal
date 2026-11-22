<?php
declare(strict_types=1);

/**
 * 前端控制器。所有页面都走 index.php?r=xxx。
 * 这样在虚拟主机上不需要伪静态也能运行；配了 .htaccess 后还能用 /g/slug 这类地址。
 */

if (!is_file(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/web.php';
require __DIR__ . '/app/pay/web.php';
require __DIR__ . '/app/upgrade.php';
require __DIR__ . '/app/github_update.php';

$route = get_q('r', '');

$routes = [
    ''             => 'ctrl_home',
    'landing'      => 'ctrl_landing',
    'login'        => 'ctrl_login',
    'register'     => 'ctrl_register',
    'forgot'       => 'ctrl_forgot',
    'account'      => 'ctrl_account',
    'mail_code'    => 'ctrl_mail_code',
    'logout'       => 'ctrl_logout',
    'search'       => 'ctrl_search',
    'g'            => 'ctrl_group',
    'entry'        => 'ctrl_entry',
    'doc'          => 'ctrl_doc',
    'keys'         => 'ctrl_keys',
    'docs'         => 'ctrl_docs',
    'key_reveal'   => 'ctrl_key_reveal',
    'group_new'    => 'ctrl_group_new',
    'entry_edit'   => 'ctrl_entry_edit',
    'admin'        => 'ctrl_admin',
    'admin_users'  => 'ctrl_admin_users',
    'admin_groups' => 'ctrl_admin_groups',
    'admin_perm'   => 'ctrl_admin_perm',
    'admin_keys'   => 'ctrl_admin_keys',
    'admin_content' => 'ctrl_admin_content',
    'admin_entry_edit' => 'ctrl_admin_entry_edit',
    'admin_entry_view' => 'ctrl_admin_entry_view',
    'admin_system' => 'ctrl_admin_system',
    'admin_mail'   => 'ctrl_admin_mail',
    'admin_import' => 'ctrl_admin_import',
    'admin_files'  => 'ctrl_admin_files',
    'ai'           => 'ctrl_ai',
    'agent'        => 'ctrl_agent',
    'ai_models'    => 'ctrl_ai_models',
    'recharge'     => 'ctrl_recharge',
    'admin_ai'     => 'ctrl_admin_ai',
    'pay_go'       => 'ctrl_pay_go',
    'pay_frame'    => 'ctrl_pay_frame',
    'pay_status'   => 'ctrl_pay_status',
    'pay_notify'   => 'ctrl_pay_notify',
    'pay_return'   => 'ctrl_pay_return',
    'admin_pay'    => 'ctrl_admin_pay',
    'f'            => 'ctrl_file',
    'admin_file'   => 'ctrl_admin_file',
    'branding'     => 'ctrl_branding',
];

// 兼容 /g/wayfair、/entry/12 这类伪静态地址（.htaccess 会传 r 参数）
if ($route === '' && !empty($_GET['p'])) {
    $parts = explode('/', trim((string)$_GET['p'], '/'));
    $route = $parts[0] ?? '';
    if (isset($parts[1])) {
        if ($route === 'g') {
            $_GET['slug'] = $parts[1];
        } else {
            $_GET['id'] = $parts[1];
        }
    }
}

if (!isset($routes[$route])) {
    http_response_code(404);
    if (current_user()) {
        view('notfound', ['title' => '页面不存在']);
    } else {
        exit('404 页面不存在');
    }
    exit;
}

$routes[$route]();
