<?php
declare(strict_types=1);

/**
 * 数据库层：PDO 单例 + 跨 SQLite / MySQL 的建表与查询封装。
 * 不依赖任何 Composer 包。
 */

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $file = BASE_PATH . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('未安装：请先访问 install.php 完成安装。');
        }
        /** @noinspection PhpIncludeInspection */
        $c = require $file;
    }
    return $c;
}

function db_driver(): string
{
    return (string)(cfg()['db']['driver'] ?? 'sqlite');
}

function db_prefix(): string
{
    return (string)(cfg()['db']['prefix'] ?? '');
}

/** 表名（带前缀） */
function tbl(string $name): string
{
    return db_prefix() . $name;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $d = cfg()['db'];
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        if ($d['driver'] === 'sqlite') {
            $path = $d['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $d['host'], (int)$d['port'], $d['database'], $d['charset'] ?: 'utf8mb4');
            $pdo = new PDO($dsn, $d['username'], $d['password'], $opts);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        exit('数据库连接失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = [], $default = null)
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? $default : $r;
}

/** 插入一行，返回自增 ID */
function insert_row(string $table, array $data): int
{
    $cols = array_keys($data);
    $ph = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT INTO ' . tbl($table) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
    q($sql, $data);
    return (int)db()->lastInsertId();
}

function update_row(string $table, int $id, array $data): void
{
    if (!$data) {
        return;
    }
    $set = [];
    foreach (array_keys($data) as $c) {
        $set[] = "$c = :$c";
    }
    $data['__id'] = $id;
    q('UPDATE ' . tbl($table) . ' SET ' . implode(', ', $set) . ' WHERE id = :__id', $data);
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * 建表。$fresh=true 时先删旧表（仅安装使用）。
 * 结构变更请走 migrations/ 下的迁移文件，不要改这里，否则老站升级会漏。
 */
function schema_install(bool $fresh = false): void
{
    $pdo = db();
    $my = db_driver() === 'mysql';
    $PK  = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $INT = $my ? 'INT' : 'INTEGER';
    $TINY = $my ? 'TINYINT' : 'INTEGER';
    $TEXT = $my ? 'LONGTEXT' : 'TEXT';
    $VC   = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
    $DT   = $my ? 'DATETIME' : 'TEXT';
    $ENG  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $tables = [];

    $tables['users'] = "CREATE TABLE IF NOT EXISTS {T}users (
        id $PK,
        username {$VC(64)} NOT NULL,
        password_hash {$VC(255)} NOT NULL,
        display_name {$VC(64)} NULL,
        email {$VC(190)} NULL,
        email_verified_at $DT NULL,
        notify_mail $TINY NOT NULL DEFAULT 1,
        role {$VC(16)} NOT NULL DEFAULT 'user',
        status $TINY NOT NULL DEFAULT 1,
        created_at $DT NULL,
        last_login_at $DT NULL
    )$ENG";

    $tables['groups'] = "CREATE TABLE IF NOT EXISTS {T}groups (
        id $PK,
        name {$VC(120)} NOT NULL,
        slug {$VC(120)} NOT NULL,
        description {$VC(500)} NULL,
        owner_id $INT NOT NULL DEFAULT 0,
        sort $INT NOT NULL DEFAULT 0,
        created_at $DT NULL
    )$ENG";

    $tables['group_user'] = "CREATE TABLE IF NOT EXISTS {T}group_user (
        id $PK,
        group_id $INT NOT NULL,
        user_id $INT NOT NULL,
        can_read $TINY NOT NULL DEFAULT 1,
        can_write $TINY NOT NULL DEFAULT 0,
        created_at $DT NULL
    )$ENG";

    $tables['entries'] = "CREATE TABLE IF NOT EXISTS {T}entries (
        id $PK,
        group_id $INT NOT NULL,
        kind {$VC(8)} NOT NULL DEFAULT 'qa',
        title {$VC(255)} NULL,
        question {$TEXT} NULL,
        body $TEXT NULL,
        source {$VC(120)} NULL,
        source_date {$VC(20)} NULL,
        qfp {$VC(64)} NULL,
        afp {$VC(64)} NULL,
        version $INT NOT NULL DEFAULT 1,
        status $TINY NOT NULL DEFAULT 1,
        created_by $INT NULL,
        created_at $DT NULL,
        updated_at $DT NULL
    )$ENG";

    $tables['entry_versions'] = "CREATE TABLE IF NOT EXISTS {T}entry_versions (
        id $PK,
        entry_id $INT NOT NULL,
        version $INT NOT NULL DEFAULT 1,
        title {$VC(255)} NULL,
        question $TEXT NULL,
        body $TEXT NULL,
        source {$VC(120)} NULL,
        source_date {$VC(20)} NULL,
        changed_by $INT NULL,
        created_at $DT NULL
    )$ENG";

    $tables['doc_links'] = "CREATE TABLE IF NOT EXISTS {T}doc_links (
        id $PK,
        doc_id $INT NOT NULL,
        entry_id $INT NOT NULL,
        sort $INT NOT NULL DEFAULT 0,
        note {$VC(500)} NULL
    )$ENG";

    $tables['api_keys'] = "CREATE TABLE IF NOT EXISTS {T}api_keys (
        id $PK,
        user_id $INT NOT NULL,
        name {$VC(120)} NOT NULL,
        key_prefix {$VC(24)} NOT NULL,
        key_hash {$VC(64)} NOT NULL,
        key_plain $TEXT NULL,
        group_id $INT NOT NULL,
        can_read $TINY NOT NULL DEFAULT 1,
        can_write $TINY NOT NULL DEFAULT 0,
        expires_at $DT NULL,
        last_used_at $DT NULL,
        revoked $TINY NOT NULL DEFAULT 0,
        created_at $DT NULL
    )$ENG";

    $tables['entry_grams'] = "CREATE TABLE IF NOT EXISTS {T}entry_grams (
        id $PK,
        entry_id $INT NOT NULL,
        gram {$VC(8)} NOT NULL
    )$ENG";

    $tables['settings'] = "CREATE TABLE IF NOT EXISTS {T}settings (
        k {$VC(64)} NOT NULL PRIMARY KEY,
        v $TEXT NULL
    )$ENG";

    $tables['throttle'] = "CREATE TABLE IF NOT EXISTS {T}throttle (
        id $PK,
        bucket {$VC(80)} NOT NULL,
        ts $DT NULL
    )$ENG";

    $tables['email_codes'] = "CREATE TABLE IF NOT EXISTS {T}email_codes (
        id $PK,
        email {$VC(190)} NOT NULL,
        purpose {$VC(16)} NOT NULL,
        code_hash {$VC(64)} NOT NULL,
        uid $INT NULL,
        tries $INT NOT NULL DEFAULT 0,
        expires_at $DT NULL,
        used_at $DT NULL,
        ip {$VC(64)} NULL,
        created_at $DT NULL
    )$ENG";

    $tables['mail_log'] = "CREATE TABLE IF NOT EXISTS {T}mail_log (
        id $PK,
        to_email {$VC(190)} NULL,
        user_id $INT NULL,
        subject {$VC(200)} NULL,
        kind {$VC(32)} NULL,
        ok $TINY NOT NULL DEFAULT 0,
        error {$VC(500)} NULL,
        created_at $DT NULL
    )$ENG";

    $tables['audit_log'] = "CREATE TABLE IF NOT EXISTS {T}audit_log (
        id $PK,
        user_id $INT NULL,
        action {$VC(64)} NOT NULL,
        detail {$VC(1000)} NULL,
        ip {$VC(64)} NULL,
        created_at $DT NULL
    )$ENG";

    /* ---------- 1.7.0 文件存储 ---------- */

    // 存储平台。config 是加密后的 JSON（AK/SK 明文绝不落库）
    $tables['storage_providers'] = "CREATE TABLE IF NOT EXISTS {T}storage_providers (
        id $PK,
        name {$VC(60)} NOT NULL,
        driver {$VC(16)} NOT NULL,
        config $TEXT NULL,
        enabled $TINY NOT NULL DEFAULT 0,
        is_primary $TINY NOT NULL DEFAULT 0,
        sort $INT NOT NULL DEFAULT 0,
        status {$VC(16)} NULL DEFAULT 'new',
        last_check_at $DT NULL,
        last_error {$VC(500)} NULL,
        last_sync_at $DT NULL,
        created_at $DT NULL
    )$ENG";

    $tables['attachments'] = "CREATE TABLE IF NOT EXISTS {T}attachments (
        id $PK,
        group_id $INT NOT NULL,
        entry_id $INT NULL,
        name {$VC(255)} NULL,
        ext {$VC(16)} NULL,
        mime {$VC(80)} NULL,
        size $INT NOT NULL DEFAULT 0,
        sha256 {$VC(64)} NULL,
        object_key {$VC(255)} NOT NULL,
        created_by $INT NULL,
        created_at $DT NULL,
        status $TINY NOT NULL DEFAULT 1
    )$ENG";

    // 每个文件在每个平台上的副本状态，是「灾备同步」的事实来源
    $tables['attachment_replicas'] = "CREATE TABLE IF NOT EXISTS {T}attachment_replicas (
        id $PK,
        attachment_id $INT NOT NULL,
        provider_id $INT NOT NULL,
        object_key {$VC(255)} NOT NULL,
        state {$VC(12)} NOT NULL DEFAULT 'ok',
        size $INT NOT NULL DEFAULT 0,
        synced_at $DT NULL,
        last_error {$VC(500)} NULL
    )$ENG";

    /* ---------------- AI：模型 / 套餐 / 积分 / 调用日志 ---------------- */

    // 模型库：owner_id=0 是系统模型（管理员维护、按积分计费），>0 是用户自带的模型（订阅功能）
    $tables['ai_models'] = "CREATE TABLE IF NOT EXISTS {T}ai_models (
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

    // 套餐：kind=credits 是积分包（一次性），monthly 是月付（解锁自带模型 + 每日额度）
    $tables['ai_plans'] = "CREATE TABLE IF NOT EXISTS {T}ai_plans (
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

    // 用户已开通的套餐（月付看 expires_at，积分包是一次性入账后留档）
    $tables['user_plans'] = "CREATE TABLE IF NOT EXISTS {T}user_plans (
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

    // 积分钱包：一个用户一行；余额变动一律走 credit_logs 留痕
    $tables['credit_wallets'] = "CREATE TABLE IF NOT EXISTS {T}credit_wallets (
        user_id $INT NOT NULL PRIMARY KEY,
        credits $INT NOT NULL DEFAULT 0,
        total_in $INT NOT NULL DEFAULT 0,
        total_out $INT NOT NULL DEFAULT 0,
        updated_at $DT NULL
    )$ENG";

    // 积分流水（正数入账、负数消耗），balance 记当时余额便于对账
    $tables['credit_logs'] = "CREATE TABLE IF NOT EXISTS {T}credit_logs (
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

    // 每次 AI 调用的审计记录（也用来统计每日次数）
    $tables['ai_calls'] = "CREATE TABLE IF NOT EXISTS {T}ai_calls (
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

    // 兑换码：kind=credits 加积分，kind=plan 开套餐
    $tables['redeem_codes'] = "CREATE TABLE IF NOT EXISTS {T}redeem_codes (
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

    $tables['redeem_logs'] = "CREATE TABLE IF NOT EXISTS {T}redeem_logs (
        id $PK,
        code_id $INT NOT NULL,
        code {$VC(40)} NULL,
        user_id $INT NOT NULL,
        kind {$VC(12)} NOT NULL,
        credits $INT NOT NULL DEFAULT 0,
        plan_id $INT NOT NULL DEFAULT 0,
        created_at $DT NULL
    )$ENG";

    // 订单：manual=后台手动标记，redeem=兑换码，epay=在线支付（彩虹易支付）
    $tables['ai_orders'] = "CREATE TABLE IF NOT EXISTS {T}ai_orders (
        id $PK,
        user_id $INT NOT NULL,
        plan_id $INT NOT NULL DEFAULT 0,
        kind {$VC(12)} NOT NULL DEFAULT 'credits',
        amount_cents $INT NOT NULL DEFAULT 0,
        credits $INT NOT NULL DEFAULT 0,
        channel {$VC(16)} NOT NULL DEFAULT 'manual',
        pay_method {$VC(16)} NULL,
        out_trade_no {$VC(64)} NULL,
        transaction_id {$VC(64)} NULL,
        status {$VC(12)} NOT NULL DEFAULT 'pending',
        paid_at $DT NULL,
        refund_state {$VC(12)} NOT NULL DEFAULT 'none',
        refund_reason $TEXT NULL,
        refund_note {$VC(255)} NULL,
        refund_at $DT NULL,
        note {$VC(255)} NULL,
        created_at $DT NULL,
        updated_at $DT NULL
    )$ENG";

    // 支付事件流水：每次发起/回调/查单/退款都留一条，排查「钱付了没到账」全靠它
    $tables['pay_events'] = "CREATE TABLE IF NOT EXISTS {T}pay_events (
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
    )$ENG";

    // 定时任务（1.8.0 只建表与开关，调度器留给下一版）
    $tables['ai_tasks'] = "CREATE TABLE IF NOT EXISTS {T}ai_tasks (
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

    // AI 工作台：一次对话 = 一个 run；每条用户消息/模型输出/工具结果 = 一条 msg
    $tables['ai_agent_runs'] = "CREATE TABLE IF NOT EXISTS {T}ai_agent_runs (
        id $PK,
        user_id $INT NOT NULL,
        model_id $INT NOT NULL DEFAULT 0,
        title {$VC(80)} NOT NULL DEFAULT '新对话',
        status {$VC(12)} NOT NULL DEFAULT 'active',
        steps $INT NOT NULL DEFAULT 0,
        credits $INT NOT NULL DEFAULT 0,
        created_at $DT NULL,
        updated_at $DT NULL
    )$ENG";

    $tables['ai_agent_msgs'] = "CREATE TABLE IF NOT EXISTS {T}ai_agent_msgs (
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
    )$ENG";

    // 同步任务：分批推进，可中断续跑
    $tables['storage_jobs'] = "CREATE TABLE IF NOT EXISTS {T}storage_jobs (
        id $PK,
        provider_id $INT NOT NULL,
        kind {$VC(12)} NOT NULL DEFAULT 'sync',
        state {$VC(12)} NOT NULL DEFAULT 'running',
        total $INT NOT NULL DEFAULT 0,
        done $INT NOT NULL DEFAULT 0,
        failed $INT NOT NULL DEFAULT 0,
        cursor $INT NOT NULL DEFAULT 0,
        cloud_copies $INT NOT NULL DEFAULT 0,
        relayed $INT NOT NULL DEFAULT 0,
        message {$VC(500)} NULL,
        started_at $DT NULL,
        finished_at $DT NULL
    )$ENG";

    if ($fresh) {
        $order = ['ai_agent_msgs', 'ai_agent_runs', 'pay_events', 'ai_tasks', 'ai_orders', 'redeem_logs', 'redeem_codes', 'ai_calls', 'credit_logs', 'credit_wallets', 'user_plans', 'ai_plans', 'ai_models', 'storage_jobs', 'attachment_replicas', 'attachments', 'storage_providers', 'mail_log', 'email_codes', 'audit_log', 'throttle', 'entry_versions', 'entry_grams', 'doc_links', 'api_keys', 'entries', 'group_user', 'groups', 'settings', 'users'];
        foreach ($order as $t) {
            $pdo->exec('DROP TABLE IF EXISTS ' . tbl($t));
        }
    }

    foreach ($tables as $name => $sql) {
        $pdo->exec(str_replace('{T}', db_prefix(), $sql));
    }

    $idx = [
        'CREATE INDEX IF NOT EXISTS {T}idx_gu_user ON {T}group_user (user_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_gu_group ON {T}group_user (group_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_entries_group ON {T}entries (group_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_entries_kind ON {T}entries (kind)',
        'CREATE INDEX IF NOT EXISTS {T}idx_grams ON {T}entry_grams (gram)',
        'CREATE INDEX IF NOT EXISTS {T}idx_grams_entry ON {T}entry_grams (entry_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_keys_hash ON {T}api_keys (key_hash)',
        'CREATE INDEX IF NOT EXISTS {T}idx_doclinks_doc ON {T}doc_links (doc_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_entries_qfp ON {T}entries (group_id, qfp)',
        'CREATE INDEX IF NOT EXISTS {T}idx_versions_entry ON {T}entry_versions (entry_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_groups_owner ON {T}groups (owner_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_throttle ON {T}throttle (bucket, ts)',
        'CREATE INDEX IF NOT EXISTS {T}idx_users_email ON {T}users (email)',
        'CREATE INDEX IF NOT EXISTS {T}idx_codes_lookup ON {T}email_codes (email, purpose)',
        'CREATE INDEX IF NOT EXISTS {T}idx_maillog ON {T}mail_log (created_at)',
        'CREATE INDEX IF NOT EXISTS {T}idx_att_group ON {T}attachments (group_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_att_entry ON {T}attachments (entry_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_rep_att ON {T}attachment_replicas (attachment_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_rep_prov ON {T}attachment_replicas (provider_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_jobs_prov ON {T}storage_jobs (provider_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_ai_models_owner ON {T}ai_models (owner_id, enabled)',
        'CREATE INDEX IF NOT EXISTS {T}idx_credit_logs_user ON {T}credit_logs (user_id, id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_ai_calls_user ON {T}ai_calls (user_id, created_at)',
        'CREATE INDEX IF NOT EXISTS {T}idx_user_plans_user ON {T}user_plans (user_id, status)',
        'CREATE INDEX IF NOT EXISTS {T}idx_ai_orders_user ON {T}ai_orders (user_id, status)',
        'CREATE INDEX IF NOT EXISTS {T}idx_ai_orders_no ON {T}ai_orders (out_trade_no)',
        'CREATE INDEX IF NOT EXISTS {T}idx_ai_orders_refund ON {T}ai_orders (refund_state)',
        'CREATE INDEX IF NOT EXISTS {T}idx_pay_events_order ON {T}pay_events (order_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_pay_events_time ON {T}pay_events (created_at)',
        'CREATE INDEX IF NOT EXISTS {T}idx_redeem_logs_user ON {T}redeem_logs (user_id)',
        'CREATE INDEX IF NOT EXISTS {T}idx_agent_runs_user ON {T}ai_agent_runs (user_id, updated_at)',
        'CREATE INDEX IF NOT EXISTS {T}idx_agent_msgs_run ON {T}ai_agent_msgs (run_id, seq)',
    ];
    foreach ($idx as $sql) {
        try {
            $pdo->exec(str_replace('{T}', db_prefix(), $sql));
        } catch (Throwable $e) {
            // MySQL 5.x 不支持 CREATE INDEX IF NOT EXISTS，忽略重复索引错误
        }
    }

    // MySQL 下 username / slug 需要唯一约束（SQLite 用唯一索引也能实现）
    // 昵称也要唯一：MySQL 的 utf8mb4_unicode_ci 本身不区分大小写，SQLite 得显式 COLLATE NOCASE
    $uniq = [
        'CREATE UNIQUE INDEX IF NOT EXISTS {T}uq_users_username ON {T}users (username)',
        'CREATE UNIQUE INDEX IF NOT EXISTS {T}uq_groups_slug ON {T}groups (slug)',
        'CREATE UNIQUE INDEX IF NOT EXISTS {T}uq_gu ON {T}group_user (group_id, user_id)',
    ];
    $uniq[] = db_driver() === 'mysql'
        ? 'CREATE UNIQUE INDEX {T}uq_users_nick ON {T}users (display_name)'
        : 'CREATE UNIQUE INDEX IF NOT EXISTS {T}uq_users_nick ON {T}users (display_name COLLATE NOCASE)';
    $uniq[] = 'CREATE UNIQUE INDEX IF NOT EXISTS {T}uq_redeem_code ON {T}redeem_codes (code)';
    foreach ($uniq as $sql) {
        try {
            $pdo->exec(str_replace('{T}', db_prefix(), $sql));
        } catch (Throwable $e) {
        }
    }

    // 全新安装也要有默认的本地磁盘平台（老站升级由 migrations/1.7.0.php 补）
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM ' . tbl('storage_providers'))->fetchColumn();
        if ($n === 0) {
            insert_row('storage_providers', [
                'name' => '本地磁盘',
                'driver' => 'local',
                'config' => json_encode(['root' => 'storage/uploads'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'enabled' => 1,
                'is_primary' => 1,
                'sort' => 0,
                'status' => 'ok',
                'created_at' => now_str(),
            ]);
        }
        if (val('SELECT COUNT(*) FROM ' . tbl('settings') . ' WHERE k = ?', ['file_max_mb'], 0) == 0) {
            q('INSERT INTO ' . tbl('settings') . ' (k, v) VALUES (?, ?)', ['file_max_mb', '20']);
        }

        // 在线支付默认关闭：没填商户号与密钥之前，前台只显示兑换码
        foreach ([
            'pay_enabled'      => '0',
            'pay_gateway'      => 'epay',
            'pay_api'          => 'https://pay.cfxcc.cn',
            'pay_methods'      => 'alipay,wxpay',
            'pay_use_mapi'     => '1',
            'pay_title_prefix' => '知识库 · ',
        ] as $pk => $pv) {
            if (val('SELECT COUNT(*) FROM ' . tbl('settings') . ' WHERE k = ?', [$pk], 0) == 0) {
                q('INSERT INTO ' . tbl('settings') . ' (k, v) VALUES (?, ?)', [$pk, $pv]);
            }
        }

        // AI 套餐默认给两个样例（模型需要管理员自己填密钥，不预置假配置）
        if ((int)val('SELECT COUNT(*) FROM ' . tbl('ai_plans'), [], 0) === 0) {
            insert_row('ai_plans', [
                'name' => '积分包 · 1000', 'kind' => 'credits', 'price_cents' => 1000, 'credits' => 1000,
                'days' => 0, 'custom_slots' => 0, 'daily_calls' => 0, 'all_models' => 1,
                'enabled' => 1, 'sort' => 0, 'note' => '一次性到账，按模型单价扣积分', 'created_at' => now_str(),
            ]);
            insert_row('ai_plans', [
                'name' => '月付 · 专业版', 'kind' => 'monthly', 'price_cents' => 4900, 'credits' => 2000,
                'days' => 30, 'custom_slots' => 3, 'daily_calls' => 300, 'all_models' => 1,
                'enabled' => 1, 'sort' => 1, 'note' => '每月送 2000 积分，可加 3 个自有模型，每日 300 次', 'created_at' => now_str(),
            ]);
        }
        foreach ([['ai_enabled', '1'], ['ai_max_input', '8000'], ['agent_fetch_max_kb', '3000'], ['agent_fetch_ssl_verify', '1']] as $kv) {
            if (val('SELECT COUNT(*) FROM ' . tbl('settings') . ' WHERE k = ?', [$kv[0]], 0) == 0) {
                q('INSERT INTO ' . tbl('settings') . ' (k, v) VALUES (?, ?)', [$kv[0], $kv[1]]);
            }
        }
    } catch (Throwable $e) {
        // 老库结构还没迁移时忽略
    }
}

function setting_get(string $k, $default = null)
{
    $v = val('SELECT v FROM ' . tbl('settings') . ' WHERE k = ?', [$k], null);
    return $v === null ? $default : $v;
}

function setting_set(string $k, $v): void
{
    $exists = val('SELECT COUNT(*) FROM ' . tbl('settings') . ' WHERE k = ?', [$k], 0);
    if ($exists) {
        q('UPDATE ' . tbl('settings') . ' SET v = ? WHERE k = ?', [$v, $k]);
    } else {
        q('INSERT INTO ' . tbl('settings') . ' (k, v) VALUES (?, ?)', [$k, $v]);
    }
}

/** MCP 接入是否开放。默认开；1.5.0 之前没有这个设置项，读不到时也按开处理。 */
function mcp_enabled(): bool
{
    return setting_get('mcp_enabled', '1') !== '0';
}

function audit(string $action, string $detail = ''): void
{
    try {
        insert_row('audit_log', [
            'user_id'    => current_user()['id'] ?? null,
            'action'     => $action,
            'detail'     => mb_substr($detail, 0, 900),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at' => now_str(),
        ]);
    } catch (Throwable $e) {
    }
}
