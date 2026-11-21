<?php
declare(strict_types=1);

/**
 * 支付相关控制器（1.9.0）：发起支付 / 异步通知 / 同步回跳 / 后台配置
 */

/**
 * 发起支付。
 * POST：plan_id + method（新单）或 order_id + method（继续付已存在的待支付单）
 * 两种出口：
 *   ajax=1（充值页弹窗用）→ 返回 JSON：订单信息 + 二维码内容 / iframe 地址 / 状态查询地址
 *   普通提交           → 302 到平台，或渲染一张自动提交到收银台的表单（无 JS 环境的兜底）
 */
function ctrl_pay_go(): void
{
    $u = require_login();
    $uid = (int)$u['id'];
    $json = post('ajax', '') === '1' || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    $fail = function (string $msg, int $code = 400) use ($json): void {
        if ($json) {
            json_out(['ok' => false, 'error' => ['message' => $msg]], $code);
        }
        flash($msg, 'err');
        redirect(u('recharge'));
    };

    if (!is_post()) {
        $fail('请从充值页选择套餐发起支付', 405);
    }
    // CSRF：AJAX 要回 JSON，不能走 csrf_check()（它 exit 的是纯文本）
    $token = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        $fail('会话已过期，请刷新页面后重试', 419);
    }

    $method = (string)post('method', '');
    $orderId = (int)post('order_id', '0');
    $o = null;

    try {
        if ($orderId > 0) {
            $o = pay_order_get($orderId);
            if (!$o || (int)$o['user_id'] !== $uid) {
                throw new PayError('forbidden', '订单不存在或不属于你');
            }
            if ($method === '') {
                $method = (string)($o['pay_method'] ?? '');
            }
        } else {
            $orderId = pay_order_new($uid, (int)post('plan_id', '0'), $method);
            $o = pay_order_get($orderId);
        }
        if ($method === '') {
            $methods = pay_methods();
            $method = (string)(array_key_first($methods) ?: 'alipay');
        }
        $r = pay_start($orderId, $method, client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } catch (PayError $e) {
        $fail($e->getMessage());
    } catch (Throwable $e) {
        $fail('发起支付失败：' . $e->getMessage());
    }

    $no = (string)$o['out_trade_no'];
    pay_frame_stash($no, $r); // 给 iframe 兜底页读取（不把签名参数塞进 URL）
    $frame = u('pay_frame', ['no' => $no]);
    $jump = (string)$r['mode'] === 'jump' && (string)($r['url'] ?? '') !== '';
    $open = $jump ? (string)$r['url'] : $frame;

    if ($json) {
        json_out(['ok' => true, 'data' => [
            'no'          => $no,
            'amount'      => pay_money((int)$o['amount_cents']),
            'plan'        => (string)($o['plan_name'] ?? ''),
            'method'      => $method,
            'method_name' => pay_method_name($method),
            'mode'        => (string)$r['mode'],
            'qr'          => (string)($r['qrcode'] ?? ''),
            'frame'       => $frame,
            'open'        => $open,
            'warning'     => (string)($r['warning'] ?? ''),
            'status_url'  => u('pay_status', ['no' => $no]),
        ]]);
    }

    if ($jump) {
        header('Location: ' . $open);
        exit;
    }

    // form / qrcode：渲染跳转页。qrcode 模式下平台收银台自己会画二维码，这里仍走 form。
    view('pay_go', [
        'title' => '正在跳转支付',
        'pay'   => $r,
        'order' => $o,
    ]);
}

/** 把发起结果暂存到会话，供 pay_frame 使用（按订单号索引，2 小时过期） */
function pay_frame_stash(string $no, array $r): void
{
    $box = $_SESSION['pay_frame'] ?? [];
    $now = time();
    foreach ($box as $k => $v) {
        if ($now - (int)($v['t'] ?? 0) > 7200) {
            unset($box[$k]);
        }
    }
    $box[$no] = [
        't'      => $now,
        'mode'   => (string)($r['mode'] ?? ''),
        'url'    => (string)($r['url'] ?? ''),
        'action' => (string)($r['action'] ?? ''),
        'params' => (array)($r['params'] ?? []),
    ];
    if (count($box) > 20) {
        $box = array_slice($box, -20, null, true);
    }
    $_SESSION['pay_frame'] = $box;
}

