<?php
declare(strict_types=1);

/**
 * 1.3.0：邮件配置 + 邮箱验证码 + 用户账户自助管理。
 *
 * 四件事：
 *   1. users 增加 email / email_verified_at / notify_mail
 *   2. 新建 email_codes（验证码）与 mail_log（发送记录）
 *   3. settings 写入邮件相关的默认值（不覆盖已有配置）
 *   4. 老账号如果用户名本身就是邮箱，顺手回填到 email（保持「未验证」，由用户自己在账户页验证）
 *
 * 重要：升级时跑的是「内存里的旧代码」，这个文件只能用手写 DDL 和升级前就有的函数
 * （db_prefix / db_driver），不能调用 1.3.0 新增的任何函数。
 */
return function (PDO $pdo): void {
    $prefix = db_prefix();
    $my = db_driver() === 'mysql';
    $users = $prefix . 'users';

    $hasColumn = function (string $table, string $col) use ($pdo, $my): bool {
        try {
            if ($my) {
                $st = $pdo->query("SHOW COLUMNS FROM {$table} LIKE " . $pdo->quote($col));
                return $st !== false && !empty($st->fetchAll(PDO::FETCH_ASSOC));
            }
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (($c['name'] ?? '') === $col) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    };

    // ---- 1) users 新列 ----
    if (!$hasColumn($users, 'email')) {
        $pdo->exec("ALTER TABLE {$users} ADD COLUMN email VARCHAR(190) NULL");
    }
    if (!$hasColumn($users, 'email_verified_at')) {
        $pdo->exec("ALTER TABLE {$users} ADD COLUMN email_verified_at DATETIME NULL");
    }
    if (!$hasColumn($users, 'notify_mail')) {
        $pdo->exec("ALTER TABLE {$users} ADD COLUMN notify_mail " . ($my ? 'TINYINT' : 'INTEGER')
            . " NOT NULL DEFAULT 1");
    }

    try {
        $pdo->exec("CREATE INDEX idx_{$prefix}users_email ON {$users} (email)");
    } catch (Throwable $e) {
        // 已存在则忽略（MySQL 不支持 IF NOT EXISTS）
    }

    // ---- 2) 验证码表 ----
    $pk = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $eng = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $codeTbl = $prefix . 'email_codes';
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$codeTbl} (
        id {$pk},
        email VARCHAR(190) NOT NULL,
        purpose VARCHAR(16) NOT NULL,
        code_hash VARCHAR(64) NOT NULL,
        uid INT NULL,
        tries INT NOT NULL DEFAULT 0,
        expires_at DATETIME NULL,
        used_at DATETIME NULL,
        ip VARCHAR(64) NULL,
        created_at DATETIME NULL
    ){$eng}");

    try {
        $pdo->exec("CREATE INDEX idx_{$prefix}codes_lookup ON {$codeTbl} (email, purpose)");
    } catch (Throwable $e) {
    }

    // ---- 3) 发送记录表 ----
    $logTbl = $prefix . 'mail_log';
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$logTbl} (
        id {$pk},
        to_email VARCHAR(190) NULL,
        user_id INT NULL,
        subject VARCHAR(200) NULL,
        kind VARCHAR(32) NULL,
        ok " . ($my ? 'TINYINT' : 'INTEGER') . " NOT NULL DEFAULT 0,
        error VARCHAR(500) NULL,
        created_at DATETIME NULL
    ){$eng}");

    // ---- 4) 设置默认值 ----
    $settings = $prefix . 'settings';
    $find = $pdo->prepare("SELECT COUNT(*) FROM {$settings} WHERE k = ?");
    $ins = $pdo->prepare("INSERT INTO {$settings} (k, v) VALUES (?, ?)");
    $defaults = [
        ['mail_enabled', '0'],
        ['mail_method', 'smtp'],
        ['mail_port', '465'],
        ['mail_secure', 'ssl'],
        ['mail_verify_register', '1'],
        ['mail_welcome', '1'],
        ['mail_no_verify', '0'],
    ];
    foreach ($defaults as [$k, $v]) {
        $find->execute([$k]);
        if ((int)$find->fetchColumn() === 0) {
            $ins->execute([$k, $v]);
        }
    }

    // ---- 5) 老账号邮箱回填：用户名本身是邮箱的那种 ----
    // 只回填地址，不置 email_verified_at —— 名字是邮箱不代表这个邮箱真是他的。
    try {
        $rows = $pdo->query("SELECT id, username FROM {$users} WHERE (email IS NULL OR email = '')")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare("UPDATE {$users} SET email = ? WHERE id = ?");
        foreach ($rows as $r) {
            $un = trim((string)$r['username']);
            if (filter_var($un, FILTER_VALIDATE_EMAIL)) {
                $upd->execute([mb_strtolower($un, 'UTF-8'), (int)$r['id']]);
            }
        }
    } catch (Throwable $e) {
        // 回填失败不影响升级
    }
};
