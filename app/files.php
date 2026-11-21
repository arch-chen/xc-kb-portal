<?php
declare(strict_types=1);

/**
 * 附件业务层：上传、多副本、下载容灾、灾备同步。
 *
 * 设计要点（和需求对齐）：
 * 1. 一个文件 = attachments 一行；每个平台上的副本 = attachment_replicas 一行
 * 2. 上传时写「主平台 + 所有已启用平台」，某个平台失败只记状态，不影响上传本身
 * 3. 中途新接一个平台：后台点「同步数据」，把它缺的副本从其他平台补过去（单向补齐，可中断续跑）
 * 4. 下载不直链：按副本顺序依次尝试，哪个能取就用哪个
 */

/** 对象名：g{分组}/{年}/{月}/{随机}.{ext}。随机且不含原文件名，避免泄露信息与冲突 */
function file_key(int $groupId, string $ext): string
{
    return 'g' . $groupId . '/' . date('Y') . '/' . date('m') . '/'
        . date('d') . '-' . bin2hex(random_bytes(10)) . '.' . $ext;
}

function file_human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/** 附件是否对某用户可读：跟着分组权限走 */
function can_read_attachment(?array $user, array $att): bool
{
    if (!$user) {
        return false;
    }
    return can_read_group((int)$user['id'], (int)$att['group_id']);
}

/**
 * 上传一个文件（$_FILES 元素）。
 * 返回 ['ok'=>true,'attachment'=>行,'warnings'=>[],'reused'=>bool] 或抛异常。
 */
function file_store(int $groupId, ?int $entryId, array $f, int $userId): array
{
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('文件超过 PHP 的 upload_max_filesize（当前 ' . ini_get('upload_max_filesize') . '）');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('上传失败（错误码 ' . $err . '）');
    }

    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new RuntimeException('临时文件不存在');
    }

    $size = (int)filesize($tmp);
    $limit = file_max_mb() * 1048576;
    if ($size <= 0) {
        throw new RuntimeException('空文件');
    }
    if ($size > $limit) {
        throw new RuntimeException('超过站点上限 ' . file_max_mb() . ' MB');
    }

    $orig = (string)($f['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, file_allowed_ext(), true)) {
        throw new RuntimeException('不支持的文件类型：' . ($ext === '' ? '无扩展名' : '.' . $ext));
    }

    // 图片额外校验：真的能解析出宽高，防止改名伪装
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        $info = @getimagesize($tmp);
        if ($info === false) {
            throw new RuntimeException('这个文件不是有效的图片');
        }
    }

    $sha = hash_file('sha256', $tmp);
    $mime = file_mime_by_ext($ext);

    // 同组同内容去重：省一份存储，也让「重复上传」不会产生一堆副本
    $dup = one(
        'SELECT * FROM ' . tbl('attachments') . ' WHERE group_id = ? AND sha256 = ? AND ext = ? AND status = 1 ORDER BY id LIMIT 1',
        [$groupId, $sha, $ext]
    );
    if ($dup) {
        if ($entryId) {
            $bound = one('SELECT COUNT(*) FROM ' . tbl('attachments') . ' WHERE entry_id = ? AND sha256 = ?', [$entryId, $sha]);
            if ((int)$bound === 0) {
                // 同一条目里也引用一次（复制一行轻量记录，指向同一个 object_key，副本共用）
                $newId = insert_row('attachments', [
                    'group_id' => $groupId, 'entry_id' => $entryId, 'name' => $orig, 'ext' => $ext,
                    'mime' => $mime, 'size' => $size, 'sha256' => $sha, 'object_key' => $dup['object_key'],
                    'created_by' => $userId, 'created_at' => now_str(), 'status' => 1,
                ]);
                $reps = all('SELECT * FROM ' . tbl('attachment_replicas') . ' WHERE attachment_id = ?', [(int)$dup['id']]);
                foreach ($reps as $r) {
                    insert_row('attachment_replicas', [
                        'attachment_id' => $newId, 'provider_id' => (int)$r['provider_id'],
                        'object_key' => (string)$r['object_key'], 'state' => (string)$r['state'],
                        'size' => (int)$r['size'], 'synced_at' => $r['synced_at'] ?? now_str(),
                    ]);
                }
            }
        }
        return ['ok' => true, 'attachment' => $dup, 'warnings' => [], 'reused' => true];
    }

    $key = file_key($groupId, $ext);
    $providers = storage_providers(true);
    if (!$providers) {
        throw new RuntimeException('还没有启用任何存储平台，请先到「文件存储」里启用一个');
    }

    $okCount = 0;
    $warnings = [];
    $failIds = [];
    $failMsg = [];
    $okIds = [];
    foreach ($providers as $p) {
        try {
            provider_driver($p)->put($key, $tmp, $mime);
            $okCount++;
            $okIds[] = (int)$p['id'];
        } catch (Throwable $e) {
            $warnings[] = $p['name'] . '：' . $e->getMessage();
            $failIds[] = (int)$p['id'];
            $failMsg[(int)$p['id']] = $e->getMessage();
        }
    }
    if ($okCount === 0) {
        throw new RuntimeException('所有存储平台都写失败：' . implode('；', $warnings));
    }

    $id = insert_row('attachments', [
        'group_id' => $groupId, 'entry_id' => $entryId, 'name' => $orig, 'ext' => $ext,
        'mime' => $mime, 'size' => $size, 'sha256' => $sha, 'object_key' => $key,
        'created_by' => $userId, 'created_at' => now_str(), 'status' => 1,
    ]);

    foreach ($providers as $p) {
        $pid = (int)$p['id'];
        if (in_array($pid, $okIds, true)) {
            replica_upsert($id, $pid, $key, 'ok', $size);
        } else {
            replica_upsert($id, $pid, $key, 'fail', $size, (string)($failMsg[$pid] ?? '上传失败'));
        }
    }

    return [
        'ok' => true,
        'attachment' => one('SELECT * FROM ' . tbl('attachments') . ' WHERE id = ?', [$id]),
        'warnings' => $warnings,
        'reused' => false,
    ];
}

