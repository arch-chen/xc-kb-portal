<?php
declare(strict_types=1);

/**
 * 1.8.0：AI 能力层（模型 / 套餐 / 积分 / 调用日志 / 兑换码 / 订单 / 定时任务占位）。
 *
 * 全新安装走 schema_install()，老站升级走这里，两边 DDL 必须保持一致。
 * 升级时跑的是旧代码，所以只用 db_prefix()/db_driver() 这种早就存在的函数。
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

    $tables = [];

    $tables['ai_models'] = "CREATE TABLE IF NOT EXISTS {$T}ai_models (
        id $PK,
        owner_id $INT NOT NULL DEFAULT 0,
        name {$VC(80)} NOT NULL,
        protocol {$VC(16)} NOT NULL DEFAULT 'openai',
        base_url {$VC(255)} NOT NULL,
        model_key {$VC(120)} NOT NULL,
        api_key $TEXT NULL,
        credits $INT NOT NULL DEFAULT 10,
        max_tokens $INT NOT NULL DEFAULT 2048,
        temperature {$VC(8)} NOT NULL DEFAULT '0.3',
        timeout $INT NOT NULL DEFAULT 120,
        note {$VC(255)} NULL,
        enabled $TINY NOT NULL DEFAULT 1,
        sort $INT NOT NULL DEFAULT 0,
        created_at $DT NULL,
        updated_at $DT NULL
    )$ENG";

    $tables['ai_plans'] = "CREATE TABLE IF NOT EXISTS {$T}ai_plans (
        id $PK,
        name {$VC(80)} NOT NULL,
        kind {$VC(12)} NOT NULL DEFAULT 'credits',
        price_cents $INT NOT NULL DEFAULT 0,
        credits $INT NOT NULL DEFAULT 0,
        days $INT NOT NULL DEFAULT 30,
        custom_slots $INT NOT NULL DEFAULT 0,
        daily_calls $INT NOT NULL DEFAULT 0,
        all_models $TINY NOT NULL DEFAULT 1,
        enabled $TINY NOT NULL DEFAULT 1,
        sort $INT NOT NULL DEFAULT 0,
        note {$VC(255)} NULL,
        created_at $DT NULL
    )$ENG";

    $tables['user_plans'] = "CREATE TABLE IF NOT EXISTS {$T}user_plans (
        id $PK,
        user_id $INT NOT NULL,
        plan_id $INT NOT NULL,
        kind {$VC(12)} NOT NULL DEFAULT 'monthly',
        credits_granted $INT NOT NULL DEFAULT 0,
        starts_at $DT NULL,
        expires_at $DT NULL,
        status $TINY NOT NULL DEFAULT 1,
        note {$VC(255)} NULL,
        created_at $DT NULL
    )$ENG";

    $tables['credit_wallets'] = "CREATE TABLE IF NOT EXISTS {$T}credit_wallets (
        user_id $INT NOT NULL PRIMARY KEY,
        credits $INT NOT NULL DEFAULT 0,
        total_in $INT NOT NULL DEFAULT 0,
        total_out $INT NOT NULL DEFAULT 0,
        updated_at $DT NULL
    )$ENG";

    $tables['credit_logs'] = "CREATE TABLE IF NOT EXISTS {$T}credit_logs (
        id $PK,
        user_id $INT NOT NULL,
        delta $INT NOT NULL,
        balance $INT NOT NULL DEFAULT 0,
        reason {$VC(24)} NOT NULL,
        model_id $INT NOT NULL DEFAULT 0,
        ref_type {$VC(24)} NULL,
        ref_id $INT NOT NULL DEFAULT 0,
        tokens_in $INT NOT NULL DEFAULT 0,
        tokens_out $INT NOT NULL DEFAULT 0,
        note {$VC(255)} NULL,
        created_at $DT NULL
    )$ENG";

    $tables['ai_calls'] = "CREATE TABLE IF NOT EXISTS {$T}ai_calls (
        id $PK,
        user_id $INT NOT NULL,
        model_id $INT NOT NULL DEFAULT 0,
        model_name {$VC(80)} NULL,
        action {$VC(24)} NOT NULL DEFAULT 'chat',
        group_id $INT NOT NULL DEFAULT 0,
        entry_id $INT NOT NULL DEFAULT 0,
        tokens_in $INT NOT NULL DEFAULT 0,
        tokens_out $INT NOT NULL DEFAULT 0,
        credits $INT NOT NULL DEFAULT 0,
        latency_ms $INT NOT NULL DEFAULT 0,
        status {$VC(12)} NOT NULL DEFAULT 'ok',
        error {$VC(255)} NULL,
        created_at $DT NULL
    )$ENG";

    $tables['redeem_codes'] = "CREATE TABLE IF NOT EXISTS {$T}redeem_codes (
        id $PK,
        code {$VC(40)} NOT NULL,
        kind {$VC(12)} NOT NULL DEFAULT 'credits',
        credits $INT NOT NULL DEFAULT 0,
        plan_id $INT NOT NULL DEFAULT 0,
        max_uses $INT NOT NULL DEFAULT 1,
        used_count $INT NOT NULL DEFAULT 0,
        expires_at $DT NULL,
        enabled $TINY NOT NULL DEFAULT 1,
        batch {$VC(40)} NULL,
        created_by $INT NOT NULL DEFAULT 0,
        created_at $DT NULL
    )$ENG";

    $tables['redeem_logs'] = "CREATE TABLE IF NOT EXISTS {$T}redeem_logs (
        id $PK,
        code_id $INT NOT NULL,
        code {$VC(40)} NULL,
        user_id $INT NOT NULL,
        kind {$VC(12)} NOT NULL,
        credits $INT NOT NULL DEFAULT 0,
        plan_id $INT NOT NULL DEFAULT 0,
        created_at $DT NULL
    )$ENG";

    $tables['ai_orders'] = "CREATE TABLE IF NOT EXISTS {$T}ai_orders (
        id $PK,
        user_id $INT NOT NULL,
        plan_id $INT NOT NULL DEFAULT 0,
        kind {$VC(12)} NOT NULL DEFAULT 'credits',
        amount_cents $INT NOT NULL DEFAULT 0,
        credits $INT NOT NULL DEFAULT 0,
        channel {$VC(16)} NOT NULL DEFAULT 'manual',
        out_trade_no {$VC(64)} NULL,
        transaction_id {$VC(64)} NULL,
        status {$VC(12)} NOT NULL DEFAULT 'pending',
        paid_at $DT NULL,
        note {$VC(255)} NULL,
        created_at $DT NULL,
        updated_at $DT NULL
    )$ENG";

    $tables['ai_tasks'] = "CREATE TABLE IF NOT EXISTS {$T}ai_tasks (
        id $PK,
        user_id $INT NOT NULL,
        name {$VC(80)} NOT NULL,
        kind {$VC(24)} NOT NULL DEFAULT 'cron_polish',
        cron_expr {$VC(40)} NULL,
        model_id $INT NOT NULL DEFAULT 0,
        prompt $TEXT NULL,
        target $TEXT NULL,
        enabled $TINY NOT NULL DEFAULT 1,
        last_run_at $DT NULL,
        next_run_at $DT NULL,
        last_status {$VC(12)} NULL,
        last_result $TEXT NULL,
        created_at $DT NULL
    )$ENG";

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // SQLite 支持 CREATE INDEX IF NOT EXISTS；MySQL 不支持，重复建索引会报错，所以按驱动分开生成并 try
    $idx = [
        'idx_ai_models_owner' => ['ai_models', '(owner_id, enabled)', false],
        'idx_credit_logs_user' => ['credit_logs', '(user_id, id)', false],
        'idx_ai_calls_user' => ['ai_calls', '(user_id, created_at)', false],
        'idx_user_plans_user' => ['user_plans', '(user_id, status)', false],
        'idx_ai_orders_user' => ['ai_orders', '(user_id, status)', false],
        'idx_redeem_logs_user' => ['redeem_logs', '(user_id)', false],
        'uq_redeem_code' => ['redeem_codes', '(code)', true],
    ];
    foreach ($idx as $name => $def) {
        [$table, $cols, $unique] = $def;
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $sql = $my
            ? "CREATE {$kind} {$T}{$name} ON {$T}{$table} {$cols}"
            : "CREATE {$kind} IF NOT EXISTS {$T}{$name} ON {$T}{$table} {$cols}";
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // 索引已存在或该驱动不支持该语法，忽略（不影响功能）
        }
    }

    // 默认套餐（管理员可改可删）
    $n = (int)$pdo->query("SELECT COUNT(*) FROM {$T}ai_plans")->fetchColumn();
    if ($n === 0) {
        $now = function_exists('now_str') ? now_str() : date('Y-m-d H:i:s');
        $ins = $pdo->prepare("INSERT INTO {$T}ai_plans
            (name, kind, price_cents, credits, days, custom_slots, daily_calls, all_models, enabled, sort, note, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute(['积分包 · 1000', 'credits', 1000, 1000, 0, 0, 0, 1, 1, 0, '一次性到账，按模型单价扣积分', $now]);
        $ins->execute(['月付 · 专业版', 'monthly', 4900, 2000, 30, 3, 300, 1, 1, 1, '每月送 2000 积分，可加 3 个自有模型，每日 300 次', $now]);
    }

    // 开关与限制
    $seeds = ['ai_enabled' => '1', 'ai_max_input' => '8000'];
    foreach ($seeds as $k => $v) {
        try {
            $hit = $pdo->prepare("SELECT COUNT(*) FROM {$T}settings WHERE k = ?");
            $hit->execute([$k]);
            if ((int)$hit->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO {$T}settings (k, v) VALUES (?, ?)")->execute([$k, $v]);
            }
        } catch (Throwable $e) {
            // settings 表结构异常时不动，交给后台默认值兜底
        }
    }
};
