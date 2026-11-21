<?php
declare(strict_types=1);

/**
 * 1.7.1：同步任务区分「云端直搬」与「本地中转」。
 *
 * 只加两列统计，不改任何既有数据：
 *   cloud_copies = 走服务端复制（不落磁盘）的个数
 *   relayed      = 走 storage/tmp 中转的个数
 *
 * 注意：升级时跑的是旧代码，这里只用 db_prefix()，不调用 1.7.1 新增的任何函数。
 */
return function (PDO $pdo): void {
    $T = function_exists('db_prefix') ? db_prefix() : 'kb_';

    foreach (['cloud_copies', 'relayed'] as $col) {
        try {
            $pdo->exec("ALTER TABLE {$T}storage_jobs ADD COLUMN {$col} INTEGER NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            // 列已存在（重装/半途中断）就跳过，不让升级整批失败
        }
    }
};