/** 写/更新某文件在某平台上的副本状态 */
function replica_upsert(int $attId, int $providerId, string $key, string $state, int $size = 0, string $error = ''): void
{
    $row = one('SELECT * FROM ' . tbl('attachment_replicas') . ' WHERE attachment_id = ? AND provider_id = ?', [$attId, $providerId]);
    $data = [
        'object_key' => $key,
        'state'      => $state,
        'size'       => $size,
        'synced_at'  => $state === 'ok' ? now_str() : null,
        'last_error' => $error !== '' ? mb_substr($error, 0, 480) : null,
    ];
    if ($row) {
        update_row('attachment_replicas', (int)$row['id'], $data);
    } else {
        $data['attachment_id'] = $attId;
        $data['provider_id'] = $providerId;
        insert_row('attachment_replicas', $data);
    }
}

/** 某分组下的附件（按时间倒序） */
function attachments_of_group(int $groupId, int $limit = 100, int $offset = 0): array
{
    return all(
        'SELECT * FROM ' . tbl('attachments') . ' WHERE group_id = ? AND status = 1 ORDER BY id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
        [$groupId]
    );
}

function attachment_by_id(int $id): ?array
{
    return one('SELECT * FROM ' . tbl('attachments') . ' WHERE id = ? AND status = 1', [$id]);
}

/** 某平台上的副本清单 */
function attachment_replicas(int $attId): array
{
    return all(
        'SELECT r.*, p.name AS provider_name, p.driver, p.enabled
         FROM ' . tbl('attachment_replicas') . ' r
         LEFT JOIN ' . tbl('storage_providers') . ' p ON p.id = r.provider_id
         WHERE r.attachment_id = ? ORDER BY r.state, r.id',
        [$attId]
    );
}

/** 删除附件：先删副本（尽力而为），再把行标成失效 */
function attachment_delete(int $id): void
{
    $att = attachment_by_id($id);
    if (!$att) {
        return;
    }

    // 同内容被多条记录引用（去重时复制过行）时，不能删平台上的对象，否则别的记录会打不开
    $shared = (int)val(
        'SELECT COUNT(*) FROM ' . tbl('attachments') . ' WHERE object_key = ? AND status = 1 AND id <> ?',
        [(string)$att['object_key'], $id],
        0
    );

    if ($shared === 0) {
        foreach (attachment_replicas($id) as $r) {
            $p = provider_by_id((int)$r['provider_id']);
            if (!$p) {
                continue;
            }
            try {
                provider_driver($p)->drop((string)$r['object_key']);
            } catch (Throwable $e) {
                // 平台侧删不掉不影响本地标记，记一条审计即可
                audit('file_drop_failed', 'att=' . $id . ' provider=' . $p['id'] . ' ' . $e->getMessage());
            }
        }
    }

    q('DELETE FROM ' . tbl('attachment_replicas') . ' WHERE attachment_id = ?', [$id]);
    update_row('attachments', $id, ['status' => 0]);
}

