<?php
declare(strict_types=1);

/**
 * 支付对账：把「收了多少钱」和「发出多少货」对起来。
 *
 * 为什么需要：退款只退钱、不回收权益，或者发货失败没人发现，都会变成
 * 「用户白拿积分」。订单表本身看不出这些，必须拿 user_plans（实发）和
 * credit_logs（已扣回）跟订单一一对照，才能算出每单的净发放。
 *
 * 口径：
 *   应发  = ai_orders.credits（套餐配置的赠送积分）
 *   实发  = user_plans 里 note 为「订单 #id」的 credits_granted 之和
 *   已扣回 = credit_logs 里 ref_type=order、ref_id=id、delta<0 的绝对值之和
 *   净发放 = 实发 - 已扣回（>0 表示这份权益还在用户手里）
 */

/** 异常说明（code => [标签, 严重度 1=要处理 2=观察]) */
function pay_issue_map(): array
{
    return [
        'unreclaimed'   => ['退款未收回积分', 1],
        'plan_active'   => ['退款后套餐仍有效', 1],
        'no_grant'      => ['已支付未发货', 1],
        'multi_grant'   => ['同一单重复发货', 1],
        'short_grant'   => ['发货数量不足', 1],
        'no_txn'        => ['缺平台流水号', 2],
        'stale_pending' => ['待支付挂太久', 2],
    ];
}

/**
 * 订单 + 发货 + 回收 一起算。
 * @return array{rows:array,summary:array}
 */
function pay_ledger(int $limit = 200): array
{
    $limit = max(20, min(500, $limit));
    $rows = all(
        'SELECT o.*, u.username, p.name AS plan_name FROM ' . tbl('ai_orders') . ' o
         LEFT JOIN ' . tbl('users') . ' u ON u.id = o.user_id
         LEFT JOIN ' . tbl('ai_plans') . ' p ON p.id = o.plan_id
         ORDER BY o.id DESC LIMIT ' . $limit
    );

    // 实发：按 user_plans.note 里的订单号归集（不同用户不会撞号，订单 id 全局唯一）
    $grant = [];
    $grantCnt = [];
    $planActive = [];
    foreach (all('SELECT user_id, note, credits_granted, status FROM ' . tbl('user_plans') . " WHERE note LIKE '订单 #%'") as $r) {
        if (!preg_match('/^订单 #(\d+)/u', (string)$r['note'], $m)) {
            continue;
        }
        $id = (int)$m[1];
        $grant[$id] = ($grant[$id] ?? 0) + (int)$r['credits_granted'];
        $grantCnt[$id] = ($grantCnt[$id] ?? 0) + 1;
        if ((int)$r['status'] === 1) {
            $planActive[$id] = true;
        }
    }

    // 已扣回：退款时记的负数流水
    $back = [];
    foreach (all('SELECT ref_id, SUM(-delta) AS s FROM ' . tbl('credit_logs') . " WHERE ref_type = 'order' AND delta < 0 GROUP BY ref_id") as $r) {
        $back[(int)$r['ref_id']] = (int)$r['s'];
    }

    $now = time();
    $sum = [
        'total' => 0, 'paid' => 0, 'refunded' => 0, 'pending' => 0, 'closed' => 0,
        'amount_paid' => 0, 'amount_refunded' => 0,
        'granted' => 0, 'reclaimed' => 0, 'net' => 0,
        'abnormal' => 0, 'by_code' => [],
    ];
    foreach ($rows as &$o) {
        $id = (int)$o['id'];
        $st = (string)$o['status'];
        $g = (int)($grant[$id] ?? 0);
        $bk = (int)($back[$id] ?? 0);
        $net = $g - $bk;
        $want = (int)($o['credits'] ?? 0);

        $issues = [];
        if ($st === 'refunded') {
            if ($net > 0) {
                $issues[] = ['unreclaimed', '还差 ' . $net . ' 积分没收回'];
            }
            if (!empty($planActive[$id])) {
                $issues[] = ['plan_active', '套餐仍是有效状态'];
            }
        } elseif ($st === 'paid') {
            if ($want > 0 && $g === 0) {
                $issues[] = ['no_grant', '应发 ' . $want . '，实际没发'];
            } elseif ($want > 0 && $g < $want) {
                $issues[] = ['short_grant', '应发 ' . $want . '，实发 ' . $g];
            }
            if ((int)($grantCnt[$id] ?? 0) > 1) {
                $issues[] = ['multi_grant', '发了 ' . (int)$grantCnt[$id] . ' 次'];
            }
            if ((string)$o['channel'] === 'epay' && trim((string)($o['transaction_id'] ?? '')) === '') {
                $issues[] = ['no_txn', '没有 trade_no，退款可能定位不到'];
            }
        } elseif ($st === 'pending') {
            $t = (int)strtotime((string)$o['created_at']);
            if ($t > 0 && $now - $t > 7200) {
                $issues[] = ['stale_pending', '下单已 ' . (int)ceil(($now - $t) / 3600) . ' 小时未支付'];
            }
        }

        $o['granted'] = $g;
        $o['reclaimed'] = $bk;
        $o['net'] = $net;
        $o['issues'] = $issues;

        $sum['total']++;
        if (isset($sum[$st])) {
            $sum[$st]++;
        }
        if ($st === 'paid' || $st === 'refunded') {
            $sum['granted'] += $g;
            $sum['reclaimed'] += $bk;
            $sum['net'] += $net;
        }
        if ($st === 'paid') {
            $sum['amount_paid'] += (int)$o['amount_cents'];
        }
        if ($st === 'refunded') {
            $sum['amount_refunded'] += (int)$o['amount_cents'];
        }
        if ($issues) {
            $sum['abnormal']++;
            foreach ($issues as $it) {
                $sum['by_code'][$it[0]] = ($sum['by_code'][$it[0]] ?? 0) + 1;
            }
        }
    }
    unset($o);

    return ['rows' => $rows, 'summary' => $sum];
}

/** 积分流水（含用户与关联单号），给后台核对「谁在加分、谁在扣分」 */
function pay_credit_logs(int $limit = 200): array
{
    $limit = max(20, min(500, $limit));
    return all(
        'SELECT l.*, u.username FROM ' . tbl('credit_logs') . ' l
         LEFT JOIN ' . tbl('users') . ' u ON u.id = l.user_id
         ORDER BY l.id DESC LIMIT ' . $limit
    );
}

/** 积分变动原因的中文名 */
function pay_credit_reason_label(string $reason): string
{
    return [
        'plan_grant'  => '套餐赠送',
        'redeem'      => '兑换码',
        'ai_call'     => 'AI 调用',
        'refund'      => '调用失败退回',
        'refund_out'  => '退款扣回',
        'admin'       => '管理员调整',
    ][$reason] ?? $reason;
}
