<?php
declare(strict_types=1);

/**
 * 又拍云 UPYUN（REST API）。
 * 签名：md5(方法&/空间名/路径&HTTP日期)，放在 Authorization: UPYUN 操作员:签名
 * 版本较老的 UPYUN 也支持，兼容性好。
 */
class StorageUpyun extends StorageDriver
{
    public function label(): string
    {
        return '又拍云';
    }

    protected function base(): string
    {
        return 'https://v0.api.upyun.com';
    }

    protected function uri(string $key): string
    {
        $bucket = $this->need('bucket', '空间名');
        return '/' . $bucket . '/' . ltrim($key, '/');
    }

    protected function authHeader(string $method, string $uri, string $date): string
    {
        $op = $this->need('operator', '操作员');
        $pwd = $this->need('password', '操作员密码');
        $sig = md5($method . '&' . $uri . '&' . $date);
        return 'Authorization: UPYUN ' . $op . ':' . $sig;
    }

    public function put(string $key, string $file, string $mime): void
    {
        $body = (string)@file_get_contents($file);
        if ($body === '' && filesize($file) > 0) {
            throw new RuntimeException('读取本地文件失败');
        }
        $uri = $this->uri($key);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $res = $this->http('PUT', $this->base() . $uri, [
            'Date: ' . $date,
            $this->authHeader('PUT', $uri, $date),
            'Content-Type: ' . $mime,
            'Content-Length: ' . strlen($body),
        ], $body);
        $this->expect($res, '上传');
    }

    public function fetch(string $key, string $dest): void
    {
        $uri = $this->uri($key);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $res = $this->http('GET', $this->base() . $uri, [
            'Date: ' . $date,
            $this->authHeader('GET', $uri, $date),
        ]);
        $this->expect($res, '下载');
        if (@file_put_contents($dest, $res['body']) === false) {
            throw new RuntimeException('写入临时文件失败');
        }
    }

    public function drop(string $key): void
    {
        $uri = $this->uri($key);
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $res = $this->http('DELETE', $this->base() . $uri, [
            'Date: ' . $date,
            $this->authHeader('DELETE', $uri, $date),
        ]);
        if ($res['code'] === 404) {
            return;
        }
        $this->expect($res, '删除');
    }

    public function probe(): string
    {
        $key = '.probe/' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'kbup');
        @file_put_contents($tmp, 'ok');
        try {
            $this->put($key, $tmp, 'text/plain');
            $this->drop($key);
        } finally {
            @unlink($tmp);
        }
        return '上传/删除正常：' . $this->need('bucket', '空间名');
    }
}
