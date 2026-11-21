<?php
declare(strict_types=1);

/**
 * MCP 入口（Streamable HTTP）。
 * 地址：https://你的域名/mcp.php
 * 鉴权：Authorization: Bearer <在「我的 API Key」创建的 Key>
 */

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null,
        'error' => ['code' => -32000, 'message' => '系统尚未安装']]);
    exit;
}

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/api.php';
require __DIR__ . '/app/mcp.php';

mcp_handle();
