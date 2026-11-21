<?php
declare(strict_types=1);

/**
 * 对外开放注册的相关逻辑：开关、限速、验证码、私有空间。
 *
 * 隔离原则：
 *   - 注册即得到一个「私有空间」（groups.owner_id = 该用户），不授权给任何其他人
 *   - 普通用户之间互相看不到，只能看到自己被授权的分组
 *   - 管理员可审计全部（隔离对外，不隔离站长）
 */

/** 是否允许注册（默认关闭由安装决定，库里没值时按开启处理） */
function register_allowed(): bool
{
    return (string)setting_get('allow_register', '1') === '1';
}

/** 同一 IP 每小时最多注册成功几次 */
function register_limit(): int
{
    $n = (int)setting_get('register_per_hour', '5');
    return $n > 0 ? min($n, 100) : 5;
}

/** 限速用的 IP：优先真实客户端，反代场景才看 X-Forwarded-For */
function throttle_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1' || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return $ip !== '' ? $ip : 'unknown';
}

/** 计数窗口内的记录数。$global=true 时统计全站（不区分 IP） */
function throttle_count(string $bucket, int $seconds, bool $global = false): int
{
    $since = date('Y-m-d H:i:s', time() - $seconds);
    return (int)val(
        'SELECT COUNT(*) FROM ' . tbl('throttle') . ' WHERE bucket = ? AND ts >= ?',
        [throttle_key($bucket, $global), $since],
        0
    );
}

/**
 * 记一次并判断是否放行。true = 放行，false = 超限。
 * 超限时不再写入，避免被刷爆表。
 */
function throttle_hit(string $bucket, int $limit, int $seconds, bool $global = false): bool
{
    if (throttle_count($bucket, $seconds, $global) >= $limit) {
        return false;
    }
    insert_row('throttle', ['bucket' => throttle_key($bucket, $global), 'ts' => now_str()]);

    // 小概率清理一天前的旧记录，避免表无限增长
    if (random_int(1, 20) === 1) {
        q('DELETE FROM ' . tbl('throttle') . ' WHERE ts < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
    return true;
}

function throttle_key(string $bucket, bool $global = false): string
{
    // 不存明文 IP，存哈希即可满足计数需求，也少一份隐私负担
    $raw = $global ? $bucket : $bucket . '|' . throttle_ip();
    return substr(sha1($raw), 0, 40);
}

/** 全站每小时最多放多少个新账号进来（防止被批量注册刷库） */
function register_hourly_cap(): int
{
    return max(20, register_limit() * 5);
}

/* ---------------- 算术验证码 ---------------- */

function captcha_new(): string
{
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    if (random_int(0, 1) === 1) {
        // 加法为主，偶尔用减法（保证结果非负）
        $hi = max($a, $b);
        $lo = min($a, $b);
        $_SESSION['captcha'] = ['n' => $hi - $lo, 'exp' => time() + 600];
        return $hi . ' − ' . $lo . ' = ?';
    }
    $_SESSION['captcha'] = ['n' => $a + $b, 'exp' => time() + 600];
    return $a . ' + ' . $b . ' = ?';
}

/** 校验验证码：无论对错都作废，防止同一题反复试 */
function captcha_check(string $input): bool
{
    $c = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']);
    if (!is_array($c) || (int)($c['exp'] ?? 0) < time()) {
        return false;
    }
    $input = trim($input);
    if ($input === '' || !preg_match('/^\d{1,3}$/', $input)) {
        return false;
    }
    return (int)$input === (int)$c['n'];
}

/* ---------------- 私有空间 ---------------- */

/**
 * 给用户建一个只属于他的空间，并授予读写。
 * slug 用 space-{uid}，万一被占用（手工建过同名的）就加随机后缀。
 */
function create_user_space(int $uid, string $username, string $displayName = ''): array
{
    $label = nick_clean($displayName) !== '' ? nick_clean($displayName) : $username;
    $name = nick_group_name($label, '');

    $slug = 'space-' . $uid;
    if (one('SELECT id FROM ' . tbl('groups') . ' WHERE slug = ?', [$slug])) {
        $slug = 'space-' . $uid . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    }

    $gid = insert_row('groups', [
        'name'        => $name,
        'slug'        => $slug,
        'description' => '注册时自动创建的私有空间，只有你本人能访问',
        'owner_id'    => $uid,
        'sort'        => 10,
        'created_at'  => now_str(),
    ]);

    insert_row('group_user', [
        'group_id'   => $gid,
        'user_id'    => $uid,
        'can_read'   => 1,
        'can_write'  => 1,
        'created_at' => now_str(),
    ]);

    return ['id' => $gid, 'name' => $name, 'slug' => $slug];
}

/** 是否是私有空间（有归属人） */
function is_private_group(array $group): bool
{
    return (int)($group['owner_id'] ?? 0) > 0;
}

/** 取某人自建的、用于注册的默认空间（没有就返回 null） */
function user_primary_space(int $uid): ?array
{
    $row = one(
        'SELECT * FROM ' . tbl('groups') . ' WHERE owner_id = ? ORDER BY sort, id LIMIT 1',
        [$uid]
    );
    return $row ?: null;
}
