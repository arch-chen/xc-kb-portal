<?php
declare(strict_types=1);

/**
 * 阿里云 OSS（V1 签名）。
 * OSS 至今仍接受 V1：Authorization: OSS {AK}:{base64(hmac-sha1(SK, sts))}
 * 不引 SDK，只用到 PUT / GET / DELETE 三个动作。
 */
class StorageOss extends StorageDriver
{
    public function label(): string
    {
        return '阿里云 OSS';
    }

    protected function host(): string
    {
        $bucket = $this->need('bucket', 'Bucket');
        $ep = $this->need('endpoint', 'Endpoint');
        $ep = preg_replace('#^https?://#i', '', $ep) ?? $ep;
        $ep = rtrim($ep, '/');
        // 允许直接填 bucket.oss-cn-xxx.aliyuncs.com
        if (str_starts_with($ep, $bucket . '.')) {
            return $ep;
        }
        return $bucket . '.' . $ep;
    }

    /**
     * V1 签名：sts = 方法\nContent-MD5\nContent-Type\nDate\nCanonicalizedOSSHeaders + CanonicalizedResource
     * 只要请求里带了 x-oss-* 头，就必须把这些头按字典序拼进 CanonicalizedOSSHeaders，
     * 否则签名对不上（这也是 copyFrom 必须单独传 $ossHeaders 的原因）。
     */
    protected function sign(string $method, string $key, string $contentType = '', array $ossHeaders = []): array
    {
        $ak = $this->need('ak', 'AccessKey ID');
        $sk = $this->need('sk', 'AccessKey Secret');
        $bucket = $this->need('bucket', 'Bucket');
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $resource = '/' . $bucket . '/' . ltrim($key, '/');
        if ($key === '' && $method === 'GET') {
            $resource = '/' . $bucket . '/';
        }

        $canon = '';
        if ($ossHeaders) {
            ksort($ossHeaders);
            foreach ($ossHeaders as $k => $v) {
                $canon .= strtolower((string)$k) . ':' . (string)$v . "\n";
            }
        }

        $sts = $method . "\n\n" . $contentType . "\n" . $date . "\n" . $canon . $resource;
        $sig = base64_encode(hash_hmac('sha1', $sts, $sk, true));
        return [
            'Date: ' . $date,
            'Authorization: OSS ' . $ak . ':' . $sig,
        ];
    }

    protected function urlFor(string $key): string
    {
        return 'https://' . $this->host() . '/' . ltrim($key, '/');
    }

    public function put(string $key, string $file, string $mime): void
    {
        $body = (string)@file_get_contents($file);
        if ($body === '' && filesize($file) > 0) {
            throw new RuntimeException('读取本地文件失败');
        }
        $h = $this->sign('PUT', $key, $mime);
        $h[] = 'Content-Type: ' . $mime;
        $h[] = 'Content-Length: ' . strlen($body);
        $res = $this->http('PUT', $this->urlFor($key), $h, $body);
        $this->expect($res, '上传');
    }

    public function fetch(string $key, string $dest): void
    {
        $res = $this->http('GET', $this->urlFor($key), $this->sign('GET', $key));
        $this->expect($res, '下载');
        if (@file_put_contents($dest, $res['body']) === false) {
            throw new RuntimeException('写入临时文件失败');
        }
    }

    /**
     * 服务端复制：PUT + x-oss-copy-source。
     * 同账号、同 endpoint（跨 region 的复制 OSS 不支持走这个头，要开数据复制功能）。
     */
    public function copyFrom(array $src, string $key): bool
    {
        if ((string)($src['driver'] ?? '') !== 'oss') {
            return false;
        }
        $sc = (array)($src['config'] ?? []);
        if ((string)($sc['ak'] ?? '') !== $this->opt('ak')) {
            return false;
        }
        if (rtrim((string)($sc['endpoint'] ?? ''), '/') !== rtrim($this->opt('endpoint'), '/')) {
            return false;
        }
        $srcBucket = trim((string)($src['bucket'] ?? ''));
        $srcKey = (string)($src['key'] ?? '');
        if ($srcBucket === '' || $srcKey === '') {
            return false;
        }

        $source = '/' . $srcBucket . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($srcKey, '/'))));
        $h = $this->sign('PUT', $key, '', ['x-oss-copy-source' => $source]);
        $h[] = 'x-oss-copy-source: ' . $source;
        $h[] = 'Content-Length: 0';
        $res = $this->http('PUT', $this->urlFor($key), $h, '');

        if ($res['code'] >= 200 && $res['code'] < 300) {
            // OSS 同样会把错误塞在 200 响应体的 <Error> 里
            if (stripos($res['body'], '<Error>') !== false) {
                return false;
            }
            return true;
        }
        return false;
    }

    public function drop(string $key): void
    {
        $res = $this->http('DELETE', $this->urlFor($key), $this->sign('DELETE', $key));
        if ($res['code'] === 404) {
            return;
        }
        $this->expect($res, '删除');
    }

    public function probe(): string
    {
        $key = '.probe/' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'kboss');
        @file_put_contents($tmp, 'ok');
        try {
            $this->put($key, $tmp, 'text/plain');
            $this->drop($key);
        } finally {
            @unlink($tmp);
        }
        return '上传/删除正常：' . $this->host();
    }
}
