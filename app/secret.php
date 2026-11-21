<?php
declare(strict_types=1);

/**
 * API Key 明文的可逆存储。
 *
 * 早期版本只存 HMAC 哈希，Key 只能显示一次。现在为了「随时回显」，
 * 把明文用 AES-256-GCM 加密后存在 api_keys.key_plain：
 *   - 密钥由 config.php 里的 security.pepper 派生，不在库里
 *   - 所以单拿走数据库文件（storage/kb.sqlite）看不到明文，
 *     得同时拿到 config.php 才能解，比直接存明文安全一档
 *   - key_hash 仍然保留并继续用于鉴权校验，key_plain 只服务于「回显」
 */

const SECRET_BLOB_PREFIX = 'g1:';

/** 32 字节对称密钥（从 pepper 派生，换 pepper 会导致老 Key 明文解不开） */
function secret_key(): string
{
    $pepper = (string)(cfg()['security']['pepper'] ?? '');
    if ($pepper === '') {
        $pepper = 'kb-portal-no-pepper';
    }
    return hash('sha256', 'kb-api-key-plain|' . $pepper, true);
}

/** 加密成可入库的字符串；环境不支持时返回空串（此时调用方按「无法回显」处理） */
function secret_encrypt(string $plain): string
{
    if ($plain === '' || !function_exists('openssl_encrypt')) {
        return '';
    }
    try {
        $iv = random_bytes(12);
    } catch (Throwable $e) {
        return '';
    }
    $tag = '';
    $ct = @openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false || $tag === '') {
        return '';
    }
    return SECRET_BLOB_PREFIX . base64_encode($iv . $tag . $ct);
}

/** 解密；任何异常都返回空串，绝不让它抛到页面上 */
function secret_decrypt(?string $blob): string
{
    if (!is_string($blob) || $blob === '' || !str_starts_with($blob, SECRET_BLOB_PREFIX)) {
        return '';
    }
    if (!function_exists('openssl_decrypt')) {
        return '';
    }
    $raw = base64_decode(substr($blob, strlen(SECRET_BLOB_PREFIX)), true);
    if ($raw === false || strlen($raw) <= 28) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $out = @openssl_decrypt($ct, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $out === false ? '' : $out;
}

/**
 * 脱敏：保留前缀和后 6 位，中间用圆点。
 * kb_b68d50d7172f_3bd696... → kb_b68d50d7172f_••••••••••1cfe6
 */
function secret_mask(string $key): string
{
    $len = strlen($key);
    if ($len <= 20) {
        return str_repeat('•', max(4, $len));
    }
    return substr($key, 0, 15) . str_repeat('•', 10) . substr($key, -6);
}