/**
 * 取文件字节到本地临时文件：按副本顺序挑一个能用的平台。
 * 返回 ['path'=>临时文件, 'provider'=>平台名] 或 null。
 */
function attachment_fetch(array $att): ?array
{
    $rows = all(
        'SELECT r.*, p.id AS pid, p.name AS provider_name, p.enabled, p.is_primary
         FROM ' . tbl('attachment_replicas') . ' r
         INNER JOIN ' . tbl('storage_providers') . ' p ON p.id = r.provider_id
         WHERE r.attachment_id = ? AND r.state = ? AND p.enabled = 1
         ORDER BY p.is_primary DESC, p.sort, r.id',
        [(int)$att['id'], 'ok']
    );
    if (!$rows) {
        // 没有标记 ok 的副本时，退一步全试（可能是同步后状态没回写）
        $rows = all(
            'SELECT r.*, p.id AS pid, p.name AS provider_name, p.enabled, p.is_primary
             FROM ' . tbl('attachment_replicas') . ' r
             INNER JOIN ' . tbl('storage_providers') . ' p ON p.id = r.provider_id
             WHERE r.attachment_id = ? AND p.enabled = 1
             ORDER BY p.is_primary DESC, p.sort, r.id',
            [(int)$att['id']]
        );
    }

    // 下载也要落一份临时文件（给 PHP 流式输出用），磁盘不够就别开始
    storage_require_space((int)($att['size'] ?? 0));

    foreach ($rows as $r) {
        $p = provider_by_id((int)$r['pid']);
        if (!$p) {
            continue;
        }
        $dest = storage_tmp_file((string)$att['ext'], 'dl');
        if ($dest === null) {
            continue;
        }
        try {
            provider_driver($p)->fetch((string)$r['object_key'], $dest);
            if (is_file($dest) && filesize($dest) > 0) {
                return ['path' => $dest, 'provider' => (string)$p['name']];
            }
            @unlink($dest);
        } catch (Throwable $e) {
            @unlink($dest);
            replica_upsert((int)$att['id'], (int)$p['id'], (string)$r['object_key'], 'fail', (int)$r['size'], $e->getMessage());
        }
    }
    return null;
}

/** 某平台还缺多少个副本（用于同步任务的 total 与列表展示） */
function storage_missing_count(int $providerId): int
{
    return (int)val(
        'SELECT COUNT(*) FROM ' . tbl('attachments') . ' a
         WHERE a.status = 1
           AND NOT EXISTS (
             SELECT 1 FROM ' . tbl('attachment_replicas') . ' r
             WHERE r.attachment_id = a.id AND r.provider_id = ? AND r.state = ?
           )',
        [$providerId, 'ok'],
        0
    );
}

/** 某平台当前有多少个 ok 副本 */
function storage_copy_count(int $providerId): int
{
    return (int)val(
        'SELECT COUNT(*) FROM ' . tbl('attachment_replicas') . ' WHERE provider_id = ? AND state = ?',
        [$providerId, 'ok'],
        0
    );
}

/* ---------------- 灾备同步（分批 job） ---------------- */

/**
 * 把一个对象从源平台搬到目标平台，两条路自动选择：
 *
 *  1) 服务端复制（cloud）：同厂商同账号时，让目标云自己去源桶取，数据不经过本站、不落磁盘。
 *  2) 本地中转（relay）：服务器 fetch 到 storage/tmp 再 put 上去。跨厂商只能这样，
 *     峰值磁盘 = 单个文件大小，put 完立刻 unlink。
 *
 * 返回 'cloud' | 'relay'，失败抛异常。
 */
