<?php
declare(strict_types=1);

/**
 * 权限层。
 * 规则：管理员全通；普通用户按 group_user 表的 can_read / can_write。
 * 关键约束：API Key 的权限是「用户权限 ∩ Key 自身开关」，永远不能放大。
 */

function user_perms(int $userId): array
{
    if (isset($GLOBALS['__user_perms'][$userId])) {
        return $GLOBALS['__user_perms'][$userId];
    }
    $rows = all('SELECT group_id, can_read, can_write FROM ' . tbl('group_user') . ' WHERE user_id = ?', [$userId]);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['group_id']] = [
            'read'  => (bool)(int)$r['can_read'],
            'write' => (bool)(int)$r['can_write'],
        ];
    }
    return $GLOBALS['__user_perms'][$userId] = $out;
}

/**
 * 清掉权限缓存。
 * 同一个请求里刚建完分组/刚授权，再去校验权限时不能吃到旧缓存
 * （AI 工作台一个回合里会「建组 → 立刻写入」，CLI 测试同理）。
 */
function perm_flush(int $userId = 0): void
{
    if ($userId > 0) {
        unset($GLOBALS['__user_perms'][$userId]);
        return;
    }
    $GLOBALS['__user_perms'] = [];
}

function group_perm(int $userId, int $groupId): array
{
    if (is_admin(one('SELECT * FROM ' . tbl('users') . ' WHERE id = ?', [$userId]))) {
        return ['read' => true, 'write' => true];
    }
    $p = user_perms($userId)[$groupId] ?? null;
    return $p ?: ['read' => false, 'write' => false];
}

function can_read_group(int $userId, int $groupId): bool
{
    return group_perm($userId, $groupId)['read'];
}

function can_write_group(int $userId, int $groupId): bool
{
    return group_perm($userId, $groupId)['write'];
}

/** 用户可见分组（读权限），管理员为全部分组 */
function visible_groups(int $userId): array
{
    if (is_admin(one('SELECT * FROM ' . tbl('users') . ' WHERE id = ?', [$userId]))) {
        return all('SELECT * FROM ' . tbl('groups') . ' ORDER BY sort, id');
    }
    return all(
        'SELECT g.* FROM ' . tbl('groups') . ' g
         INNER JOIN ' . tbl('group_user') . ' gu ON gu.group_id = g.id
         WHERE gu.user_id = ? AND gu.can_read = 1
         ORDER BY g.sort, g.id',
        [$userId]
    );
}

function find_group(string $key): ?array
{
    if (ctype_digit($key)) {
        return one('SELECT * FROM ' . tbl('groups') . ' WHERE id = ?', [(int)$key]);
    }
    return one('SELECT * FROM ' . tbl('groups') . ' WHERE slug = ?', [$key]);
}

function group_by_id(int $id): ?array
{
    return one('SELECT * FROM ' . tbl('groups') . ' WHERE id = ?', [$id]);
}

/** 生成唯一 slug（拼音不可靠，直接把中文转成短哈希后缀） */
function make_slug(string $name, ?int $ignoreId = null): string
{
    $base = strtolower(trim(preg_replace('/\s+/u', '-', $name) ?? ''));
    $base = preg_replace('/[^a-z0-9\-_]+/u', '', $base) ?? '';
    if ($base === '' || mb_strlen($base) < 2) {
        $base = 'g-' . substr(sha1($name), 0, 8);
    }
    $base = trim($base, '-');
    $slug = $base;
    $i = 1;
    while (true) {
        $exists = one('SELECT id FROM ' . tbl('groups') . ' WHERE slug = ?', [$slug]);
        if (!$exists || ($ignoreId !== null && (int)$exists['id'] === $ignoreId)) {
            return $slug;
        }
        $slug = $base . '-' . (++$i);
    }
}

function group_entry_count(int $groupId, string $kind = ''): int
{
    $sql = 'SELECT COUNT(*) FROM ' . tbl('entries') . ' WHERE group_id = ? AND status = 1';
    $p = [$groupId];
    if ($kind !== '') {
        $sql .= ' AND kind = ?';
        $p[] = $kind;
    }
    return (int)val($sql, $p, 0);
}
