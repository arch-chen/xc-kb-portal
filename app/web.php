<?php
declare(strict_types=1);

/** 前台与后台的页面控制器（路由处理函数） */

/* ---------------- 登录 ---------------- */

function ctrl_login(): void
{
    if (current_user()) {
        redirect(u());
    }
    $err = '';
    if (is_post()) {
        csrf_check();
        $wait = login_locked_for();
        if ($wait > 0) {
            $err = '尝试次数过多，请 ' . $wait . ' 秒后再试';
        } elseif (login_attempt(post('username'), post('password'))) {
            $to = $_SESSION['after_login'] ?? '';
            unset($_SESSION['after_login']);
            redirect($to !== '' ? $to : u());
        } else {
            $err = '账号或密码不正确';
        }
    }
    auth_view('login', ['loginErr' => $err]);
}

/**
 * 登录 / 注册共用的渲染入口（2.6.0）。
 *
 * 两个页面合成一张 16:9 卡片、卡内切 tab，所以这里统一出参：
 * 哪个 tab 出错就默认停在哪个 tab，用户改完直接再提交，不用重新点一次。
 */
function auth_view(string $mode, array $extra = []): void
{
    $mailVerify = mail_register_verify();
    view('auth', array_merge([
        'title'     => $mode === 'register' ? '注册' : '登录',
        'mode'      => $mode,
        'loginErr'  => '',
        'regErr'    => '',
        'regOpen'   => register_allowed(),
        'mailReady' => mail_ready(),
        'reg'       => [
            'question'     => $mailVerify ? '' : captcha_new(),
            'limit'        => register_limit(),
            'mailVerify'   => $mailVerify,
            'mailOptional' => !$mailVerify && mail_ready(),
        ],
    ], $extra), false);
}

/**
 * 可直接打开某个 tab 的入口（2.6.0）。
 *
 * 老链接 `?r=register` 仍然有效（goto 到新地址并保留 mode），
 * 这样已有书签、已发出的注册邀请链接不会 404。
 */
function ctrl_auth(): void
{
    if (current_user()) {
        redirect(u());
    }
    $m = get_q('m') === 'register' ? 'register' : 'login';
    if ($m === 'register' && !register_allowed()) {
        http_response_code(403);
        view('register_closed', ['title' => '注册已关闭'], false);
        return;
    }
    auth_view($m);
}

function ctrl_logout(): void
{
    if (is_post()) {
        csrf_check();
        logout();
    }
    redirect(u('login'));
}

/* ---------------- 注册 ---------------- */

function ctrl_register(): void
{
    if (current_user()) {
        redirect(u());
    }
    if (!register_allowed()) {
        http_response_code(403);
        view('register_closed', ['title' => '注册已关闭'], false);
        return;
    }

    $mailVerify = mail_register_verify();

    $err = '';    if (is_post()) {
        csrf_check();
        $username = post('username');
        $pwd = post('password');
        $pwd2 = post('password2');
        $display = nick_clean(post('display_name'));
        $nick = $display !== '' ? $display : $username;
        $email = mail_email_norm(post('email'));

        // 先按 IP 计一次尝试：失败也计数，防止拿注册接口当用户名探测器
        $attemptLimit = max(10, register_limit() * 3);
        if (!throttle_hit('register', $attemptLimit, 3600)) {
            $err = '这一小时内注册尝试过多，请稍后再来';
        } elseif (!$mailVerify && !captcha_check(post('captcha'))) {
            $err = '验证码不对，请重新计算';
        } elseif (throttle_count('register_ok', 3600, true) >= register_hourly_cap()) {
            $err = '本站本小时注册人数已达上限，请稍后再试';
        } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,32}$/', $username)) {
            $err = '账号只能用字母、数字和 _ . @ -，长度 3~32';
        } elseif (one('SELECT id FROM ' . tbl('users') . ' WHERE username = ?', [$username])) {
            $err = '这个账号已经被占用，换一个';
        } elseif (($nickErr = nick_error($nick)) !== '') {
            $err = $nickErr;
        } elseif (mb_strlen($pwd) < 8) {
            $err = '密码至少 8 位';
        } elseif (preg_match('/^\d+$/', $pwd)) {
            $err = '密码不能全是数字';
        } elseif (strcasecmp($pwd, $username) === 0) {
            $err = '密码不能和账号相同';
        } elseif ($pwd !== $pwd2) {
            $err = '两次输入的密码不一致';
        } elseif ($mailVerify && $email === '') {
            $err = '本站要求邮箱验证，请填写邮箱并获取验证码';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = '邮箱格式不对';
        } elseif ($email !== '' && mail_user_by_email($email)) {
            $err = '这个邮箱已经被其他账号使用';
        } elseif ($mailVerify && ($v = mail_code_verify($email, 'register', post('code'))) && !$v['ok']) {
            $err = $v['error'];
        } else {
            $uid = create_user($username, $pwd, $nick, 'user');
            // 走邮箱验证注册的，直接记为已验证；选填邮箱的留待用户自己在账户页验证
            if ($email !== '') {
                update_row('users', $uid, [
                    'email'             => $email,
                    'email_verified_at' => $mailVerify ? now_str() : null,
                ]);
            }
            $space = create_user_space($uid, $username, $nick);
            throttle_hit('register_ok', 1000000, 3600, true);
            audit('register', 'user=' . $username . ' space=' . $space['id']
                . ($email !== '' ? ' email=1' : ''));

            if ($email !== '' && $mailVerify) {
                mail_welcome($uid);
            }

            // 注册完直接进入，不用再输一遍密码
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            update_row('users', $uid, ['last_login_at' => now_str()]);
            flash('已为你创建私有空间「' . $space['name'] . '」，这里的内容只有你自己能看到');
            redirect(u());
        }
    }

    auth_view('register', ['regErr' => $err]);
}

/* ---------------- 发邮件验证码（AJAX） ---------------- */

function ctrl_mail_code(): void
{
    header('Cache-Control: no-store');
    if (!is_post()) {
        json_out(['ok' => false, 'error' => ['message' => '只接受 POST']], 405);
    }
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || $t === '' || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        json_out(['ok' => false, 'error' => ['message' => '会话已过期，请刷新页面重试']], 419);
    }
    $purpose = post('purpose');
    if (!in_array($purpose, ['register', 'reset', 'verify', 'change'], true)) {
        json_out(['ok' => false, 'error' => ['message' => '未知的用途']], 400);
    }
    $email = mail_email_norm(post('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'error' => ['message' => '邮箱格式不对']], 400);
    }
    if (!mail_ready()) {
        json_out(['ok' => false, 'error' => ['message' => '管理员还没有配置好邮件服务']], 400);
    }

    $uid = 0;
    if ($purpose === 'register') {
        if (!register_allowed()) {
            json_out(['ok' => false, 'error' => ['message' => '本站没有开放注册']], 403);
        }
        $exists = mail_user_by_email($email);
        if ($exists) {
            json_out(['ok' => false, 'error' => ['message' => '这个邮箱已经被其他账号使用']], 409);
        }
    } elseif ($purpose === 'reset') {
        // 不暴露邮箱是否存在：有账号才真发，回复永远一样
        $u = mail_user_by_email($email);
        if (!$u) {
            json_out(['ok' => true, 'data' => [
                'message' => '如果这个邮箱注册过，验证码会在几秒内送达',
                'cooldown' => MAIL_CODE_COOLDOWN, 'sent' => false,
            ]]);
        }
        $uid = (int)$u['id'];
    } else {
        // 这两个用途必须登录。这里不能用 require_login()——它会 302 到登录页，
        // AJAX 拿到一坨 HTML 只会报「返回的不是 JSON」。
        $u = current_user();
        if (!$u) {
            json_out(['ok' => false, 'error' => ['message' => '登录已过期，请重新登录后再试']], 401);
        }
        $uid = (int)$u['id'];
        if ($purpose === 'verify') {
            $other = mail_user_by_email($email);
            if ($other && (int)$other['id'] !== $uid) {
                json_out(['ok' => false, 'error' => ['message' => '这个邮箱已被其他账号绑定']], 409);
            }
        }
        if ($purpose === 'change' && trim((string)$u['email']) === '') {
            json_out(['ok' => false, 'error' => ['message' => '请先绑定并验证邮箱']], 400);
        }
    }

    $r = mail_code_issue($email, $purpose, $uid);
    if (!$r['ok']) {
        json_out([
            'ok' => false,
            'error' => ['message' => $r['error'], 'wait' => $r['wait']],
        ], 429);
    }
    $msg = $purpose === 'reset'
        ? '如果这个邮箱注册过，验证码会在几秒内送达'
        : '验证码已发送，请查收邮件（含垃圾箱）';
    json_out(['ok' => true, 'data' => [
        'message' => $msg, 'cooldown' => $r['wait'], 'ttl' => $r['ttl'], 'sent' => true,
    ]]);
}

/* ---------------- 找回密码 ---------------- */

function ctrl_forgot(): void
{
    if (current_user()) {
        redirect(u());
    }
    $ready = mail_ready();
    $err = '';
    $step = (int)($_SESSION['forgot_step'] ?? 1);
    $email = (string)($_SESSION['forgot_email'] ?? '');

    if (is_post()) {
        csrf_check();
        $action = post('action');
        if ($action === 'send') {
            $email = mail_email_norm(post('email'));
            if (!$ready) {
                $err = '邮件服务未启用，请联系管理员重置密码';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err = '邮箱格式不对';
            } elseif (!throttle_hit('forgot', 8, 3600)) {
                $err = '请求太频繁，请稍后再试';
            } else {
                $u = mail_user_by_email($email);
                if ($u) {
                    $r = mail_code_issue($email, 'reset', (int)$u['id']);
                    if (!$r['ok']) {
                        $err = $r['error'];
                    }
                }
                if ($err === '') {
                    $_SESSION['forgot_email'] = $email;
                    $_SESSION['forgot_step'] = 2;
                    flash('如果这个邮箱注册过，验证码已经发出');
                    redirect(u('forgot'));
                }
            }
        } elseif ($action === 'reset') {
            $code = post('code');
            $pwd = post('password');
            $pwd2 = post('password2');
            $email = (string)($_SESSION['forgot_email'] ?? '');
            $u = $email !== '' ? mail_user_by_email($email) : null;
            if (!$u) {
                $err = '会话已过期，请重新获取验证码';
                $step = 1;
            } elseif (mb_strlen($pwd) < 8) {
                $err = '密码至少 8 位';
            } elseif (preg_match('/^\d+$/', $pwd)) {
                $err = '密码不能全是数字';
            } elseif ($pwd !== $pwd2) {
                $err = '两次输入的密码不一致';
            } else {
                $v = mail_code_verify($email, 'reset', $code);
                if (!$v['ok']) {
                    $err = $v['error'];
                } else {
                    update_row('users', (int)$u['id'], ['password_hash' => password_hash($pwd, PASSWORD_DEFAULT)]);
                    audit('reset_password_by_mail', 'user=' . (int)$u['id']);
                    unset($_SESSION['forgot_email'], $_SESSION['forgot_step'], $_SESSION['login_fail']);
                    flash('密码已重置，用新密码登录');
                    redirect(u('login'));
                }
            }
        } elseif ($action === 'back') {
            unset($_SESSION['forgot_email'], $_SESSION['forgot_step']);
            redirect(u('forgot'));
        }
    }
    if ($step === 2 && $email === '') {
        $step = 1;
    }

    view('forgot', [
        'title' => '找回密码',
        'ready' => $ready,
        'step'  => $step,
        'email' => $email,
        'err'   => $err,
    ], false);
}

/* ---------------- 账户设置 ---------------- */