function storage_transfer(array $att, array $src, array $dst, ?string &$note = null): string
{
    $key = (string)$att['object_key'];
    $mime = (string)($att['mime'] ?: 'application/octet-stream');
    $size = (int)$att['size'];

    if ((string)$src['driver'] === (string)$dst['driver'] && storage_same_account($src, $dst)) {
        try {
            $done = provider_driver($dst)->copyFrom([
                'driver' => (string)$src['driver'],
                'bucket' => storage_bucket_of($src),
                'key'    => $key,
                'config' => provider_config($src),
            ], $key);
            if ($done) {
                return 'cloud';
            }
            $note = '云端未接受服务端复制（多为跨桶/跨区权限），已改用中转';
        } catch (Throwable $e) {
            $note = '服务端复制出错（' . mb_substr($e->getMessage(), 0, 120) . '），已改用中转';
        }
    }

    storage_require_space($size);
    $tmp = storage_tmp_file((string)$att['ext'], 'sync');
    if ($tmp === null) {
        throw new RuntimeException('无法在 storage/tmp 创建临时文件，检查目录权限');
    }
    try {
        provider_driver($src)->fetch($key, $tmp);
        provider_driver($dst)->put($key, $tmp, $mime);
    } finally {
        @unlink($tmp);
    }
    return 'relay';
}

/** 新建同步任务（把其他平台的副本补到这个平台） */
function storage_sync_start(int $providerId): array
{
    $p = provider_by_id($providerId);
    if (!$p) {
        throw new RuntimeException('平台不存在');
    }
    // 开跑前先清一遍上次被 kill 留下的中转文件，避免慢性塞盘
    storage_tmp_gc();
    $running = one(
        'SELECT * FROM ' . tbl('storage_jobs') . ' WHERE provider_id = ? AND state = ? ORDER BY id DESC LIMIT 1',
        [$providerId, 'running']
    );
    if ($running) {
        return $running;
    }
    $total = storage_missing_count($providerId);
    $id = insert_row('storage_jobs', [
        'provider_id' => $providerId,
        'kind'        => 'sync',
        'state'       => $total > 0 ? 'running' : 'done',
        'total'       => $total,
        'done'        => 0,
        'failed'      => 0,
        'cursor'      => 0,
        'message'     => $total > 0 ? '准备中' : '没有需要补齐的文件',
        'started_at'  => now_str(),
        'finished_at' => $total > 0 ? null : now_str(),
    ]);
    return one('SELECT * FROM ' . tbl('storage_jobs') . ' WHERE id = ?', [$id]);
}

/**
 * 推进一批（默认 10 个文件）。
 * 前端每秒调一次，直到 state != running。
 */