/**
 * 弹窗里的 iframe 兜底页：平台只给跳转地址/收银台表单时用。
 * 只做“把用户送到平台去”，不做任何业务判断。
 */
function ctrl_pay_frame(): void
{
    $u = require_login();
    $no = get_q('no');
    $st = $_SESSION['pay_frame'][$no] ?? null;
    $o = $no !== '' ? pay_order_by_no($no) : null;
    if (!$st || !$o || (int)$o['user_id'] !== (int)$u['id']) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><p style="font-family:system-ui;padding:18px;color:#666">这个支付页已过期，请关闭弹窗后重新发起支付。</p>';
        exit;
    }
    view('pay_frame', [
        'title' => '跳转支付',
        'st'    => $st,
        'order' => $o,
        'dark'  => get_q('dark') === '1',
    ], false);
}

/**
 * 弹窗轮询：返回订单当前状态。待支付时顺带主动查一次平台（6 秒节流），
 * 这样即使异步通知被挡（域名/防火墙），用户付完也能马上看到成功。
 */
function ctrl_pay_status(): void
{
    $u = require_login();
    $uid = (int)$u['id'];
    $no = get_q('no');
    $o = $no !== '' ? pay_order_by_no($no) : null;
    if (!$o || (int)$o['user_id'] !== $uid) {
        json_out(['ok' => false, 'error' => ['message' => '订单不存在']], 404);
    }

    $state = (string)$o['status'];
    $msg = '';
    $checked = false;

    if ($state === 'pending') {
        $last = (int)($_SESSION['pay_check'][$no] ?? 0);
        if (time() - $last >= 6) {
            $_SESSION['pay_check'][$no] = time();
            // 查单最长十几秒，先把会话锁放掉，别把用户其它请求堵住
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $checked = true;
            try {
                $rec = pay_reconcile((int)$o['id']);
                $msg = (string)$rec['msg'];
            } catch (Throwable $e) {
                $msg = '查单失败：' . $e->getMessage();
            }
            $fresh = pay_order_get((int)$o['id']);
            if ($fresh) {
                $o = $fresh;
                $state = (string)$o['status'];
            }
        }
    }

    json_out(['ok' => true, 'data' => [
        'state'   => $state,
        'paid'    => $state === 'paid',
        'msg'     => $msg,
        'checked' => $checked,
        'credits' => (int)$o['credits'],
        'amount'  => pay_money((int)$o['amount_cents']),
        'balance' => ai_balance($uid),
    ]]);
}

/** 异步通知：必须无登录、无 CSRF，纯文本回 success / fail */
function ctrl_pay_notify(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    // 回调不需要会话；提前释放 session 锁，避免平台并发重试时互相阻塞
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $params = array_merge($_GET, $_POST);
    // 关键坑：notify_url 是 ?r=pay_notify，平台会把 r 一起带回来。
    // r 不参与平台签名，留着会让验签必然失败。
    unset($params['r'], $params['p']);

    try {
        $r = pay_notify($params);
        echo !empty($r['ok']) ? 'success' : 'fail';
    } catch (Throwable $e) {
        echo 'fail';
    }
    exit;
}