function ctrl_account(): void
{
    $u = require_login();
    $uid = (int)$u['id'];
    $ready = mail_ready();

    if (is_post()) {
        csrf_check();
        $action = post('action');

        if ($action === 'profile') {
            $name = nick_clean(post('display_name'));
            $name = $name !== '' ? $name : nick_of($u);
            $nickErr = nick_error($name, $uid);
            if ($nickErr !== '') {
                flash($nickErr, 'err');
                redirect(u('account'));
            }
            $oldNick = nick_of($u);
            update_row('users', $uid, [
                'display_name' => $name,
                'notify_mail'  => post('notify_mail') === '1' ? 1 : 0,
            ]);
            $moved = $name !== $oldNick ? sync_user_group_names($uid, $oldNick, $name) : 0;
            audit('update_profile', 'user=' . $uid . ' nick=' . $name . ' groups=' . $moved);
            flash($moved > 0
                ? '昵称已改为「' . $name . '」，名下 ' . $moved . ' 个知识库名称已同步更新'
                : '资料已保存');
            redirect(u('account'));
        }

        if ($action === 'email') {
            $email = mail_email_norm(post('email'));
            $other = mail_user_by_email($email);
            if (!$ready) {
                flash('管理员还没有启用邮件服务，暂时不能验证邮箱', 'err');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('邮箱格式不对', 'err');
            } elseif ($other && (int)$other['id'] !== $uid) {
                flash('这个邮箱已被其他账号绑定', 'err');
            } else {
                $v = mail_code_verify($email, 'verify', post('code'));
                if (!$v['ok']) {
                    flash($v['error'], 'err');
                } else {
                    update_row('users', $uid, ['email' => $email, 'email_verified_at' => now_str()]);
                    audit('bind_email', 'user=' . $uid);
                    flash('邮箱已验证并保存，之后可以用它找回密码');
                }
            }
            redirect(u('account'));
        }

        if ($action === 'password') {
            $old = (string)($_POST['old_password'] ?? '');
            $new = post('new_password');
            $new2 = post('new_password2');
            if (!password_verify($old, (string)$u['password_hash'])) {
                flash('当前密码不对', 'err');
            } elseif (mb_strlen($new) < 8) {
                flash('新密码至少 8 位', 'err');
            } elseif (preg_match('/^\d+$/', $new)) {
                flash('新密码不能全是数字', 'err');
            } elseif ($new !== $new2) {
                flash('两次输入的新密码不一致', 'err');
            } else {
                update_row('users', $uid, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                audit('change_password', 'user=' . $uid);
                flash('密码已修改');
            }
            redirect(u('account'));
        }

        if ($action === 'password_code') {
            $new = post('new_password');
            $new2 = post('new_password2');
            $email = mail_email_norm((string)$u['email']);
            if (!$ready || $email === '') {
                flash('请先绑定并验证邮箱', 'err');
            } elseif (mb_strlen($new) < 8) {
                flash('新密码至少 8 位', 'err');
            } elseif ($new !== $new2) {
                flash('两次输入的新密码不一致', 'err');
            } else {
                $v = mail_code_verify($email, 'change', post('code'));
                if (!$v['ok']) {
                    flash($v['error'], 'err');
                } else {
                    update_row('users', $uid, ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
                    session_regenerate_id(true);
                    audit('change_password_by_mail', 'user=' . $uid);
                    flash('密码已修改');
                }
            }
            redirect(u('account'));
        }

        redirect(u('account'));
    }

    $space = user_primary_space($uid);
    view('account', [
        'title'  => '账户设置',
        'u'      => $u,
        'space'  => $space,
        'ready'  => $ready,
        'groups' => visible_groups($uid),
    ]);
}


/* ---------------- 前台 ---------------- */

function ctrl_home(): void
{
    // 未登录访问根地址 → 落地页（2.5.0）。
    // 以前直接 require_login()，陌生人点进来只看到一个登录框，不知道这是什么系统。
    // 已登录的用户体验完全不变，仍然进工作台。
    if (!current_user()) {
        redirect(u('landing'));
    }
    $u = require_login();
    $groups = visible_groups((int)$u['id']);
    $ids = array_map(fn($g) => (int)$g['id'], $groups);
    $stats = group_stats($ids);
    $recent = recent_entries($ids, 8);
    $totalQa = 0;
    $totalDoc = 0;
    foreach ($stats as $s) {
        $totalQa += $s['qa'] ?? 0;
        $totalDoc += $s['doc'] ?? 0;
    }
    view('home', [
        'title'    => '首页',
        'groups'   => $groups,
        'stats'    => $stats,
        'recent'   => $recent,
        'totalQa'  => $totalQa,
        'totalDoc' => $totalDoc,
    ]);
}

function ctrl_search(): void
{
    $u = require_login();
    $q = get_q('q');
    $kind = in_array(get_q('kind'), ['qa', 'doc'], true) ? get_q('kind') : '';
    $gKey = get_q('g');
    $groups = visible_groups((int)$u['id']);
    $allowed = array_map(fn($g) => (int)$g['id'], $groups);

    $scope = $allowed;
    $scopeName = '';
    if ($gKey !== '') {
        $g = find_group($gKey);
        if ($g && in_array((int)$g['id'], $allowed, true)) {
            $scope = [(int)$g['id']];
            $scopeName = $g['name'];
        }
    }

    $page = max(1, (int)get_q('p', '1'));
    $per = 20;
    $res = ['total' => 0, 'items' => []];
    $pg = paginate(0, $per, 1);
    if ($q !== '') {
        $res = search_entries($q, $scope, $kind, $per, ($page - 1) * $per);
        $pg = paginate($res['total'], $per, $page);
        if ($pg['page'] !== $page) {
            $res = search_entries($q, $scope, $kind, $per, $pg['offset']);
        }
    }
    view('search', [
        'title' => $q === '' ? '搜索' : $q,
        'q' => $q, 'kind' => $kind, 'groups' => $groups, 'scopeName' => $scopeName,
        'gKey' => $gKey, 'res' => $res, 'pg' => $pg,
    ]);
}

function ctrl_group(): void
{
    $u = require_login();
    $g = find_group(get_q('slug', get_q('id')));
    if (!$g) {
        http_response_code(404);
        exit('分组不存在');
    }
    if (!can_read_group((int)$u['id'], (int)$g['id'])) {
        http_response_code(403);
        exit('无权访问该分组');
    }

    $owner = (int)$g['owner_id'];
    $ownerNick = $owner > 0 ? (group_owner_nick($g) ?: '用户' . $owner) : '';
    /**
     * 谁能改这个分组的名字：判定收在 perm.php 的 can_rename_group()。
     * 2.6.5 起侧栏的「⋯ → 重命名」也走同一份判定，两处不再各写一套
     * （2.3.0 的 group_perm()/my_groups() 就是各写一套，最后出现
     * 「点得进去但侧栏看不见」的坑）。
     */
    $canManage = can_rename_group((int)$u['id'], (int)$g['id']);

    /**
     * 改名 / 改说明。
     *
     * 2.6.5 起改名主入口挪到左侧栏的「⋯」（?r=group_rename），这里只剩「说明」，
     * 所以用 action 字段区分：没有 action 的是老页面残留的提交，按改名处理（兼容），
     * action=desc 的只更新说明，不碰名称。
     */
    if (is_post()) {
        csrf_check();
        if (!$canManage) {
            flash($owner > 0
                ? '只有这个分组的创建者本人可以改名'
                : '只有管理员可以改共享分组的名称', 'err');
            redirect(u('g', ['slug' => $g['slug']]));
        }
        if (post('action') === 'desc') {
            update_row('groups', (int)$g['id'], [
                'description' => mb_substr(post('description'), 0, 480, 'UTF-8'),
            ]);
            flash('说明已保存');
            redirect(u('g', ['slug' => $g['slug']]));
        }
        $nameIn = nick_clean(post('name'));
        $isDefaultSpace = post('default_space') === '1';
        if ($nameIn === '' && !($owner > 0 && $isDefaultSpace)) {
            flash('分组名称不能为空', 'err');
        } else {
            $r = group_rename((int)$g['id'], $nameIn, post('description'));
            if ($r['ok']) {
                audit('rename_group', 'id=' . $g['id'] . ' ' . $r['name']);
                flash('分组名称已更新为「' . $r['name'] . '」');
            } else {
                flash($r['error'], 'err');
            }
        }
        redirect(u('g', ['slug' => $g['slug']]));
    }

    $kind = in_array(get_q('kind'), ['qa', 'doc'], true) ? get_q('kind') : '';
    $page = max(1, (int)get_q('p', '1'));
    $per = 30;
    $where = 'status = 1 AND group_id = ?';
    $p = [(int)$g['id']];
    if ($kind !== '') {
        $where .= ' AND kind = ?';
        $p[] = $kind;
    }
    $total = (int)val('SELECT COUNT(*) FROM ' . tbl('entries') . ' WHERE ' . $where, $p, 0);
    $pg = paginate($total, $per, $page);
    $rows = all('SELECT * FROM ' . tbl('entries') . ' WHERE ' . $where
        . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $per . ' OFFSET ' . $pg['offset'], $p);
    view('group', [
        'title' => $g['name'], 'group' => $g, 'rows' => $rows, 'pg' => $pg, 'kind' => $kind,
        'canWrite' => can_write_group((int)$u['id'], (int)$g['id']),
        'canManage' => $canManage,
        'ownerNick' => $ownerNick,
        'isAdmin' => is_admin($u),
        'baseName' => $owner > 0 ? group_base_of((string)$g['name'], $ownerNick) : (string)$g['name'],
    ]);
}

function ctrl_entry(): void
{
    $u = require_login();
    $id = (int)get_q('id');
    $e = entry_by_id($id);
    if (!$e || (int)$e['status'] !== 1) {
        http_response_code(404);
        exit('条目不存在');
    }
    if (!can_read_group((int)$u['id'], (int)$e['group_id'])) {
        http_response_code(403);
        exit('无权访问');
    }
    if ($e['kind'] === 'doc') {
        redirect(u('doc', ['id' => $id]));
    }
    $g = group_by_id((int)$e['group_id']);
    view('entry', [
        'title' => $e['title'] ?: mb_substr((string)$e['question'], 0, 40, 'UTF-8'),
        'e' => $e, 'group' => $g,
        'docs' => entry_docs($id),
        'versions' => entry_versions($id),
        'canWrite' => can_write_group((int)$u['id'], (int)$e['group_id']),
    ]);
}

function ctrl_doc(): void
{
    $u = require_login();
    $id = (int)get_q('id');
    $e = entry_by_id($id);
    if (!$e || (int)$e['status'] !== 1) {
        http_response_code(404);
        exit('文档不存在');
    }
    if (!can_read_group((int)$u['id'], (int)$e['group_id'])) {
        http_response_code(403);
        exit('无权访问');
    }
    $g = group_by_id((int)$e['group_id']);
    view('doc', [
        'title' => $e['title'],
        'e' => $e, 'group' => $g, 'items' => doc_entries($id),
        'canWrite' => can_write_group((int)$u['id'], (int)$e['group_id']),
    ]);
}

/* ---------------- 公开开发文档 ---------------- */

/**
 * 对外接入文档：不需要登录，方便把链接直接发给合作方或平台方。
 * 页面里所有地址都由 base_url() 现算，换个域名不用改文档。
 */
function ctrl_docs(): void
{
    view('docs', [
        'title'      => '开发文档',
        'mcpEnabled' => mcp_enabled(),
    ], false);
}

/* ---------------- 我的 API Key ---------------- */

function ctrl_keys(): void
{
    $u = require_login();
    $uid = (int)$u['id'];

    if (is_post()) {
        csrf_check();
        $action = post('action');
        if ($action === 'create') {
            $gid = (int)post('group_id');
            $perm = group_perm($uid, $gid);
            $wantRead = post('can_read') === '1';
            $wantWrite = post('can_write') === '1';
            if (!$perm['read'] && !$perm['write']) {
                flash('你没有这个分组的权限', 'err');
                redirect(u('keys'));
            }
            // 不允许申请超出自身权限的能力
            $canRead = $wantRead && $perm['read'];
            $canWrite = $wantWrite && $perm['write'];
            if (!$canRead && !$canWrite) {
                flash('请至少选择读或写；写权限需要你先拥有该分组的写权限', 'err');
                redirect(u('keys'));
            }
            $exp = post('expires_at');
            if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
                $exp = '';
            }
            $r = issue_api_key($uid, post('name'), $gid, $canRead, $canWrite, $exp !== '' ? $exp . ' 23:59:59' : null);
            $_SESSION['new_key'] = $r['key'];
            audit('create_api_key', 'id=' . $r['id'] . ' group=' . $gid);
            flash('API Key 已创建，之后随时可以在本页查看完整 Key');
            redirect(u('keys'));
        }
        if ($action === 'revoke') {
            $kid = (int)post('id');
            $row = one('SELECT * FROM ' . tbl('api_keys') . ' WHERE id = ? AND user_id = ?', [$kid, $uid]);
            if ($row) {
                update_row('api_keys', $kid, ['revoked' => 1]);
                audit('revoke_api_key', 'id=' . $kid);
                flash('已吊销');
            }
            redirect(u('keys'));
        }
    }

    $newKey = $_SESSION['new_key'] ?? null;
    unset($_SESSION['new_key']);
    $rows = all('SELECT k.*, g.name AS group_name, g.slug AS group_slug
                 FROM ' . tbl('api_keys') . ' k
                 LEFT JOIN ' . tbl('groups') . ' g ON g.id = k.group_id
                 WHERE k.user_id = ? ORDER BY k.id DESC', [$uid]);
    $groups = visible_groups($uid);
    $perms = [];
    foreach ($groups as $g) {
        $perms[(int)$g['id']] = group_perm($uid, (int)$g['id']);
    }
    // 回显用的脱敏文本；旧版本创建的 Key 只有哈希，key_plain 为空，页面会提示重建
    foreach ($rows as &$r) {
        $plain = secret_decrypt((string)($r['key_plain'] ?? ''));
        $r['key_mask'] = $plain !== '' ? secret_mask($plain) : '';
    }
    unset($r);
    view('keys', [
        'title' => '我的 API Key', 'rows' => $rows, 'groups' => $groups,
        'perms' => $perms, 'newKey' => $newKey,
    ]);
}

/**
 * 取 Key 明文（JSON）。只有创建者本人能取，管理员也不能看别人的 Key——
 * 能看就等于能冒充对方的 Agent，这条边界不能为了省事放开。
 */
function ctrl_key_reveal(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $u = require_login();
    $id = (int)get_q('id');

    if (is_post()) {
        csrf_check();
    }

    $row = one('SELECT * FROM ' . tbl('api_keys') . ' WHERE id = ?', [$id]);
    if (!$row) {
        json_out(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Key 不存在']], 404);
    }
    if ((int)$row['user_id'] !== (int)$u['id']) {
        json_out(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => '只能查看自己创建的 Key']], 403);
    }
    if ((int)$row['revoked'] === 1) {
        json_out(['ok' => false, 'error' => ['code' => 'revoked', 'message' => '该 Key 已吊销，无法查看']], 403);
    }
    $plain = secret_decrypt((string)($row['key_plain'] ?? ''));
    if ($plain === '') {
        json_out([
            'ok' => false,
            'error' => [
                'code' => 'legacy',
                'message' => '这条 Key 是旧版本创建的，明文没有留存、无法还原。吊销后用新版本重建一个即可长期查看。',
            ],
        ], 404);
    }
    json_out(['ok' => true, 'data' => ['id' => $id, 'key' => $plain, 'mask' => secret_mask($plain)]]);
}

/* ---------------- 后台 ---------------- */

function ctrl_admin(): void
{
    require_admin();
    $counts = [
        'users'   => (int)val('SELECT COUNT(*) FROM ' . tbl('users'), [], 0),
        'groups'  => (int)val('SELECT COUNT(*) FROM ' . tbl('groups'), [], 0),
        'entries' => (int)val('SELECT COUNT(*) FROM ' . tbl('entries') . ' WHERE status = 1', [], 0),
        'keys'    => (int)val('SELECT COUNT(*) FROM ' . tbl('api_keys') . ' WHERE revoked = 0', [], 0),
    ];
    $dbSize = 0;
    if (db_driver() === 'sqlite') {
        $p = cfg()['db']['sqlite_path'];
        $dbSize = is_file($p) ? (int)filesize($p) : 0;
    }
    view('admin_home', [
        'title' => '后台', 'counts' => $counts, 'dbSize' => $dbSize,
        'recentLogs' => all('SELECT * FROM ' . tbl('audit_log') . ' ORDER BY id DESC LIMIT 12'),
    ]);
}

function ctrl_admin_users(): void
{
    require_admin();
    if (is_post()) {
        csrf_check();
        $action = post('action');
        if ($action === 'create') {
            $username = post('username');
            $pwd = post('password');
            $nick = nick_clean(post('display_name')) ?: $username;
            if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $username)) {
                flash('用户名只能用字母数字和 _ . @ -，3~64 位', 'err');
            } elseif (strlen($pwd) < 8) {
                flash('密码至少 8 位', 'err');
            } elseif (one('SELECT id FROM ' . tbl('users') . ' WHERE username = ?', [$username])) {
                flash('用户名已存在', 'err');
            } elseif (($nickErr = nick_error($nick)) !== '') {
                flash($nickErr, 'err');
            } else {
                $id = create_user($username, $pwd, $nick, 'user');
                $email = mail_email_norm(post('email'));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !mail_user_by_email($email)) {
                    update_row('users', $id, ['email' => $email, 'email_verified_at' => now_str()]);
                }
                audit('create_user', $username);
                flash('用户已创建，请到「分组授权」给他分配权限');
                redirect(u('admin_perm', ['uid' => $id]));
            }
        }
        if ($action === 'reset') {
            $id = (int)post('id');
            $pwd = post('password');
            if (strlen($pwd) < 8) {
                flash('密码至少 8 位', 'err');
            } else {
                update_row('users', $id, ['password_hash' => password_hash($pwd, PASSWORD_DEFAULT)]);
                audit('reset_password', 'user=' . $id);
                flash('密码已重置');
            }
        }
        if ($action === 'email') {
            $id = (int)post('id');
            $email = mail_email_norm(post('email'));
            $row = one('SELECT * FROM ' . tbl('users') . ' WHERE id = ?', [$id]);
            $other = $email !== '' ? mail_user_by_email($email) : null;
            if (!$row) {
                flash('用户不存在', 'err');
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('邮箱格式不对', 'err');
            } elseif ($other && (int)$other['id'] !== $id) {
                flash('这个邮箱已被其他账号使用', 'err');
            } else {
                update_row('users', $id, [
                    'email'             => $email !== '' ? $email : null,
                    'email_verified_at' => $email !== '' ? now_str() : null,
                ]);
                audit('admin_set_email', 'user=' . $id);
                flash($email !== ''
                    ? '邮箱已保存，并视为已验证（管理员代设）。之后的通知、找回密码都能用'
                    : '邮箱已清空，该账号不再收通知');
            }
        }
        if ($action === 'toggle') {
            $id = (int)post('id');
            $row = one('SELECT * FROM ' . tbl('users') . ' WHERE id = ?', [$id]);
            if ($row) {
                if ((int)$row['id'] === (int)current_user()['id']) {
                    flash('不能停用自己', 'err');
                } else {
                    update_row('users', $id, ['status' => (int)$row['status'] === 1 ? 0 : 1]);
                    flash('状态已切换');
                }
            }
        }
        if ($action === 'delete') {
            $id = (int)post('id');
            if ($id === (int)current_user()['id']) {
                flash('不能删除自己', 'err');
            } else {
                // 私有空间不跟着账号一起消失：转为共享分组，内容留给管理员处置
                $spaces = all('SELECT name FROM ' . tbl('groups') . ' WHERE owner_id = ?', [$id]);
                q('UPDATE ' . tbl('groups') . ' SET owner_id = 0 WHERE owner_id = ?', [$id]);
                q('DELETE FROM ' . tbl('group_user') . ' WHERE user_id = ?', [$id]);
                q('UPDATE ' . tbl('api_keys') . ' SET revoked = 1 WHERE user_id = ?', [$id]);
                q('DELETE FROM ' . tbl('users') . ' WHERE id = ?', [$id]);
                audit('delete_user', 'user=' . $id . ' spaces=' . count($spaces));
                flash(count($spaces) > 0
                    ? '用户已删除；其 ' . count($spaces) . ' 个私有空间已转为共享分组，内容保留在「分组管理」里'
                    : '用户已删除');
            }
        }
        redirect(u('admin_users'));
    }
    $rows = all('SELECT u.*,
                        (SELECT COUNT(*) FROM ' . tbl('groups') . ' g WHERE g.owner_id = u.id) AS spaces
                 FROM ' . tbl('users') . ' u ORDER BY u.role, u.id');
    view('admin_users', ['title' => '用户管理', 'rows' => $rows]);
}