function storage_sync_step(int $jobId, int $limit = 10): array
{
    $job = one('SELECT * FROM ' . tbl('storage_jobs') . ' WHERE id = ?', [$jobId]);
    if (!$job) {
        throw new RuntimeException('任务不存在');
    }
    if ((string)$job['state'] !== 'running') {
        return $job;
    }

    $providerId = (int)$job['provider_id'];
    $p = provider_by_id($providerId);
    if (!$p) {
        update_row('storage_jobs', $jobId, ['state' => 'failed', 'message' => '平台已被删除', 'finished_at' => now_str()]);
        return one('SELECT * FROM ' . tbl('storage_jobs') . ' WHERE id = ?', [$jobId]);
    }

    $rows = all(
        'SELECT * FROM ' . tbl('attachments') . ' WHERE status = 1 AND id > ? ORDER BY id LIMIT ' . (int)$limit,
        [(int)$job['cursor']]
    );

    if (!$rows) {
        update_row('storage_jobs', $jobId, [
            'state' => 'done',
            'message' => '同步完成',
            'finished_at' => now_str(),
        ]);
        update_row('storage_providers', $providerId, ['last_sync_at' => now_str()]);
        return one('SELECT * FROM ' . tbl('storage_jobs') . ' WHERE id = ?', [$jobId]);
    }

    $done = (int)$job['done'];
    $failed = (int)$job['failed'];
    $cursor = (int)$job['cursor'];
    $cloud = (int)($job['cloud_copies'] ?? 0);
    $relay = (int)($job['relayed'] ?? 0);
    $lastMsg = ''; // 只记本批的异常提示；不沿用上一批的 message，免得把「准备中」带到结果里

    foreach ($rows as $att) {
        $cursor = (int)$att['id'];
        $have = one(
            'SELECT * FROM ' . tbl('attachment_replicas') . ' WHERE attachment_id = ? AND provider_id = ? AND state = ?',
            [(int)$att['id'], $providerId, 'ok']
        );
        if ($have) {
            continue;
        }
        $src = attachment_source_for($att, $providerId);
        if (!$src) {
            $failed++;
            replica_upsert((int)$att['id'], $providerId, (string)$att['object_key'], 'fail', (int)$att['size'], '找不到可用的源副本');
            $lastMsg = '有文件在所有平台都取不到';
            continue;
        }
        $note = '';
        try {
            $mode = storage_transfer($att, $src, $p, $note);
            if ($mode === 'cloud') {
                $cloud++;
            } else {
                $relay++;
            }
            replica_upsert((int)$att['id'], $providerId, (string)$att['object_key'], 'ok', (int)$att['size']);
            $done++;
            if ($note !== '') {
                $lastMsg = $note;
            }
        } catch (Throwable $e) {
            $failed++;
            replica_upsert((int)$att['id'], $providerId, (string)$att['object_key'], 'fail', (int)$att['size'], $e->getMessage());
            $lastMsg = mb_substr($e->getMessage(), 0, 200);
        }
    }

    $more = (int)val(
        'SELECT COUNT(*) FROM ' . tbl('attachments') . ' WHERE status = 1 AND id > ?',
        [$cursor],
        0
    );

    $tail = '云端直搬 ' . $cloud . '，本地中转 ' . $relay;
    update_row('storage_jobs', $jobId, [
        'done'   => $done,
        'failed' => $failed,
        'cursor' => $cursor,
        'cloud_copies' => $cloud,
        'relayed' => $relay,
        'state'  => $more > 0 ? 'running' : 'done',
        'message' => $more > 0
            ? ('已补齐 ' . $done . ' 个（' . $tail . '），继续…')
            : ('同步完成：成功 ' . $done . '，失败 ' . $failed . '（' . $tail . '）' . ($lastMsg !== '' ? '；' . $lastMsg : '')),
        'finished_at' => $more > 0 ? null : now_str(),
    ]);
    if ($more === 0) {
        update_row('storage_providers', $providerId, ['last_sync_at' => now_str()]);
    }

    return one('SELECT * FROM ' . tbl('storage_jobs') . ' WHERE id = ?', [$jobId]);
}

/** 找一个「不是目标平台、且状态 ok」的源副本，返回平台行 */
function attachment_source_for(array $att, int $excludeProviderId): ?array
{
    $row = one(
        'SELECT p.* FROM ' . tbl('attachment_replicas') . ' r
         INNER JOIN ' . tbl('storage_providers') . ' p ON p.id = r.provider_id
         WHERE r.attachment_id = ? AND r.provider_id <> ? AND r.state = ? AND p.enabled = 1
         ORDER BY p.is_primary DESC, p.sort LIMIT 1',
        [(int)$att['id'], $excludeProviderId, 'ok']
    );
    if ($row) {
        return $row;
    }
    return one(
        'SELECT p.* FROM ' . tbl('attachment_replicas') . ' r
         INNER JOIN ' . tbl('storage_providers') . ' p ON p.id = r.provider_id
         WHERE r.attachment_id = ? AND r.provider_id <> ? AND p.enabled = 1
         ORDER BY p.is_primary DESC, p.sort LIMIT 1',
        [(int)$att['id'], $excludeProviderId]
    );
}

/** 附件总数 / 占用空间 */
function storage_stats(): array
{
    return [
        'files' => (int)val('SELECT COUNT(*) FROM ' . tbl('attachments') . ' WHERE status = 1', [], 0),
        'bytes' => (int)val('SELECT COALESCE(SUM(size),0) FROM ' . tbl('attachments') . ' WHERE status = 1', [], 0),
        'replicas' => (int)val('SELECT COUNT(*) FROM ' . tbl('attachment_replicas') . ' WHERE state = ?', ['ok'], 0),
    ];
}

/** 全部分组的最近附件（后台「全部」视图用） */
function attachments_recent(int $limit = 200): array
{
    return all(
        'SELECT a.*, g.name AS group_name FROM ' . tbl('attachments') . ' a
         LEFT JOIN ' . tbl('groups') . ' g ON g.id = a.group_id
         WHERE a.status = 1 ORDER BY a.id DESC LIMIT ' . (int)$limit
    );
}
