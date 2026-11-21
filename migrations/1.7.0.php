<?php
declare(strict_types=1);

/**
 * 1.7.0：文件存储（对象存储多平台 + 灾备同步）。
 *
 * 新建四张表，并插入一个默认的「本地磁盘」平台（enabled=1, is_primary=1），
 * 这样不配任何云账号也能立刻上传文件；后面再加云平台做副本。
 *
 * 注意：升级时跑的是旧代码，这里只用 db_prefix()/db_driver() 这种早就存在的函数，
 * 不调用 1.7.0 新增的任何 helper。
 */
return function (PDO $pdo): void {
    $my = function_exists('db_driver') && db_driver() === 'mysql';
    $T = db_prefix();

    $PK   = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $INT  = $my ? 'INT' : 'INTEGER';
    $TINY = $my ? 'TINYINT' : 'INTEGER';
    $TEXT = $my ? 'LONGTEXT' : 'TEXT';
    $VC   = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
    $DT   = $my ? 'DATETIME' : 'TEXT';
    $ENG  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $tables = [];

    $tables['storage_providers'] = "CREATE TABLE IF NOT EXISTS {$T}storage_providers (
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

    $tables['attachments'] = "CREATE TABLE IF NOT EXISTS {$T}attachments (
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

    $tables['attachment_replicas'] = "CREATE TABLE IF NOT EXISTS {$T}attachment_replicas (
        id $PK,
        attachment_id $INT NOT NULL,
        provider_id $INT NOT NULL,
        object_key {$VC(255)} NOT NULL,
        state {$VC(12)} NOT NULL DEFAULT 'ok',
        size $INT NOT NULL DEFAULT 0,
        synced_at $DT NULL,
        last_error {$VC(500)} NULL
    )$ENG";

    $tables['storage_jobs'] = "CREATE TABLE IF NOT EXISTS {$T}storage_jobs (
        id $PK,
        provider_id $INT NOT NULL,
        kind {$VC(12)} NOT NULL DEFAULT 'sync',
        state {$VC(12)} NOT NULL DEFAULT 'running',
        total $INT NOT NULL DEFAULT 0,
        done $INT NOT NULL DEFAULT 0,
        failed $INT NOT NULL DEFAULT 0,
        cursor $INT NOT NULL DEFAULT 0,
        message {$VC(500)} NULL,
        started_at $DT NULL,
        finished_at $DT NULL
    )$ENG";

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    $idx = [
        "CREATE INDEX {$T}idx_att_group ON {$T}attachments (group_id)",
        "CREATE INDEX {$T}idx_att_entry ON {$T}attachments (entry_id)",
        "CREATE INDEX {$T}idx_rep_att ON {$T}attachment_replicas (attachment_id)",
        "CREATE INDEX {$T}idx_rep_prov ON {$T}attachment_replicas (provider_id)",
        "CREATE INDEX {$T}idx_jobs_prov ON {$T}storage_jobs (provider_id)",
    ];
    foreach ($idx as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // 重复索引，忽略
        }
    }

    /* ---------- 默认本地磁盘平台 ---------- */
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM {$T}storage_providers")->fetchColumn();
    if ($cnt === 0) {
        $st = $pdo->prepare("INSERT INTO {$T}storage_providers
            (name, driver, config, enabled, is_primary, sort, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            '本地磁盘',
            'local',
            json_encode(['root' => 'storage/uploads'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            1,
            1,
            0,
            'ok',
            date('Y-m-d H:i:s'),
        ]);
    }

    /* ---------- 上传限额默认值 ---------- */
    $chk = $pdo->prepare("SELECT COUNT(*) FROM {$T}settings WHERE k = ?");
    $chk->execute(['file_max_mb']);
    if ((int)$chk->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO {$T}settings (k, v) VALUES (?, ?)");
        $ins->execute(['file_max_mb', '20']);
    }
};