function ctrl_admin_perm(): void
{
    require_admin();
    $uid = (int)get_q('uid', '0');
    $user = one('SELECT * FROM ' . tbl('users') . ' WHERE id = ?', [$uid]);
    if (!$user) {
        $list = all('SELECT * FROM ' . tbl('users') . ' ORDER BY role, id');
        view('admin_perm_pick', ['title' => '分组授权', 'rows' => $list]);
        return;
    }
    $allGroups = all('SELECT g.*, u.username AS owner_name
                      FROM ' . tbl('groups') . ' g
                      LEFT JOIN ' . tbl('users') . ' u ON u.id = g.owner_id
                      ORDER BY g.sort, g.id');
    // 私有空间不参与授权：只有归属人自己能进，管理员也不能把人塞进去
    $groups = array_values(array_filter($allGroups, fn($g) => !is_private_group($g)));
    $privateSpaces = array_values(array_filter($allGroups, fn($g) => is_private_group($g)));
    if (is_post()) {
        csrf_check();
        $read = (array)($_POST['read'] ?? []);
        $write = (array)($_POST['write'] ?? []);
        // 只重建「公共分组」的授权，私有空间原有的成员关系保持不动
        $privateIds = array_map(fn($g) => (int)$g['id'], $privateSpaces);
        if ($privateIds) {
            $in = implode(',', array_fill(0, count($privateIds), '?'));
            q('DELETE FROM ' . tbl('group_user') . ' WHERE user_id = ? AND group_id NOT IN (' . $in . ')',
                array_merge([$uid], $privateIds));
        } else {
            q('DELETE FROM ' . tbl('group_user') . ' WHERE user_id = ?', [$uid]);
        }
        foreach ($groups as $g) {
            $gid = (int)$g['id'];
            $r = in_array((string)$gid, array_map('strval', $read), true) ? 1 : 0;
            $w = in_array((string)$gid, array_map('strval', $write), true) ? 1 : 0;
            if ($r || $w) {
                insert_row('group_user', [
                    'group_id' => $gid, 'user_id' => $uid,
                    'can_read' => $r ? 1 : 0, 'can_write' => $w ? 1 : 0,
                    'created_at' => now_str(),
                ]);
            }
        }
        audit('set_group_perms', 'user=' . $uid);
        flash('权限已保存');
        redirect(u('admin_perm', ['uid' => $uid]));
    }
    $perms = user_perms($uid);
    view('admin_perm', [
        'title' => '分组授权 · ' . $user['username'], 'user' => $user,
        'groups' => $groups, 'perms' => $perms, 'privateSpaces' => $privateSpaces,
    ]);
}

function ctrl_admin_groups(): void
{
    $u = require_admin();
    if (is_post()) {
        csrf_check();
        $action = post('action');
        if ($action === 'create' || $action === 'update') {
            $name = nick_clean(post('name'));
            if ($name === '') {
                flash('分组名称不能为空', 'err');
            } else {
                $data = [
                    'name' => mb_substr($name, 0, 120, 'UTF-8'),
                    'description' => mb_substr(post('description'), 0, 480, 'UTF-8'),
                    'sort' => (int)post('sort', '0'),
                ];
                if ($action === 'create') {
                    $data['slug'] = make_slug($name);
                    $data['created_at'] = now_str();
                    $gid = insert_row('groups', $data);
                    // 2.3.0：后台建的共享分组（owner_id=0）没有归属人，前台又不再给管理员全通，
                    // 必须把创建者记成成员，否则他自己在用户页面里也看不到这个分组。
                    group_member_ensure($gid, (int)$u['id']);
                    perm_flush((int)$u['id']);
                    audit('create_group', $name);
                    flash('分组已创建，记得给用户授权');
                    redirect(u('admin_groups', ['scope' => post('scope') === 'users' ? 'users' : null]));
                } else {
                    $id = (int)post('id');
                    $slugIn = post('slug');
                    if ($slugIn !== '') {
                        $data['slug'] = make_slug($slugIn, $id);
                    }
                    // 私有空间的名称始终带归属人昵称，管理员改了也只改后半截
                    $g = group_by_id($id);
                    if ($g && (int)$g['owner_id'] > 0) {
                        $nick = group_owner_nick($g) ?: '用户' . (int)$g['owner_id'];
                        $data['name'] = nick_group_name($nick, group_base_of($name, $nick));
                    }
                    update_row('groups', $id, $data);
                    audit('update_group', $data['name']);
                    flash('分组已更新');
                }
            }
        }
        if ($action === 'delete') {
            $id = (int)post('id');
            $n = (int)val('SELECT COUNT(*) FROM ' . tbl('entries') . ' WHERE group_id = ?', [$id], 0);
            if ($n > 0) {
                flash('该分组下还有 ' . $n . ' 条内容，请先清空或转移', 'err');
            } else {
                q('DELETE FROM ' . tbl('group_user') . ' WHERE group_id = ?', [$id]);
                q('DELETE FROM ' . tbl('groups') . ' WHERE id = ?', [$id]);
                audit('delete_group', 'group=' . $id);
                flash('分组已删除');
            }
        }
        redirect(u('admin_groups', ['scope' => post('scope') === 'users' ? 'users' : null]));
    }
    $me = (int)$u['id'];
    $scope = get_q('scope') === 'users' ? 'users' : 'mine';
    $where = $scope === 'users'
        ? 'g.owner_id > 0 AND g.owner_id <> ?'
        : '(g.owner_id = 0 OR g.owner_id = ?)';
    $rows = all('SELECT g.*, u.username AS owner_name, u.display_name AS owner_nick,
                        (SELECT COUNT(*) FROM ' . tbl('entries') . ' e WHERE e.group_id = g.id AND e.status = 1) AS cnt
                 FROM ' . tbl('groups') . ' g
                 LEFT JOIN ' . tbl('users') . ' u ON u.id = g.owner_id
                 WHERE ' . $where . '
                 ORDER BY g.sort, g.id', [$me]);
    $cntMine = (int)val('SELECT COUNT(*) FROM ' . tbl('groups') . ' WHERE owner_id = 0 OR owner_id = ?', [$me], 0);
    $cntUsers = (int)val('SELECT COUNT(*) FROM ' . tbl('groups') . ' WHERE owner_id > 0 AND owner_id <> ?', [$me], 0);
    view('admin_groups', [
        'title' => '分组管理', 'rows' => $rows, 'scope' => $scope,
        'cntMine' => $cntMine, 'cntUsers' => $cntUsers,
    ]);
}

function ctrl_admin_keys(): void
{
    require_admin();
    if (is_post()) {
        csrf_check();
        $kid = (int)post('id');
        $row = one('SELECT * FROM ' . tbl('api_keys') . ' WHERE id = ?', [$kid]);
        if ($row) {
            update_row('api_keys', $kid, ['revoked' => (int)$row['revoked'] === 1 ? 0 : 1]);
            audit('admin_toggle_key', 'id=' . $kid);
            flash('状态已切换');
        }
        redirect(u('admin_keys'));
    }
    $rows = all('SELECT k.*, u.username, g.name AS group_name
                 FROM ' . tbl('api_keys') . ' k
                 LEFT JOIN ' . tbl('users') . ' u ON u.id = k.user_id
                 LEFT JOIN ' . tbl('groups') . ' g ON g.id = k.group_id
                 ORDER BY k.id DESC');
    view('admin_keys', ['title' => 'API Key 总览', 'rows' => $rows]);
}

/* ---------------- 内容管理（超管） ---------------- */

function ctrl_admin_content(): void
{
    $u = require_admin();
    $gid = (int)get_q('gid', '0');
    $groups = all('SELECT * FROM ' . tbl('groups') . ' ORDER BY sort, id');
    if (!$groups) {
        view('admin_content', ['title' => '内容管理', 'groups' => [], 'group' => null, 'rows' => [], 'pg' => paginate(0, 20, 1), 'kind' => '']);
        return;
    }
    if ($gid <= 0) {
        $gid = (int)$groups[0]['id'];
    }
    $group = group_by_id($gid);
    $kind = in_array(get_q('kind'), ['qa', 'doc'], true) ? get_q('kind') : '';
    $q = get_q('q');
    $where = 'group_id = ? AND status = 1';
    $p = [$gid];
    if ($kind !== '') {
        $where .= ' AND kind = ?';
        $p[] = $kind;
    }
    if ($q !== '') {
        $where .= ' AND (title LIKE ? OR question LIKE ?)';
        $p[] = '%' . $q . '%';
        $p[] = '%' . $q . '%';
    }
    $page = max(1, (int)get_q('p', '1'));
    $per = 20;
    $total = (int)val('SELECT COUNT(*) FROM ' . tbl('entries') . ' WHERE ' . $where, $p, 0);
    $pg = paginate($total, $per, $page);
    $rows = all('SELECT * FROM ' . tbl('entries') . ' WHERE ' . $where
        . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $per . ' OFFSET ' . $pg['offset'], $p);
    view('admin_content', [
        'title' => '内容管理', 'groups' => $groups, 'group' => $group,
        'rows' => $rows, 'pg' => $pg, 'kind' => $kind, 'q' => $q,
    ]);
}

/** 前台编辑条目：只按分组写权限，管理员在这里也只是普通用户 */
function ctrl_entry_edit(): void
{
    entry_edit_run(false);
}

/** 后台编辑条目（管理后台 → 内容）：管理员在这里是全通身份，返回也回后台 */
function ctrl_admin_entry_edit(): void
{
    require_admin();
    entry_edit_run(true);
}

/**
 * 条目编辑的共用实现。
 * $inAdmin=false 时按前台口径（can_write_group，管理员不额外放行）；
 * $inAdmin=true 时已在后台上下文里（admin_ctx 已打标），can_write_group 会自动全通。
 */
function entry_edit_run(bool $inAdmin): void
{
    $u = require_login();
    $back = $inAdmin ? 'admin_content' : '';
    $id = (int)get_q('id', '0');
    $gid = (int)get_q('gid', '0');
    $e = $id > 0 ? entry_by_id($id) : null;
    if ($e) {
        $gid = (int)$e['group_id'];
    }
    $group = group_by_id($gid);
    if (!$group) {
        flash('请先选择分组', 'err');
        redirect($inAdmin ? u('admin_content') : u());
    }
    if (!can_write_group((int)$u['id'], $gid)) {
        http_response_code(403);
        exit('403 没有该分组的写权限');
    }

    if (is_post()) {
        csrf_check();
        $action = post('action', 'save');
        if ($action === 'delete' && $e) {
            soft_delete_entry((int)$e['id']);
            audit('delete_entry', 'id=' . $e['id'] . ($inAdmin ? ' from=admin' : ''));
            flash('已删除');
            redirect($inAdmin ? u('admin_content', ['gid' => $gid]) : u('g', ['slug' => $group['slug']]));
        }
        $data = [
            'group_id' => $gid,
            'kind' => post('kind', 'qa'),
            'title' => post('title'),
            'question' => post('question'),
            'body' => (string)($_POST['body'] ?? ''),
            'source' => post('source'),
            'source_date' => post('source_date'),
        ];
        if ($data['title'] === '' && $data['question'] === '' && trim($data['body']) === '') {
            flash('标题、问题、内容至少填一项', 'err');
        } else {
            $newId = save_entry($data, $e ? (int)$e['id'] : null);
            if ($data['kind'] === 'doc') {
                $ids = array_filter(array_map('intval', (array)($_POST['links'] ?? [])));
                save_doc_links($newId, $ids);
            }
            audit($e ? 'update_entry' : 'create_entry', 'id=' . $newId . ($inAdmin ? ' from=admin' : ''));
            flash('已保存');
            redirect(u($inAdmin ? 'admin_entry_edit' : 'entry_edit', ['id' => $newId]));
        }
    }

    $links = $e && $e['kind'] === 'doc' ? array_map(fn($r) => (int)$r['id'], doc_entries((int)$e['id'])) : [];
    $qaList = $e && $e['kind'] === 'doc'
        ? all('SELECT id, question, title FROM ' . tbl('entries') . ' WHERE group_id = ? AND kind = ? AND status = 1 ORDER BY updated_at DESC LIMIT 500', [$gid, 'qa'])
        : [];
    view('admin_entry_edit', [
        'title' => $e ? '编辑条目' : '新建条目',
        'e' => $e, 'group' => $group, 'links' => $links, 'qaList' => $qaList,
        'inAdmin' => $inAdmin,
    ]);
}

/**
 * 后台预览条目（管理后台 → 内容 → 查看）。
 * 前台 entry/doc 现在按普通用户口径判权，管理员看别人的分组会 403；
 * 后台要核对内容就得走这里——地址带 admin_ 前缀，天然是后台上下文。
 */
function ctrl_admin_entry_view(): void
{
    require_admin();
    $u = current_user();
    $id = (int)get_q('id');
    $e = $id > 0 ? entry_by_id($id) : null;
    if (!$e || (int)$e['status'] !== 1) {
        http_response_code(404);
        exit('条目不存在');
    }
    $g = group_by_id((int)$e['group_id']);
    $backUrl = u('admin_content', ['gid' => (int)$e['group_id']]);
    if ($e['kind'] === 'doc') {
        view('doc', [
            'title' => $e['title'], 'e' => $e, 'group' => $g, 'items' => doc_entries($id),
            'canWrite' => true, 'inAdmin' => true, 'backUrl' => $backUrl,
        ]);
        return;
    }
    view('entry', [
        'title' => $e['title'] ?: mb_substr((string)$e['question'], 0, 40, 'UTF-8'),
        'e' => $e, 'group' => $g,
        'docs' => entry_docs($id),
        'versions' => entry_versions($id),
        'canWrite' => true, 'inAdmin' => true, 'backUrl' => $backUrl,
    ]);
}

/* ---------------- 系统与升级 ---------------- */

function ctrl_admin_system(): void
{
    require_admin();
    /**
     * 后台系统页不许缓存（2.6.4）。
     *
     * 这个页面上有版本号、站点信息的当前值、升级记录——全是「刚改完就要看到新值」
     * 的东西。以前没发任何 Cache-Control，一旦前面挂了 CDN / 反代 / 或者浏览器
     * 开了「返回时用缓存」，保存成功后重定向回来看到的还是旧值，
     * 症状和「保存没落盘」一模一样，白排查半天。表单页一律 no-store。
     */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $msg = '';
    $err = '';
    if (is_post()) {
        csrf_check();
        /* ---- 网站基础信息（2.4.0）：站点名、副标题、logo、页脚、备案号等 ---- */
        if (($_POST['action'] ?? '') === 'site_info') {
            $r = site_save($_POST, $_FILES);
            if ($r['ok']) {
                audit('update_site_info', 'name=' . app_name() . ' logo=' . (site_info('logo') !== '' ? 'yes' : 'no'));
                flash($r['message']);
                redirect(u('admin_system'));
            }
            $err = $r['error'];
        }
        if (($_POST['action'] ?? '') === 'settings') {
            setting_set('allow_register', post('allow_register') === '1' ? '1' : '0');
            setting_set('mcp_enabled', post('mcp_enabled') === '1' ? '1' : '0');
            $n = (int)post('register_per_hour', '5');
            setting_set('register_per_hour', (string)max(1, min(100, $n)));
            audit('update_settings', 'allow_register=' . setting_get('allow_register') . ' per_hour=' . setting_get('register_per_hour'));
            flash('设置已保存');
            redirect(u('admin_system'));
        }
        if (($_POST['action'] ?? '') === 'upgrade') {
            $f = $_FILES['package'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $err = '没有收到上传文件，或上传失败（检查 php.ini 的 upload_max_filesize）';
            } elseif (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'zip') {
                $err = '更新包必须是 .zip';
            } else {
                $r = upgrade_apply((string)$f['tmp_name']);
                if ($r['ok']) {
                    $msg = $r['message'];
                } else {
                    $err = $r['message'];
                }
            }
        }

        /* ---- GitHub 在线更新（检查 / 一键升级 / 更新源设置） ---- */
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'gh_settings') {
            $repo = trim((string)post('gh_repo', ''));
            $branch = trim((string)post('gh_branch', ''));
            $mirror = trim((string)post('gh_mirror', ''));
            $source = trim((string)post('gh_source', 'api'));
            if ($repo !== '' && !preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo)) {
                $err = '仓库格式应为 owner/repo';
            } elseif ($branch !== '' && !preg_match('#^[A-Za-z0-9._/-]+$#', $branch)) {
                $err = '分支名只能包含字母、数字、点、下划线、短横线和斜杠';
            } elseif (!in_array($source, ['api', 'raw', 'custom'], true)) {
                $err = '更新源通道不合法';
            } elseif ($source === 'custom' && $mirror === '') {
                $err = '自定义镜像通道必须填写镜像地址';
            } elseif ($mirror !== '' && !preg_match('#^https://[^\s]+$#i', $mirror)) {
                $err = '更新源镜像必须以 https:// 开头';
            } else {
                setting_set('gh_repo', $repo);
                setting_set('gh_branch', $branch);
                setting_set('gh_mirror', $mirror);
                setting_set('gh_source', $source);
                setting_set('gh_ssl_verify', post('gh_ssl_verify', '0') === '1' ? '1' : '0');
                audit('gh_settings', 'source=' . gh_source() . ' repo=' . gh_repo() . ' branch=' . gh_branch()
                    . ' mirror=' . (gh_mirror() !== '' ? 'yes' : 'no') . ' ssl=' . (gh_verify_ssl() ? '1' : '0'));
                flash('更新源设置已保存');
                redirect(u('admin_system'));
            }
        }
        if ($act === 'gh_check') {
            try {
                $_SESSION['gh_check'] = gh_check();
            } catch (Throwable $e) {
                $_SESSION['gh_check'] = [
                    'ok' => false, 'error' => $e->getMessage(),
                    'current' => gh_current_version(), 'checked_at' => date('Y-m-d H:i:s'),
                ];
            }
            unset($_SESSION['gh_result']);
            redirect(u('admin_system'));
        }
        if ($act === 'gh_update') {
            $_SESSION['gh_result'] = gh_update_run();
            unset($_SESSION['gh_check']);
            redirect(u('admin_system'));
        }
    }

    $ghCheck = $_SESSION['gh_check'] ?? null;
    $ghResult = $_SESSION['gh_result'] ?? null;
    unset($_SESSION['gh_check'], $_SESSION['gh_result']);
    $verJson = json_decode((string)@file_get_contents(BASE_PATH . '/version.json'), true) ?: [];
    view('admin_system', [
        'title' => '系统与升级',
        'ver' => $verJson,
        'msg' => $msg, 'err' => $err,
        'site' => site_info(),
        'ghCheck' => $ghCheck,
        'ghResult' => $ghResult,
        'ghRepo' => gh_repo(),
        'ghBranch' => gh_branch(),
        'ghMirror' => gh_mirror(),
        'ghSource' => gh_source(),
        'ghSslVerify' => gh_verify_ssl(),
        'ghManifestUrl' => gh_source() === 'api'
            ? 'https://api.github.com/repos/' . gh_repo() . '/contents/updates.json?ref=' . rawurlencode(gh_branch())
            : gh_base() . '/' . gh_repo() . '/' . gh_branch() . '/updates.json',
        'history' => all('SELECT * FROM ' . tbl('audit_log') . " WHERE action LIKE 'upgrade%' OR action LIKE 'gh_%' ORDER BY id DESC LIMIT 10"),
        'php' => PHP_VERSION,
        'driver' => db_driver(),
        'allowRegister' => register_allowed(),
        'mcpEnabled' => mcp_enabled(),
        'perHour' => register_limit(),
        'userCount' => (int)val('SELECT COUNT(*) FROM ' . tbl('users'), [], 0),
        'spaceCount' => (int)val('SELECT COUNT(*) FROM ' . tbl('groups') . ' WHERE owner_id > 0', [], 0),
    ]);
}

/* ---------------- 登录 / 注册外观（2.6.0） ---------------- */

function ctrl_admin_auth(): void
{
    require_admin();
    $msg = '';
    $err = '';

    if (is_post()) {
        csrf_check();
        $act = (string)($_POST['action'] ?? '');

        if ($act === 'auth_bg') {
            $r = auth_bg_save($_POST, $_FILES);
            if ($r['ok']) {
                audit('update_auth_bg',
                    'login=' . auth_bg_get('login')['type'] . '/' . mb_substr(auth_bg_get('login')['value'], 0, 40, 'UTF-8')
                    . ' register=' . auth_bg_get('register')['type']);
                flash($r['message']);
                redirect(u('admin_auth'));
            }
            $err = $r['error'];
        }
    }

    view('admin_auth', [
        'title'     => '登录外观',
        'msg'       => $msg,
        'err'       => $err,
        'presets'   => auth_bg_presets(),
        'slots'     => auth_bg_slots(),
        'cfgLogin'  => auth_bg_get('login'),
        'cfgReg'    => auth_bg_get('register'),
        'regOpen'   => register_allowed(),
    ]);
}

/* ---------------- 邮件配置 ---------------- */

function ctrl_admin_mail(): void
{
    require_admin();

    if (is_post()) {
        csrf_check();
        $action = post('action');

        if ($action === 'save') {
            $port = (int)post('mail_port', '465');
            $secure = in_array(post('mail_secure'), ['ssl', 'tls', 'none'], true) ? post('mail_secure') : 'ssl';
            $from = mail_email_norm(post('mail_from'));
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                flash('「发件人邮箱」格式不对', 'err');
                redirect(u('admin_mail'));
            }
            $method = post('mail_method');
            if (!in_array($method, ['resend', 'smtp', 'mail'], true)) {
                $method = 'resend';
            }
            setting_set('mail_method', $method);
            setting_set('mail_host', mb_substr(post('mail_host'), 0, 190));
            setting_set('mail_port', (string)($port > 0 ? $port : 465));
            setting_set('mail_secure', $secure);
            setting_set('mail_user', mb_substr(post('mail_user'), 0, 190));

            $pass = trim((string)($_POST['mail_pass'] ?? ''));
            if ($pass !== '') {
                setting_set('mail_pass', secret_encrypt($pass));
            } elseif (post('mail_pass_clear') === '1') {
                setting_set('mail_pass', '');
            }

            // Resend API Key：与 SMTP 密码分开存，切换通道时不会互相覆盖
            $apiKey = trim((string)($_POST['mail_api_key'] ?? ''));
            if ($apiKey !== '') {
                // 常见误操作：把整行 "Authorization: Bearer re_xxx" 或带引号的内容粘进来
                $apiKey = preg_replace('/^\s*Authorization\s*:\s*/i', '', $apiKey) ?? $apiKey;
                $apiKey = preg_replace('/^\s*Bearer\s+/i', '', $apiKey) ?? $apiKey;
                $apiKey = trim($apiKey, " \t\n\r\0\x0B\"'");
                setting_set('mail_api_key', secret_encrypt($apiKey));
            } elseif (post('mail_api_key_clear') === '1') {
                setting_set('mail_api_key', '');
            }

            setting_set('mail_from', $from);
            setting_set('mail_from_name', mb_substr(post('mail_from_name'), 0, 60));
            setting_set('mail_no_verify', post('mail_no_verify') === '1' ? '1' : '0');
            setting_set('mail_enabled', post('mail_enabled') === '1' ? '1' : '0');
            setting_set('mail_verify_register', post('mail_verify_register') === '1' ? '1' : '0');
            setting_set('mail_welcome', post('mail_welcome') === '1' ? '1' : '0');
            mail_cfg(true);
            audit('update_mail_settings', 'enabled=' . setting_get('mail_enabled')
                . ' method=' . setting_get('mail_method') . ' host=' . setting_get('mail_host'));
            flash('邮件设置已保存' . (setting_get('mail_enabled') === '1' && !mail_ready()
                ? '。注意：开关已打开，但发件人或' . ($method === 'resend' ? ' API Key ' : '服务器')
                    . '还没填全，现在还不能发信' : ''));
            redirect(u('admin_mail'));
        }

        if ($action === 'test') {
            $to = mail_email_norm(post('to'));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('收件邮箱格式不对', 'err');
                redirect(u('admin_mail'));
            }
            $html = mail_html(
                '测试邮件',
                '如果你看到这封邮件，说明「' . app_name() . '」的邮件通道是通的。',
                ['验证码、找回密码、通知都会走这条通道。收不到时先看垃圾箱，再看下面的发送记录。'],
                null,
                [['发送时间', now_str()], ['收件地址', $to]]
            );
            $r = mail_send($to, app_name() . ' 测试邮件', $html, '', 'test', (int)current_user()['id']);
            flash($r['ok']
                ? '测试邮件已交给服务器，请查收 ' . $to . '（也看一眼垃圾箱）'
                : '发送失败：' . $r['error'], $r['ok'] ? 'ok' : 'err');
            redirect(u('admin_mail'));
        }

        if ($action === 'notify') {
            $subject = post('subject');
            $body = (string)($_POST['body'] ?? '');
            if ($subject === '' || trim($body) === '') {
                flash('通知的主题和正文都要填', 'err');
                redirect(u('admin_mail'));
            }
            $users = mail_recipients(200);
            if (!$users) {
                flash('没有可发送的人：需要「有邮箱 + 邮箱已验证 + 没关掉通知」', 'err');
                redirect(u('admin_mail'));
            }
            @set_time_limit(240);
            $tpl = mail_notice_mail($subject, $body);
            $ok = 0;
            $fail = [];
            foreach ($users as $row) {
                $r = mail_send((string)$row['email'], $tpl['subject'], $tpl['html'], $tpl['text'],
                    'notice', (int)$row['id']);
                if ($r['ok']) {
                    $ok++;
                } else {
                    $fail[] = $row['email'] . '：' . $r['error'];
                }
            }
            audit('send_notice', 'ok=' . $ok . ' fail=' . count($fail) . ' subject=' . mb_substr($subject, 0, 60));
            if ($fail) {
                flash('已发送 ' . $ok . ' 封，失败 ' . count($fail) . ' 封。第一个失败原因：' . $fail[0], 'err');
            } else {
                flash('通知已发送给 ' . $ok . ' 位用户');
            }
            redirect(u('admin_mail'));
        }

        if ($action === 'log_clear') {
            q('DELETE FROM ' . tbl('mail_log'));
            flash('发送记录已清空');
            redirect(u('admin_mail'));
        }

        redirect(u('admin_mail'));
    }

    $c = mail_cfg();
    view('admin_mail', [
        'title'     => '邮件设置',
        'c'         => $c,
        'ready'     => mail_ready(),
        'hasPass'   => $c['pass'] !== '',
        'hasApiKey' => $c['api_key'] !== '',
        'lastError' => (string)setting_get('mail_last_error', ''),
        'lastErrAt' => (string)setting_get('mail_last_error_at', ''),
        'lastOkAt'  => (string)setting_get('mail_last_ok_at', ''),
        'recipients' => count(mail_recipients(1000)),
        'logs'      => all('SELECT * FROM ' . tbl('mail_log') . ' ORDER BY id DESC LIMIT 30'),
        'phpMail'   => function_exists('mail'),
    ]);
}

