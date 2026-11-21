<?php
declare(strict_types=1);

/**
 * 支付业务层（1.9.0）
 *
 * 职责分界：
 *   gateway  → 「怎么跟平台说话」（签名、报文、HTTP）
 *   orders   → 「订单怎么建、钱到没到、货发没发」（落库、核对金额、幂等、补单）
 * 控制器只负责取参数和输出，不写业务判断。
 */

/* ---------------- 配置 ---------------- */

/**
 * 支付配置。$fresh=true 时强制重读（后台刚保存、测试里改过设置都需要）。
 */
function pay_conf(bool $fresh = false): array
{
    static $c = null;
    if ($c !== null && !$fresh) {
        return $c;
    }
    $keyEnc = (string)setting_get('pay_key', '');
    $methods = array_values(array_filter(array_map('trim', explode(',', (string)setting_get('pay_methods', 'alipay,wxpay')))));
    $c = [
        'gateway'      => (string)setting_get('pay_gateway', 'epay') ?: 'epay',
        'enabled'      => (string)setting_get('pay_enabled', '0') === '1',
        'api'          => rtrim((string)setting_get('pay_api', 'https://pay.cfxcc.cn'), '/') ?: 'https://pay.cfxcc.cn',
        'pid'          => trim((string)setting_get('pay_pid', '')),
        'key'          => $keyEnc !== '' ? secret_decrypt($keyEnc) : '',
        'key_enc'      => $keyEnc,
        'methods'      => $methods,
        'use_mapi'     => (string)setting_get('pay_use_mapi', '1') === '1',
        'title_prefix' => (string)setting_get('pay_title_prefix', '知识库 · '),
        'notify_url'   => base_url() . '/index.php?r=pay_notify',
        'return_url'   => base_url() . '/index.php?r=pay_return',
    ];
    return $c;
}

/** 支付是否可用（开关 + 商户号 + 密钥 + 至少一个支付方式） */
function pay_ready(): bool
{
    $c = pay_conf();
    return $c['enabled'] && $c['pid'] !== '' && $c['key'] !== '' && $c['methods'];
}

/** 全部支付方式（代码 => 名称） */
function pay_methods_all(): array
{
    return [
        'alipay' => '支付宝',
        'wxpay'  => '微信支付',
        'qqpay'  => 'QQ 钱包',
    ];
}

/** 本站开启的支付方式 */
function pay_methods(): array
{
    $all = pay_methods_all();
    $out = [];
    foreach (pay_conf()['methods'] as $m) {
        if (isset($all[$m])) {
            $out[$m] = $all[$m];
        }
    }
    return $out;
}

function pay_method_name(string $m): string
{
    return pay_methods_all()[$m] ?? ($m !== '' ? $m : '—');
}

/**
 * 兑换码的购买入口（后台「AI 设置 → 兑换码」里配）。
 * 只认 http(s)，配错了当作没配，避免把 javascript: 之类的东西渲染到页面上。
 */
function redeem_shop_url(): string
{
    $u = trim((string)setting_get('redeem_shop_url', ''));
    return preg_match('#^https?://#i', $u) ? $u : '';
}

/* ---------------- 订单 ---------------- */

/** 商户订单号：直接复用 AI 订单的编号规则，避免两套格式 */
function pay_order_no(): string
{
    return ai_order_no();
}

function pay_order_get(int $id): ?array
{
    return one(
        'SELECT o.*, p.name AS plan_name FROM ' . tbl('ai_orders') . ' o
         LEFT JOIN ' . tbl('ai_plans') . ' p ON p.id = o.plan_id
         WHERE o.id = ?',
        [$id]
    );
}

function pay_order_by_no(string $no): ?array
{
    return one(
        'SELECT o.*, p.name AS plan_name FROM ' . tbl('ai_orders') . ' o
         LEFT JOIN ' . tbl('ai_plans') . ' p ON p.id = o.plan_id
         WHERE o.out_trade_no = ?',
        [$no]
    );
}

/**
 * 新建待支付订单。
 * 只允许买「上架的、有价格的」套餐；免费的直接拒绝，让他们用兑换码或管理员发放。
 */
