<?php
declare(strict_types=1);

/**
 * 打「首次安装包」（给全新服务器用，含 install.php 与内置数据）：
 *
 *   php tools/build_release.php
 *   php tools/build_release.php --out=dist/kb-portal.zip
 *
 * 排除：config.php（含密码）、storage/ 下的数据库与备份、dist/ 本身。
 */

if (PHP_SAPI !== 'cli') {
    exit("只能在命令行运行\n");
}

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$root = dirname(__DIR__);
$ver = json_decode((string)@file_get_contents($root . '/version.json'), true) ?: [];
$version = (string)($ver['version'] ?? '1.0.0');
$out = (string)($opts['out'] ?? ($root . '/dist/kb-portal-' . $version . '.zip'));

$include = ['app', 'assets', 'data', 'examples', 'migrations', 'tools', '.htaccess', 'index.php', 'api.php',
    'mcp.php', 'openapi.php', 'llms.php', 'install.php', 'version.json', 'README.md', 'DEPLOY.md',
    'nginx.example.conf', 'config.sample.php'];

$skipFiles = ['config.php'];
$skipDirs = ['storage', 'dist', '.git', '__MACOSX', 'node_modules', '__pycache__'];

if (!is_dir(dirname($out))) {
    @mkdir(dirname($out), 0775, true);
}
if (is_file($out)) {
    @unlink($out);
}

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "无法创建 {$out}\n");
    exit(1);
}

// storage 目录结构要保留（空目录 + 保护文件）
$zip->addFromString('storage/.htaccess', "# 禁止外部访问\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
$zip->addFromString('storage/backups/.gitkeep', '');
$zip->addFromString('storage/tmp/.gitkeep', '');

$count = 0;
$add = function (string $abs, string $rel) use ($zip, &$count, &$add, $skipFiles, $skipDirs) {
    $base = basename($abs);
    if (in_array($base, $skipFiles, true)) {
        return;
    }
    if (is_dir($abs)) {
        if (in_array($base, $skipDirs, true)) {
            return;
        }
        foreach (scandir($abs) ?: [] as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $add($abs . '/' . $it, rtrim($rel, '/') . '/' . $it);
        }
        return;
    }
    if (!is_file($abs) || $base === '.gitkeep') {
        return;
    }
    $zip->addFile($abs, ltrim($rel, '/'));
    $count++;
};

foreach ($include as $item) {
    $abs = $root . '/' . $item;
    if (file_exists($abs)) {
        $add($abs, $item);
    }
}

$zip->close();

printf("已生成 %s\n  版本 %s｜%d 个文件｜%.1f MB\n  上传解压后访问 install.php 即可安装\n",
    $out, $version, $count, filesize($out) / 1048576);
