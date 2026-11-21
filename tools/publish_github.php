<?php
declare(strict_types=1);

/**
 * 发版并同步到 GitHub（在开发机跑，不是给服务器用的）。
 *
 *   php tools/publish_github.php                # 同步包 → 生成清单 → commit → push
 *   php tools/publish_github.php --dry-run     # 只生成清单，不 commit / push
 *   php tools/publish_github.php --no-push     # 本地 commit，不 push
 *
 * 前提：
 *   - 已用 build_update.php / build_release.php 生成 dist/ 里的包
 *   - 已 git init、remote 指向 GitHub，凭据走系统 credential helper（不写进任何文件）
 *
 * 每次发版固定动作（别跳）：
 *   改 version.json → build_update.php --version --from → build_release.php
 *   → 实测旧版→新版 → 本脚本 → 交付
 */

if (PHP_SAPI !== 'cli') {
    exit("只能在命令行运行\n");
}

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/s', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? '1';
    }
}
$dryRun = isset($opts['dry-run']);
$noPush = isset($opts['no-push']);

$root = dirname(__DIR__);
@chdir($root);   // git 依赖当前工作目录，避免把中文路径传给 cmd
$distDir = $root . '/dist';
$updatesDir = $root . '/updates';

if (!is_dir($updatesDir) && !@mkdir($updatesDir, 0775, true) && !is_dir($updatesDir)) {
    fwrite(STDERR, "无法创建 updates/ 目录\n");
    exit(1);
}

/** 执行 git 命令，返回 [退出码, 输出] */
function pub_git(array $args): array
{
    $cmd = 'git ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

/** 从 remote 解析 owner/repo，解析不到就用默认 */
function pub_repo(): string
{
    [$code, $out] = pub_git(['remote', 'get-url', 'origin']);
    $url = trim($out);
    if ($code === 0 && preg_match('#github\.com[:/]([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+?)(?:\.git)?$#', $url, $m)) {
        return $m[1] . '/' . $m[2];
    }
    return 'arch-chen/xc-kb-portal';
}

/** 包内不允许出现这些路径（防御：别把运行时数据打进公开仓库） */
function pub_bad_entry(string $name): bool
{
    $n = str_replace('\\', '/', $name);
    return (bool)preg_match('#(?:^|/)(?:config\.php|storage/|data/|\.git/|\.env|.*\.(?:sqlite|db|bak|sql|log))$#i', $n);
}

// 1) 同步 dist/update-*.zip → updates/
$copied = 0;
foreach (glob($distDir . '/update-*.zip') ?: [] as $src) {
    $dst = $updatesDir . '/' . basename($src);
    if (!is_file($dst) || md5_file($dst) !== md5_file($src)) {
        if (!@copy($src, $dst)) {
            fwrite(STDERR, "复制失败：$src\n");
            exit(1);
        }
        $copied++;
    }
}
printf("1) 同步更新包：dist/ → updates/（新增或变更 %d 个）\n", $copied);

// 2) 扫描 updates/*.zip 生成清单
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "PHP 缺少 zip 扩展，无法读取更新包元信息\n");
    exit(1);
}
$releases = [];
$skipped = [];
foreach (glob($updatesDir . '/*.zip') ?: [] as $zip) {
    $z = new ZipArchive();
    if ($z->open($zip) !== true) {
        $skipped[] = basename($zip) . '（不是有效 zip）';
        continue;
    }
    $meta = null;
    $bad = '';
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = str_replace('\\', '/', (string)$z->getNameIndex($i));
        if ($meta === null && preg_match('#^(?:[^/]+/)?version\.json$#', $n)) {
            $meta = json_decode((string)$z->getFromName($n), true);
        }
        if ($bad === '' && pub_bad_entry($n)) {
            $bad = $n;
        }
    }
    $z->close();
    if ($bad !== '') {
        $skipped[] = basename($zip) . '（含敏感路径 ' . $bad . '，已拒绝）';
        continue;
    }
    if (!is_array($meta) || empty($meta['version']) || !preg_match('/^\d+\.\d+\.\d+$/', (string)$meta['version'])) {
        $skipped[] = basename($zip) . '（缺少合法 version.json）';
        continue;
    }
    $releases[] = [
        'version'     => (string)$meta['version'],
        'min_version' => (string)($meta['min_version'] ?? '0.0.0'),
        'released'    => (string)($meta['released'] ?? ''),
        'notes'       => (string)($meta['notes'] ?? ''),
        'zip'         => basename($zip),
        'sha256'      => hash_file('sha256', $zip),
        'size'        => filesize($zip),
    ];
}
usort($releases, fn($a, $b) => version_compare($a['version'], $b['version']));
$latest = $releases ? $releases[count($releases) - 1]['version'] : '';

$ver = json_decode((string)@file_get_contents($root . '/version.json'), true) ?: [];
$current = (string)($ver['version'] ?? '0.0.0');
$manifest = [
    'schema'       => 1,
    'repo'         => pub_repo(),
    'branch'       => 'main',
    'generated_at' => date('c'),
    'current_src'  => $current,
    'latest'       => $latest,
    'releases'     => $releases,
];
$manifestPath = $root . '/updates.json';
if (@file_put_contents($manifestPath,
    json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") === false) {
    fwrite(STDERR, "无法写入 updates.json\n");
    exit(1);
}
printf("2) 生成清单：%d 个版本，最新 v%s（源码当前 v%s）\n", count($releases), $latest, $current);
foreach ($skipped as $s) {
    echo "   ! 跳过 $s\n";
}

// 3) git commit + push
[$c0, $status] = pub_git(['status', '--porcelain']);
if ($dryRun) {
    echo "3) --dry-run：跳过 commit / push。待提交变更：\n" . ($status !== '' ? $status : "   （无变更）") . "\n";
    echo "\n完成（预览）。清单：$manifestPath\n";
    exit(0);
}

$msg = 'Release v' . $current . ': sync updates.json and update packages';
if ($status !== '') {
    [$c1, $o1] = pub_git(['add', '-A']);
    if ($c1 !== 0) {
        fwrite(STDERR, "git add 失败：$o1\n");
        exit(1);
    }
    [$c2, $o2] = pub_git(['commit', '-m', $msg]);
    if ($c2 !== 0) {
        fwrite(STDERR, "git commit 失败：$o2\n");
        exit(1);
    }
    echo "3) 已提交：$msg\n";
} else {
    echo "3) 工作区无变更，跳过 commit\n";
}

if ($noPush) {
    echo "4) --no-push：跳过 push\n";
} else {
    [$c3, $o3] = pub_git(['push', 'origin', 'HEAD']);
    if ($c3 !== 0) {
        fwrite(STDERR, "git push 失败：$o3\n凭据请走系统 credential helper，不要写进 remote URL。\n");
        exit(1);
    }
    echo "4) 已推送到 GitHub\n";
}

$repo = $manifest['repo'];
echo "\n完成：https://github.com/$repo\n";
echo "后台「系统与升级 → GitHub 在线更新」现在能读到这份清单：\n";
echo "  https://raw.githubusercontent.com/$repo/main/updates.json\n";
