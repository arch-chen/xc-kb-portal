<?php
declare(strict_types=1);

/**
 * 命令行导入（服务器有 SSH/终端时用；没有就用后台的「导入数据」页面）：
 *
 *   php tools/import_wayfair.php --csv=/path/问答总表.csv --group=跨境电商 --user=wayfair
 */

if (PHP_SAPI !== 'cli') {
    exit("只能在命令行运行\n");
}

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
        $args[$m[1]] = $m[2];
    }
}

$root = dirname(__DIR__);
$csv = $args['csv'] ?? ($root . '/data/wayfair-qa.csv');
$groupName = $args['group'] ?? '跨境电商';
$username = $args['user'] ?? 'wayfair';
$source = $args['source'] ?? '微信群 · Wayfair破浪远洋3群';

if (!is_file($csv)) {
    fwrite(STDERR, "找不到 CSV：{$csv}\n");
    exit(1);
}

require $root . '/app/bootstrap.php';

try {
    [$group, $user] = ensure_group_and_perm($groupName, $username);
    echo "· 分组 #{$group['id']} {$group['name']}｜用户 {$user['username']}\n";
    $r = import_csv($csv, (int)$group['id'], (int)$user['id'], $source);
    echo "\n导入完成\n";
    echo "  本次新增 {$r['inserted']} 条，跳过 {$r['skipped']} 条（重复或空行）\n";
    echo "  分组内现有：问答 {$r['qa']} 条 / 文档 {$r['doc']} 篇\n";
    echo "  检索索引：" . (int)val('SELECT COUNT(*) FROM ' . tbl('entry_grams'), [], 0) . " 个二字组\n";
} catch (Throwable $e) {
    fwrite(STDERR, '导入失败：' . $e->getMessage() . "\n");
    exit(1);
}