function pay_order_new(int $uid, int $planId, string $method): int
{
    $plan = one('SELECT * FROM ' . tbl('ai_plans') . ' WHERE id = ? AND enabled = 1', [$planId]);
    if (!$plan) {
        throw new PayError('no_plan', '套餐不存在或已下架');
    }
    if ((int)$plan['price_cents'] <= 0) {
        throw new PayError('free_plan', '这个套餐是免费的，不需要付款');
    }
    if (!array_key_exists($method, pay_methods())) {
        throw new PayError('bad_method', '请选择支付方式');
    }
    $id = ai_order_create($uid, $planId, 'epay', pay_order_no());
    update_row('ai_orders', $id, ['pay_method' => $method, 'updated_at' => now_str()]);
    return $id;
}

/** 关闭未支付订单（用户放弃时用） */
function pay_order_close(int $orderId, int $uid = 0): array
{
    $o = pay_order_get($orderId);
    if (!$o) {
        throw new PayError('no_order', '订单不存在');
    }
    if ($uid > 0 && (int)$o['user_id'] !== $uid) {
        throw new PayError('forbidden', '这不是你的订单');
    }
    if ((string)$o['status'] === 'paid') {
        throw new PayError('paid', '已支付的订单不能关闭');
    }
    if ((string)$o['status'] === 'pending') {
        update_row('ai_orders', $orderId, ['status' => 'closed', 'updated_at' => now_str()]);
    }
    return ['ok' => true];
}

/* ---------------- 发起支付 ---------------- */

/**
 * 发起支付，返回给控制器决定怎么跳。
 * 抛 PayError('config'|'paid'|'closed'|'gateway'|...) 由控制器翻成提示。
 */
function pay_start(int $orderId, string $method, string $clientIp, string $ua): array
{
    $c = pay_conf();
    if (!$c['enabled']) {
        throw new PayError('disabled', '在线支付还没开启，请联系管理员');
    }
    if ($c['pid'] === '' || $c['key'] === '') {
        throw new PayError('config', '支付参数没配好（商户号或密钥为空），请联系管理员');
    }
    if (!array_key_exists($method, pay_methods())) {
        throw new PayError('bad_method', '这个支付方式没开启，请换一个');
    }
    $o = pay_order_get($orderId);
    if (!$o) {
        throw new PayError('no_order', '订单不存在');
    }
    $st = (string)$o['status'];
    if ($st === 'paid') {
        throw new PayError('paid', '这单已经付过了');
    }
    if ($st === 'refunded') {
        throw new PayError('refunded', '这单已退款，请重新下单');
    }
    if ($st !== 'pending') {
        throw new PayError('closed', '订单已关闭，请重新下单');
    }

    $gw = pay_gateway($c);
    $r = $gw->start($o, $method, $clientIp, pay_device($ua));

    if (empty($r['ok'])) {
        pay_event($orderId, 'start', (string)$o['out_trade_no'], false, 0, '发起失败：' . (string)($r['error'] ?? ''), ['method' => $method], $clientIp);
        throw new PayError('gateway', (string)($r['error'] ?? '支付平台暂时不可用'));
    }

    update_row('ai_orders', $orderId, ['pay_method' => $method, 'updated_at' => now_str()]);
    pay_event(
        $orderId,
        'start',
        (string)$o['out_trade_no'],
        true,
        (int)$o['amount_cents'],
        '发起支付：' . pay_method_name($method) . '，模式 ' . (string)$r['mode'],
        ['method' => $method, 'mode' => $r['mode'] ?? '', 'warning' => $r['warning'] ?? ''],
        $clientIp
    );
    return $r;
}

/* ---------------- 回调 ---------------- */

/**
 * 处理异步通知。返回 ['ok'=>bool,'msg'=>string]，控制器按 ok 输出 success / fail。
 * 幂等：同一单重复通知不会重复发货（ai_order_pay 自带 already 判断）。
 */
