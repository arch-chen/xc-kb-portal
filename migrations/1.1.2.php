<?php
declare(strict_types=1);

/**
 * 1.1.2：api_keys 增加 key_plain（Key 明文的 AES 密文），让 Key 可以长期回显。
 *
 * 重要：升级请求跑的是「内存里的旧代码」，所以这个文件只能用 1.1.1 就已存在的
 * 函数（db_prefix / db_driver），不能调 secret_encrypt / secret_mask 这类新版函数，
 * 否则会 Call to undefined function。
 *
 * 老 Key 当初只存了 HMAC 哈希，数学上还原不出明文，这里保持 NULL，
 * 页面上会提示「旧 Key，重建后才能查看」。
 */
return function (PDO $pdo): void {
    $table = db_prefix() . 'api_keys';
    $exists = false;

    try {
        if (db_driver() === 'mysql') {
            $rows = $pdo->query("SHOW COLUMNS FROM {$table} LIKE 'key_plain'")->fetchAll();
            $exists = !empty($rows);
        } else {
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (($c['name'] ?? '') === 'key_plain') {
                    $exists = true;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        $exists = false;
    }

    if ($exists) {
        return;
    }

    $pdo->exec("ALTER TABLE {$table} ADD COLUMN key_plain TEXT NULL");
};
