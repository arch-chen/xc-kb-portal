<?php
/**
 * 配置样例。正式安装时会由 install.php 自动生成 config.php，无需手工改这个文件。
 * 需要手工部署时：复制为 config.php 后填写。
 */
return [
    'db' => [
        // sqlite 或 mysql
        'driver'      => 'sqlite',
        'sqlite_path' => __DIR__ . '/storage/kb.sqlite',
        'host'        => '127.0.0.1',
        'port'        => 3306,
        'database'    => 'kb',
        'username'    => '',
        'password'    => '',
        'charset'     => 'utf8mb4',
        // 表前缀，多个站点共用一个库时用它隔离，例如 'kb1_'
        'prefix'      => '',
    ],
    'app' => [
        'name'     => 'XC知识库系统',
        'url'      => '',          // 例：https://kb.example.com，留空自动识别
        'timezone' => 'Asia/Shanghai',
        'debug'    => false,
    ],
    'security' => [
        // 用于 API Key 哈希的盐，安装时自动生成，请勿外泄
        'pepper'       => '',
        'session_name' => 'kbsid',
    ],
];