/* ---------------- 数据导入 ---------------- */

function ctrl_admin_import(): void
{
    require_admin();
    $msg = '';
    $err = '';
    $stats = null;
    $builtin = BASE_PATH . '/data/wayfair-qa.csv';

    if (is_post()) {
        csrf_check();
        $mode = post('mode', 'builtin');
        $groupName = post('group_name', '跨境电商');
        $username = post('username');
        $source = post('source', '微信群 · Wayfair破浪远洋3群');
        $csv = '';

        if ($mode === 'builtin') {
            if (!is_file($builtin)) {
                $err = '内置数据文件不存在（data/wayfair-qa.csv）';
            } else {
                $csv = $builtin;
            }
        } else {
            $f = $_FILES['csv'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $err = '没有收到文件，或上传失败（检查 upload_max_filesize）';
            } else {
                $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['csv', 'txt'], true)) {
                    $err = '只支持 .csv 文件';
                } else {
                    $tmp = BASE_PATH . '/storage/tmp/import-' . bin2hex(random_bytes(4)) . '.csv';
                    if (!move_uploaded_file((string)$f['tmp_name'], $tmp)) {
                        $err = '文件保存失败，检查 storage/tmp 权限';
                    } else {
                        $csv = $tmp;
                    }
                }
            }
        }

        if ($err === '' && $csv !== '') {
            try {
                [$group, $user] = ensure_group_and_perm($groupName !== '' ? $groupName : '跨境电商', $username);
                $stats = import_csv($csv, (int)$group['id'], (int)$user['id'], $source);
                audit('import_csv', "group={$group['id']} inserted={$stats['inserted']}");
                $msg = "导入完成：新增 {$stats['inserted']} 条，跳过 {$stats['skipped']} 条";
                if ($csv !== $builtin) {
                    @unlink($csv);
                }
            } catch (Throwable $e) {
                $err = '导入失败：' . $e->getMessage();
            }
        }
    }

    view('admin_import', [
        'title' => '导入数据',
        'msg' => $msg,
        'err' => $err,
        'stats' => $stats,
        'builtin' => is_file($builtin),
        'builtinSize' => is_file($builtin) ? round(filesize($builtin) / 1048576, 1) : 0,
        'users' => all('SELECT id, username, display_name FROM ' . tbl('users') . ' WHERE status = 1 ORDER BY role, id'),
        'groups' => all('SELECT id, name FROM ' . tbl('groups') . ' ORDER BY sort, id'),
    ]);
}