function pay_notify(array $params): array
{
    $c = pay_conf();
    $ip = client_ip();
    $outNo = trim((string)($params['out_trade_no'] ?? ''));

    if ($c['pid'] === '' || $c['key'] === '') {
        pay_event(0, 'notify', $outNo, false, 0, '本站支付未配置，拒绝通知', $params, $ip);
        return ['ok' => false, 'msg' => 'not configured'];
    }

    try {
        $gw = pay_gateway($c);
        $v = $gw->verify($params);
    } catch (Throwable $e) {
        pay_event(0, 'notify', $outNo, false, 0, '校验异常：' . $e->getMessage(), $params, $ip);
        return ['ok' => false, 'msg' => 'verify error'];
    }

    if (empty($v['ok'])) {
        pay_event(0, 'notify', $outNo, false, 0, '验签失败：' . (string)$v['error'], $params, $ip);
        return ['ok' => false, 'msg' => (string)$v['error']];
    }

    $o = pay_order_by_no((string)$v['out_trade_no']);
    if (!$o) {
        pay_event(0, 'notify', (string)$v['out_trade_no'], true, 0, '订单不存在（可能是别的系统的单）', $params, $ip);
        return ['ok' => false, 'msg' => 'order not found'];
    }

    $oid = (int)$o['id'];
    $cents = pay_cents((string)$v['money']);
    if ($cents < 0) {
        pay_event($oid, 'notify', (string)$o['out_trade_no'], true, 0, '金额格式异常：' . (string)$v['money'], $params, $ip);
        return ['ok' => false, 'msg' => 'bad money'];
    }
    if ($cents !== (int)$o['amount_cents']) {
        pay_event(
            $oid, 'notify', (string)$o['out_trade_no'], true, $cents,
            '金额不符：订单 ' . pay_money((int)$o['amount_cents']) . '，通知 ' . (string)$v['money'] . '，已拒绝',
            $params, $ip
        );
        return ['ok' => false, 'msg' => 'amount mismatch'];
    }

    if (empty($v['paid'])) {
        // 非成功状态（如已关闭/待付款），记录后告知已收到，避免平台无意义重试
        pay_event($oid, 'notify', (string)$o['out_trade_no'], true, $cents, '状态非成功：' . (string)($v['status'] ?? ''), $params, $ip);
        return ['ok' => true, 'msg' => 'ignored'];
    }

    if ((string)$o['status'] === 'paid') {
        pay_event($oid, 'notify', (string)$o['out_trade_no'], true, $cents, '重复通知，已支付，跳过发货', $params, $ip);
        return ['ok' => true, 'msg' => 'already paid'];
    }
    if (in_array((string)$o['status'], ['refunded', 'closed'], true)) {
        pay_event($oid, 'notify', (string)$o['out_trade_no'], true, $cents, '订单状态为 ' . $o['status'] . '，不发货', $params, $ip);
        return ['ok' => true, 'msg' => 'order ' . $o['status']];
    }

    try {
        $r = ai_order_pay($oid, (string)$v['trade_no'], 'epay');
        update_row('ai_orders', $oid, ['pay_method' => (string)($v['type'] ?: $o['pay_method']), 'updated_at' => now_str()]);
        pay_event(
            $oid, 'notify', (string)$o['out_trade_no'], true, $cents,
            ($r['already'] ? '已支付，未重复发货' : '支付成功，已发货'),
            $params, $ip
        );
        return ['ok' => true, 'msg' => 'ok'];
    } catch (Throwable $e) {
        pay_event($oid, 'notify', (string)$o['out_trade_no'], true, $cents, '发货失败：' . $e->getMessage(), $params, $ip);
        return ['ok' => false, 'msg' => 'grant failed'];
    }
}

/**
 * 主动查远端并补发货（通知丢失、用户回跳后想立刻看到结果，都走这里）。
 * 已支付的单也会查：管理员可能在支付平台后台手工退了款，本站要能同步过来。
 * 返回 ['ok'=>,'state'=>'paid'|'refunded'|'pending'|'mismatch'|'error','msg'=>..]
 */
