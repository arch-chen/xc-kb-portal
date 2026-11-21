<?php
declare(strict_types=1);

/**
 * 腾讯云 COS（请求签名）。
 * 文档：SignKey = HMAC-SHA1(SecretKey, KeyTime)，再对 HttpString 的 SHA1 做 HMAC-SHA1。
 * 只用 path-style 的 bucket.cos.region.myqcloud.com 域名。
 */
class StorageCos extends StorageDriver
{
    public function label(): string
    {
        return '腾讯云 COS';
    }

    protected function host(): string
    {
        $bucket = $this->need('bucket', 'Bucket');
        $region = $this->need('region', 'Region');
        if (str_contains($bucket, '.cos.')) {
            return $bucket;
        }
        return $bucket . '.cos.' . $region . '.myqcloud.com';
    }

    protected function authorization(string $method, string $key): string
    {
        $ak = $this->need('ak', 'SecretId');
        $sk = $this->need('sk', 'SecretKey');
        $host = $this->host();

        $start = time() - 60;
        $end = time() + 3600;
        $keyTime = $start . ';' . $end;

        $uri = '/' . ltrim($key, '/');
        // 逐段编码，保留斜杠
        $uri = implode('/', array_map('rawurlencode', explode('/', $uri)));

        $headerList = 'host';
        $headerStr = 'host=' . rawurlencode(strtolower($host));
        $httpString = strtolower($method) . "\n" . $uri . "\n" . '' . "\n" . $headerStr . "\n";

        $stringToSign = "sha1\n" . $keyTime . "\n" . sha1($httpString) . "\n";
        $signKey = hash_hmac('sha1', $keyTime, $sk);
        $sig = hash_hmac('sha1', $stringToSign, $signKey);

        return 'q-sign-algorithm=sha1'
            . '&q-ak=' . rawurlencode($ak)
            . '&q-sign-time=' . $keyTime
            . '&q-key-time=' . $keyTime
            . '&q-header-list=' . $headerList
            . '&q-url-param-list='
            . '&q-signature=' . $sig;
    }

    protected function urlFor(string $key): string
    {
        $uri = implode('/', array_map('rawurlencode', explode('/', '/' . ltrim($key, '/'))));
        return 'https://' . $this->host() . $uri;
    }

    public function put(string $key, string $file, string $mime): void
    {
        $body = (string)@file_get_contents($file);
        if ($body === '' && filesize($file) > 0) {
            throw new RuntimeException('读取本地文件失败');
        }
        $h = [
            'Host: ' . $this->host(),
            'Content-Type: ' . $mime,
            'Content-Length: ' . strlen($body),
            'Authorization: ' . $this->authorization('PUT', $key),
        ];
        $res = $this->http('PUT', $this->urlFor($key), $h, $body);
        $this->expect($res, '上传');
    }

    public function fetch(string $key, string $dest): void
    {
        $h = [
            'Host: ' . $this->host(),
            'Authorization: ' . $this->authorization('GET', $key),
        ];
        $res = $this->http('GET', $this->urlFor($key), $h);
        $this->expect($res, '下载');
        if (@file_put_contents($dest, $res['body']) === false) {
            throw new RuntimeException('写入临时文件失败');
        }
    }

    /**
     * 服务端复制：PUT + x-cos-copy-source（同账号同 region）。
     * 该头不参与签名（q-header-list 仍然只有 host），COS 允许只对部分头签名。
     */
    public function copyFrom(array $src, string $key): bool
    {
        if ((string)($src['driver'] ?? '') !== 'cos') {
            return false;
        }
        $sc = (array)($src['config'] ?? []);
        if ((string)($sc['ak'] ?? '') !== $this->opt('ak')) {
            return false;
        }
        if ((string)($sc['region'] ?? '') !== $this->opt('region')) {
            return false;
        }
        $srcBucket = trim((string)($src['bucket'] ?? ''));
        $srcKey = (string)($src['key'] ?? '');
        if ($srcBucket === '' || $srcKey === '') {
            return false;
        }
        if (str_contains($srcBucket, '.cos.')) {
            $srcHost = $srcBucket;
        } else {
            $srcHost = $srcBucket . '.cos.' . $this->opt('region') . '.myqcloud.com';
        }
        $srcPath = implode('/', array_map('rawurlencode', explode('/', '/' . ltrim($srcKey, '/'))));

        $h = [
            'Host: ' . $this->host(),
            'x-cos-copy-source: ' . $srcHost . $srcPath,
            'Content-Length: 0',
            'Authorization: ' . $this->authorization('PUT', $key),
        ];
        $res = $this->http('PUT', $this->urlFor($key), $h, '');

        if ($res['code'] >= 200 && $res['code'] < 300) {
            if (stripos($res['body'], '<Error>') !== false) {
                return false;
            }
            return true;
        }
        return false;
    }

    public function drop(string $key): void
    {
        $h = [
            'Host: ' . $this->host(),
            'Authorization: ' . $this->authorization('DELETE', $key),
        ];
        $res = $this->http('DELETE', $this->urlFor($key), $h);
        if ($res['code'] === 404) {
            return;
        }
        $this->expect($res, '删除');
    }

    public function probe(): string
    {
        $key = '.probe/' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'kbcos');
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
