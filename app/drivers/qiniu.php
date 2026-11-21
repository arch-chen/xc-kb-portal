<?php
declare(strict_types=1);

/**
 * 七牛云 Kodo。
 * 上传：表单 POST 到 up.qiniup.com（可换加速域名），token = AK:base64url(HMAC-SHA1(SK, policy))
 * 下载：私有空间要带 e + token；token 对「完整 URL（含 e）」再签一次
 * 删除：管理 API rs.qiniuapi.com/delete/{base64url(bucket:key)}
 */
class StorageQiniu extends StorageDriver
{
    public function label(): string
    {
        return '七牛云';
    }

    protected function b64url(string $raw): string
    {
        return str_replace(['+', '/'], ['-', '_'], base64_encode($raw));
    }

    protected function uploadHost(): string
    {
        $h = trim((string)$this->opt('host', 'up.qiniup.com'));
        if ($h === '') { $h = 'up.qiniup.com'; }
        $h = preg_replace('#^https?://#i', '', $h) ?? $h;
        // 同样容错「粘了完整 URL」的情况，上传域名只能是 host
        $host = parse_url('https://' . $h, PHP_URL_HOST);
        if (is_string($host) && $host !== '') { $h = $host; }
        return rtrim($h, '/');
    }

    protected function domain(): string
    {
        $d = trim($this->need('domain', '绑定域名'));
        $d = preg_replace('#^https?://#i', '', $d) ?? $d;
        // 后台常见误填：把 https://cdn.example.com/some/path 整条粘进来。
        // 绑定域名只能是 host，路径会拼成 404 的下载 URL，这里容错只取主机部分。
        $host = parse_url('https://' . $d, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $port = parse_url('https://' . $d, PHP_URL_PORT);
            $d = $host . ($port ? ':' . $port : '');
        }
        return rtrim($d, '/');
    }

    protected function uploadToken(string $key): string
    {
        $ak = $this->need('ak', 'AccessKey');
        $sk = $this->need('sk', 'SecretKey');
        $bucket = $this->need('bucket', 'Bucket');
        $policy = json_encode([
            'scope'    => $bucket . ':' . $key,
            'deadline' => time() + 3600,
        ], JSON_UNESCAPED_SLASHES);
        return $ak . ':' . $this->b64url(hash_hmac('sha1', (string)$policy, $sk, true));
    }

    public function put(string $key, string $file, string $mime): void
    {
        $bytes = (string)@file_get_contents($file);
        if ($bytes === '' && filesize($file) > 0) {
            throw new RuntimeException('读取本地文件失败');
        }
        $boundary = '----kb' . bin2hex(random_bytes(8));
        $eol = "\r\n";
        $body = '';
        $body .= '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="token"' . $eol . $eol
            . $this->uploadToken($key) . $eol;
        $body .= '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="key"' . $eol . $eol
            . $key . $eol;
        $body .= '--' . $boundary . $eol
            . 'Content-Disposition: form-data; name="file"; filename="' . basename($key) . '"' . $eol
            . 'Content-Type: ' . $mime . $eol . $eol
            . $bytes . $eol;
        $body .= '--' . $boundary . '--' . $eol;

        $res = $this->http('POST', 'https://' . $this->uploadHost() . '/', [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ], $body);

        if ($res['code'] >= 200 && $res['code'] < 300) {
            $d = json_decode($res['body'], true);
            if (is_array($d) && isset($d['error'])) {
                throw new RuntimeException('上传失败：' . (string)$d['error']);
            }
            return;
        }
        $this->expect($res, '上传');
    }

    protected function downloadUrl(string $key): string
    {
        $ak = $this->need('ak', 'AccessKey');
        $sk = $this->need('sk', 'SecretKey');
        $base = 'https://' . $this->domain() . '/' . ltrim($key, '/') . '?e=' . (time() + 3600);
        $token = $ak . ':' . $this->b64url(hash_hmac('sha1', $base, $sk, true));
        return $base . '&token=' . $token;
    }

    public function fetch(string $key, string $dest): void
    {
        $res = $this->http('GET', $this->downloadUrl($key));
        $this->expect($res, '下载');
        if (@file_put_contents($dest, $res['body']) === false) {
            throw new RuntimeException('写入临时文件失败');
        }
    }

    /**
     * 服务端复制：rs 的 /copy/<源entry>/<目标entry>，同账号跨空间可以，数据不落本站。
     */
    public function copyFrom(array $src, string $key): bool
    {
        if ((string)($src['driver'] ?? '') !== 'qiniu') {
            return false;
        }
        $sc = (array)($src['config'] ?? []);
        if ((string)($sc['ak'] ?? '') !== $this->opt('ak')) {
            return false;
        }
        $srcBucket = trim((string)($src['bucket'] ?? ''));
        $srcKey = (string)($src['key'] ?? '');
        if ($srcBucket === '' || $srcKey === '') {
            return false;
        }
        if ($srcBucket . ':' . $srcKey === $this->need('bucket', 'Bucket') . ':' . $key) {
            return true; // 同一个对象，没什么可复制的
        }

        $ak = $this->need('ak', 'AccessKey');
        $sk = $this->need('sk', 'SecretKey');
        $path = '/copy/' . $this->b64url($srcBucket . ':' . $srcKey)
            . '/' . $this->b64url($this->need('bucket', 'Bucket') . ':' . $key);
        $sign = $ak . ':' . $this->b64url(hash_hmac('sha1', $path . "\n", $sk, true));

        $res = $this->http('POST', 'https://rs.qiniuapi.com' . $path, [
            'Authorization: QBox ' . $sign,
            'Content-Type: application/x-www-form-urlencoded',
        ], '');
        return $res['code'] >= 200 && $res['code'] < 300;
    }

    public function drop(string $key): void
    {
        $ak = $this->need('ak', 'AccessKey');
        $sk = $this->need('sk', 'SecretKey');
        $bucket = $this->need('bucket', 'Bucket');

        $entry = $this->b64url($bucket . ':' . $key);
        $path = '/delete/' . $entry;
        $sign = $ak . ':' . $this->b64url(hash_hmac('sha1', $path . "\n", $sk, true));

        $res = $this->http('POST', 'https://rs.qiniuapi.com' . $path, [
            'Authorization: QBox ' . $sign,
            'Content-Type: application/x-www-form-urlencoded',
        ], '');
        if ($res['code'] === 404 || $res['code'] === 612) {
            return;
        }
        $this->expect($res, '删除');
    }

    public function probe(): string
    {
        $key = '.probe/' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'kbqn');
        @file_put_contents($tmp, 'ok');
        try {
            $this->put($key, $tmp, 'text/plain');
            $this->drop($key);
        } finally {
            @unlink($tmp);
        }
        return '上传/删除正常：' . $this->uploadHost();
    }
}