/** 同步回跳：给用户看的落地页。发货以 notify / 查单为准，不凭这个页面的参数发货 */
function ctrl_pay_return(): void
{
    $params = array_merge($_GET, $_POST);
    unset($params['r'], $params['p']);

    $verified = false;
    $error = '';
    $order = null;
    $state = '';
    $note = '';

    try {
        $c = pay_conf();
        if ($c['pid'] !== '' && $c['key'] !== '') {
            $gw = pay_gateway($c);
            $v = $gw->verify($params);
            $verified = !empty($v['ok']);
            if ($verified) {
                $order = pay_order_by_no((string)$v['out_trade_no']);
                if ($order) {
                    if (!empty($v['paid'])) {
                        // 主动查一次：通知可能比回跳晚到，这里确认后就地补发货
                        $rec = pay_reconcile((int)$order['id']);
                        $state = (string)$rec['state'];
                        $note = (string)$rec['msg'];
                        $order = pay_order_get((int)$order['id']);
                    } else {
                        $state = 'pending';
                        $note = '平台返回的状态不是支付成功';
                    }
                } else {
                    $error = '找不到对应的订单';
                }
            } else {
                $error = (string)$v['error'];
            }
        } else {
            $error = '本站还没配置支付参数';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    view('pay_return', [
        'title'    => '支付结果',
        'verified' => $verified,
        'error'    => $error,
        'order'    => $order,
        'state'    => $state,
        'note'     => $note,
        'logged'   => !empty(current_user()),
    ], false);
}

/**
 * 后台：支付设置 / 订单对账 / 退款
 */
function ctrl_admin_pay(): void
{
    $u = require_admin();
    $uid = (int)$u['id'];

    if (is_post()) {
        csrf_check();
        $action = post('action');
        try {
            if ($action === 'save') {
                $api = trim(post('pay_api', 'https://pay.cfxcc.cn'));
                if (!preg_match('#^https?://#i', $api)) {
                    throw new RuntimeException('接口地址要以 http:// 或 https:// 开头');
                }
                $pid = trim(post('pay_pid', ''));
                if ($pid !== '' && !preg_match('/^\d+$/', $pid)) {
                    throw new RuntimeException('商户 ID 只能是数字');
                }
                $methods = $_POST['methods'] ?? [];
                $methods = is_array($methods) ? array_values(array_intersect(array_map('strval', $methods), array_keys(pay_methods_all()))) : [];

                setting_set('pay_gateway', 'epay');
                setting_set('pay_api', rtrim($api, '/'));
                setting_set('pay_pid', $pid);
                setting_set('pay_methods', implode(',', $methods));
                setting_set('pay_use_mapi', post('pay_use_mapi') === '1' ? '1' : '0');
                setting_set('pay_title_prefix', mb_substr(trim(post('pay_title_prefix', '')), 0, 30));

                $newKey = trim((string)($_POST['pay_key'] ?? ''));
                if ($newKey !== '') {
                    setting_set('pay_key', secret_encrypt($newKey));
                }
                // 只有配齐了才允许打开，避免开关开了前台一片报错
                $wantOn = post('pay_enabled') === '1';
                $conf = pay_conf(true);
                if ($wantOn && ($conf['pid'] === '' || $conf['key'] === '' || $conf['methods'] === [])) {
                    setting_set('pay_enabled', '0');
                    throw new RuntimeException('商户号、商户密钥、支付方式三项没配齐，开关保持关闭');
                }
                setting_set('pay_enabled', $wantOn ? '1' : '0');
                pay_conf(true);
                audit('pay_save', 'enabled=' . ($wantOn ? '1' : '0') . ' pid=' . $pid);
                flash('支付设置已保存');
                redirect(u('admin_pay'));
            }

            if ($action === 'test') {
                $c = pay_conf(true);
                if ($c['pid'] === '' || $c['key'] === '') {
                    throw new RuntimeException('先填商户 ID 和商户密钥');
                }
                $r = pay_gateway($c)->probe();
                flash((!empty($r['ok']) ? '✓ ' : '✗ ') . (string)$r['message'], !empty($r['ok']) ? 'ok' : 'err');
                redirect(u('admin_pay'));
            }

            // 退款接口自检：读商户信息 + 拿不存在的单号探一次退款，都不产生真实退款
            if ($action === 'refund_probe') {
                $c = pay_conf(true);
                if ($c['pid'] === '' || $c['key'] === '') {
                    throw new RuntimeException('先填商户 ID 和商户密钥');
                }
                $gw = pay_gateway($c);
                $pr = $gw->probe();
                $rf = method_exists($gw, 'refundSelftest')
                    ? $gw->refundSelftest()
                    : ['ok' => false, 'state' => 'na', 'msg' => '当前支付通道不支持自检'];
                $_SESSION['pay_refund_probe'] = [
                    'at'     => now_str(),
                    'probe'  => $pr,
                    'refund' => $rf,
                ];
                flash('退款接口自检完成，结果见下方面板');
                redirect(u('admin_pay'));
            }

            if ($action === 'query') {
                $r = pay_reconcile((int)post('order_id', '0'));
                flash((!empty($r['ok']) ? '✓ ' : '✗ ') . (string)$r['msg'], !empty($r['ok']) ? 'ok' : 'err');
                redirect(u('admin_pay'));
            }

            if ($action === 'close') {
                pay_order_close((int)post('order_id', '0'));
                audit('pay_order_close', 'order=' . (int)post('order_id', '0'));
                flash('订单已关闭');
                redirect(u('admin_pay'));
            }

            if ($action === 'refund') {
                $r = pay_refund((int)post('order_id', '0'), mb_substr(trim(post('reason')), 0, 100));
                flash('退款已提交；' . (string)$r['note']);
                redirect(u('admin_pay'));
            }

            // 同意用户提交的退款申请 → 立即调平台退款
            if ($action === 'refund_approve') {
                $r = pay_refund_approve((int)post('order_id', '0'), mb_substr(trim(post('note')), 0, 100));
                flash('✓ ' . (string)$r['note'], 'ok');
                redirect(u('admin_pay'));
            }

            // 补扣：已退款但积分还留在用户账上（2.0.4 之前月付单的漏扣）
            if ($action === 'reclaim') {
                $r = pay_reclaim_shortfall((int)post('order_id', '0'), (string)($u['username'] ?? ''));
                flash('✓ ' . (string)$r['note'], 'ok');
                redirect(u('admin_pay'));
            }

            // 平台没开退款接口 / 已在平台后台手工退了 → 只记账并回收权益
            if ($action === 'refund_manual') {
                $r = pay_refund_manual(
                    (int)post('order_id', '0'),
                    mb_substr(trim(post('note')), 0, 120),
                    (string)($u['username'] ?? '')
                );
                flash('✓ ' . (string)$r['note'], 'ok');
                redirect(u('admin_pay'));
            }

            // 驳回退款申请（订单保持已支付）
            if ($action === 'refund_reject') {
                pay_refund_reject((int)post('order_id', '0'), mb_substr(trim(post('note')), 0, 100));
                flash('退款申请已驳回，用户能看到驳回说明');
                redirect(u('admin_pay'));
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
            redirect(u('admin_pay'));
        }
    }

    $c = pay_conf(true);
    $today = date('Y-m-d 00:00:00');
    $ledger = pay_ledger(200);
    view('admin_pay', [
        'title'    => '支付设置',
        'conf'     => $c,
        'ready'    => pay_ready(),
        'keyMask'  => $c['key'] !== '' ? secret_mask($c['key']) : '',
        'refundProbe' => $_SESSION['pay_refund_probe'] ?? null,
        'methodsAll' => pay_methods_all(),
        'ledger'      => $ledger['rows'],
        'ledgerSum'   => $ledger['summary'],
        'creditLogs'  => pay_credit_logs(200),
        'refunds'  => all(
            'SELECT o.*, p.name AS plan_name, u.username FROM ' . tbl('ai_orders') . ' o
             LEFT JOIN ' . tbl('ai_plans') . ' p ON p.id = o.plan_id
             LEFT JOIN ' . tbl('users') . ' u ON u.id = o.user_id
             WHERE o.refund_state = ? ORDER BY o.id DESC LIMIT 40',
            ['pending']
        ),
        'orders'   => all(
            'SELECT o.*, p.name AS plan_name, u.username FROM ' . tbl('ai_orders') . ' o
             LEFT JOIN ' . tbl('ai_plans') . ' p ON p.id = o.plan_id
             LEFT JOIN ' . tbl('users') . ' u ON u.id = o.user_id
             WHERE o.channel = ? OR o.status = ? ORDER BY o.id DESC LIMIT 60',
            ['epay', 'pending']
        ),
        'events'   => all('SELECT * FROM ' . tbl('pay_events') . ' ORDER BY id DESC LIMIT 40'),
        'stat'     => [
            'paid_today'   => (int)val('SELECT COUNT(*) FROM ' . tbl('ai_orders') . ' WHERE status = ? AND channel = ? AND paid_at >= ?', ['paid', 'epay', $today], 0),
            'amount_today' => (int)val('SELECT COALESCE(SUM(amount_cents),0) FROM ' . tbl('ai_orders') . ' WHERE status = ? AND channel = ? AND paid_at >= ?', ['paid', 'epay', $today], 0),
            'amount_all'   => (int)val('SELECT COALESCE(SUM(amount_cents),0) FROM ' . tbl('ai_orders') . ' WHERE status = ? AND channel = ?', ['paid', 'epay'], 0),
            'pending'      => (int)val('SELECT COUNT(*) FROM ' . tbl('ai_orders') . ' WHERE status = ? AND amount_cents > 0', ['pending'], 0),
        ],
    ]);
}
