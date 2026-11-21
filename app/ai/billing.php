<?php
declare(strict_types=1);

/**
 * 计费与权益层。
 *
 * 口径（按需求确认）：
 *  - 系统模型：按次固定积分，调用前预扣，失败全额退回。
 *  - 用户自带模型：不扣积分，属于月付套餐功能，只受「槽位」和「每日次数」限制。
 *  - 管理员：**前台与普通用户同权同价**（2.3.0）。管理员要看全站内容、
 *    给自己加积分 / 开套餐，都从「管理后台 → AI 与套餐」走；
 *    在前台（AI 工作台 / AI 助手）里余额不足照样被门禁拦下，不再享免单。
 *
 * 余额只有 credit_wallets 一个来源，任何变动都必须写 credit_logs，便于对账。
 */

function ai_on(): bool
{
    return (string)setting_get('ai_enabled', '1') === '1';
}

/** 事务包装：SQLite 用 BEGIN IMMEDIATE，避免并发下两个请求都读到旧余额。支持嵌套（内层不提交） */
function ai_tx(callable $fn)
{
    static $depth = 0;
    $pdo = db();
    $sqlite = db_driver() === 'sqlite';
    if ($depth === 0) {
        if ($sqlite) {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
    }
    $depth++;
    try {
        $r = $fn();
        $depth--;
        if ($depth === 0) {
            if ($sqlite) {
                $pdo->exec('COMMIT');
            } else {
                $pdo->commit();
            }
        }
        return $r;
    } catch (Throwable $e) {
        $depth--;
        if ($depth === 0) {
            try {
                $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
            } catch (Throwable $x) {
            }
        }
        throw $e;
    }
}

function ai_ensure_wallet(int $uid): void
{
    if ($uid <= 0) {
        return;
    }
    $n = (int)val('SELECT COUNT(*) FROM ' . tbl('credit_wallets') . ' WHERE user_id = ?', [$uid], 0);
    if ($n > 0) {
        return;
    }
    try {
        insert_row('credit_wallets', [
            'user_id' => $uid, 'credits' => 0, 'total_in' => 0, 'total_out' => 0, 'updated_at' => now_str(),
        ]);
    } catch (Throwable $e) {
        // 并发下另一个请求已经插入了，忽略
    }
}

function ai_balance(int $uid): int
{
    if ($uid <= 0) {
        return 0;
    }
    return (int)val('SELECT credits FROM ' . tbl('credit_wallets') . ' WHERE user_id = ?', [$uid], 0);
}

function ai_wallet(int $uid): array
{
    ai_ensure_wallet($uid);
    $r = one('SELECT * FROM ' . tbl('credit_wallets') . ' WHERE user_id = ?', [$uid]);
    return [
        'credits'   => (int)($r['credits'] ?? 0),
        'total_in'  => (int)($r['total_in'] ?? 0),
        'total_out' => (int)($r['total_out'] ?? 0),
        'updated_at' => $r['updated_at'] ?? null,
    ];
}

/**
 * 积分变动（正数入账 / 负数消耗）。扣减时用 SQL 条件保证不会扣成负数。
 * 返回 ['ok'=>bool,'balance'=>int,'error'=>string]
 */
function ai_credit_move(int $uid, int $delta, string $reason, array $opt = []): array
{
    if ($uid <= 0 || $delta === 0) {
        return ['ok' => false, 'balance' => ai_balance($uid), 'error' => '无效的积分变动'];
    }
    ai_ensure_wallet($uid);

    return ai_tx(function () use ($uid, $delta, $reason, $opt) {
        $now = now_str();
        if ($delta > 0) {
            q('UPDATE ' . tbl('credit_wallets') . '
               SET credits = credits + ?, total_in = total_in + ?, updated_at = ?
               WHERE user_id = ?', [$delta, $delta, $now, $uid]);
        } else {
            $need = -$delta;
            // 条件更新：余额不够时 affected=0，绝不会扣成负数
            $stmt = q('UPDATE ' . tbl('credit_wallets') . '
                       SET credits = credits - ?, total_out = total_out + ?, updated_at = ?
                       WHERE user_id = ? AND credits >= ?', [$need, $need, $now, $uid, $need]);
            if ((int)$stmt->rowCount() === 0) {
                $bal = (int)val('SELECT credits FROM ' . tbl('credit_wallets') . ' WHERE user_id = ?', [$uid], 0);
                return ['ok' => false, 'balance' => $bal, 'error' => '积分不足，还需要 ' . max(0, $need - $bal) . ' 积分'];
            }
        }

        $balance = (int)val('SELECT credits FROM ' . tbl('credit_wallets') . ' WHERE user_id = ?', [$uid], 0);
        insert_row('credit_logs', [
            'user_id'   => $uid,
            'delta'     => $delta,
            'balance'   => $balance,
            'reason'    => $reason,
            'model_id'  => (int)($opt['model_id'] ?? 0),
            'ref_type'  => (string)($opt['ref_type'] ?? ''),
            'ref_id'    => (int)($opt['ref_id'] ?? 0),
            'tokens_in' => (int)($opt['tokens_in'] ?? 0),
            'tokens_out' => (int)($opt['tokens_out'] ?? 0),
            'note'      => mb_substr((string)($opt['note'] ?? ''), 0, 200),
            'created_at' => $now,
        ]);
        return ['ok' => true, 'balance' => $balance, 'error' => ''];
    });
}

/** 当前有效的月付套餐（过期的不算），返回 user_plans 行 + 套餐参数 */
function ai_active_plan(int $uid): ?array
{
    if ($uid <= 0) {
        return null;
    }
    $rows = all(
        'SELECT up.*, p.name AS plan_name, p.custom_slots, p.daily_calls, p.all_models, p.kind AS plan_kind
         FROM ' . tbl('user_plans') . ' up
         INNER JOIN ' . tbl('ai_plans') . ' p ON p.id = up.plan_id
         WHERE up.user_id = ? AND up.status = 1 AND up.kind = ?
         ORDER BY up.id DESC LIMIT 5',
        [$uid, 'monthly']
    );
    $now = time();
    foreach ($rows as $r) {
        $exp = (string)($r['expires_at'] ?? '');
        if ($exp === '' || strtotime($exp) > $now) {
            return $r;
        }
    }
    return null;
}

/** 今日已用次数（只算成功调用，失败的退款不算） */
function ai_daily_used(int $uid): int
{
    $start = date('Y-m-d 00:00:00');
    return (int)val(
        'SELECT COUNT(*) FROM ' . tbl('ai_calls') . ' WHERE user_id = ? AND created_at >= ? AND status = ?',
        [$uid, $start, 'ok'],
        0
    );
}

/** 用户已添加的自有模型数量 */
function ai_custom_slots_used(int $uid): int
{
    return (int)val('SELECT COUNT(*) FROM ' . tbl('ai_models') . ' WHERE owner_id = ?', [$uid], 0);
}

/** 汇总一个用户当前的 AI 权益 */
function ai_entitlement(int $uid): array
{
    $w = ai_wallet($uid);
    $plan = ai_active_plan($uid);
    $slots = $plan ? (int)$plan['custom_slots'] : 0;
    $daily = $plan ? (int)$plan['daily_calls'] : 0;

    return [
        'credits'      => $w['credits'],
        'active'       => $plan !== null,
        'plan'         => $plan ? [
            'id'          => (int)$plan['plan_id'],
            'name'        => (string)$plan['plan_name'],
            'expires_at'  => $plan['expires_at'],
        ] : null,
        'custom_slots' => $slots,
        'custom_used'  => ai_custom_slots_used($uid),
        'daily_limit'  => $daily,
        'daily_used'   => ai_daily_used($uid),
        'all_models'   => $plan ? (int)$plan['all_models'] === 1 : true,
    ];
}

/**
 * 判断某用户能不能用某个模型。返回 ['ok'=>bool,'reason'=>string,'free'=>bool]
 * free=true 表示这次调用不扣积分（自有模型，或模型单价为 0）。
 *
 * 2.3.0：管理员不再在这里免单。前台就是普通用户，一样受积分 / 套餐 / 每日次数约束；
 * 想不花钱调试，用「管理后台 → AI 与套餐」给自己加积分，或接一个自有模型。
 */
function ai_can_use_model(array $user, array $model, array $ent): array
{
    $uid = (int)$user['id'];

    if ((int)$model['enabled'] !== 1) {
        return ['ok' => false, 'reason' => '这个模型已停用', 'free' => false];
    }

    $own = (int)($model['owner_id'] ?? 0);
    if ($own !== 0 && $own !== $uid) {
        return ['ok' => false, 'reason' => '这不是你的模型', 'free' => false];
    }

    if ($ent['daily_limit'] > 0 && $ent['daily_used'] >= $ent['daily_limit']) {
        return ['ok' => false, 'reason' => '今天的调用次数已用完（上限 ' . $ent['daily_limit'] . ' 次），明天再来或升级套餐', 'free' => false];
    }

    if ($own > 0) {
        if (!$ent['active']) {
            return ['ok' => false, 'reason' => '自带模型属于月付套餐功能，请先开通套餐', 'free' => false];
        }
        return ['ok' => true, 'reason' => '', 'free' => true];
    }

    if ($ent['active'] && !$ent['all_models']) {
        return ['ok' => false, 'reason' => '当前套餐不包含系统模型，请使用你自己的模型', 'free' => false];
    }

    $price = (int)$model['credits'];
    if ($price > 0 && $ent['credits'] < $price) {
        return [
            'ok' => false,
            'reason' => '积分不足：这次需要 ' . $price . ' 积分，当前余额 ' . $ent['credits'] . '，请先充值',
            'free' => false,
        ];
    }
    return ['ok' => true, 'reason' => '', 'free' => $price === 0];
}

/* ---------------- 套餐 / 订单 / 兑换码 ---------------- */

/** 给用户开通套餐（月付叠加时长，积分包直接加积分） */
function ai_grant_plan(int $uid, int $planId, string $note = '', string $channel = 'manual'): array
{
    $plan = one('SELECT * FROM ' . tbl('ai_plans') . ' WHERE id = ?', [$planId]);
    if (!$plan) {
        throw new RuntimeException('套餐不存在');
    }
    if ($uid <= 0) {
        throw new RuntimeException('用户不存在');
    }
    $kind = (string)$plan['kind'];
    $now = now_str();

    ai_tx(function () use ($uid, $plan, $kind, $now, $note, $channel) {
        $planId = (int)$plan['id'];
        if ($kind === 'credits') {
            $c = (int)$plan['credits'];
            if ($c > 0) {
                ai_credit_move($uid, $c, 'plan_grant', [
                    'ref_type' => 'plan', 'ref_id' => $planId, 'note' => '购买套餐：' . $plan['name'],
                ]);
            }
            insert_row('user_plans', [
                'user_id' => $uid, 'plan_id' => $planId, 'kind' => 'credits',
                'credits_granted' => $c, 'starts_at' => $now, 'expires_at' => null,
                'status' => 1, 'note' => mb_substr($note, 0, 200), 'created_at' => $now,
            ]);
            return;
        }

        // 月付：续费就往后顺延
        $days = (int)$plan['days'] > 0 ? (int)$plan['days'] : 30;
        $cur = ai_active_plan($uid);
        $base = ($cur && !empty($cur['expires_at'])) ? strtotime((string)$cur['expires_at']) : time();
        if ($base < time()) {
            $base = time();
        }
        $expires = date('Y-m-d H:i:s', $base + $days * 86400);
        insert_row('user_plans', [
            'user_id' => $uid, 'plan_id' => $planId, 'kind' => 'monthly',
            'credits_granted' => (int)$plan['credits'], 'starts_at' => $now, 'expires_at' => $expires,
            'status' => 1, 'note' => mb_substr($note !== '' ? $note : ('渠道：' . $channel), 0, 200), 'created_at' => $now,
        ]);
        if ((int)$plan['credits'] > 0) {
            ai_credit_move($uid, (int)$plan['credits'], 'plan_grant', [
                'ref_type' => 'plan', 'ref_id' => $planId, 'note' => '月付赠送：' . $plan['name'],
            ]);
        }
    });

    audit('ai_grant_plan', 'user=' . $uid . ' plan=' . $planId . ' channel=' . $channel);
    return ['ok' => true, 'plan' => $plan];
}

/**
 * 商户订单号：纯字母数字 22 位。
 * 不用横杠/下划线，是因为部分支付通道对 out_trade_no 的字符集有限制。
 */
function ai_order_no(): string
{
    return 'KB' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
}

/** 生成一个订单（pending）。$outTradeNo 由支付模块传入，保证与发给平台的单号一致 */
function ai_order_create(int $uid, int $planId, string $channel = 'manual', ?string $outTradeNo = null): int
{
    $plan = one('SELECT * FROM ' . tbl('ai_plans') . ' WHERE id = ? AND enabled = 1', [$planId]);
    if (!$plan) {
        throw new RuntimeException('套餐不存在或已下架');
    }
    $now = now_str();
    return insert_row('ai_orders', [
        'user_id' => $uid, 'plan_id' => (int)$plan['id'], 'kind' => (string)$plan['kind'],
        'amount_cents' => (int)$plan['price_cents'], 'credits' => (int)$plan['credits'],
        'channel' => $channel,
        'out_trade_no' => $outTradeNo !== null && $outTradeNo !== '' ? $outTradeNo : ai_order_no(),
        'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
    ]);
}

/** 标记订单已支付并发货（幂等：已支付的直接返回成功） */
function ai_order_pay(int $orderId, string $transactionId = '', string $channel = ''): array
{
    $o = one('SELECT * FROM ' . tbl('ai_orders') . ' WHERE id = ?', [$orderId]);
    if (!$o) {
        throw new RuntimeException('订单不存在');
    }
    if ((string)$o['status'] === 'paid') {
        return ['ok' => true, 'already' => true];
    }
    if ((string)$o['status'] !== 'pending') {
        throw new RuntimeException('订单状态是 ' . $o['status'] . '，不能标记为已支付');
    }
    $now = now_str();
    update_row('ai_orders', $orderId, [
        'status' => 'paid', 'paid_at' => $now, 'updated_at' => $now,
        'transaction_id' => mb_substr($transactionId, 0, 60),
        'channel' => $channel !== '' ? $channel : (string)$o['channel'],
    ]);
    ai_grant_plan((int)$o['user_id'], (int)$o['plan_id'], '订单 #' . $orderId, (string)$o['channel']);
    return ['ok' => true, 'already' => false];
}

/** 兑换码：一次性/多次，支持积分和套餐 */
function ai_redeem(int $uid, string $code): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        throw new RuntimeException('请输入兑换码');
    }
    $row = one('SELECT * FROM ' . tbl('redeem_codes') . ' WHERE code = ?', [$code]);
    if (!$row) {
        throw new RuntimeException('兑换码不存在');
    }
    if ((int)$row['enabled'] !== 1) {
        throw new RuntimeException('这个兑换码已停用');
    }
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
        throw new RuntimeException('兑换码已过期');
    }
    if ((int)$row['used_count'] >= (int)$row['max_uses']) {
        throw new RuntimeException('兑换码已被用完');
    }
    // 同一用户对同一码只能兑一次（多次码是给不同人用的）
    $dup = (int)val(
        'SELECT COUNT(*) FROM ' . tbl('redeem_logs') . ' WHERE code_id = ? AND user_id = ?',
        [(int)$row['id'], $uid],
        0
    );
    if ($dup > 0) {
        throw new RuntimeException('你已经兑换过这个码了');
    }

    ai_tx(function () use ($row, $uid, $code) {
        update_row('redeem_codes', (int)$row['id'], ['used_count' => (int)$row['used_count'] + 1]);
        insert_row('redeem_logs', [
            'code_id' => (int)$row['id'], 'code' => $code, 'user_id' => $uid,
            'kind' => (string)$row['kind'], 'credits' => (int)$row['credits'],
            'plan_id' => (int)$row['plan_id'], 'created_at' => now_str(),
        ]);
        if ((string)$row['kind'] === 'plan' && (int)$row['plan_id'] > 0) {
            ai_grant_plan($uid, (int)$row['plan_id'], '兑换码 ' . $code, 'redeem');
        } elseif ((int)$row['credits'] > 0) {
            ai_credit_move($uid, (int)$row['credits'], 'redeem', [
                'ref_type' => 'redeem_code', 'ref_id' => (int)$row['id'], 'note' => '兑换码 ' . $code,
            ]);
        }
    });

    audit('ai_redeem', 'user=' . $uid . ' code=' . $code);
    return [
        'ok' => true,
        'kind' => (string)$row['kind'],
        'credits' => (int)$row['credits'],
        'plan_id' => (int)$row['plan_id'],
        'balance' => ai_balance($uid),
    ];
}