function pay_reconcile(int $orderId): array
{
    $o = pay_order_get($orderId);
    if (!$o) {
        return ['ok' => false, 'state' => 'error', 'msg' => '订单不存在'];
    }
    $st = (string)$o['status'];
    if ($st === 'refunded') {
        return ['ok' => true, 'state' => 'refunded', 'msg' => '这单已退款'];
    }
    if ($st === 'closed') {
        return ['ok' => true, 'state' => 'pending', 'msg' => '订单已关闭'];
    }
    $c = pay_conf();
    if ($c['pid'] === '' || $c['key'] === '') {
        return ['ok' => false, 'state' => 'error', 'msg' => '支付未配置'];
    }
    $gw = pay_gateway($c);
    $q = $gw->query((string)$o['out_trade_no']);
    if (empty($q['ok'])) {
        pay_event((int)$o['id'], 'query', (string)$o['out_trade_no'], false, 0, '查单失败：' . (string)($q['error'] ?? ''), [], client_ip());
        return ['ok' => false, 'state' => 'error', 'msg' => (string)($q['error'] ?? '查单失败')];
    }

    // 易支付订单状态：0 未支付 / 1 已支付 / 2 已退款 / 3 已冻结
    $remote = (int)($q['status'] ?? 0);
    if ($remote === 2) {
        if ($st !== 'paid') {
            pay_event((int)$o['id'], 'query', (string)$o['out_trade_no'], true, 0, '平台显示已退款，但本单未支付过，未自动处理', [], client_ip());
            return ['ok' => false, 'state' => 'error', 'msg' => '平台显示已退款，但本站这单没支付过，请人工核对'];
        }
        // 平台那边钱已经退了（多为管理员在平台后台手工操作），本站同步状态并回收权益
        $notes = pay_reclaim_benefits($o);
        update_row('ai_orders', (int)$o['id'], [
            'status'       => 'refunded',
            'refund_state' => 'done',
            'refund_at'    => now_str(),
            'refund_note'  => mb_substr('查单发现平台已退款，已自动同步；' . implode('，', $notes), 0, 250),
            'updated_at'   => now_str(),
        ]);
        pay_event((int)$o['id'], 'refund_sync', (string)$o['out_trade_no'], true, (int)$o['amount_cents'],
            '查单发现平台已退款，已同步本站状态；' . implode('，', $notes), [], client_ip());
        audit('pay_refund_sync', 'order=' . (int)$o['id']);
        return ['ok' => true, 'state' => 'refunded', 'msg' => '平台已退款，本站已同步；' . implode('，', $notes)];
    }

    if (empty($q['paid'])) {
        pay_event((int)$o['id'], 'query', (string)$o['out_trade_no'], true, 0, '远端未支付', [], client_ip());
        return ['ok' => true, 'state' => 'pending', 'msg' => '平台显示还未支付'];
    }
    if ($st === 'paid') {
        return ['ok' => true, 'state' => 'paid', 'msg' => '已支付，平台状态正常'];
    }
    $cents = pay_cents((string)$q['money']);
    if ($cents >= 0 && $cents !== (int)$o['amount_cents']) {
        pay_event((int)$o['id'], 'query', (string)$o['out_trade_no'], true, $cents, '查单金额不符，未发货', [], client_ip());
        return ['ok' => false, 'state' => 'mismatch', 'msg' => '平台金额与订单不符，已记录，请联系管理员'];
    }
    ai_order_pay((int)$o['id'], (string)$q['trade_no'], 'epay');
    pay_event((int)$o['id'], 'query', (string)$o['out_trade_no'], true, $cents, '查单确认已支付，已补发货', [], client_ip());
    return ['ok' => true, 'state' => 'paid', 'msg' => '已确认支付并发货'];
}

/**
 * 退款（先退钱，再尽可能回收权益）。
 * 回收策略：积分单余额够就扣回；月付单把对应的 user_plans 置为失效；不够/找不到就留痕说明。
 */
