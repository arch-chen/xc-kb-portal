<?php
declare(strict_types=1);

/**
 * 昵称与分组命名规则（1.4.0 起）
 *
 *  1. 昵称全站唯一：不区分大小写，也不能和别人的登录名撞车。
 *  2. 用户自己建的分组，完整名称 = 「昵称 · 自定义名」，管理员一眼看出归谁。
 *  3. 注册时自动建的默认空间 = 「昵称 的空间」。
 *  4. 改昵称时，该用户名下所有分组名称跟着重写（见 sync_user_group_names）。
 *
 * 库里存的是带前缀的完整名称，所以后台列表、API 返回、文档引用都不用再拼一次；
 * 代价是改名时必须知道「旧昵称」才能把前缀剥下来，因此所有调用都要求传入旧昵称。
 */

const GROUP_SEP = ' · ';
const GROUP_SPACE_SUFFIX = ' 的空间';
const NICK_MIN = 2;
const NICK_MAX = 40;
const GROUP_BASE_MAX = 60;

/** 归一化昵称：全角空格、连续空白都收成一个半角空格 */
function nick_clean(string $s): string
{
    $s = str_replace(["\xc2\xa0", "\u{3000}", "\u{200b}"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return trim($s);
}

/** 取用户对外显示名（没设昵称就用账号） */
function nick_of(array $user): string
{
    $n = nick_clean((string)($user['display_name'] ?? ''));
    return $n !== '' ? $n : (string)($user['username'] ?? '');
}

/**
 * 校验昵称，返回错误文案；空串代表通过。
 * $ignoreUid 是「自己」，改昵称时不能把自己算成占用。
 */
function nick_error(string $nick, int $ignoreUid = 0): string
{
    $nick = nick_clean($nick);
    if ($nick === '') {
        return '昵称不能为空';
    }
    $len = mb_strlen($nick, 'UTF-8');
    if ($len < NICK_MIN) {
        return '昵称至少 ' . NICK_MIN . ' 个字';
    }
    if ($len > NICK_MAX) {
        return '昵称最多 ' . NICK_MAX . ' 个字';
    }
    if (str_contains($nick, '·')) {
        return '昵称里不能有「·」，这个符号留给「昵称 · 分组名」做分隔';
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $nick)) {
        return '昵称里有不可见字符，换一个';
    }
    $row = one(
        'SELECT id, username FROM ' . tbl('users')
        . " WHERE id <> ? AND (LOWER(username) = LOWER(?) OR LOWER(COALESCE(display_name, '')) = LOWER(?))"
        . ' LIMIT 1',
        [$ignoreUid, $nick, $nick]
    );
    if ($row) {
        return '昵称「' . $nick . '」已经被占用，换一个';
    }
    return '';
}

/** 组完整名称：昵称 · 自定义名；自定义名为空则视为默认空间 */
function nick_group_name(string $nick, string $base): string
{
    $nick = nick_clean($nick);
    $base = nick_clean($base);
    if ($base === '') {
        return mb_substr($nick . GROUP_SPACE_SUFFIX, 0, 120, 'UTF-8');
    }
    $base = mb_substr($base, 0, GROUP_BASE_MAX, 'UTF-8');
    return mb_substr($nick . GROUP_SEP . $base, 0, 120, 'UTF-8');
}

/** 从完整名称里剥掉昵称前缀，拿回用户填的那部分；认不出前缀就原样返回 */
function group_base_of(string $name, string $nick): string
{
    $name = (string)$name;
    $nick = nick_clean($nick);
    if ($nick === '') {
        return $name;
    }
    $p = $nick . GROUP_SEP;
    if (str_starts_with($name, $p)) {
        return (string)substr($name, strlen($p));
    }
    if ($name === $nick . GROUP_SPACE_SUFFIX) {
        return '';
    }
    return $name;
}

/** 某个分组的归属人昵称（共享分组返回空串） */
function group_owner_nick(array $group): string
{
    $owner = (int)($group['owner_id'] ?? 0);
    if ($owner <= 0) {
        return '';
    }
    $u = one('SELECT id, username, display_name FROM ' . tbl('users') . ' WHERE id = ?', [$owner]);
    return $u ? nick_of($u) : '';
}

/**
 * 昵称变了以后，把这个用户名下所有分组的名称重写一遍。
 * 返回实际改动的条数。
 */
function sync_user_group_names(int $uid, string $oldNick, string $newNick): int
{
    $rows = all('SELECT * FROM ' . tbl('groups') . ' WHERE owner_id = ? ORDER BY id', [$uid]);
    $n = 0;
    foreach ($rows as $g) {
        $base = group_base_of((string)$g['name'], $oldNick);
        $new = nick_group_name($newNick, $base);
        if ($new !== $g['name']) {
            update_row('groups', (int)$g['id'], ['name' => $new]);
            $n++;
        }
    }
    return $n;
}
