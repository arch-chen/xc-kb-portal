<?php
declare(strict_types=1);

/**
 * 在线升级：上传 zip 包 → 校验 → 备份 → 覆盖文件 → 执行迁移 → 更新版本号。
 *
 * 更新包结构（根目录下）：
 *   version.json          {"version":"1.1.0","min_version":"1.0.0","notes":"..."}
 *   files/                要覆盖到站点根目录的文件树
 *   migrations/*.php      每个文件 return function(PDO $pdo): void
 *
 * @param string|null $curVersion 当前版本。链式自动升级时由调用方显式传入，
 *                                因为 APP_VERSION 是进程启动时定义的常量，同进程里升完一级不会变。
 */
function upgrade_apply(string $zipPath, ?string $curVersion = null): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'PHP 缺少 zip 扩展，无法解压更新包'];
    }
    $cur = ($curVersion !== null && $curVersion !== '') ? $curVersion : APP_VERSION;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'message' => '无法打开 zip（文件损坏或不是 zip）'];
    }
    $tmp = '';

    try {
        // 1) 找到 version.json（允许包内有一层目录）
        $verEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));
            if (preg_match('#^(?:[^/]+/)?version\.json$#', $name)) {
                $verEntry = $name;
                break;
            }
        }
        if (!$verEntry) {
            return ['ok' => false, 'message' => '更新包缺少 version.json'];
        }
        $newVer = json_decode((string)$zip->getFromName($verEntry), true);
        if (!is_array($newVer) || empty($newVer['version'])) {
            return ['ok' => false, 'message' => 'version.json 格式不对，缺少 version 字段'];
        }
        $to = (string)$newVer['version'];
        if (version_compare($to, $cur, '<=')) {
            return ['ok' => false, 'message' => "包内版本 {$to} 不高于当前版本 {$cur}，无需升级"];
        }
        if (!empty($newVer['min_version']) && version_compare($cur, (string)$newVer['min_version'], '<')) {
            return ['ok' => false, 'message' => "该更新包要求当前版本不低于 {$newVer['min_version']}，当前是 $cur"];
        }

        $baseDir = rtrim(str_replace('\\', '/', dirname($verEntry)), '/');
        if ($baseDir === '.') {
            $baseDir = '';
        }
        $prefix = $baseDir === '' ? '' : $baseDir . '/';

        // 2) 备份数据库
        $backup = '';
        if (db_driver() === 'sqlite') {
            $src = cfg()['db']['sqlite_path'];
            $dir = BASE_PATH . '/storage/backups';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $backup = $dir . '/db-' . date('Ymd-His') . '-v' . $cur . '.sqlite';
            if (is_file($src) && !@copy($src, $backup)) {
                return ['ok' => false, 'message' => '数据库备份失败，已中止升级'];
            }
        }

        // 3) 解压到临时目录
        $tmp = BASE_PATH . '/storage/tmp/up-' . bin2hex(random_bytes(6));        if (!is_dir($tmp) && !@mkdir($tmp, 0775, true)) {
            return ['ok' => false, 'message' => '无法创建临时目录 storage/tmp'];
        }

        $copied = 0;
        $failed = [];
        $blocked = ['config.php', 'storage/', 'data/'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', $zip->getNameIndex($i));
            if ($prefix !== '') {
                if (!str_starts_with($entry, $prefix)) {
                    continue;
                }
                $rel = substr($entry, strlen($prefix));
            } else {
                $rel = $entry;
            }
            if ($rel === '' || str_ends_with($rel, '/')) {
                continue;
            }
            if (!str_starts_with($rel, 'files/')) {
                continue; // 只覆盖 files/ 下的内容，migrations 单独处理
            }
            $target = substr($rel, strlen('files/'));
            if (!upgrade_safe_path($target)) {
                $failed[] = $rel . '（非法路径）';
                continue;
            }
            foreach ($blocked as $b) {
                if ($target === $b || str_starts_with($target, $b)) {
                    $failed[] = $rel . '（受保护，已跳过）';
                    continue 2;
                }
            }
            $dest = BASE_PATH . '/' . $target;
            $dir = dirname($dest);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                $failed[] = $rel . '（无法创建目录）';
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false || @file_put_contents($dest, $content) === false) {
                $failed[] = $rel . '（写入失败）';
                continue;
            }
            $copied++;
        }

        // 4) 执行迁移
        $migDone = [];
        $migDir = $prefix . 'migrations/';
        $migs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', $zip->getNameIndex($i));
            if (!str_starts_with($entry, $migDir) || !str_ends_with($entry, '.php')) {
                continue;
            }
            $migs[] = $entry;
        }
        sort($migs);
        $pdo = db();
        foreach ($migs as $m) {
            $name = basename($m, '.php');
            $key = 'mig_' . $name;
            if (setting_get($key)) {
                continue;
            }
            $tmpFile = $tmp . '/' . $name . '.php';
            file_put_contents($tmpFile, (string)$zip->getFromName($m));
            $fn = require $tmpFile;
            if (is_callable($fn)) {
                $fn($pdo);
            }
            setting_set($key, date('Y-m-d H:i:s'));
            $migDone[] = $name;
        }

        // 5) 更新版本文件
        @file_put_contents(BASE_PATH . '/version.json',
            json_encode($newVer, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

        // 6) 清理（临时目录在 finally 里统一清）
        if ($backup !== '' && is_file($backup)) {
            // 只保留最近 5 个备份
            $files = glob(dirname($backup) . '/db-*.sqlite') ?: [];
            sort($files);
            while (count($files) > 5) {
                @unlink(array_shift($files));
            }
        }

        audit('upgrade', "v$cur → v$to" . ($migDone ? ' 迁移:' . implode(',', $migDone) : ''));
        $msg = "已升级到 v{$to}，覆盖 {$copied} 个文件";
        if ($migDone) {
            $msg .= '，执行迁移 ' . implode(', ', $migDone);
        }
        if ($failed) {
            $msg .= '；跳过 ' . count($failed) . ' 个：' . implode('、', array_slice($failed, 0, 5));
        }
        if ($backup !== '') {
            $msg .= '；数据库已备份到 storage/backups/' . basename($backup);
        }
        return ['ok' => true, 'message' => $msg];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '升级失败：' . $e->getMessage()
            . '。已覆盖的文件不会自动还原，数据库备份见 storage/backups/'];
    } finally {
        if ($tmp !== '') {
            upgrade_rmdir($tmp);
        }
        $zip->close();
    }
}

/** 拒绝 ..、绝对路径、盘符、协议前缀 */
function upgrade_safe_path(string $rel): bool
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || str_starts_with($rel, '/') || preg_match('#^[a-zA-Z]:#', $rel)) {
        return false;
    }
    $parts = explode('/', $rel);
    foreach ($parts as $p) {
        if ($p === '..' || $p === '') {
            return false;
        }
    }
    return true;
}

function upgrade_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $p = $dir . '/' . $it;
        is_dir($p) ? upgrade_rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
