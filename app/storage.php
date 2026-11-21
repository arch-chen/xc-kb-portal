<?php
declare(strict_types=1);

/**
 * 存储驱动层。
 *
 * 一个平台 = storage_providers 一行；驱动只负责「把这段字节放到那边 / 取回来 / 删掉」，
 * 不掺业务逻辑。多副本、灾备同步、权限都在 app/files.php 里。
 *
 * 约束：零依赖。5 个云平台的签名全部手写，不用 SDK。
 */

abstract class StorageDriver
{
    protected array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /** 上传本地文件到 $key */
    abstract public function put(string $key, string $file, string $mime): void;

    /** 下载 $key 到本地 $dest（失败抛异常） */
    abstract public function fetch(string $key, string $dest): void;

    /** 删除 $key（不存在也算成功） */
    abstract public function drop(string $key): void;

    /** 连通性测试：成功返回一句人话，失败抛异常 */
    abstract public function probe(): string;

    /** 驱动显示名 */
    abstract public function label(): string;

    /** 驱动标识，与 storage_providers.driver 一致（StorageR2 -> r2） */
    public function driverName(): string
    {
        $c = static::class;
        return strtolower(str_starts_with($c, 'Storage') ? substr($c, 7) : $c);
    }

    /**
     * 服务端复制：让本平台自己去源桶把对象搬到自己的 bucket 里，数据不经过本站磁盘。
     *
     * $src = ['driver'=>string, 'bucket'=>string, 'key'=>string, 'config'=>array]
     *
     * 返回 true = 已复制成功；false = 本平台/本场景不支持（调用方自动退化为本地中转）。
     * 只有「同厂商 + 同账号」才可能成功，跨厂商没有任何互认的 copy 协议。
     */
    public function copyFrom(array $src, string $key): bool
    {
        return false;
    }

    protected function opt(string $k, string $d = ''): string
    {
        $v = $this->cfg[$k] ?? $d;
        return is_scalar($v) ? trim((string)$v) : $d;
    }

    protected function need(string $k, string $label): string
    {
        $v = $this->opt($k);
        if ($v === '') {
            throw new RuntimeException('缺少配置项：' . $label);
        }
        return $v;
    }

    /**
     * 通用 HTTP 请求（curl）。
     * $body 为 null 表示无请求体；返回 ['code'=>int, 'body'=>string]。
     * 大文件走内存，所以上传上限要卡在 settings 的 file_max_mb 上，默认 20MB。
     */
    protected function http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'kb-portal/1.7 (+php)',
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if (defined('CURLOPT_PROTOCOLS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('网络请求失败：' . ($err !== '' ? $err : '未知错误'));
        }
        return ['code' => $code, 'body' => (string)$resp];
    }

    /** 统一把非 2xx 转成异常，消息带上响应体前 200 字（云端报错通常就在里面） */
    protected function expect(array $res, string $what): string
    {
        if ($res['code'] >= 200 && $res['code'] < 300) {
            return $res['body'];
        }
        $msg = trim(preg_replace('/\s+/u', ' ', mb_substr($res['body'], 0, 200)) ?? '');
        throw new RuntimeException($what . '失败（HTTP ' . $res['code'] . '）' . ($msg !== '' ? '：' . $msg : ''));
    }
}

/** 支持的驱动清单（后台下拉框直接用这个） */
function storage_driver_labels(): array
{
    return [
        'local' => '本地磁盘',
        'oss'   => '阿里云 OSS',
        'cos'   => '腾讯云 COS',
        'qiniu' => '七牛云',
        'upyun' => '又拍云',
        'r2'    => 'Cloudflare R2（S3 兼容）',
    ];
}