/* ---------------- 用户自建分组 ---------------- */

function ctrl_group_new(): void
{
    $u = require_login();
    if (is_post()) {
        csrf_check();
        $name = nick_clean(post('name'));
        $desc = post('description');
        if ($name === '') {
            flash('分组名称不能为空', 'err');
        } else {
            // 私有分组的完整名称 = 昵称 · 自定义名，管理员在后台一眼看出归属
            $full = nick_group_name(nick_of($u), $name);
            $gid = insert_row('groups', [
                'name' => $full,
                'slug' => make_slug($name),
                'description' => mb_substr($desc, 0, 480, 'UTF-8'),
                'owner_id' => (int)$u['id'],
                'sort' => 50,
                'created_at' => now_str(),
            ]);
            // 创建者自动获得读写权限；owner_id already 标记了归属，别人不会被授权进来
            insert_row('group_user', [
                'group_id' => $gid,
                'user_id' => (int)$u['id'],
                'can_read' => 1,
                'can_write' => 1,
                'created_at' => now_str(),
            ]);
            perm_flush((int)$u['id']);
            perm_flush((int)$u['id']);
            audit('create_group_by_user', 'id=' . $gid . ' ' . $name);
            flash('分组已创建。它是你的私有空间，只有你能访问');
            $g = group_by_id($gid);
            redirect(u('g', ['slug' => $g['slug']]));
        }
    }
    view('group_new', ['title' => '新建分组']);
}

/**
 * 侧栏「⋯ → 重命名」的 AJAX 端点（2.6.5）。
 *
 * 为什么单独开一个端点而不是复用 ?r=g 的表单提交：
 * 侧栏在每个页面都在，用户在任意页面（首页 / 搜索 / 条目页）都能就地把分组改名。
 * 走表单提交会整页跳转到分组页并丢当前上下文，体验上像「点了个链接」而不是「改了个名字」。
 *
 * 与页面表单共用 can_rename_group() + group_rename()，判定与落库逻辑只有一份。
 */
function ctrl_group_rename(): void
{
    if (!is_post()) {
        json_out(['ok' => false, 'error' => ['message' => '只接受 POST']], 405);
    }
    // AJAX 端点不能用 require_login()（会 302 出一段 HTML 给 fetch）
    $u = current_user();
    if (!$u) {
        json_out(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => '登录已过期，请重新登录']], 401);
    }
    csrf_check();

    $id = (int)post('id', '0');
    if ($id <= 0) {
        json_out(['ok' => false, 'error' => ['message' => '缺少分组 id']], 400);
    }
    $g = group_by_id($id);
    if (!$g) {
        json_out(['ok' => false, 'error' => ['code' => 'not_found', 'message' => '分组不存在']], 404);
    }
    $owner = (int)$g['owner_id'];
    if (!can_rename_group((int)$u['id'], $id)) {
        json_out([
            'ok' => false,
            'error' => [
                'code' => 'forbidden',
                'message' => $owner > 0
                    ? '只有这个分组的创建者本人可以改名'
                    : '只有管理员可以改共享分组的名称',
            ],
        ], 403);
    }

    $nameIn = nick_clean(post('name'));
    if ($nameIn === '') {
        json_out(['ok' => false, 'error' => ['message' => '分组名称不能为空']], 400);
    }
    $r = group_rename($id, $nameIn);
    if (!$r['ok']) {
        json_out(['ok' => false, 'error' => ['message' => $r['error']]], 400);
    }
    audit('rename_group', 'id=' . $id . ' ' . $r['name']);
    json_out(['ok' => true, 'data' => [
        'id'   => $id,
        'name' => $r['name'],
        // 私有分组带昵称前缀，前端把前缀单独显示，输入框里只放后半截
        'base' => $owner > 0 ? group_base_of($r['name'], group_owner_nick($g)) : $r['name'],
        'prefix' => $owner > 0 ? (group_owner_nick($g) . GROUP_SEP) : '',
    ]]);
}

/* ================= 文件存储（1.7.0） ================= */