function pay_refund(int $orderId, string $reason = ''): array
{
    $o = pay_order_get($orderId);
    if (!$o) {
        throw new PayError('no_order', '订单不存在');
    }
    if ((string)$o['status'] !== 'paid') {
        throw new PayError('bad_status', '只有已支付的订单能退款');
    }
    $c = pay_conf();
    if ($c['pid'] === '' || $c['key'] === '') {
        throw new PayError('config', '支付未配置，无法调退款接口');
    }
    $gw = pay_gateway($c);
    $r = $gw->refund((string)$o['out_trade_no'], pay_money((int)$o['amount_cents']), (string)($o['transaction_id'] ?? ''));
    if (empty($r['ok'])) {
        $err = (string)($r['error'] ?? '退款失败');
        $raw = trim((string)($r['raw'] ?? ''));
        pay_event($orderId, 'refund', (string)$o['out_trade_no'], false, (int)$o['amount_cents'], '退款失败：' . ($raw !== '' ? $raw : $err), [], client_ip());
        // 平台侧没开退款权限这类问题，代码解决不了，要引导管理员去开通或用「线下已退」
        throw new PayError('refund', $err);
    }

    $note = pay_reclaim_benefits($o);
    if (!empty($r['already'])) {
        $note[] = '平台显示该单此前已退款';
    }

    update_row('ai_orders', $orderId, [
        'status'       => 'refunded',
        'refund_state' => 'done',
        'refund_at'    => now_str(),
        'refund_note'  => mb_substr('在线退款成功；' . implode('，', $note), 0, 250),
        'note'         => mb_substr(trim('退款：' . $reason . '；' . implode('，', $note)), 0, 250),
        'updated_at'   => now_str(),
    ]);
    audit('pay_refund', 'order=' . $orderId . ' amount=' . (int)$o['amount_cents']);
    pay_event($orderId, 'refund', (string)$o['out_trade_no'], true, (int)$o['amount_cents'], '退款成功；' . implode('，', $note), [], client_ip());

    return ['ok' => true, 'note' => implode('，', $note)];
}

/**
 * 线下已退款：不调平台接口，直接把订单标记为已退款并回收权益。
 *
 * 用于平台没给商户号开退款 API（提示「商户未开启退款API接口」）、接口报错、
 * 或者管理员已经在易支付后台 / 微信里手工把钱退了的情况。只做记账，
 * 钱是否真退了由点按钮的人自己确认。
 */
function pay_refund_manual(int $orderId, string $note = '', string $actor = ''): array
{
    $o = pay_order_get($orderId);
    if (!$o) {
        throw new PayError('no_order', '订单不存在');
    }
    if ((string)$o['status'] === 'refunded') {
        return ['ok' => true, 'note' => '这单已经标记为已退款了，无需重复操作'];
    }
    if ((string)$o['status'] !== 'paid') {
        throw new PayError('bad_status', '只有已支付的订单能标记退款');
    }

    $notes = pay_reclaim_benefits($o);
    $why = trim($note);
    if ($why === '') {
        $why = '平台未开放退款接口或已线下退款';
    }
    $tail = implode('，', $notes);
    if ($actor !== '') {
        $tail .= '；操作人 ' . $actor;
    }

    update_row('ai_orders', $orderId, [
        'status'       => 'refunded',
        'refund_state' => 'done',
        'refund_at'    => now_str(),
        'refund_note'  => mb_substr('管理员标记「线下已退」（未走平台接口）：' . $why . '；' . $tail, 0, 250),
        'note'         => mb_substr(trim('退款（线下）：' . $why . '；' . $tail), 0, 250),
        'updated_at'   => now_str(),
    ]);
    audit('pay_refund_manual', 'order=' . $orderId . ' amount=' . (int)$o['amount_cents']);
    pay_event($orderId, 'refund_manual', (string)($o['out_trade_no'] ?? ''), true, (int)$o['amount_cents'], '管理员标记线下已退款：' . $why . '；' . $tail, [], client_ip());

    return ['ok' => true, 'note' => '已标记为已退款（钱需你确认已在平台/线下退回）；' . $tail, 'online' => false];
}

/**
 * 这笔订单实际发出去的积分。
 * 订单表的 credits 是「套餐应发」，真正的发放量记在 user_plans.credits_granted 上
 * （月付单也会发积分，2.0.3 之前退款只把套餐置失效、积分漏扣，就是没看这里）。
 */
