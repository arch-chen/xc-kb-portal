<?php
declare(strict_types=1);

/**
 * 权限层。
 * 规则：普通用户按 group_user 表的 can_read / can_write；自己建的分组天然可读写。
 *
 * 2.3.0 起的硬边界：**管理员的前台与后台分开算**
 *  - 只有「管理后台」（?r=admin*，由 require_admin() 打标）才是全通身份；
 *  - 前台页面（首页 / 分组 / 条目 / 搜索 / AI 工作台 / AI 助手 / 文件）里，
 *    管理员和普通用户完全一样：只看自己被授权 + 自己建的分组，AI 也照样按积分 / 套餐计费。
 *  这样「系统管理员的功能和页面全部从左侧管理后台入口进入」才落得下去，
 *  也避免拿管理员账号在前台把别人的知识库当成自己的。
 *
 * 另一个约束不变：API Key 的权限是「用户权限 ∩ Key 自身开关」，永远不能放大。
 */

/** 当前请求是否在「管理后台」上下文里（require_admin 会打标，仅当次请求有效） */
function admin_ctx(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/** 后台全通身份 = 管理员 且 处于后台上下文。前台一律按普通用户，返回 false */
function perm_root(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u !== null && is_admin($u) && admin_ctx();
}

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
    $GLOBALS['__group_owner'] = [];
    if ($userId > 0) {
        unset($GLOBALS['__user_perms'][$userId]);
        return;
    }
    $GLOBALS['__user_perms'] = [];
}

/** 分组归属人（带请求内缓存；授权 / 建组后由 perm_flush 清掉） */
function group_owner_id(int $groupId): int
{
    if (!isset($GLOBALS['__group_owner'])) {
        $GLOBALS['__group_owner'] = [];
    }
    if (array_key_exists($groupId, $GLOBALS['__group_owner'])) {
        return (int)$GLOBALS['__group_owner'][$groupId];
    }
    $r = one('SELECT owner_id FROM ' . tbl('groups') . ' WHERE id = ?', [$groupId]);
    $GLOBALS['__group_owner'][$groupId] = $r ? (int)$r['owner_id'] : -1;
    return (int)$GLOBALS['__group_owner'][$groupId];
}

