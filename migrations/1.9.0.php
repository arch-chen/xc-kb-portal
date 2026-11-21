<?php
declare(strict_types=1);

/**
 * 1.9.0：在线支付模块（彩虹易支付 / 其他易支付同协议）。
 *
 * 改动：
 *   1. ai_orders 加 pay_method（alipay/wxpay/qqpay），记录用户选的是哪种支付方式
 *   2. 新建 pay_events：发起/回调/查单/退款每次都留痕，排查「付了钱没到账」全靠它
 *   3. 补 out_trade_no 索引：回调按商户单号查订单，没索引会全表扫
 *   4. 种支付设置项，默认关闭（管理员填了商户号与密钥再打开）
 *
 * 升级时跑的是旧代码，这里不调用 1.9.0 新增的任何函数。
 */
return function (PDO $pdo): void {
    $my = function_exists('db_driver') && db_driver() === 'mysql';
    $T = function_exists('db_prefix') ? db_prefix() : 'kb_';

    $PK   = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $INT  = $my ? 'INT' : 'INTEGER';
    $TINY = $my ? 'TINYINT' : 'INTEGER';
    $TEXT = $my ? 'LONGTEXT' : 'TEXT';
    $VC   = function (int $n) use ($my) { return $my ? "VARCHAR($n)" : 'TEXT'; };
    $DT   = $my ? 'DATETIME' : 'TEXT';
    $ENG  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    // 1) 订单加支付方式列
    try {
        $pdo->exec("ALTER TABLE {$T}ai_orders ADD COLUMN pay_method {$VC(16)} NULL");
    } catch (Throwable $e) {
        // 列已存在（重装/半途中断）跳过
    }

    // 2) 支付事件流水
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$T}pay_events (
        id $PK,
        order_id $INT NOT NULL DEFAULT 0,
        out_trade_no {$VC(64)} NULL,
        kind {$VC(16)} NOT NULL DEFAULT 'notify',
        gateway {$VC(16)} NULL,
        sign_ok $TINY NOT NULL DEFAULT 0,
        amount_cents $INT NOT NULL DEFAULT 0,
        result {$VC(255)} NULL,
        payload $TEXT NULL,
        ip {$VC(45)} NULL,
        created_at $DT NULL
    )$ENG");

    // 3) 索引（MySQL 不支持 IF NOT EXISTS，逐个 try；索引名带表前缀，与 schema_install 保持一致）
    foreach ([
        "CREATE INDEX {$T}idx_ai_orders_no ON {$T}ai_orders (out_trade_no)",
        "CREATE INDEX {$T}idx_pay_events_order ON {$T}pay_events (order_id)",
        "CREATE INDEX {$T}idx_pay_events_time ON {$T}pay_events (created_at)",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // 已存在就跳过
        }
    }

    // 4) 支付设置项（默认关闭，避免「开关打开但没配商户号」导致前台报错）
    $defaults = [
        'pay_enabled'      => '0',
        'pay_gateway'      => 'epay',
        'pay_api'          => 'https://pay.cfxcc.cn',
        'pay_methods'      => 'alipay,wxpay',
        'pay_use_mapi'     => '1',
        'pay_title_prefix' => '知识库 · ',
    ];
    foreach ($defaults as $k => $v) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM {$T}settings WHERE k = ?");
            $st->execute([$k]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO {$T}settings (k, v) VALUES (?, ?)")->execute([$k, $v]);
            }
        } catch (Throwable $e) {
            // 设置表异常不该让升级整批失败
        }
    }
};