function pay_order_granted_credits(array $o): int
{
    $sum = (int)val(
        'SELECT COALESCE(SUM(credits_granted),0) FROM ' . tbl('user_plans') . ' WHERE user_id = ? AND note = ?',
        [(int)$o['user_id'], '订单 #' . (int)$o['id']],
        0
    );
    // 没有发放记录时退回订单上的应发值（老数据 / 手工补单）
    return $sum > 0 ? $sum : (int)($o['credits'] ?? 0);
}

/**
 * 回收订单带来的权益（退款成功后调用）。
 *
 * 月付单同样要扣积分：套餐送的积分是实打实的权益，只把 user_plans 置失效、
 * 积分留着，用户就能「买月付 → 退款 → 白拿积分」。
 * 余额不够时能扣多少扣多少，差额记进 audit，后台对账页会标红。
 *
 * @return string[] 说明短语
 */
function pay_reclaim_benefits(array $o): array
{
    $orderId = (int)$o['id'];
    $uid = (int)$o['user_id'];
    $note = [];

    if ((string)$o['kind'] !== 'credits') {
        q(
            'UPDATE ' . tbl('user_plans') . ' SET status = 0 WHERE user_id = ? AND plan_id = ? AND note = ?',
            [$uid, (int)$o['plan_id'], '订单 #' . $orderId]
        );
        $note[] = '月付权益已失效';
    }

    $granted = pay_order_granted_credits($o);
    if ($granted <= 0) {
        return $note;
    }

    $bal = ai_balance($uid);
    $take = min($granted, max(0, $bal));
    if ($take > 0) {
        $r = ai_credit_move($uid, -$take, 'refund_out', [
            'ref_type' => 'order',
            'ref_id'   => $orderId,
            'note'     => '订单退款，扣回赠送积分' . ($take < $granted ? '（余额只够扣 ' . $take . '）' : ''),
        ]);
        if (!empty($r['ok'])) {
            $note[] = '已扣回 ' . $take . ' 积分';
        } else {
            $note[] = '扣回积分失败：' . (string)($r['error'] ?? '未知原因');
            $take = 0;
        }
    }

    $short = $granted - $take;
    if ($short > 0) {
        $note[] = '另有 ' . $short . ' 积分已被消耗，无法扣回（需人工追缴）';
        audit('pay_refund_short', 'order=' . $orderId . ' user=' . $uid . ' short=' . $short);
    }
    return $note;
}

/**
 * 补扣：针对「已退款但积分没收回」的历史订单（2.0.4 之前的月付单就是这样漏的）。
 * 只扣净额（实发 − 已扣回），反复点也不会重复扣；余额不够就扣到 0，剩下的记进审计。
 */
function pay_reclaim_shortfall(int $orderId, string $actor = ''): array
{
    $o = pay_order_get($orderId);
    if (!$o) {
        throw new PayError('no_order', '订单不存在');
    }
    if ((string)$o['status'] !== 'refunded') {
        throw new PayError('bad_status', '只有已退款的订单需要补扣积分');
    }
    $uid = (int)$o['user_id'];
    $granted = pay_order_granted_credits($o);
    $reclaimed = (int)val(
        'SELECT COALESCE(SUM(-delta),0) FROM ' . tbl('credit_logs') . " WHERE ref_type = 'order' AND ref_id = ? AND delta < 0",
        [$orderId],
        0
    );
    $net = $granted - $reclaimed;
    if ($net <= 0) {
        return ['ok' => true, 'note' => '这单的积分已经收回，不用再扣', 'took' => 0, 'short' => 0];
    }

    $bal = ai_balance($uid);
    $take = min($net, max(0, $bal));
    if ($take > 0) {
        $r = ai_credit_move($uid, -$take, 'refund_out', [
            'ref_type' => 'order',
            'ref_id'   => $orderId,
            'note'     => '补扣退款未收回的积分' . ($take < $net ? '（余额只够扣 ' . $take . '）' : ''),
        ]);
        if (empty($r['ok'])) {
            throw new PayError('credit', '扣回失败：' . (string)($r['error'] ?? '未知原因'));
        }
    }
    $short = $net - $take;

    $tail = '补扣 ' . $take . ' 积分' . ($short > 0 ? '，另有 ' . $short . ' 已被消耗无法扣回' : '');
    update_row('ai_orders', $orderId, [
        'refund_note' => mb_substr(trim((string)($o['refund_note'] ?? '') . '；' . $tail . ($actor !== '' ? '（' . $actor . '）' : '')), 0, 250),
        'updated_at'  => now_str(),
    ]);
    audit('pay_reclaim_shortfall', 'order=' . $orderId . ' took=' . $take . ' short=' . $short . ' by=' . $actor);
    pay_event($orderId, 'reclaim', (string)$o['out_trade_no'], true, (int)$o['amount_cents'], $tail, [], client_ip());

    return ['ok' => true, 'note' => $tail, 'took' => $take, 'short' => $short];
}