/** 后台：存储平台管理 + 上传 + 附件列表 */
function ctrl_admin_files(): void
{
    $u = require_admin();
    $do = get_q('do', '');

    /* --- AJAX：测试连接 --- */
    if ($do === 'test') {
        $p = provider_by_id((int)get_q('id', '0'));
        if (!$p) {
            json_out(['ok' => false, 'error' => ['message' => '平台不存在']], 404);
        }
        try {
            $GLOBALS['__qiniu_host_ok'] = '';
            $msg = provider_driver($p)->probe();
            // 七牛：如果自动识别出了空间真实所在区域的上传域名，写回配置，
            // 这样后续真实上传不再撞「域名与区域不匹配」。
            $auto = trim((string)($GLOBALS['__qiniu_host_ok'] ?? ''));
            if ($auto !== '' && (string)$p['driver'] === 'qiniu') {
                $cfg = provider_config($p);
                if (trim((string)($cfg['auto_host'] ?? '')) !== $auto
                    || trim((string)($cfg['host'] ?? '')) !== $auto) {
                    $cfg['auto_host'] = $auto;
                    $cfg['host'] = $auto;   // 同步填进可见字段，用户编辑时能看到
                    update_row('storage_providers', (int)$p['id'], [
                        'config' => secret_encrypt(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    ]);
                    $msg .= '；已自动把「上传加速域名」改为 ' . $auto . '（空间所在区域）';
                }
            }
            update_row('storage_providers', (int)$p['id'], [
                'status' => 'ok', 'last_check_at' => now_str(), 'last_error' => null,
            ]);
            json_out(['ok' => true, 'data' => ['message' => $msg]]);
        } catch (Throwable $e) {
            update_row('storage_providers', (int)$p['id'], [
                'status' => 'fail', 'last_check_at' => now_str(),
                'last_error' => mb_substr($e->getMessage(), 0, 480),
            ]);
            json_out(['ok' => false, 'error' => ['message' => $e->getMessage()]], 200);
        }
    }

    /* --- AJAX：发起同步 --- */
    if ($do === 'sync_start' && is_post()) {
        csrf_check();
        try {
            $job = storage_sync_start((int)post('provider_id', '0'));
            audit('storage_sync_start', 'provider=' . (int)$job['provider_id'] . ' job=' . (int)$job['id']);
            json_out(['ok' => true, 'data' => ['job' => storage_job_public($job)]]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => ['message' => $e->getMessage()]]);
        }
    }

    /* --- AJAX：推进一批 / 查进度 --- */
    if ($do === 'sync_step' && is_post()) {
        csrf_check();
        try {
            $job = storage_sync_step((int)post('job_id', '0'), 10);
            json_out(['ok' => true, 'data' => ['job' => storage_job_public($job)]]);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => ['message' => $e->getMessage()]]);
        }
    }

    /* --- AJAX：只查进度 --- */
    if ($do === 'job') {
        $job = one('SELECT * FROM ' . tbl('storage_jobs') . ' WHERE id = ?', [(int)get_q('id', '0')]);
        if (!$job) {
            json_out(['ok' => false, 'error' => ['message' => '任务不存在']], 404);
        }
        json_out(['ok' => true, 'data' => ['job' => storage_job_public($job)]]);
    }

    /* --- 表单提交 --- */
    if (is_post()) {
        csrf_check();
        $action = post('action');

        if ($action === 'provider_save') {
            $id = (int)post('provider_id', '0');
            $driver = post('driver');
            if (!isset(storage_driver_labels()[$driver])) {
                flash('不支持的平台类型', 'err');
                redirect(u('admin_files'));
            }
            $name = trim(post('name')) ?: storage_driver_labels()[$driver];
            $old = $id > 0 ? provider_config(provider_by_id($id) ?: []) : [];
            // 表单按驱动分组提交 cfg[driver][field]；兼容升级瞬间浏览器缓存的旧表单
            // （旧表单是扁平 cfg[field]，只在该驱动分组完全没有时才回退读取）。
            $postCfg = $_POST['cfg'] ?? [];
            $group = (isset($postCfg[$driver]) && is_array($postCfg[$driver])) ? $postCfg[$driver] : [];
            $cfg = [];
            foreach (storage_driver_fields($driver) as $k => $meta) {
                $raw = array_key_exists($k, $group) ? $group[$k] : ($postCfg[$k] ?? '');
                $v = trim((string)(is_scalar($raw) ? $raw : ''));
                // 浏览器密码管理器会把掩码串自动填进密钥框，那不是密钥，
                // 当作「未修改」，别把真 SecretKey 覆盖成一串星号。
                if ($v !== '' && preg_match('/^[*•·●]+$/u', $v)) {
                    $v = '';
                }
                if ($v === '' && !empty($meta[3]) && isset($old[$k]) && $old[$k] !== '') {
                    $cfg[$k] = $old[$k]; // 密钥留空 = 不修改
                } else {
                    $cfg[$k] = $v;
                }
            }
            $data = [
                'name'   => mb_substr($name, 0, 60),
                'driver' => $driver,
                'config' => secret_encrypt(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ];
            // 七牛：auto_host 是「测试连接」自动识别出来的区域域名，不是表单字段。
            // 表单保存会按白名单重建 cfg，这里把它捞回来，否则用户点一次保存就丢了。
            if (isset($old['auto_host']) && trim((string)$old['auto_host']) !== ''
                && trim((string)($cfg['auto_host'] ?? '')) === '') {
                $cfg['auto_host'] = (string)$old['auto_host'];
                $data['config'] = secret_encrypt(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if ($id > 0) {
                update_row('storage_providers', $id, $data);
                $pid = $id;
            } else {
                $data['enabled'] = 0;
                $data['is_primary'] = 0;
                $data['sort'] = 100;
                $data['status'] = 'new';
                $data['created_at'] = now_str();
                $pid = insert_row('storage_providers', $data);
            }
            audit('storage_provider_save', 'id=' . $pid . ' driver=' . $driver);
            flash('平台已保存。记得点「测试连接」确认能通，再决定是否启用。');
            redirect(u('admin_files'));
        }

        if ($action === 'provider_toggle') {
            $pid = (int)post('provider_id', '0');
            $p = provider_by_id($pid);
            if ($p) {
                $turnOn = (int)$p['enabled'] !== 1;
                if (!$turnOn) {
                    // 停用前检查：不能把最后一个启用的平台停掉
                    $others = (int)val('SELECT COUNT(*) FROM ' . tbl('storage_providers') . ' WHERE enabled = 1 AND id <> ?', [$pid], 0);
                    if ($others === 0) {
                        flash('至少要留一个启用的平台，否则文件无处可存', 'err');
                        redirect(u('admin_files'));
                    }
                    update_row('storage_providers', $pid, ['enabled' => 0, 'is_primary' => 0]);
                    flash('已停用：之后的新文件不再写到这个平台，历史副本保留');
                } else {
                    update_row('storage_providers', $pid, ['enabled' => 1]);
                    flash('已启用。历史文件需要手动点「同步数据」补齐到这台');
                }
                audit('storage_provider_toggle', 'id=' . $pid . ' enabled=' . ($turnOn ? 1 : 0));
            }
            redirect(u('admin_files'));
        }

        if ($action === 'provider_primary') {
            $pid = (int)post('provider_id', '0');
            $p = provider_by_id($pid);
            if ($p) {
                q('UPDATE ' . tbl('storage_providers') . ' SET is_primary = 0');
                update_row('storage_providers', $pid, ['is_primary' => 1, 'enabled' => 1]);
                flash('已设为默认平台：新上传先写这里');
                audit('storage_provider_primary', 'id=' . $pid);
            }
            redirect(u('admin_files'));
        }

        if ($action === 'provider_delete') {
            $pid = (int)post('provider_id', '0');
            $p = provider_by_id($pid);
            if ($p) {
                if ((int)$p['is_primary'] === 1) {
                    flash('默认平台不能删，先把别的平台设为默认', 'err');
                    redirect(u('admin_files'));
                }
                $copies = storage_copy_count($pid);
                $others = (int)val(
                    'SELECT COUNT(*) FROM ' . tbl('storage_providers') . ' WHERE enabled = 1 AND id <> ?',
                    [$pid],
                    0
                );
                if ($copies > 0 && $others === 0) {
                    flash('这是唯一有副本的平台，删了文件就没了。先启用另一个平台并同步数据', 'err');
                    redirect(u('admin_files'));
                }
                q('DELETE FROM ' . tbl('attachment_replicas') . ' WHERE provider_id = ?', [$pid]);
                q('DELETE FROM ' . tbl('storage_jobs') . ' WHERE provider_id = ?', [$pid]);
                q('DELETE FROM ' . tbl('storage_providers') . ' WHERE id = ?', [$pid]);
                audit('storage_provider_delete', 'id=' . $pid . ' ' . $p['name']);
                flash('平台已删除。注意：那边的文件本体没有删，需要的话去云控制台清理');
            }
            redirect(u('admin_files'));
        }

        if ($action === 'upload') {
            $gid = (int)post('group_id', '0');
            $entryId = (int)post('entry_id', '0') ?: null;
            if ($gid <= 0 || !can_write_group((int)$u['id'], $gid)) {
                flash('没有这个分组的写权限', 'err');
                redirect(u('admin_files'));
            }
            $files = $_FILES['files'] ?? null;
            if (!$files || !is_array($files['name'] ?? null)) {
                flash('没有选中文件', 'err');
                redirect(u('admin_files'));
            }
            $okN = 0;
            $errs = [];
            $warns = [];
            $n = count($files['name']);
            for ($i = 0; $i < $n; $i++) {
                if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $one = [
                    'name'     => (string)$files['name'][$i],
                    'type'     => (string)($files['type'][$i] ?? ''),
                    'tmp_name' => (string)$files['tmp_name'][$i],
                    'error'    => (int)($files['error'][$i] ?? 0),
                    'size'     => (int)($files['size'][$i] ?? 0),
                ];
                try {
                    $r = file_store($gid, $entryId, $one, (int)$u['id']);
                    $okN++;
                    foreach ($r['warnings'] as $w) {
                        $warns[] = $one['name'] . ' → ' . $w;
                    }
                } catch (Throwable $e) {
                    $errs[] = $one['name'] . '：' . $e->getMessage();
                }
            }
            audit('file_upload', 'group=' . $gid . ' ok=' . $okN . ' fail=' . count($errs));
            if ($okN > 0) {
                flash('已上传 ' . $okN . ' 个文件' . ($warns ? '（部分平台没写成功，见下方提示）' : ''));
            }
            if ($errs) {
                flash('失败 ' . count($errs) . ' 个：' . implode('；', array_slice($errs, 0, 3)), 'err');
            }
            if ($warns) {
                flash('副本告警：' . implode('；', array_slice($warns, 0, 3)), 'err');
            }
            redirect(u('admin_files', $gid > 0 ? ['gid' => $gid] : []));
        }

        if ($action === 'file_delete') {
            $att = attachment_by_id((int)post('id', '0'));
            if ($att && can_write_group((int)$u['id'], (int)$att['group_id'])) {
                attachment_delete((int)$att['id']);
                audit('file_delete', 'id=' . (int)$att['id'] . ' ' . $att['name']);
                flash('文件已删除');
            } else {
                flash('没有权限删除这个文件', 'err');
            }
            redirect(u('admin_files', ['gid' => (int)($att['group_id'] ?? 0)]));
        }

        redirect(u('admin_files'));
    }

    $gid = (int)get_q('gid', '0');
    $groups = all('SELECT * FROM ' . tbl('groups') . ' ORDER BY sort, id');
    $providers = storage_providers();
    $files = [];
    if ($gid > 0) {
        $files = attachments_of_group($gid, 200);
    } else {
        $files = attachments_recent(200);
    }
    $jobs = all('SELECT j.*, p.name AS provider_name FROM ' . tbl('storage_jobs') . ' j LEFT JOIN ' . tbl('storage_providers') . ' p ON p.id = j.provider_id ORDER BY j.id DESC LIMIT 8');

    $rows = [];
    foreach ($providers as $p) {
        $rows[] = [
            'p' => $p,
            'copies' => storage_copy_count((int)$p['id']),
            'missing' => storage_missing_count((int)$p['id']),
            'job' => one('SELECT * FROM ' . tbl('storage_jobs') . ' WHERE provider_id = ? ORDER BY id DESC LIMIT 1', [(int)$p['id']]),
        ];
    }

    $editP = null;
    $editCfg = [];
    $editId = (int)get_q('edit', '0');
    if ($editId > 0) {
        $editP = provider_by_id($editId);
        if ($editP) {
            $editCfg = provider_config($editP);
        }
    }

    view('admin_files', [
        'title' => '文件存储',
        'gid' => $gid,
        'groups' => $groups,
        'rows' => $rows,
        'files' => $files,
        'jobs' => $jobs,
        'stats' => storage_stats(),
        'maxMb' => file_max_mb(),
        'exts' => implode(', ', file_allowed_ext()),
        'allGroups' => $groups,
        'edit' => $editP,
        'editCfg' => $editCfg,
    ]);
}

/** 同步任务对外字段 */
function storage_job_public(array $job): array
{
    $total = (int)$job['total'];
    $done = (int)$job['done'];
    $failed = (int)$job['failed'];
    $pct = $total > 0 ? min(100, (int)round(($done + $failed) * 100 / $total)) : 100;
    return [
        'id' => (int)$job['id'],
        'state' => (string)$job['state'],
        'total' => $total,
        'done' => $done,
        'failed' => $failed,
        'percent' => $pct,
        'cloud_copies' => (int)($job['cloud_copies'] ?? 0),
        'relayed' => (int)($job['relayed'] ?? 0),
        'message' => (string)($job['message'] ?? ''),
    ];
}

/** 附件下载（走鉴权，不暴露对象存储地址）。前台口径：按分组读权限 */
function ctrl_file(): void
{
    file_send();
}

/** 站点 logo / favicon 输出（2.4.0）。免登录：登录页、注册页都要显示 */
function ctrl_branding(): void
{
    site_brand_send(get_q('f'));
}

/**
 * 对外落地页（2.5.0）。免登录，不查任何用户数据。
 *
 * 刻意不统计业务数字：groups 表没有「公开」标记，匿名访客不该知道
 * 你库里有多少条问答——那本身就是信息泄露。登录后回工作台才看得到真实规模。
 * 登录用户访问这里也给看（他可能就是点页脚链接过来的），按钮换成「进入工作台」。
 */
function ctrl_landing(): void
{
    view('landing', [
        'title' => (string)site_info('subtitle') !== '' ? (string)site_info('subtitle') : '开源知识库系统',
        'repo'  => KB_REPO_URL,
        'aiOn'  => ai_on(),
        'reg'   => register_allowed(),
        'qa'    => 0,
        'doc'   => 0,
        'caps'  => landing_caps(),
    ], false);
}

/**
 * 落地页的能力清单。文案跟代码保持同步——
 * 加了大功能记得回来改这里，不然首页会写着一件其实没有的事。
 */
function landing_caps(): array
{
    $caps = [
        [
            'ico'   => '🗂',
            'title' => '分组 + 权限，边界是硬的',
            'text'  => '知识按分组隔离，谁能读谁能写逐人配置。API Key 绑定到分组，连管理员的 Key 也跨不了组。',
            'tags'  => ['读 / 写分权', 'Key 绑组'],
        ],
        [
            'ico'   => '🔍',
            'title' => '中文检索不用外挂',
            'text'  => '入库时自动切二字组建索引，查询按覆盖率打分。没有 Elasticsearch，没有分词服务，SQLite 就够。',
            'tags'  => ['二字组索引', '零依赖'],
        ],
        [
            'ico'   => '🔁',
            'title' => '写入幂等，改过留版本',
            'text'  => '按问题指纹去重：同样的问答重复推不会变两份，答案改了才留一条历史。Agent 可以放心重跑。',
            'tags'  => ['指纹去重', '版本历史'],
        ],
        [
            'ico'   => '🔌',
            'title' => 'REST / MCP / OpenAPI 三套口',
            'text'  => '同一套权限，三种用法。本地 Agent 走 REST，Claude / ChatGPT 这类客户端走 MCP，要文档就给 OpenAPI。',
            'tags'  => ['REST', 'MCP', 'OpenAPI 3.0.3'],
        ],
        [
            'ico'   => '📎',
            'title' => '附件跟随正文走',
            'text'  => '图片和文件直传对象存储，正文里引用。驱动六套可选（本地 / S3 / OSS / COS / 七牛 / 又拍云），换存储不用改内容。',
            'tags'  => ['六种驱动', '签名直传'],
        ],
        [
            'ico'   => '🤖',
            'title' => 'AI 是可选件，不是必需品',
            'text'  => '要就接上模型做问答和工作台，不要就纯当知识库用。计费先扣后调、失败全额退，退出也不锁你的数据。',
            'tags'  => ['可关', '失败退款'],
        ],
    ];
    return $caps;
}

/** 后台下载 / 预览附件（管理后台 → 文件存储）：require_admin 已打后台标记，不在前台口径里 */
function ctrl_admin_file(): void
{
    require_admin();
    file_send();
}

function file_send(): void
{
    $id = (int)get_q('id', '0');
    $att = $id > 0 ? attachment_by_id($id) : null;
    $u = current_user();
    if (!$att || !can_read_attachment($u, $att)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('404 文件不存在');
    }

    $got = attachment_fetch($att);
    if (!$got) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        exit('文件暂时取不到：所有存储平台都失败，请到后台「文件存储」检查连接');
    }

    $ext = strtolower((string)$att['ext']);
    $mime = (string)($att['mime'] ?: file_mime_by_ext($ext));
    $inline = in_array($ext, file_inline_ext(), true) && get_q('dl', '') !== '1';
    $name = (string)($att['name'] ?: ('file.' . $ext));

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($got['path']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    header(
        'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . preg_replace('/["\\\\\r\n]/', '', $name) . '"'
        . "; filename*=UTF-8''" . rawurlencode($name)
    );
    readfile($got['path']);
    @unlink($got['path']);
    exit;
}

/* ==================== AI 助手（1.8.0） ==================== */

/** AI 错误 → HTTP 状态码 */
function ai_http_status(string $kind): int
{
    return match ($kind) {
        'insufficient' => 402,
        'forbidden' => 403,
        'auth' => 502,
        'rate' => 429,
        'timeout' => 504,
        'disabled' => 503,
        default => 400,
    };
}

/** 我能写哪些分组（AI 结果入库用）。前台口径：按分组写权限，管理员不额外放行 */
function ai_writable_groups(array $u): array
{
    $out = [];
    foreach (visible_groups((int)$u['id']) as $g) {
        if (can_write_group((int)$u['id'], (int)$g['id'])) {
            $out[] = $g;
        }
    }
    return $out;
}

function ctrl_ai(): void
{
    $u = require_login();
    $uid = (int)$u['id'];
    if (!ai_on()) {
        flash('AI 功能还没开启，请联系管理员', 'err');
        redirect(u());
    }

    $do = get_q('do', '');
    if ($do !== '' && is_post()) {
        csrf_check();
        $mid = (int)post('model_id', '0');
        if ($mid <= 0) {
            $mid = ai_default_model_id($uid);
        }
        try {
            if ($do === 'preflight') {
                // 只判断、不调用、不扣分：前端进页面 / 切换模型时据此动态显示引导卡片
                $mid = (int)post('model_id', '0');
                if ($mid <= 0) {
                    $mid = ai_default_model_id($uid);
                }
                json_out(['ok' => true, 'data' => ['guide' => ai_preflight($uid, $u, $mid)]]);
            }
            if ($do === 'chat') {
                $hist = json_decode((string)($_POST['history'] ?? '[]'), true);
                $r = ai_chat_flow($uid, $mid, post('text'), is_array($hist) ? $hist : []);
                json_out(['ok' => true, 'data' => [
                    'text' => $r['text'], 'credits' => $r['credits'], 'balance' => $r['balance'],
                    'latency_ms' => $r['latency_ms'], 'usage' => $r['usage'],
                ]]);
            }
            if ($do === 'polish') {
                $eid = (int)post('entry_id', '0');
                $r = ai_polish_answer($uid, $mid, ['entry_id' => $eid, 'text' => (string)($_POST['text'] ?? '')]);
                json_out(['ok' => true, 'data' => [
                    'text' => $r['text'], 'credits' => $r['credits'], 'balance' => $r['balance'],
                    'latency_ms' => $r['latency_ms'], 'usage' => $r['usage'], 'entry_id' => $eid,
                ]]);
            }
            if ($do === 'extract') {
                $r = ai_extract_qa($uid, $mid, [
                    'text' => (string)($_POST['text'] ?? ''),
                    'max' => (int)post('max', '8'),
                ]);
                json_out(['ok' => true, 'data' => [
                    'items' => $r['items'], 'text' => $r['text'], 'parse_ok' => $r['parse_ok'],
                    'credits' => $r['credits'], 'balance' => $r['balance'],
                    'latency_ms' => $r['latency_ms'], 'usage' => $r['usage'],
                ]]);
            }
            if ($do === 'write_entry') {
                $eid = (int)post('entry_id', '0');
                $e = entry_by_id($eid);
                if (!$e || (int)$e['status'] !== 1) {
                    throw new RuntimeException('条目不存在或已删除');
                }
                if (!can_write_group($uid, (int)$e['group_id'])) {
                    throw new AiError('forbidden', '你没有这个分组的写权限');
                }
                $body = (string)($_POST['body'] ?? '');
                if (trim($body) === '') {
                    throw new RuntimeException('内容为空，没东西可写');
                }
                snapshot_version($e, $uid);
                save_entry([
                    'group_id' => (int)$e['group_id'], 'kind' => (string)$e['kind'],
                    'title' => (string)$e['title'], 'question' => (string)$e['question'],
                    'body' => $body, 'source' => (string)$e['source'], 'source_date' => (string)$e['source_date'],
                ], $eid);
                audit('ai_write_entry', 'id=' . $eid);
                json_out(['ok' => true, 'data' => [
                    'id' => $eid,
                    'version' => (int)val('SELECT version FROM ' . tbl('entries') . ' WHERE id = ?', [$eid], 0),
                ]]);
            }
            if ($do === 'write_qa') {
                $gid = (int)post('group_id', '0');
                if (!group_by_id($gid)) {
                    throw new RuntimeException('请先选择分组');
                }
                if (!can_write_group($uid, $gid)) {
                    throw new AiError('forbidden', '你没有这个分组的写权限');
                }
                $q = trim(post('question'));
                $a = (string)($_POST['answer'] ?? '');
                if ($q === '' || trim($a) === '') {
                    throw new RuntimeException('问题和答案都不能为空');
                }
                $id = save_entry([
                    'group_id' => $gid, 'kind' => 'qa', 'question' => $q, 'body' => $a,
                    'source' => 'AI 整理', 'created_by' => $uid,
                ], null);
                audit('ai_write_qa', 'id=' . $id);
                json_out(['ok' => true, 'data' => ['id' => $id]]);
            }
            throw new RuntimeException('未知操作：' . $do);
        } catch (AiError $e) {
            $err = ['code' => $e->kind, 'message' => $e->getMessage()];
            if ($e->guide !== []) {
                $err['guide'] = $e->guide;
            }
            json_out(['ok' => false, 'error' => $err], ai_http_status($e->kind));
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => ['code' => 'error', 'message' => $e->getMessage()]], 400);
        }
    }

    $defaultId = ai_default_model_id($uid);
    view('ai', [
        'title'     => 'AI 助手',
        'models'    => ai_models_for($uid),
        'ent'       => ai_entitlement($uid),
        'groups'    => ai_writable_groups($u),
        'defaultId' => $defaultId,
        // 进页面就先判断一次：积分不足 / 自定义模型没配好，会话窗口直接摆出引导卡片
        'initialGuide' => ai_preflight($uid, $u, $defaultId),
        'calls'     => all('SELECT * FROM ' . tbl('ai_calls') . ' WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$uid]),
    ]);
}

/* ================= AI 工作台（2.0.0）：能动手的对话 ================= */

/**
 * ?r=agent —— 对话窗口 + 工具执行。
 *
 * 一步一个请求：send / step 每次只让模型做一件事（调一个工具或收尾），
 * 前端拿到 running 就继续 step，所以「抓取→建组→写入」的过程是能看见的，
 * 也不会因为一次请求里串好几次大模型调用而撞 PHP 超时。
 */
function ctrl_agent(): void
{
    $do = get_q('do', '');
    $u = current_user();
    if (!$u) {
        if ($do !== '') {
            json_out(['ok' => false, 'error' => ['code' => 'forbidden', 'message' => '登录已过期，请重新登录']], 401);
        }
        redirect(u('login'));
    }
    $uid = (int)$u['id'];
    if (!ai_on()) {
        if ($do !== '') {
            json_out(['ok' => false, 'error' => ['code' => 'disabled', 'message' => 'AI 功能还没开启']], 403);
        }
        flash('AI 功能还没开启，请联系管理员', 'err');
        redirect(u());
    }

    if ($do !== '' && is_post()) {
        csrf_check();
        try {
            if ($do === 'preflight') {
                // 只判断、不调用、不扣分：前端进页面 / 切换模型时据此动态显示引导卡片
                $mid = (int)post('model_id', '0');
                if ($mid <= 0) {
                    $mid = ai_default_model_id($uid);
                }
                json_out(['ok' => true, 'data' => ['guide' => ai_preflight($uid, $u, $mid)]]);
            }
            if ($do === 'new') {
                $mid = (int)post('model_id', '0');
                if ($mid <= 0) {
                    $mid = ai_default_model_id($uid);
                }
                // 连点「新对话」会在侧栏堆一排没说过话的空会话，先清掉本人零步且无消息的旧空会话。
                // 只清自己的，且只在新建时清；已经聊过的会话一条都不动。
                $stale = all(
                    'SELECT r.id FROM ' . tbl('ai_agent_runs') . ' r '
                    . 'WHERE r.user_id = ? AND r.steps = 0 '
                    . 'AND NOT EXISTS (SELECT 1 FROM ' . tbl('ai_agent_msgs') . ' m WHERE m.run_id = r.id)',
                    [$uid]
                );
                foreach ($stale as $s) {
                    agent_run_delete((int)$s['id'], $uid);
                }
                $rid = agent_run_create($uid, $mid, post('title', ''));
                json_out(['ok' => true, 'data' => ['run_id' => $rid, 'title' => '新对话']]);
            }

            if ($do === 'rename') {
                $rid = (int)post('run_id', '0');
                $title = trim((string)post('title', ''));
                if (!agent_run_get($rid, $uid)) {
                    throw new RuntimeException('会话不存在');
                }
                if ($title === '' || mb_strlen($title, 'UTF-8') > 60) {
                    throw new RuntimeException('名称要 1～60 个字');
                }
                // patch 会同时刷新 updated_at，改名后排到列表最前，符合直觉
                agent_run_patch($rid, ['title' => $title]);
                json_out(['ok' => true, 'data' => ['run_id' => $rid, 'title' => $title]]);
            }

            if ($do === 'send' || $do === 'step') {
                $rid = (int)post('run_id', '0');
                $mid = (int)post('model_id', '0');
                $run = agent_run_get($rid, $uid);
                if (!$run) {
                    throw new RuntimeException('会话不存在，请新建一个对话');
                }
                if ($do === 'send') {
                    agent_user_message($rid, (string)($_POST['text'] ?? ''));
                    agent_run_patch($rid, ['status' => 'active']);
                }
                $r = agent_step($uid, $u, $rid, $mid);
                $run = agent_run_get($rid, $uid);
                json_out(['ok' => true, 'data' => [
                    'run_id'  => $rid,
                    'status'  => $r['status'],
                    'msgs'    => $r['msgs'],
                    // 门禁拦截 / 接口不通时把引导卡片透传给前端，由会话窗口顶部统一显示
                    'guide'   => $r['guide'] ?? null,
                    'step'    => $r['step'],
                    'credits' => $r['credits'],
                    'balance' => $r['balance'],
                    'title'   => $run ? (string)$run['title'] : '',
                ]]);
            }

            if ($do === 'del') {
                $rid = (int)post('run_id', '0');
                if (!agent_run_delete($rid, $uid)) {
                    throw new RuntimeException('会话不存在');
                }
                json_out(['ok' => true, 'data' => ['run_id' => 0]]);
            }

            if ($do === 'undo') {
                $mid = (int)post('msg_id', '0');
                $m = $mid > 0 ? one('SELECT * FROM ' . tbl('ai_agent_msgs') . ' WHERE id = ?', [$mid]) : null;
                if (!$m || !agent_run_get((int)$m['run_id'], $uid)) {
                    throw new RuntimeException('这条记录不存在');
                }
                $res = json_decode((string)$m['tool_result'], true);
                if (!is_array($res) || empty($res['undo'])) {
                    throw new RuntimeException('这条没有可撤销的动作');
                }
                if ((int)$m['ok'] !== 1) {
                    throw new RuntimeException('失败的写入不用撤销');
                }
                $out = agent_undo($u, (array)$res['undo']);
                update_row('ai_agent_msgs', $mid, ['status' => 'undone']);
                json_out(['ok' => true, 'data' => $out]);
            }

            throw new RuntimeException('未知操作：' . $do);
        } catch (AiError $e) {
            $err = ['code' => $e->kind, 'message' => $e->getMessage()];
            if ($e->guide !== []) {
                $err['guide'] = $e->guide;
            }
            json_out(['ok' => false, 'error' => $err], ai_http_status($e->kind));
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => ['code' => 'error', 'message' => $e->getMessage()]], 400);
        }
    }

    $runs = agent_runs_list($uid, 40);
    $curId = (int)get_q('id', '0');
    $cur = null;
    if ($curId > 0) {
        $cur = agent_run_get($curId, $uid);
    }
    if (!$cur && $runs) {
        $cur = $runs[0];
    }
    $msgs = $cur ? array_map('agent_msg_public', agent_msgs((int)$cur['id'])) : [];

    // 告诉界面「它到底能看到多少东西」，免得用户以为只检索自己建的分组
    $visGroups = visible_groups($uid);
    $writable = 0;
    foreach ($visGroups as $g) {
        if (can_write_group($uid, (int)$g['id'])) {
            $writable++;
        }
    }

    $avail = ai_models_for($uid);
    $availIds = array_map(static fn($m) => (int)$m['id'], $avail);
    // 会话记住的模型可能已被删除 / 停用，这时回退到默认模型，
    // 否则下拉没有任何选中项、首屏门禁也会按一个不存在的模型判断。
    $defaultId = ($cur && in_array((int)$cur['model_id'], $availIds, true))
        ? (int)$cur['model_id']
        : ai_default_model_id($uid);
    view('agent', [
        'title'  => 'AI 工作台',
        'runs'   => $runs,
        'run'    => $cur,
        'msgs'   => $msgs,
        'models' => $avail,
        'ent'    => ai_entitlement($uid),
        'defaultId' => $defaultId,
        // 进页面就先判断一次：积分不足 / 自定义模型没配好，会话窗口直接摆出引导卡片
        'initialGuide' => ai_preflight($uid, $u, $defaultId),
        'maxSteps'  => AGENT_MAX_STEPS,
        'scope'     => ['read' => count($visGroups), 'write' => $writable],
    ]);
}

