<?php
declare(strict_types=1);

/**
 * 2.0.0：AI 工作台（能动手的对话）+ 订单退款申请。
 *
 * 改动：
 *   1. 新建 ai_agent_runs / ai_agent_msgs：一次对话一个 run，每条消息/工具结果一条 msg
 *   2. ai_orders 加退款申请字段：refund_state / refund_reason / refund_note / refund_at
 *   3. 索引：按用户查会话、按 run 查消息、按退款状态筛订单
 *   4. 种 agent_fetch_max_kb（抓网页大小上限，默认 3000KB）
 *
 * 升级时跑的是旧代码，这里不调用 2.0.0 新增的任何函数。
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

    // 1) 工作台会话
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$T}ai_agent_runs (
        id $PK,
        user_id $INT NOT NULL,
        model_id $INT NOT NULL DEFAULT 0,
        title {$VC(80)} NOT NULL DEFAULT '新对话',
        status {$VC(12)} NOT NULL DEFAULT 'active',
        steps $INT NOT NULL DEFAULT 0,
        credits $INT NOT NULL DEFAULT 0,
        created_at $DT NULL,
        updated_at $DT NULL
    )$ENG");

    // 2) 会话消息（role: user / assistant / tool / notice）
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$T}ai_agent_msgs (
        id $PK,
        run_id $INT NOT NULL,
        seq $INT NOT NULL DEFAULT 0,
        role {$VC(12)} NOT NULL DEFAULT 'user',
        content $TEXT NULL,
        tool_name {$VC(40)} NULL,
        tool_args $TEXT NULL,
        tool_result $TEXT NULL,
        ok $TINY NOT NULL DEFAULT 1,
        status {$VC(12)} NOT NULL DEFAULT 'ok',
        created_at $DT NULL
    )$ENG");

    // 3) 订单退款申请字段（列已存在就跳过）
    foreach ([
        "ALTER TABLE {$T}ai_orders ADD COLUMN refund_state {$VC(12)} NOT NULL DEFAULT 'none'",
        "ALTER TABLE {$T}ai_orders ADD COLUMN refund_reason $TEXT NULL",
        "ALTER TABLE {$T}ai_orders ADD COLUMN refund_note {$VC(255)} NULL",
        "ALTER TABLE {$T}ai_orders ADD COLUMN refund_at $DT NULL",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // 已存在
        }
    }

    // 4) 索引
    foreach ([
        "CREATE INDEX {$T}idx_agent_runs_user ON {$T}ai_agent_runs (user_id, updated_at)",
        "CREATE INDEX {$T}idx_agent_msgs_run ON {$T}ai_agent_msgs (run_id, seq)",
        "CREATE INDEX {$T}idx_ai_orders_refund ON {$T}ai_orders (refund_state)",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // 已存在
        }
    }

    // 5) 设置项
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM {$T}settings WHERE k = ?");
        $ins = $pdo->prepare("INSERT INTO {$T}settings (k, v) VALUES (?, ?)");
        foreach ([['agent_fetch_max_kb', '3000'], ['agent_fetch_ssl_verify', '1']] as [$k, $v]) {
            $st->execute([$k]);
            if ((int)$st->fetchColumn() === 0) {
                $ins->execute([$k, $v]);
            }
        }
    } catch (Throwable $e) {
        // settings 表不存在（异常安装）就跳过
    }
};