/* ---------------- 退款申请（用户发起 → 后台同意 → 自动退） ---------------- */

function pay_refund_state_label(string $state): string
{
    return [
        'none'     => '',
        'pending'  => '退款申请中',
        'rejected' => '退款被驳回',
        'done'     => '已退款',
    ][$state] ?? '';
}

/** 用户对已支付订单发起退款申请（只登记，不动钱） */
function pay_refund_request(int $orderId, int $uid, string $reason): array
{
    $o = pay_order_get($orderId);
    if (!$o || (int)$o['user_id'] !== $uid) {
        throw new PayError('no_order', '订单不存在或不属于你');
    }
    if ((string)$o['status'] === 'refunded') {
        throw new PayError('bad_status', '这单已经退过款了');
    }
    if ((string)$o['status'] !== 'paid') {
        throw new PayError('bad_status', '只有已支付的订单可以申请退款');
    }
    if ((string)($o['refund_state'] ?? 'none') === 'pending') {
        throw new PayError('bad_status', '已经提交过了，正在等管理员处理');
    }
    $reason = trim($reason);
    if (mb_strlen($reason) < 4) {
        throw new PayError('bad_config', '简单写一下退款原因（至少 4 个字），方便管理员判断');
    }
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }

    update_row('ai_orders', $orderId, [
        'refund_state'  => 'pending',
        'refund_reason' => $reason,
        'refund_note'   => null,
        'refund_at'     => null,
        'updated_at'    => now_str(),
    ]);
    audit('pay_refund_request', 'order=' . $orderId . ' user=' . $uid);
    pay_event($orderId, 'refund_req', (string)($o['out_trade_no'] ?? ''), true, (int)$o['amount_cents'], '用户申请退款：' . $reason, [], client_ip());

    return ['ok' => true, 'message' => '退款申请已提交，管理员同意后会自动退回原支付渠道'];
}

/**
 * 后台同意退款申请 → 立刻退钱。
 * 在线支付（epay 且平台返回过交易号）走平台退款接口；其余（后台标记/兑换码）没有在线交易，
 * 只确认「线下已退」并回收权益，在备注里写清楚。
 */
