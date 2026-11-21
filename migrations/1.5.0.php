<?php
declare(strict_types=1);

/**
 * 1.5.0：开放 MCP 接入（ChatGPT / Claude 连接器、自定义 GPT）。
 *
 * 只加一个开关设置项，不动任何表结构，也不碰已有数据。
 * 升级时跑的是旧代码，这里只用 db_prefix() 这种升级前就存在的函数。
 */
return function (PDO $pdo): void {
    $settings = db_prefix() . 'settings';

    $chk = $pdo->prepare("SELECT COUNT(*) FROM {$settings} WHERE k = ?");
    $chk->execute(['mcp_enabled']);
    if ((int)$chk->fetchColumn() > 0) {
        return;
    }
    $ins = $pdo->prepare("INSERT INTO {$settings} (k, v) VALUES (?, ?)");
    $ins->execute(['mcp_enabled', '1']);
};