/** 我的模型（自带接口，月付套餐功能） */
function ctrl_ai_models(): void
{
    $u = require_login();
    $uid = (int)$u['id'];
    $ent = ai_entitlement($uid);

    if (is_post()) {
        csrf_check();
        $action = post('action');
        $id = (int)post('id', '0');

        try {
            if ($action === 'delete') {
                $m = ai_model_get($id);
                if (!$m || (int)$m['owner_id'] !== $uid) {
                    throw new RuntimeException('模型不存在');
                }
                q('DELETE FROM ' . tbl('ai_models') . ' WHERE id = ? AND owner_id = ?', [$id, $uid]);
                audit('ai_model_delete', 'id=' . $id);
                flash('已删除');
                redirect(u('ai_models'));
            }

            if ($action === 'test') {
                $m = ai_model_get($id);
                if (!$m || (int)$m['owner_id'] !== $uid) {
                    throw new RuntimeException('模型不存在');
                }
                flash(ai_probe($m));
                redirect(u('ai_models'));
            }

            if ($action === 'save') {
                if (!$ent['active']) {
                    throw new RuntimeException('自带模型是月付套餐功能，请先到「充值 / 兑换」开通');
                }
                $old = $id > 0 ? ai_model_get($id) : null;
                if ($id > 0 && (!$old || (int)$old['owner_id'] !== $uid)) {
                    throw new RuntimeException('模型不存在');
                }
                if ($id === 0 && (int)$ent['custom_slots'] > 0 && (int)$ent['custom_used'] >= (int)$ent['custom_slots']) {
                    throw new RuntimeException('套餐允许的模型数已用完（' . $ent['custom_slots'] . ' 个），删掉一个再加');
                }
                $name = trim(post('name'));
                $base = trim(post('base_url'));
                $modelKey = trim(post('model_key'));
                if ($base === '' || $modelKey === '') {
                    throw new RuntimeException('接口地址和模型名必填');
                }
                $keyPlain = trim((string)($_POST['api_key'] ?? ''));
                $data = [
                    'owner_id'    => $uid,
                    'name'        => mb_substr($name !== '' ? $name : $modelKey, 0, 60),
                    'protocol'    => ai_protocol(post('protocol', 'openai')),
                    'base_url'    => mb_substr($base, 0, 240),
                    'model_key'   => mb_substr($modelKey, 0, 110),
                    'credits'     => 0, // 自有模型不扣积分
                    'max_tokens'  => max(64, min(32000, (int)post('max_tokens', '2048'))),
                    'temperature' => (string)max(0, min(2, (float)post('temperature', '0.3'))),
                    'timeout'     => max(10, min(300, (int)post('timeout', '120'))),
                    'note'        => mb_substr(trim(post('note')), 0, 200),
                    'enabled'     => 1,
                    'updated_at'  => now_str(),
                ];
                if ($keyPlain !== '') {
                    $data['api_key'] = secret_encrypt($keyPlain);
                } elseif (!$old) {
                    throw new RuntimeException('API Key 必填');
                }
                if ($old) {
                    update_row('ai_models', $id, $data);
                    audit('ai_model_update', 'id=' . $id);
                    flash('已保存');
                } else {
                    $data['sort'] = 0;
                    $data['created_at'] = now_str();
                    $newId = insert_row('ai_models', $data);
                    audit('ai_model_create', 'id=' . $newId);
                    flash('已添加，建议点「测试」确认能通');
                }
                redirect(u('ai_models'));
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
            redirect(u('ai_models'));
        }
    }

    $rows = all('SELECT * FROM ' . tbl('ai_models') . ' WHERE owner_id = ? ORDER BY id DESC', [$uid]);
    foreach ($rows as &$r) {
        $plain = ai_model_secret($r);
        $r['key_mask'] = $plain !== '' ? secret_mask($plain) : '';
    }
    unset($r);

    view('ai_models', [
        'title' => '我的模型',
        'rows'  => $rows,
        'ent'   => $ent,
        'edit'  => null,
    ]);
}

/** 充值 / 兑换 */
function ctrl_recharge(): void
{
    $u = require_login();
    $uid = (int)$u['id'];

    if (is_post()) {
        csrf_check();
        $action = post('action');
        try {
            if ($action === 'redeem') {
                $r = ai_redeem($uid, post('code'));
                flash($r['kind'] === 'plan'
                    ? '兑换成功，套餐已开通'
                    : '兑换成功，到账 ' . (int)$r['credits'] . ' 积分，当前余额 ' . (int)$r['balance']);
                redirect(u('recharge'));
            }
            if ($action === 'buy') {
                $oid = ai_order_create($uid, (int)post('plan_id', '0'), 'manual');
                flash('订单 #' . $oid . ' 已创建（待支付）。用兑换码自助充值，或联系管理员付款后由后台标记到账。');
                redirect(u('recharge'));
            }
            if ($action === 'refund_request') {
                $r = pay_refund_request((int)post('order_id', '0'), $uid, (string)($_POST['reason'] ?? ''));
                flash($r['message'], 'ok');
                redirect(u('recharge'));
            }
            if ($action === 'refresh') {
                $oid = (int)post('order_id', '0');
                $o = pay_order_get($oid);
                if (!$o || (int)$o['user_id'] !== $uid) {
                    throw new RuntimeException('订单不存在或不属于你');
                }
                $r = pay_reconcile($oid);
                flash(($r['state'] === 'paid' ? '✓ ' : '') . $r['msg'], $r['state'] === 'paid' ? 'ok' : 'err');
                redirect(u('recharge'));
            }
            if ($action === 'close_order') {
                pay_order_close((int)post('order_id', '0'), $uid);
                flash('订单已关闭');
                redirect(u('recharge'));
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
            redirect(u('recharge'));
        }
    }

    view('recharge', [
        'title'  => '充值 / 兑换',
        'ent'    => ai_entitlement($uid),
        'wallet' => ai_wallet($uid),
        'plans'  => all('SELECT * FROM ' . tbl('ai_plans') . ' WHERE enabled = 1 ORDER BY sort, id'),
        'orders' => all('SELECT o.*, p.name AS plan_name FROM ' . tbl('ai_orders') . ' o
                         LEFT JOIN ' . tbl('ai_plans') . ' p ON p.id = o.plan_id
                         WHERE o.user_id = ? ORDER BY o.id DESC LIMIT 20', [$uid]),
        'logs'   => all('SELECT * FROM ' . tbl('credit_logs') . ' WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$uid]),
        'payReady'   => pay_ready(),
        'payMethods' => pay_methods(),
        'payEnabled' => (bool)pay_conf()['enabled'],
        'shopName'   => (string)setting_get('redeem_shop_name', ''),
        'shopUrl'    => redeem_shop_url(),
    ]);
}

/** 后台：AI 设置（模型 / 套餐 / 兑换码 / 订单 / 积分 / 提示词 / 日志） */
function ctrl_admin_ai(): void
{
    $u = require_admin();
    $uid = (int)$u['id'];

    if (is_post()) {
        csrf_check();
        $action = post('action');
        try {
            if ($action === 'toggle_ai') {
                $on = post('ai_enabled') === '1' ? '1' : '0';
                setting_set('ai_enabled', $on);
                audit('ai_toggle', 'enabled=' . $on);
                flash($on === '1' ? 'AI 功能已开启' : 'AI 功能已关闭（用户端入口会提示未开启）');
                redirect(u('admin_ai'));
            }
            if ($action === 'limits_save') {
                $max = (int)post('ai_max_input', '8000');
                setting_set('ai_max_input', (string)max(500, min(200000, $max)));
                $kb = (int)post('agent_fetch_max_kb', '3000');
                setting_set('agent_fetch_max_kb', (string)max(200, min(8000, $kb)));
                setting_set('agent_fetch_ssl_verify', post('agent_fetch_ssl_verify') === '1' ? '1' : '0');
                flash('已保存');
                redirect(u('admin_ai'));
            }
            if ($action === 'prompt_save') {
                foreach (['chat', 'polish', 'extract', 'agent'] as $code) {
                    $v = trim((string)($_POST['prompt_' . $code] ?? ''));
                    setting_set('ai_prompt_' . $code . '_system', $v);
                }
                flash('提示词已保存（留空则用内置默认）');
                redirect(u('admin_ai'));
            }
            if ($action === 'shop_save') {
                $name = mb_substr(trim(post('shop_name')), 0, 40);
                $url = trim(post('shop_url'));
                if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                    throw new RuntimeException('购买地址要以 http:// 或 https:// 开头');
                }
                setting_set('redeem_shop_name', $name);
                setting_set('redeem_shop_url', $url);
                audit('redeem_shop_save', 'url=' . $url);
                flash($url === '' ? '已保存。地址留空，前台就不显示购买入口' : '已保存，用户充值页的兑换区会显示购买入口');
                redirect(u('admin_ai'));
            }
            if ($action === 'model_save') {
                $id = (int)post('id', '0');
                $old = $id > 0 ? ai_model_get($id) : null;
                if ($id > 0 && (!$old || (int)$old['owner_id'] !== 0)) {
                    throw new RuntimeException('只能编辑系统模型');
                }
                $name = trim(post('name'));
                $base = trim(post('base_url'));
                $modelKey = trim(post('model_key'));
                if ($name === '' || $base === '' || $modelKey === '') {
                    throw new RuntimeException('名称、接口地址、模型名都要填');
                }
                $keyPlain = trim((string)($_POST['api_key'] ?? ''));
                $data = [
                    'owner_id'    => 0,
                    'name'        => mb_substr($name, 0, 60),
                    'protocol'    => ai_protocol(post('protocol', 'openai')),
                    'base_url'    => mb_substr($base, 0, 240),
                    'model_key'   => mb_substr($modelKey, 0, 110),
                    'credits'     => max(0, (int)post('credits', '10')),
                    'max_tokens'  => max(64, min(32000, (int)post('max_tokens', '2048'))),
                    'temperature' => (string)max(0, min(2, (float)post('temperature', '0.3'))),
                    'timeout'     => max(10, min(300, (int)post('timeout', '120'))),
                    'note'        => mb_substr(trim(post('note')), 0, 200),
                    'enabled'     => post('enabled') === '1' ? 1 : 0,
                    'sort'        => (int)post('sort', '0'),
                    'updated_at'  => now_str(),
                ];
                if ($keyPlain !== '') {
                    $data['api_key'] = secret_encrypt($keyPlain);
                } elseif (!$old) {
                    throw new RuntimeException('API Key 必填');
                }
                if ($old) {
                    update_row('ai_models', $id, $data);
                    audit('ai_model_update', 'id=' . $id);
                    flash('模型已保存');
                } else {
                    $data['created_at'] = now_str();
                    $newId = insert_row('ai_models', $data);
                    audit('ai_model_create', 'id=' . $newId);
                    flash('模型已添加，建议点「测试」确认能通');
                }
                redirect(u('admin_ai'));
            }
            if ($action === 'model_delete') {
                $m = ai_model_get((int)post('id', '0'));
                if (!$m || (int)$m['owner_id'] !== 0) {
                    throw new RuntimeException('只能删除系统模型');
                }
                q('DELETE FROM ' . tbl('ai_models') . ' WHERE id = ? AND owner_id = 0', [(int)$m['id']]);
                audit('ai_model_delete', 'id=' . $m['id']);
                flash('已删除');
                redirect(u('admin_ai'));
            }
            if ($action === 'model_test') {
                $m = ai_model_get((int)post('id', '0'));
                if (!$m) {
                    throw new RuntimeException('模型不存在');
                }
                flash(ai_probe($m));
                redirect(u('admin_ai'));
            }
            // 分段体检：DNS / TCP / TLS / HTTP / 接口，卡在哪一步一目了然
            if ($action === 'model_diag') {
                $m = ai_model_get((int)post('id', '0'));
                if (!$m) {
                    throw new RuntimeException('模型不存在');
                }
                $_SESSION['ai_diag'] = ai_diagnose($m, post('deep') === '1') + ['id' => (int)$m['id']];
                redirect(u('admin_ai'));
            }
            if ($action === 'plan_save') {
                $id = (int)post('id', '0');
                $name = trim(post('name'));
                if ($name === '') {
                    throw new RuntimeException('套餐名必填');
                }
                $kind = post('kind') === 'monthly' ? 'monthly' : 'credits';
                $data = [
                    'name'         => mb_substr($name, 0, 60),
                    'kind'         => $kind,
                    'price_cents'  => max(0, (int)round((float)post('price_yuan', '0') * 100)),
                    'credits'      => max(0, (int)post('credits', '0')),
                    'days'         => $kind === 'monthly' ? max(1, (int)post('days', '30')) : 0,
                    'custom_slots' => $kind === 'monthly' ? max(0, (int)post('custom_slots', '0')) : 0,
                    'daily_calls'  => max(0, (int)post('daily_calls', '0')),
                    'all_models'   => post('all_models') === '1' ? 1 : 0,
                    'enabled'      => post('enabled') === '1' ? 1 : 0,
                    'sort'         => (int)post('sort', '0'),
                    'note'         => mb_substr(trim(post('note')), 0, 200),
                ];
                if ($id > 0) {
                    update_row('ai_plans', $id, $data);
                    audit('ai_plan_update', 'id=' . $id);
                    flash('套餐已保存');
                } else {
                    $data['created_at'] = now_str();
                    $newId = insert_row('ai_plans', $data);
                    audit('ai_plan_create', 'id=' . $newId);
                    flash('套餐已创建');
                }
                redirect(u('admin_ai'));
            }
            if ($action === 'plan_toggle') {
                $p = one('SELECT * FROM ' . tbl('ai_plans') . ' WHERE id = ?', [(int)post('id', '0')]);
                if ($p) {
                    update_row('ai_plans', (int)$p['id'], ['enabled' => (int)$p['enabled'] === 1 ? 0 : 1]);
                    flash((int)$p['enabled'] === 1 ? '已下架' : '已上架');
                }
                redirect(u('admin_ai'));
            }
            if ($action === 'plan_delete') {
                $pid = (int)post('id', '0');
                $used = (int)val('SELECT COUNT(*) FROM ' . tbl('user_plans') . ' WHERE plan_id = ?', [$pid], 0);
                if ($used > 0) {
                    throw new RuntimeException('已经有 ' . $used . ' 条开通记录，不能删，改成「下架」更安全');
                }
                q('DELETE FROM ' . tbl('ai_plans') . ' WHERE id = ?', [$pid]);
                audit('ai_plan_delete', 'id=' . $pid);
                flash('已删除');
                redirect(u('admin_ai'));
            }
            if ($action === 'code_gen') {
                $count = max(1, min(100, (int)post('count', '10')));
                $kind = post('kind') === 'plan' ? 'plan' : 'credits';
                $credits = max(0, (int)post('credits', '0'));
                $planId = (int)post('plan_id', '0');
                if ($kind === 'plan' && !one('SELECT id FROM ' . tbl('ai_plans') . ' WHERE id = ?', [$planId])) {
                    throw new RuntimeException('请选择要兑换的套餐');
                }
                if ($kind === 'credits' && $credits <= 0) {
                    throw new RuntimeException('积分数量要大于 0');
                }
                $maxUses = max(1, min(1000, (int)post('max_uses', '1')));
                $expDays = (int)post('exp_days', '0');
                $expires = $expDays > 0 ? date('Y-m-d 23:59:59', time() + $expDays * 86400) : null;
                $batch = 'B' . date('ymdHis') . '-' . bin2hex(random_bytes(2));
                $codes = [];
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                for ($i = 0; $i < $count; $i++) {
                    $raw = '';
                    for ($j = 0; $j < 12; $j++) {
                        $raw .= $chars[random_int(0, strlen($chars) - 1)];
                    }
                    $code = 'KB' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
                    insert_row('redeem_codes', [
                        'code' => $code, 'kind' => $kind, 'credits' => $credits,
                        'plan_id' => $kind === 'plan' ? $planId : 0,
                        'max_uses' => $maxUses, 'used_count' => 0, 'expires_at' => $expires,
                        'enabled' => 1, 'batch' => $batch, 'created_by' => $uid, 'created_at' => now_str(),
                    ]);
                    $codes[] = $code;
                }
                $_SESSION['ai_new_codes'] = ['batch' => $batch, 'codes' => $codes];
                audit('ai_code_gen', 'batch=' . $batch . ' n=' . $count);
                flash('已生成 ' . $count . ' 个兑换码（批次 ' . $batch . '），在下方复制发放');
                redirect(u('admin_ai'));
            }
            if ($action === 'code_toggle') {
                $c = one('SELECT * FROM ' . tbl('redeem_codes') . ' WHERE id = ?', [(int)post('id', '0')]);
                if ($c) {
                    update_row('redeem_codes', (int)$c['id'], ['enabled' => (int)$c['enabled'] === 1 ? 0 : 1]);
                    flash((int)$c['enabled'] === 1 ? '已作废' : '已启用');
                }
                redirect(u('admin_ai'));
            }
            if ($action === 'order_pay') {
                $r = ai_order_pay((int)post('id', '0'), 'manual-by-' . $uid, 'manual');
                flash($r['already'] ? '这单已经是已支付状态' : '已标记支付并发放套餐');
                redirect(u('admin_ai'));
            }
            if ($action === 'credit_adjust') {
                $target = (int)post('user_id', '0');
                $delta = (int)post('delta', '0');
                $note = trim(post('note'));
                if ($target <= 0 || $delta === 0) {
                    throw new RuntimeException('用户 ID 与积分变动量都要填');
                }
                if (!one('SELECT id FROM ' . tbl('users') . ' WHERE id = ?', [$target])) {
                    throw new RuntimeException('用户不存在');
                }
                $r = ai_credit_move($target, $delta, 'admin', [
                    'ref_type' => 'admin', 'ref_id' => $uid, 'note' => $note !== '' ? $note : ('管理员 ' . $u['username'] . ' 调整'),
                ]);
                if (!$r['ok']) {
                    throw new RuntimeException($r['error']);
                }
                audit('ai_credit_adjust', 'user=' . $target . ' delta=' . $delta);
                flash('已调整，该用户当前余额 ' . (int)$r['balance'] . ' 积分');
                redirect(u('admin_ai'));
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
            redirect(u('admin_ai'));
        }
    }

    $newCodes = $_SESSION['ai_new_codes'] ?? null;
    unset($_SESSION['ai_new_codes']);

    $today = date('Y-m-d 00:00:00');
    $diag = null;
    if (!empty($_SESSION['ai_diag'])) {
        $diag = (array)$_SESSION['ai_diag'];
        unset($_SESSION['ai_diag']);
    }

    view('admin_ai', [
        'title'  => 'AI 设置',
        'diag'   => $diag,
        'on'     => ai_on(),
        'maxIn'  => ai_max_input(),
        'models' => all('SELECT m.*, u.username AS owner_name FROM ' . tbl('ai_models') . ' m
                         LEFT JOIN ' . tbl('users') . ' u ON u.id = m.owner_id
                         ORDER BY m.owner_id, m.sort, m.id'),
        'plans'  => all('SELECT * FROM ' . tbl('ai_plans') . ' ORDER BY sort, id'),
        'codes'  => all('SELECT * FROM ' . tbl('redeem_codes') . ' ORDER BY id DESC LIMIT 60'),
        'orders' => all('SELECT o.*, p.name AS plan_name, u.username FROM ' . tbl('ai_orders') . ' o
                         LEFT JOIN ' . tbl('ai_plans') . ' p ON p.id = o.plan_id
                         LEFT JOIN ' . tbl('users') . ' u ON u.id = o.user_id
                         ORDER BY o.id DESC LIMIT 60'),
        'calls'  => all('SELECT c.*, u.username FROM ' . tbl('ai_calls') . ' c
                         LEFT JOIN ' . tbl('users') . ' u ON u.id = c.user_id
                         ORDER BY c.id DESC LIMIT 60'),
        'newCodes' => $newCodes,
        'stat'   => [
            'calls_today' => (int)val('SELECT COUNT(*) FROM ' . tbl('ai_calls') . ' WHERE created_at >= ?', [$today], 0),
            'credits_today' => (int)val('SELECT COALESCE(SUM(-delta),0) FROM ' . tbl('credit_logs') . ' WHERE delta < 0 AND reason = ? AND created_at >= ?', ['ai_call', $today], 0),
            'users' => (int)val('SELECT COUNT(*) FROM ' . tbl('credit_wallets') . ' WHERE credits > 0', [], 0),
            'paid' => (int)val('SELECT COUNT(*) FROM ' . tbl('ai_orders') . ' WHERE status = ?', ['paid'], 0),
            'revenue' => (int)val('SELECT COALESCE(SUM(amount_cents),0) FROM ' . tbl('ai_orders') . ' WHERE status = ?', ['paid'], 0),
        ],
        'prompts' => [
            'chat' => (string)setting_get('ai_prompt_chat_system', ''),
            'polish' => (string)setting_get('ai_prompt_polish_system', ''),
            'extract' => (string)setting_get('ai_prompt_extract_system', ''),
            'agent' => (string)setting_get('ai_prompt_agent_system', ''),
        ],
        'fetchKb' => (int)setting_get('agent_fetch_max_kb', '3000'),
        'fetchVerify' => (string)setting_get('agent_fetch_ssl_verify', '1') !== '0',
        'shopName' => (string)setting_get('redeem_shop_name', ''),
        'shopUrl'  => (string)setting_get('redeem_shop_url', ''),
    ]);
}
