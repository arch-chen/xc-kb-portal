<?php
declare(strict_types=1);

/**
 * 1.2.0：对外注册 + 数据隔离。
 *
 * 三件事：
 *   1. groups 增加 owner_id —— 0 表示共享分组，>0 表示某个用户的私有空间
 *   2. 新建 throttle 表 —— 注册限速（按 IP 计数）
 *   3. settings 写入注册开关的默认值
 *
 * 重要：升级请求跑的是「内存里的旧代码」，所以这个文件只能用升级前就存在的函数
 * （db_prefix / db_driver），不能调用 1.2.0 新增的任何函数，否则 Call to undefined function。
 * 表结构一律手写 DDL，不要引用 db.php 里的类型变量。
 */
return function (PDO $pdo): void {
    $prefix = db_prefix();
    $groups = $prefix . 'groups';

    // ---- 1) groups.owner_id ----
    $hasOwner = false;
    try {
        if (db_driver() === 'mysql') {
            $hasOwner = !empty($pdo->query("SHOW COLUMNS FROM {$groups} LIKE 'owner_id'")->fetchAll());
        } else {
            foreach ($pdo->query("PRAGMA table_info({$groups})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (($c['name'] ?? '') === 'owner_id') {
                    $hasOwner = true;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        $hasOwner = false;
    }

    if (!$hasOwner) {
        $pdo->exec("ALTER TABLE {$groups} ADD COLUMN owner_id INTEGER NOT NULL DEFAULT 0");
    }

    // ---- 2) throttle 表 ----
    // 两种驱动的主键写法不同，必须先判断再建，不能先建后补救（MySQL 会直接抛错中断迁移）
    if (db_driver() === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}throttle (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bucket VARCHAR(80) NOT NULL,
            ts DATETIME NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}throttle (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket VARCHAR(80) NOT NULL,
            ts DATETIME NULL
        )");
    }

    try {
        $pdo->exec("CREATE INDEX idx_{$prefix}throttle_bucket ON {$prefix}throttle (bucket, ts)");
    } catch (Throwable $e) {
        // 索引已存在（MySQL 不支持 IF NOT EXISTS），忽略
    }

    // ---- 3) 注册开关默认值：只在没写过时插入，不覆盖管理员的选择 ----
    $settings = $prefix . 'settings';
    $exists = $pdo->prepare("SELECT COUNT(*) FROM {$settings} WHERE k = ?");
    foreach ([['allow_register', '1'], ['register_per_hour', '5']] as [$k, $v]) {
        $exists->execute([$k]);
        if ((int)$exists->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT INTO {$settings} (k, v) VALUES (?, ?)");
            $ins->execute([$k, $v]);
        }
    }

    // ---- 4) 回填历史私有分组 ----
    // 保守规则：分组只有一个成员、该成员是普通用户、且有写权限 → 认定是这个人自建的空间。
    // 管理员参与的分组、多人协作的分组一律保持 0（共享），避免误判把别人的东西圈走。
    $gu = $prefix . 'group_user';
    $users = $prefix . 'users';

    $onlyMember = $pdo->query(
        "SELECT gu.group_id, gu.user_id, gu.can_write, u.role
         FROM {$gu} gu
         INNER JOIN {$users} u ON u.id = gu.user_id
         WHERE gu.group_id IN (SELECT group_id FROM {$gu} GROUP BY group_id HAVING COUNT(*) = 1)"
    )->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("UPDATE {$groups} SET owner_id = ? WHERE id = ? AND owner_id = 0");
    foreach ($onlyMember as $r) {
        if ((string)$r['role'] === 'user' && (int)$r['can_write'] === 1) {
            $upd->execute([(int)$r['user_id'], (int)$r['group_id']]);
        }
    }
};
