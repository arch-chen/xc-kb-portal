<?php
declare(strict_types=1);

/**
 * 2.3.0：管理员前台/后台身份分离后的数据补齐。
 *
 * 背景：2.3.0 起管理员在「用户页面」里按普通用户判权（只看自己建的 + 被授权的分组），
 * 只有进了「管理后台」才全通。这对私有分组是对的（别人的私有空间不该被管理员在前台翻），
 * 但共享分组（owner_id = 0，后台建的公共库）没有归属人，升级后会变成谁都看不见。
 *
 * 处理：把现有共享分组补给所有管理员一份读写成员关系。
 * 不动私有分组（owner_id > 0）——那些本来就是某个用户的私有空间。
 * 幂等：已有成员记录的只补缺失的 can_read / can_write，不重复插入。
 *
 * 升级时跑的是旧代码，这里不调用 2.3.0 新增的任何函数。
 */
return function (PDO $pdo): void {
    $T = function_exists('db_prefix') ? db_prefix() : 'kb_';

    // 1) 共享分组 id
    $gids = [];
    try {
        $st = $pdo->query("SELECT id FROM {$T}groups WHERE COALESCE(owner_id, 0) = 0");
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $gids[] = (int)$r['id'];
        }
    } catch (Throwable $e) {
        return;
    }
    if (!$gids) {
        return;
    }

    // 2) 管理员 id
    $admins = [];
    try {
        $st = $pdo->query("SELECT id FROM {$T}users WHERE role = 'admin' AND status = 1");
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $admins[] = (int)$r['id'];
        }
    } catch (Throwable $e) {
        return;
    }
    if (!$admins) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $sel = $pdo->prepare("SELECT id, can_read, can_write FROM {$T}group_user WHERE group_id = ? AND user_id = ?");
    $ins = $pdo->prepare("INSERT INTO {$T}group_user (group_id, user_id, can_read, can_write, created_at) VALUES (?, ?, 1, 1, ?)");
    $upd = $pdo->prepare("UPDATE {$T}group_user SET can_read = 1, can_write = 1 WHERE id = ?");

    foreach ($gids as $gid) {
        foreach ($admins as $uid) {
            $sel->execute([$gid, $uid]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ((int)$row['can_read'] !== 1 || (int)$row['can_write'] !== 1) {
                    $upd->execute([(int)$row['id']]);
                }
                continue;
            }
            try {
                $ins->execute([$gid, $uid, $now]);
            } catch (Throwable $e) {
                // 唯一索引冲突（并发/重复执行）忽略
            }
        }
    }
};