/** 各驱动需要的配置字段（后台表单按这个渲染，密钥类字段加密存） */
function storage_driver_fields(string $driver): array
{
    $map = [
        'local' => [
            'root' => ['本地目录', '相对站点根目录，默认 storage/uploads', 'storage/uploads', false],
        ],
        'oss' => [
            'endpoint' => ['Endpoint', '如 oss-cn-hangzhou.aliyuncs.com（不带 bucket）', 'oss-cn-hangzhou.aliyuncs.com', false],
            'bucket'   => ['Bucket', '存储桶名', '', false],
            'ak'       => ['AccessKey ID', '', '', false],
            'sk'       => ['AccessKey Secret', '加密保存，页面不回显', '', true],
            'domain'   => ['自定义域名（可选）', '如 cdn.example.com，填了下载走它', '', false],
        ],
        'cos' => [
            'region' => ['Region', '如 ap-guangzhou', 'ap-guangzhou', false],
            'bucket' => ['Bucket', '形如 kb-1250000000（带 APPID）', '', false],
            'ak'     => ['SecretId', '', '', false],
            'sk'     => ['SecretKey', '加密保存', '', true],
            'domain' => ['自定义域名（可选）', '', '', false],
        ],
        'qiniu' => [
            'bucket' => ['Bucket', '七牛控制台里的空间名（不是这里的显示名称）', '', false],
            'ak'     => ['AccessKey', '密钥管理里的 AK', '', false],
            'sk'     => ['SecretKey', '加密保存，编辑时留空表示不修改', '', true],
            'domain' => ['绑定域名', '只填域名，如 img.example.com；不要带 https:// 和路径', '', false],
            'host'   => ['上传加速域名（可选）', '留空用默认 up.qiniup.com；华东可填 up-z0.qiniup.com，不要带 https://', 'up.qiniup.com', false],
        ],
        'upyun' => [
            'bucket'   => ['空间名', '', '', false],
            'operator' => ['操作员', '', '', false],
            'password' => ['操作员密码', '加密保存', '', true],
            'domain'   => ['绑定域名（可选）', '', '', false],
        ],
        'r2' => [
            'account' => ['Account ID', 'Cloudflare 后台的账户 ID（自建 MinIO 可随意填）', '', false],
            'bucket'  => ['Bucket', '', '', false],
            'ak'      => ['Access Key ID', '', '', false],
            'sk'      => ['Secret Access Key', '加密保存', '', true],
            'endpoint' => ['自定义 Endpoint（可选）', '如 https://minio.example.com 或 http://127.0.0.1:9000；留空走 Cloudflare', '', false],
            'domain'  => ['公开域名（可选）', '如 pub-xxx.r2.dev，仅公开桶可用', '', false],
        ],
    ];
    return $map[$driver] ?? [];
}

/** 解密平台配置（库里存的是 secret_encrypt 后的 JSON） */
function provider_config(array $p): array
{
    $raw = (string)($p['config'] ?? '');
    if ($raw === '') {
        return [];
    }
    $plain = $raw;
    if (function_exists('secret_decrypt') && str_starts_with($raw, 'g1:')) {
        $plain = secret_decrypt($raw);
    }
    $d = json_decode($plain, true);
    return is_array($d) ? $d : [];
}

/** 按平台行实例化驱动 */
function provider_driver(array $p): StorageDriver
{
    $cls = 'Storage' . ucfirst((string)$p['driver']);
    if (!class_exists($cls)) {
        throw new RuntimeException('不支持的驱动：' . (string)$p['driver']);
    }
    /** @var StorageDriver $d */
    $d = new $cls(provider_config($p));
    return $d;
}

/** 启用的平台，主平台排最前 */
function storage_providers(bool $onlyEnabled = false): array
{
    $sql = 'SELECT * FROM ' . tbl('storage_providers');
    if ($onlyEnabled) {
        $sql .= ' WHERE enabled = 1';
    }
    $sql .= ' ORDER BY is_primary DESC, sort, id';
    return all($sql);
}

/** 新文件默认写到这个平台 */
function storage_primary(): ?array
{
    $p = one('SELECT * FROM ' . tbl('storage_providers') . ' WHERE enabled = 1 ORDER BY is_primary DESC, sort, id LIMIT 1');
    return $p ?: null;
}

function provider_by_id(int $id): ?array
{
    return one('SELECT * FROM ' . tbl('storage_providers') . ' WHERE id = ?', [$id]);
}

/** 平台使用的桶名（本地磁盘驱动没有 bucket，用它的根目录代替） */
function storage_bucket_of(array $p): string
{
    $c = provider_config($p);
    $bucket = trim((string)($c['bucket'] ?? ''));
    if ($bucket !== '') {
        return $bucket;
    }
    return trim((string)($c['root'] ?? ''));
}

/**
 * 账号指纹：「同厂商 + 同凭证」才算同一个账号。
 * 服务端复制是目标云拿自己的密钥去源桶取对象，跨账号要额外授权，所以只在同账号时尝试。
 * 返回空串表示配置不全，不做判断。
 */
function storage_account_sig(array $p): string
{
    $keys = [
        'oss'   => ['ak', 'endpoint'],
        'cos'   => ['ak', 'region'],
        'r2'    => ['ak', 'account', 'endpoint'],
        'qiniu' => ['ak'],
        'upyun' => ['operator'],
        'local' => ['root'],
    ][(string)$p['driver']] ?? [];
    if (!$keys) {
        return '';
    }
    $c = provider_config($p);
    $parts = [(string)$p['driver']];
    foreach ($keys as $k) {
        $v = trim((string)($c[$k] ?? ''));
        if ($v === '') {
            return ''; // 关键字段缺一个就不敢认定同账号
        }
        $parts[] = $v;
    }
    return hash('sha256', implode('|', $parts));
}

