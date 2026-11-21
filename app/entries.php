<?php
declare(strict_types=1);

/** 条目与文档的读写封装 */

function entry_by_id(int $id): ?array
{
    return one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ?', [$id]);
}

/** 文档关联的问答条目（按 sort 排序） */
function doc_entries(int $docId): array
{
    return all(
        'SELECT e.*, l.note AS note, l.sort AS link_sort
         FROM ' . tbl('doc_links') . ' l
         INNER JOIN ' . tbl('entries') . ' e ON e.id = l.entry_id
         WHERE l.doc_id = ? AND e.status = 1
         ORDER BY l.sort, l.id',
        [$docId]
    );
}

/** 某个问答被哪些文档引用 */
function entry_docs(int $entryId): array
{
    return all(
        'SELECT d.* FROM ' . tbl('doc_links') . ' l
         INNER JOIN ' . tbl('entries') . ' d ON d.id = l.doc_id
         WHERE l.entry_id = ? AND d.status = 1 ORDER BY d.title',
        [$entryId]
    );
}

function save_entry(array $data, ?int $id = null): int
{
    $now = now_str();
    $fields = [
        'group_id'    => (int)($data['group_id'] ?? 0),
        'kind'        => ($data['kind'] ?? 'qa') === 'doc' ? 'doc' : 'qa',
        'title'       => mb_substr(trim((string)($data['title'] ?? '')), 0, 250, 'UTF-8'),
        'question'    => trim((string)($data['question'] ?? '')),
        'body'        => (string)($data['body'] ?? ''),
        'source'      => mb_substr(trim((string)($data['source'] ?? '')), 0, 100, 'UTF-8'),
        'source_date' => trim((string)($data['source_date'] ?? '')),
    ];
    if ($fields['title'] === '' && $fields['question'] !== '') {
        $fields['title'] = mb_substr(preg_replace('/\s+/u', ' ', $fields['question']) ?? '', 0, 60, 'UTF-8');
    }

    if ($id === null) {
        $fields['version'] = 1;
        $fields['status'] = 1;
        $fields['created_by'] = (int)($data['created_by'] ?? 0);
        $fields['created_at'] = $now;
        $fields['updated_at'] = $now;
        $id = insert_row('entries', $fields);
    } else {
        $cur = entry_by_id($id);
        if (!$cur) {
            throw new RuntimeException('条目不存在');
        }
        $fields['version'] = (int)$cur['version'] + 1;
        $fields['updated_at'] = $now;
        update_row('entries', $id, $fields);
    }
    reindex_entry($id, $fields['title'], $fields['question'], $fields['body']);
    return $id;
}

function soft_delete_entry(int $id): void
{
    update_row('entries', $id, ['status' => 0, 'updated_at' => now_str()]);
    q('DELETE FROM ' . tbl('entry_grams') . ' WHERE entry_id = ?', [$id]);
    q('DELETE FROM ' . tbl('doc_links') . ' WHERE entry_id = ? OR doc_id = ?', [$id, $id]);
}

/** 保存文档的关联条目（保留 note） */
function save_doc_links(int $docId, array $entryIds): void
{
    q('DELETE FROM ' . tbl('doc_links') . ' WHERE doc_id = ?', [$docId]);
    $i = 0;
    foreach (array_unique(array_map('intval', $entryIds)) as $eid) {
        if ($eid <= 0) {
            continue;
        }
        insert_row('doc_links', ['doc_id' => $docId, 'entry_id' => $eid, 'sort' => $i++, 'note' => '']);
    }
}

function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page = min(max(1, $page), $pages);
    return [
        'total' => $total,
        'page'  => $page,
        'pages' => $pages,
        'per'   => $perPage,
        'offset' => ($page - 1) * $perPage,
    ];
}

/** 生成 API Key 明文与入库数据 */
function issue_api_key(int $userId, string $name, int $groupId, bool $canRead, bool $canWrite, ?string $expiresAt): array
{
    $raw = 'kb_' . bin2hex(random_bytes(6)) . '_' . bin2hex(random_bytes(20));
    $prefix = substr($raw, 0, 15);
    $id = insert_row('api_keys', [
        'user_id'    => $userId,
        'name'       => mb_substr($name !== '' ? $name : 'default', 0, 100, 'UTF-8'),
        'key_prefix' => $prefix,
        'key_hash'   => key_hash($raw),
        'key_plain'  => secret_encrypt($raw),
        'group_id'   => $groupId,
        'can_read'   => $canRead ? 1 : 0,
        'can_write'  => $canWrite ? 1 : 0,
        'expires_at' => $expiresAt,
        'revoked'    => 0,
        'created_at' => now_str(),
    ]);
    return ['id' => $id, 'key' => $raw, 'prefix' => $prefix];
}
