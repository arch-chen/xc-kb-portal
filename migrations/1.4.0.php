<?php
declare(strict_types=1);

/**
 * 1.4.0：昵称唯一 + 私有分组名带归属人昵称。
 *
 * 三件事：
 *   1. users.display_name 归一化：空的补成用户名，重复的加「（2）」后缀，含「·」的换成「-」
 *   2. 给 display_name 建唯一索引（SQLite 用 COLLATE NOCASE，MySQL 靠 utf8mb4_unicode_ci）
 *   3. 私有分组改名：默认空间 =「昵称 的空间」，其余 =「昵称 · 原名」
 *
 * 重要：升级时跑的是「内存里的旧代码」，这里只能用手写 SQL 和升级前就有的函数
 * （db_prefix / db_driver），不要调用 1.4.0 新增的 nick_* 系列。
 */
return function (PDO $pdo): void {
    $prefix = db_prefix();
    $my = db_driver() === 'mysql';
    $users = $prefix . 'users';
    $groups = $prefix . 'groups';

    $lower = static fn(string $s): string => mb_strtolower($s, 'UTF-8');

    // ---- 1) 昵称归一化 + 去重 ----
    $rows = $pdo->query("SELECT id, username, display_name FROM {$users} ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);

    $used = [];   // 小写昵称 => uid
    $list = [];   // [uid, 原值, 新值, username]
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        $un = trim((string)$r['username']);
        $used[$lower($un)] = $uid;  // 登录名也占位，昵称不能和别人的登录名撞
        $list[] = [$uid, (string)($r['display_name'] ?? ''), $un];
    }

    $upd = $pdo->prepare("UPDATE {$users} SET display_name = ? WHERE id = ?");
    foreach ($list as [$uid, $raw, $un]) {
        $nick = $raw;
        // 全角空格、不间断空格、零宽空格统一成半角空格
        $nick = str_replace(["\xc2\xa0", "\u{3000}", "\u{200b}"], ' ', $nick);
        $nick = preg_replace('/\s+/u', ' ', $nick) ?? '';
        $nick = trim($nick);
        // 「·」留给「昵称 · 分组名」做分隔，历史昵称里有就换成 -
        $nick = str_replace(['·', '‧', '•'], '-', $nick);
        if ($nick === '') {
            $nick = $un !== '' ? $un : '用户' . $uid;
        }
        $nick = mb_substr($nick, 0, 40, 'UTF-8');

        $base = $nick;
        $n = 1;
        while (isset($used[$lower($nick)]) && $used[$lower($nick)] !== $uid) {
            $n++;
            $nick = mb_substr($base, 0, 36, 'UTF-8') . '（' . $n . '）';
        }
        $used[$lower($nick)] = $uid;

        if ($nick !== $raw) {
            $upd->execute([$nick, $uid]);
        }
    }

    // ---- 2) 唯一索引 ----
    try {
        $pdo->exec($my
            ? "CREATE UNIQUE INDEX uq_{$prefix}users_nick ON {$users} (display_name)"
            : "CREATE UNIQUE INDEX IF NOT EXISTS uq_{$prefix}users_nick ON {$users} (display_name COLLATE NOCASE)");
    } catch (Throwable $e) {
        // 已存在（或极少数历史脏数据仍在）就跳过，应用层还有一道校验
    }

    // ---- 3) 私有分组名回填 ----
    $nickOf = static function (int $uid) use ($pdo, $users): string {
        $st = $pdo->prepare("SELECT username, display_name FROM {$users} WHERE id = ?");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return '用户' . $uid;
        }
        $d = trim((string)($r['display_name'] ?? ''));
        return $d !== '' ? $d : (string)$r['username'];
    };

    $gs = $pdo->query("SELECT id, name, owner_id FROM {$groups} WHERE owner_id > 0 ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    $gu = $pdo->prepare("UPDATE {$groups} SET name = ? WHERE id = ?");
    $nicks = [];
    $defaultSeen = [];
    foreach ($gs as $g) {
        $owner = (int)$g['owner_id'];
        if (!isset($nicks[$owner])) {
            $nicks[$owner] = $nickOf($owner);
        }
        $nick = $nicks[$owner];
        $name = (string)$g['name'];
        $sep = ' · ';

        if (!isset($defaultSeen[$owner])) {
            // 名下 id 最小的那个 = 注册时自动建的默认空间
            $defaultSeen[$owner] = true;
            $new = $nick . ' 的空间';
        } else {
            $base = $name;
            if (str_starts_with($name, $nick . $sep)) {
                $base = (string)substr($name, strlen($nick . $sep));
            } elseif ($name === $nick . ' 的空间') {
                $base = '';
            }
            $new = $base === '' ? $nick . ' 的空间' : $nick . $sep . $base;
        }

        if ($new !== $name) {
            $gu->execute([mb_substr($new, 0, 120, 'UTF-8'), (int)$g['id']]);
        }
    }
};
