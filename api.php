<?php
declare(strict_types=1);

/** Agent API 入口：/api.php?path=/v1/xxx ，或伪静态 /api/v1/xxx */

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => ['code' => 'not_installed', 'message' => '系统尚未安装']]);
    exit;
}

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/api.php';

api_handle();