/** 两个平台是否同一个账号（服务端复制的前提，注意「同一个桶」不算，桶必须不同才有同步的意义） */
function storage_same_account(array $a, array $b): bool
{
    $sa = storage_account_sig($a);
    return $sa !== '' && $sa === storage_account_sig($b);
}

/* ---------------- 临时区（中转/下载暂存） ---------------- */

/** 站点临时目录 storage/tmp：与「本地磁盘存储平台」无关，禁用 local 平台它照样在 */
function storage_tmp_dir(): string
{
    $d = BASE_PATH . '/storage/tmp';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

/** 分配一个中转用的临时文件名（先 touch 出来，异常残留也能被 gc 认出来） */
function storage_tmp_file(string $ext, string $prefix = 'sync'): ?string
{
    $ext = preg_match('/^[a-z0-9]{1,8}$/', strtolower($ext)) ? strtolower($ext) : 'bin';
    $path = storage_tmp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    return @touch($path) ? $path : null;
}

/**
 * 清掉陈旧的临时文件。
 * 为什么要 gc：进程被 kill / PHP 超时时 finally 不执行，sync-*、dl-* 会一直堆着慢性塞盘。
 */
function storage_tmp_gc(int $maxAgeSec = 7200, int $maxScan = 500): int
{
    $dir = storage_tmp_dir();
    $fh = @opendir($dir);
    if (!$fh) {
        return 0;
    }
    $now = time();
    $removed = 0;
    $seen = 0;
    while (($f = readdir($fh)) !== false) {
        if ($f === '.' || $f === '..' || $f === '.gitkeep') {
            continue;
        }
        if (++$seen > $maxScan) {
            break;
        }
        if (!preg_match('/^(sync|dl|import|up)-[0-9a-f]+/i', $f)) {
            continue;
        }
        $path = $dir . '/' . $f;
        $mt = @filemtime($path);
        if ($mt !== false && $now - $mt > $maxAgeSec && @unlink($path)) {
            $removed++;
        }
    }
    closedir($fh);
    return $removed;
}

/** storage/tmp 里当前有多少字节（后台展示用） */
function storage_tmp_usage(): array
{
    $dir = storage_tmp_dir();
    $files = 0;
    $bytes = 0;
    foreach (glob($dir . '/*') ?: [] as $p) {
        if (is_file($p) && basename($p) !== '.gitkeep') {
            $files++;
            $bytes += (int)@filesize($p);
        }
    }
    return ['files' => $files, 'bytes' => $bytes];
}

/**
 * 中转前检查磁盘余量，不够就直接拒绝（宁可任务失败，也不要写半个文件）。
 * 需要 2 倍文件大小 + 64MB 余量：中转峰值就是单文件大小，留一点给日志和 sqlite。
 */
function storage_require_space(int $needBytes): void
{
    $free = @disk_free_space(BASE_PATH);
    if ($free === false) {
        return; // 取不到就当作不限，别因为环境差异卡住同步
    }
    $need = max(64 * 1048576, $needBytes * 2 + 64 * 1048576);
    if ($free < $need) {
        throw new RuntimeException(
            '服务器临时磁盘余量不足：剩 ' . round($free / 1048576) . ' MB，本次至少需要 ' . round($need / 1048576) . ' MB'
        );
    }
}

/** 上传限额（MB） */
function file_max_mb(): int
{
    $mb = (int)setting_get('file_max_mb', '20');
    return $mb > 0 ? min($mb, 200) : 20;
}

/** 允许的扩展名。刻意不含 svg（可内嵌脚本）、html、php、js 这类可执行/可提权格式 */
function file_allowed_ext(): array
{
    return [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico',
        'pdf', 'txt', 'md', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'rar', '7z',
        'mp4', 'mov', 'mp3', 'wav',
    ];
}

/** 按扩展名给个保守的 Content-Type（下载时用，避免内容嗅探） */
function file_mime_by_ext(string $ext): string
{
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/** 这些扩展名在浏览器里可以直接看，其余强制下载 */
function file_inline_ext(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'pdf', 'txt', 'md', 'mp4', 'mov', 'mp3', 'wav'];
}

// 驱动实现（一个平台一个文件，放在 app/drivers/）
foreach (glob(__DIR__ . '/drivers/*.php') ?: [] as $__drv) {
    require_once $__drv;
}
unset($__drv);
