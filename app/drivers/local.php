<?php
declare(strict_types=1);

/**
 * 本地磁盘驱动（默认平台，不配云也能用）。
 * 目录在 web 根内的 storage/uploads，靠 nginx/Apache 规则挡住直链；
 * 就算规则漏了，文件名是随机串，也猜不到。
 */
class StorageLocal extends StorageDriver
{
    public function label(): string
    {
        return '本地磁盘';
    }

    protected function root(): string
    {
        $r = $this->opt('root', 'storage/uploads');
        if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $r)) {
            $r = BASE_PATH . '/' . trim($r, '/');
        }
        return rtrim(str_replace('\\', '/', $r), '/');
    }

    protected function pathOf(string $key): string
    {
        $key = str_replace('\\', '/', $key);
        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            throw new RuntimeException('非法的对象名');
        }
        return $this->root() . '/' . ltrim($key, '/');
    }

    public function put(string $key, string $file, string $mime): void
    {
        $dest = $this->pathOf($key);
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
        if (!@copy($file, $dest)) {
            throw new RuntimeException('写入失败：' . $dest);
        }
        @chmod($dest, 0644);
    }

    public function fetch(string $key, string $dest): void
    {
        $src = $this->pathOf($key);
        if (!is_file($src)) {
            throw new RuntimeException('文件不存在：' . $key);
        }
        if (!@copy($src, $dest)) {
            throw new RuntimeException('读取失败：' . $key);
        }
    }

    public function drop(string $key): void
    {
        $p = $this->pathOf($key);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    public function probe(): string
    {
        $root = $this->root();
        if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('目录不存在且无法创建：' . $root);
        }
        $name = '.probe-' . bin2hex(random_bytes(4)) . '.txt';
        $file = $root . '/' . $name;
        if (@file_put_contents($file, 'ok') === false) {
            throw new RuntimeException('目录不可写：' . $root);
        }
        $back = (string)@file_get_contents($file);
        @unlink($file);
        if ($back !== 'ok') {
            throw new RuntimeException('写入后读不回来，检查磁盘或权限');
        }
        return '目录可读写：' . $root;
    }
}
