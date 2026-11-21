<?php
declare(strict_types=1);

/**
 * 生成更新包（在开发机/本机跑，不是给服务器用的）：
 *
 *   php tools/build_update.php --version=1.1.0 --notes="修了什么"
 *   php tools/build_update.php --version=1.1.0 --from=1.0.0 --notes="..." --out=dist/update-1.1.0.zip
 *
 * --from 是「这份包要从哪个版本升上来」，只有 > from 的迁移文件会被打进去。
 * 不传就取 version.json 里的当前版本（此时源码树应该已经是新版本，所以建议显式传）。
 *
 * 产物结构（后台可直接上传）：
 *   update-1.1.0.zip
 *   ├── version.json
 *   ├── files/        app/ assets/ index.php api.php install.php .htaccess
 *   └── migrations/   可选，1.1.0.php 里 return function(PDO $pdo){...}
 *
 * 不会把 config.php、storage/、data/ 打进包，覆盖时也会跳过这些。
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
$version = trim((string)($opts['version'] ?? ''));
if ($version === '' || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "用法：php tools/build_update.php --version=1.1.0 [--min=1.0.0] [--notes=\"说明\"]\n");
    exit(1);
}

$root = dirname(__DIR__);
$notes = (string)($opts['notes'] ?? '');
$min = (string)($opts['min'] ?? '');
$out = (string)($opts['out'] ?? ($root . '/dist/update-' . $version . '.zip'));

$cur = json_decode((string)@file_get_contents($root . '/version.json'), true) ?: [];
$from = (string)($opts['from'] ?? ($cur['version'] ?? '0.0.0'));
if ($min === '') {
    $min = $from !== '0.0.0' ? $from : (string)($cur['version'] ?? '1.0.0');
}

// 要打进 files/ 的内容
// 注意：install.php 故意不打进去。升级包里带它会把用户已经删掉的安装向导又放回来，
// 白白多一个暴露面。安装向导只属于全量包（build_release.php）。
// updates.json 也故意不打进去：它记录了各更新包的 sha256，一旦进包就会改变包内容、
// 让清单里的 hash 自相矛盾。清单只放在 GitHub 仓库里，后台在线更新时实时拉取。
$include = ['app', 'assets', 'examples', 'index.php', 'api.php', 'mcp.php', 'openapi.php', 'llms.php', '.htaccess', 'tools',
    'README.md', 'DEPLOY.md', 'nginx.example.conf', 'LICENSE'];
$skipNames = ['config.php', 'config.sample.php', 'version.json'];
$skipDirs = ['storage', 'dist', 'data', '.git', '__MACOSX', '__pycache__'];

$zip = new ZipArchive();
if (!is_dir(dirname($out))) {
    @mkdir(dirname($out), 0775, true);
}
if (is_file($out)) {
    @unlink($out);
}
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "无法创建 $out\n");
    exit(1);
}

// 没显式传 --notes 时沿用 version.json 里的说明：以前会写空，升级完站点上就看不到更新内容了
$meta = [
    'name' => $cur['name'] ?? 'XC知识库系统',
    'version' => $version,
    'released' => (string)($cur['released'] ?? '') !== '' ? (string)$cur['released'] : date('Y-m-d'),
    'min_version' => $min,
    'notes' => $notes !== '' ? $notes : (string)($cur['notes'] ?? ''),
];
$zip->addFromString('version.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

$count = 0;
$addPath = function (string $abs, string $relInZip) use ($zip, &$count, &$addPath, $skipNames, $skipDirs) {
    $base = basename($abs);
    if (in_array($base, $skipNames, true)) {
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
            $addPath($abs . '/' . $it, rtrim($relInZip, '/') . '/' . $it);
        }
        return;
    }
    if (!is_file($abs)) {
        return;
    }
    $zip->addFile($abs, 'files/' . ltrim($relInZip, '/'));
    $count++;
};

foreach ($include as $item) {
    $abs = $root . '/' . $item;
    if (file_exists($abs)) {
        $addPath($abs, $item);
    }
}

// 迁移文件（只打比 from 新的）
$migDir = $root . '/migrations';
if (is_dir($migDir)) {
    foreach (glob($migDir . '/*.php') ?: [] as $mig) {
        $v = basename($mig, '.php');
        if (version_compare($v, $from, '>')) {
            $zip->addFile($mig, 'migrations/' . basename($mig));
        }
    }
}

$zip->close();

printf("已生成 %s\n  版本 %s（要求 ≥%s，从 %s 升上来）\n  打包 %d 个文件\n  大小 %.1f KB\n",
    $out, $version, $min, $from, $count, filesize($out) / 1024);
echo "后台 → 系统与升级 → 上传这个 zip 即可。\n";
