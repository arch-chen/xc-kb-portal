<?php
declare(strict_types=1);

/**
 * 七牛云 Kodo。
 * 上传：表单 POST 到 up.qiniup.com（可换加速域名），
 *       uptoken = AK:Sign:EncodedPutPolicy（三段；Sign 对 EncodedPutPolicy 签名）
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
        // 与七牛官方 SDK 的 base64_urlSafeEncode 一致：只替换 +/ ，保留 '=' 填充。
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

    /**
     * 上传凭证（uptoken）：必须是三段 AK:Sign:EncodedPutPolicy。
     *
     *   EncodedPutPolicy = base64url(json_encode(policy))
     *   Sign             = base64url(HMAC-SHA1(SecretKey, EncodedPutPolicy))
     *
     * 注意签名对象是「编码后的策略串」，不是原始 JSON。老实现只输出两段 AK:Sign、
     * 且对原始 JSON 签名，服务端一律回 401 {"error":"bad token"}。
     * 与官方 PHP SDK Qiniu\Auth::uploadToken() 保持一致。
     */
    protected function uploadToken(string $key): string
    {
        $ak = $this->need('ak', 'AccessKey');
        $sk = $this->need('sk', 'SecretKey');
        $bucket = $this->need('bucket', 'Bucket');
        $policy = json_encode([
            'scope'    => $bucket . ':' . $key,
            'deadline' => time() + 3600,
        ], JSON_UNESCAPED_SLASHES);
        $encoded = $this->b64url((string)$policy);
        $sign = $this->b64url(hash_hmac('sha1', $encoded, $sk, true));
        return $ak . ':' . $sign . ':' . $encoded;
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
        if ($res['code'] === 401) {
            // bad token 最常见是密钥不属于该账号 / 服务器时间偏差 / 上传域名与空间区域不符，
            // 把实际使用的域名带出来，方便后台直接排查，而不是只丢一句 JSON。
            $body401 = trim((string)$res['body']);
            throw new RuntimeException(
                '上传失败（HTTP 401）：' . ($body401 !== '' ? $body401 : 'bad token')
                . '。请核对：① AccessKey / SecretKey 是否为同一账号的密钥（重新复制粘贴，勿手输）；'
                . '② Bucket 填的是七牛控制台的「空间名」而非显示名称；'
                . '③ 服务器时间是否准确（凭证有效期 1 小时）；'
                . '④ 当前上传加速域名 = ' . $this->uploadHost() . '（留空即走 up.qiniup.com，'
                . '华东可用 up-z0.qiniup.com、华北 up-z1、华南 up-z2）'
            );
        }
        $this->expect($res, '上传');
    }

    protected function downloadUrl(string $key): string
    {
        $ak = $this->need('ak', 'AccessKey');
        $sk = $this->need('sk', 'SecretKey');
        // 私有空间下载凭证：对「含 ?e= 的完整 URL」签名，格式 AK:Sign。
        // 与官方 SDK Auth::privateDownloadUrl() 一致。
        $base = 'https://' . $this->domain() . '/' . ltrim($key, '/') . '?e=' . (time() + 3600);
        $token = $ak . ':' . $this->b64url(hash_hmac('sha1', $base, $sk, true));
        return $base . '&token=' . $token;
    }

    public function fetch(string $key, string $dest): void
    {
        $res = $this->http('GET', $this->downloadUrl($key));
        // 公开空间不需要下载凭证，个别 CDN 会把 ?e=&token= 当成非法参数拒掉。
        // 401/403 时退回裸 URL 再试一次；私有空间仍会失败，由 expect() 正常报出。
        if ($res['code'] === 401 || $res['code'] === 403) {
            $plain = 'https://' . $this->domain() . '/' . ltrim($key, '/');
            $retry = $this->http('GET', $plain);
            if ($retry['code'] >= 200 && $retry['code'] < 300) {
                $res = $retry;
            }
        }
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