function pay_refund_approve(int $orderId, string $note = ''): array
{
    $o = pay_order_get($orderId);
    if (!$o) {
        throw new PayError('no_order', '订单不存在');
    }
    if ((string)$o['status'] === 'refunded') {
        return ['ok' => true, 'note' => '这单已经退过款了', 'online' => false];
    }
    if ((string)$o['status'] !== 'paid') {
        throw new PayError('bad_status', '只有已支付的订单能退款');
    }
    $reason = trim((string)($o['refund_reason'] ?? ''));
    $isOnline = (string)$o['channel'] === 'epay' && (string)($o['out_trade_no'] ?? '') !== '';

    if ($isOnline) {
        $c = pay_conf();
        if ($c['pid'] === '' || $c['key'] === '') {
            throw new PayError('config', '支付未配置，无法自动退款；先驳回申请，线下处理完再手动标记');
        }
        $r = pay_refund($orderId, trim('用户申请：' . $reason . ($note !== '' ? '（' . $note . '）' : '')));
        update_row('ai_orders', $orderId, [
            'refund_note' => mb_substr('已通过申请，在线退款成功；' . (string)$r['note'], 0, 250),
        ]);
        pay_event($orderId, 'refund_ok', (string)$o['out_trade_no'], true, (int)$o['amount_cents'], '管理员同意退款申请，已自动退款', [], client_ip());
        audit('pay_refund_approve', 'order=' . $orderId);
        return ['ok' => true, 'note' => (string)$r['note'], 'online' => true];
    }

    // 线下单：没有在线交易，回收权益并标记已退款，提示管理员自己转账
    $notes = pay_reclaim_benefits($o);
    update_row('ai_orders', $orderId, [
        'status'       => 'refunded',
        'refund_state' => 'done',
        'refund_at'    => now_str(),
        'refund_note'  => mb_substr('非在线支付订单，请在后台/线下把 ¥' . number_format((int)$o['amount_cents'] / 100, 2) . ' 退给用户；' . implode('，', $notes), 0, 250),
        'note'         => mb_substr(trim('退款：' . $reason . '；' . implode('，', $notes)), 0, 250),
        'updated_at'   => now_str(),
    ]);
    pay_event($orderId, 'refund_ok', (string)($o['out_trade_no'] ?? ''), true, (int)$o['amount_cents'], '非在线订单，管理员确认线下退款', [], client_ip());
    audit('pay_refund_approve_offline', 'order=' . $orderId);
    return ['ok' => true, 'note' => '已标记为已退款（非在线支付，钱需要你线下退）', 'online' => false];
}

/** 后台驳回退款申请 */
function pay_refund_reject(int $orderId, string $note = ''): array
{
    $o = pay_order_get($orderId);
    if (!$o) {
        throw new PayError('no_order', '订单不存在');
    }
    if ((string)($o['refund_state'] ?? 'none') !== 'pending') {
        throw new PayError('bad_status', '这单没有待处理的退款申请');
    }
    $note = mb_substr(trim($note) !== '' ? trim($note) : '不符合退款条件', 0, 200);
    update_row('ai_orders', $orderId, [
        'refund_state' => 'rejected',
        'refund_note'  => '驳回：' . $note,
        'refund_at'    => now_str(),
        'updated_at'   => now_str(),
    ]);
    audit('pay_refund_reject', 'order=' . $orderId);
    pay_event($orderId, 'refund_rej', (string)($o['out_trade_no'] ?? ''), true, (int)$o['amount_cents'], '驳回退款申请：' . $note, [], client_ip());
    return ['ok' => true, 'message' => '已驳回'];
}

/* ---------------- 事件日志 ---------------- */

/**
 * 记一条支付事件。payload 只留可核对的内容，截断防爆库。
 * 每 50 次顺手清掉旧记录，避免长期运行把表撑大。
 */
function pay_event(
    int $orderId,
    string $kind,
    string $outTradeNo,
    bool $signOk,
    int $cents,
    string $result,
    array $payload = [],
    string $ip = ''
): void {
    try {
        insert_row('pay_events', [
            'order_id'     => $orderId,
            'out_trade_no' => mb_substr($outTradeNo, 0, 64),
            'kind'         => mb_substr($kind, 0, 16),
            'gateway'      => 'epay',
            'sign_ok'      => $signOk ? 1 : 0,
            'amount_cents' => $cents,
            'result'       => mb_substr($result, 0, 255),
            'payload'      => $payload ? mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '', 0, 4000) : '',
            'ip'           => mb_substr($ip, 0, 45),
            'created_at'   => now_str(),
        ]);
        if (random_int(1, 50) === 1) {
            $max = (int)val('SELECT MAX(id) FROM ' . tbl('pay_events'), [], 0);
            if ($max > 2000) {
                q('DELETE FROM ' . tbl('pay_events') . ' WHERE id <= ?', [$max - 2000]);
            }
        }
    } catch (Throwable $e) {
        // 日志写失败不能影响收款
    }
}