function group_perm(int $userId, int $groupId): array
{
    // 后台上下文里的管理员：全通
    $cu = current_user();
    if ($cu !== null && (int)$cu['id'] === $userId && perm_root($cu)) {
        return ['read' => true, 'write' => true];
    }
    // 自己建的分组（含注册时自动创建的私有空间）：天然可读写
    if ($userId > 0 && group_owner_id($groupId) === $userId) {
        return ['read' => true, 'write' => true];
    }
    /**
     * 共享分组（owner_id=0）对管理员默认读写（2.6.4 补）。
     *
     * 2.3.0 把管理员的身份拆成前台/后台两套：前台按普通用户判权。这对私有分组
     * 是对的（别人的私有空间不该被管理员在前台翻），但共享分组没有归属人，
     * 2.3.0 用一条迁移把「当时存在的」共享分组补给当时的管理员一份成员关系。
     *
     * 问题就在「当时」两个字上：
     *   - 迁移之后新建的管理员账号，一条成员关系都没有 → 前台看不到任何共享分组，
     *     连页面都进不去（403），更别说改名字；
     *   - 迁移之后由导入 / 安装脚本创建的共享分组，也不会自动补成员。
     * 结果就是「管理员明明有权限，却什么共享库都看不到」，用户报的「分组名称不能改」
     * 就是这么来的。
     *
     * 判定用「$userId 这个人是不是管理员」，不是 current_user()——
     * 这个函数经常被拿来查别人的权限（后台列表、测试、AI 工具），
     * 用当前登录人判断会给错答案。
     *
     * 读写都给，和 2.3.0 那条迁移补进去的成员关系保持一致（它给的是
     * can_read=1 且 can_write=1）。这里只是把「迁移补过的那几个管理员」
     * 扩成「所有管理员」，不改变权限语义，避免同一个管理员在
     * 「老的共享库能写、新加的共享库不能写」之间来回摇摆。
     * 私有分组完全不受影响——上面那两行早在本判断之前就返回了。
     */
    if ($userId > 0 && group_owner_id($groupId) === 0 && is_admin_user($userId)) {
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

/**
 * 「我的分组」：永远按前台口径（自己建的 + 被授权的），不受后台上下文影响。
 * 左侧栏专用——管理员在后台页面时侧栏也不该突然列出全站分组。
 *
 * 2.6.4：管理员额外带上共享分组。这个 SQL 不走 group_perm()，
 * 所以光改 group_perm() 是不够的——权限放开了、侧栏还是列不出来。
 * 两个地方必须一起改，否则会出现「点得进去但侧栏看不见」这种更奇怪的状态。
 */
function my_groups(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    if (is_admin_user($userId)) {
        return all(
            'SELECT g.* FROM ' . tbl('groups') . ' g
             WHERE g.owner_id = ?
                OR COALESCE(g.owner_id, 0) = 0
                OR EXISTS (SELECT 1 FROM ' . tbl('group_user') . ' gu
                            WHERE gu.group_id = g.id AND gu.user_id = ? AND gu.can_read = 1)
             ORDER BY g.sort, g.id',
            [$userId, $userId]
        );
    }
    return all(
        'SELECT g.* FROM ' . tbl('groups') . ' g
         WHERE g.owner_id = ?
            OR EXISTS (SELECT 1 FROM ' . tbl('group_user') . ' gu
                        WHERE gu.group_id = g.id AND gu.user_id = ? AND gu.can_read = 1)
         ORDER BY g.sort, g.id',
        [$userId, $userId]
    );
}

/**
 * 按用户 id 判管理员（不依赖当前会话）。
 *
 * group_perm() / my_groups() 会被拿来查「任意用户」的权限——后台用户列表、
 * 权限矩阵、AI 工具、以及测试。以前这些地方只能用 current_user()，
 * 一旦被查的人不是当前登录人就会给出错答案。
 * 结果按 id 缓存在 $GLOBALS 里，一个请求内同一个人的角色只查一次库。
 */
function is_admin_user(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    if (!isset($GLOBALS['__is_admin_user'])) {
        $GLOBALS['__is_admin_user'] = [];
    }
    if (array_key_exists($userId, $GLOBALS['__is_admin_user'])) {
        return (bool)$GLOBALS['__is_admin_user'][$userId];
    }
    $role = (string)val(
        'SELECT role FROM ' . tbl('users') . ' WHERE id = ? AND status = 1',
        [$userId],
        ''
    );
    return (bool)($GLOBALS['__is_admin_user'][$userId] = ($role === 'admin'));
}

/**
 * 用户可见分组（读权限）。
 * 前台口径：自己建的 + 别人授权的。管理员在前台也是这个口径（全站分组走管理后台）。
 * 后台口径（admin_ctx 已打标）：全部分组。
 */
function visible_groups(int $userId): array
{
    $cu = current_user();
    if ($cu !== null && (int)$cu['id'] === $userId && perm_root($cu)) {
        return all('SELECT * FROM ' . tbl('groups') . ' ORDER BY sort, id');
    }
    return my_groups($userId);
}

/**
 * 确保某人是某分组的成员（读写都给）。
 * 共享分组（owner_id=0）没有归属人，前台又只认「自己建的 + 被授权的」，
 * 所以在后台建组、或管理员要进自己建的共享分组时，必须补一条成员记录。
 */
function group_member_ensure(int $groupId, int $userId, bool $write = true): void
{
    if ($groupId <= 0 || $userId <= 0) {
        return;
    }
    $has = one('SELECT * FROM ' . tbl('group_user') . ' WHERE group_id = ? AND user_id = ?', [$groupId, $userId]);
    if ($has) {
        if (!(int)$has['can_read'] || ($write && !(int)$has['can_write'])) {
            update_row('group_user', (int)$has['id'], [
                'can_read'  => 1,
                'can_write' => $write ? 1 : (int)$has['can_write'],
            ]);
        }
        return;
    }
    insert_row('group_user', [
        'group_id'   => $groupId,
        'user_id'    => $userId,
        'can_read'   => 1,
        'can_write'  => $write ? 1 : 0,
        'created_at' => now_str(),
    ]);
}

/** 前台口径的读权限：不管当前是不是后台上下文，管理员也要靠归属 / 授权 */
function can_read_group_front(int $userId, int $groupId): bool
{
    if ($userId <= 0) {
        return false;
    }
    return group_owner_id($groupId) === $userId
        || (bool)(user_perms($userId)[$groupId]['read'] ?? false);
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
