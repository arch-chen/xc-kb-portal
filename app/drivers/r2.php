<?php
declare(strict_types=1);

/**
 * Cloudflare R2（S3 兼容，AWS SigV4）。
 * 区域固定 auto，path-style：/{bucket}/{key}
 * 签名要点：payload 哈希必须真实（PUT 用文件 sha256，GET/DELETE 用空串哈希）。
 */
class StorageR2 extends StorageDriver
{
    public function label(): string
    {
        return 'Cloudflare R2';
    }

    protected function host(): string
    {
        return $this->need('account', 'Account ID') . '.r2.cloudflarestorage.com';
    }

    /** 自定义 Endpoint：兼容自建 MinIO / 其它 S3 网关；留空走 Cloudflare */
    protected function baseUrl(): string
    {
        $ep = rtrim($this->opt('endpoint'), '/');
        return $ep !== '' ? $ep : 'https://' . $this->host();
    }

    /** 参与签名的 Host（自定义 endpoint 时含端口） */
    protected function hostHeader(): string
    {
        $ep = $this->opt('endpoint');
        if ($ep !== '') {
            $h = parse_url($ep, PHP_URL_HOST) ?: '';
            $p = parse_url($ep, PHP_URL_PORT);
            return $p ? $h . ':' . $p : $h;
        }
        return $this->host();
    }

    protected function pathFor(?string $key): string
    {
        $bucket = $this->need('bucket', 'Bucket');
        if ($key === null || $key === '') {
            return '/' . $bucket;
        }
        return '/' . $bucket . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    protected function signedHeaders(string $method, ?string $key, string $payloadHash): array
    {
        $ak = $this->need('ak', 'Access Key ID');
        $sk = $this->need('sk', 'Secret Access Key');
        $host = $this->hostHeader();
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $path = $this->pathFor($key);

        $canonical = $method . "\n"
            . $path . "\n"
            . '' . "\n"
            . 'host:' . $host . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $amzDate . "\n"
            . '' . "\n"
            . 'host;x-amz-content-sha256;x-amz-date' . "\n"
            . $payloadHash;

        $scope = $dateStamp . '/auto/s3/aws4_request';
        $sts = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonical);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $sk, true);
        $kRegion = hash_hmac('sha256', 'auto', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $sig = hash_hmac('sha256', $sts, $kSigning);

        return [
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amzDate,
            'Authorization: AWS4-HMAC-SHA256 Credential=' . $ak . '/' . $scope
                . ', SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=' . $sig,
        ];
    }

    protected function urlFor(?string $key): string
    {
        return $this->baseUrl() . $this->pathFor($key);
    }

    public function put(string $key, string $file, string $mime): void
    {
        $body = (string)@file_get_contents($file);
        if ($body === '' && filesize($file) > 0) {
            throw new RuntimeException('读取本地文件失败');
        }
        $hash = hash('sha256', $body);
        $h = $this->signedHeaders('PUT', $key, $hash);
        $h[] = 'Content-Type: ' . $mime;
        $h[] = 'Content-Length: ' . strlen($body);
        $res = $this->http('PUT', $this->urlFor($key), $h, $body);
        $this->expect($res, '上传');
    }

    public function fetch(string $key, string $dest): void
    {
        $empty = hash('sha256', '');
        $res = $this->http('GET', $this->urlFor($key), $this->signedHeaders('GET', $key, $empty));
        $this->expect($res, '下载');
        if (@file_put_contents($dest, $res['body']) === false) {
            throw new RuntimeException('写入临时文件失败');
        }
    }

    /**
     * 服务端复制：PUT + x-amz-copy-source，由 R2/MinIO 自己去源桶取对象。
     * 要求同 account、同 endpoint（跨 Cloudflare 账户没有读权限，跨厂商不认这个头）。
     * S3 的坑：失败也可能回 200，错误埋在响应体的 <Error> 里。
     */
    public function copyFrom(array $src, string $key): bool
    {
        if ((string)($src['driver'] ?? '') !== 'r2') {
            return false;
        }
        $sc = (array)($src['config'] ?? []);
        if ((string)($sc['account'] ?? '') !== $this->opt('account')) {
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
        $h = $this->signedHeaders('PUT', $key, hash('sha256', ''));
        $h[] = 'x-amz-copy-source: ' . $source;
        $h[] = 'Content-Length: 0';
        $res = $this->http('PUT', $this->urlFor($key), $h, '');

        if ($res['code'] >= 200 && $res['code'] < 300) {
            if (stripos($res['body'], '<Error>') !== false) {
                return false; // 权限/源对象不存在之类，让调用方退回中转，别在同步任务里假装成功
            }
            return true;
        }
        return false;
    }

    public function drop(string $key): void
    {
        $empty = hash('sha256', '');
        $res = $this->http('DELETE', $this->urlFor($key), $this->signedHeaders('DELETE', $key, $empty));
        if ($res['code'] === 404) {
            return;
        }
        $this->expect($res, '删除');
    }

    public function probe(): string
    {
        $empty = hash('sha256', '');
        $res = $this->http('HEAD', $this->urlFor(null), $this->signedHeaders('HEAD', null, $empty));
        if ($res['code'] >= 200 && $res['code'] < 300) {
            return '存储桶可访问：' . $this->need('bucket', 'Bucket');
        }
        if ($res['code'] === 404) {
            throw new RuntimeException('存储桶不存在，或 Access Key 没有权限');
        }
        $this->expect($res, '连接测试');
        return '';
    }
}
