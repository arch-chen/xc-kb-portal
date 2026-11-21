<?php
declare(strict_types=1);

/**
 * Agent API 路由。入口 api.php。
 * 支持两种地址：
 *   1) api.php?path=/v1/search&q=xxx      （任何环境都能用）
 *   2) /api/v1/search?q=xxx               （服务器开了 rewrite 时，见 .htaccess）
 */

function api_path(): string
{
    if (!empty($_GET['path'])) {
        // 防呆：有人写成 ?path=/v1/x?limit=1，把尾巴上的 query 切掉
        $p = explode('?', (string)$_GET['path'], 2)[0];
        return '/' . trim($p, '/');
    }
    if (!empty($_SERVER['PATH_INFO'])) {
        return '/' . trim((string)$_SERVER['PATH_INFO'], '/');
    }
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pos = strpos($uri, '/api/');
    if ($pos !== false) {
        return '/' . trim(substr($uri, $pos + 4), '/');
    }
    return '/';
}

function api_fail(string $code, string $msg, int $status = 400): void
{
    json_out(['ok' => false, 'error' => ['code' => $code, 'message' => $msg]], $status);
}

function api_body(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $raw = file_get_contents('php://input') ?: '';
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if ($raw !== '' && str_contains($ct, 'json')) {
        $d = json_decode($raw, true);
        return $b = is_array($d) ? $d : [];
    }
    if ($raw !== '' && str_contains($ct, 'application/x-www-form-urlencoded')) {
        parse_str($raw, $d);
        return $b = is_array($d) ? $d : [];
    }
    return $b = $_POST ?: [];
}

/** 从请求头取 Bearer token */
function api_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $h = $v;
                break;
            }
        }
    }
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) {
        return trim($m[1]);
    }
    $x = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($x !== '') {
        return trim((string)$x);
    }
    return trim((string)($_GET['key'] ?? ''));
}

/** 校验 Key，返回 [keyRow, userRow] 或 null */
function api_auth(): ?array
{
    $raw = api_token();
    if ($raw === '' || !str_starts_with($raw, 'kb_')) {
        return null;
    }
    $row = one('SELECT * FROM ' . tbl('api_keys') . ' WHERE key_hash = ?', [key_hash($raw)]);
    if (!$row) {
        return null;
    }
    if ((int)$row['revoked'] === 1) {
        return null;
    }
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
        return null;
    }
    $user = one('SELECT * FROM ' . tbl('users') . ' WHERE id = ? AND status = 1', [(int)$row['user_id']]);
    if (!$user) {
        return null;
    }
    // 记录使用时间（每 60 秒最多写一次，避免高频请求拖慢）
    $last = $row['last_used_at'] ? strtotime((string)$row['last_used_at']) : 0;
    if (time() - $last > 60) {
        update_row('api_keys', (int)$row['id'], ['last_used_at' => now_str()]);
    }
    return [$row, $user];
}

/** Key 的有效能力：分组 + 读 + 写（与用户自身权限取交集） */
function key_caps(array $key, array $user): array
{
    $gid = (int)$key['group_id'];
    $perm = group_perm((int)$user['id'], $gid);
    return [
        'group_id' => $gid,
        'read'     => (bool)(int)$key['can_read'] && $perm['read'],
        'write'    => (bool)(int)$key['can_write'] && $perm['write'],
        'user_id'  => (int)$user['id'],
        'is_admin' => is_admin($user),
    ];
}

function api_entry_public(array $r): array
{
    return [
        'id'          => (int)$r['id'],
        'group_id'    => (int)$r['group_id'],
        'kind'        => $r['kind'],
        'title'       => $r['title'],
        'question'    => $r['question'],
        'body'        => $r['body'],
        'source'      => $r['source'],
        'source_date' => $r['source_date'],
        'version'     => (int)$r['version'],
        'updated_at'  => $r['updated_at'],
        'created_at'  => $r['created_at'],
    ];
}

function api_handle(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $path = api_path();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // 健康检查不需要鉴权，方便部署自检
    if ($path === '/' || $path === '/v1/health') {
        json_out(['ok' => true, 'data' => ['service' => app_name(), 'version' => APP_VERSION]]);
    }

    $auth = api_auth();
    if (!$auth) {
        api_fail('unauthorized', 'API Key 缺失、无效、已吊销或已过期', 401);
    }
    [$key, $user] = $auth;
    $caps = key_caps($key, $user);

    if (($caps['read'] || $caps['write']) === false) {
        api_fail('forbidden', '该 API Key 对应的用户已失去此分组的权限，请重新授权', 403);
    }

    // ---- /v1/me ----
    if ($path === '/v1/me' && $method === 'GET') {
        $g = group_by_id($caps['group_id']);
        json_out(['ok' => true, 'data' => [
            'user'    => ['id' => (int)$user['id'], 'username' => $user['username'], 'display_name' => $user['display_name']],
            'key'     => ['name' => $key['name'], 'prefix' => $key['key_prefix'], 'expires_at' => $key['expires_at']],
            'group'   => $g ? ['id' => (int)$g['id'], 'name' => $g['name'], 'slug' => $g['slug']] : null,
            'scopes'  => ['read' => $caps['read'], 'write' => $caps['write']],
            'entries' => $g ? group_entry_count((int)$g['id']) : 0,
            'version' => APP_VERSION,
        ]]);
    }

    // 写操作统一校验
    $requireWrite = function () use ($caps) {
        if (!$caps['write']) {
            api_fail('readonly', '该 API Key 只读，不能写入', 403);
        }
    };

    // ---- /v1/groups ----
    if ($path === '/v1/groups' && $method === 'GET') {
        // 一个 Key 只对应一个分组，这里就只返回自己那一个（不给 Agent 越界的想象空间）
        $g = group_by_id($caps['group_id']);
        $data = $g ? [[
            'id'    => (int)$g['id'],
            'name'  => $g['name'],
            'slug'  => $g['slug'],
            'count' => group_entry_count((int)$g['id']),
        ]] : [];
        json_out(['ok' => true, 'data' => $data]);
    }

    // ---- /v1/search  （GET 用 query，POST 用 JSON，长问题建议 POST）----
    if ($path === '/v1/search' && ($method === 'GET' || $method === 'POST')) {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $src = $method === 'POST' ? api_body() : [];
        $q = $method === 'POST' ? trim((string)($src['q'] ?? $src['query'] ?? '')) : get_q('q');
        if ($q === '') {
            api_fail('bad_request', '缺少参数 q');
        }
        $limit = min(50, max(1, (int)($src['limit'] ?? get_q('limit', '10'))));
        $offset = max(0, (int)($src['offset'] ?? get_q('offset', '0')));
        $kindRaw = (string)($src['kind'] ?? get_q('kind'));
        $kind = in_array($kindRaw, ['qa', 'doc'], true) ? $kindRaw : '';
        $res = search_entries($q, [$caps['group_id']], $kind, $limit, $offset);
        $items = [];
        foreach ($res['items'] as $it) {
            $row = api_entry_public($it['entry']);
            $row['score'] = $it['score'];
            $row['snippet'] = snippet((string)$it['entry']['body'], $q, 200);
            $items[] = $row;
        }
        json_out(['ok' => true, 'data' => $items, 'meta' => [
            'total' => $res['total'], 'limit' => $limit, 'offset' => $offset, 'query' => $q,
        ]]);
    }

    // ---- /v1/entries ----
    if ($path === '/v1/entries' && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $limit = min(100, max(1, (int)get_q('limit', '20')));
        $offset = max(0, (int)get_q('offset', '0'));
        $kind = in_array(get_q('kind'), ['qa', 'doc'], true) ? get_q('kind') : '';
        $sql = 'SELECT * FROM ' . tbl('entries') . ' WHERE status = 1 AND group_id = ?';
        $p = [$caps['group_id']];
        if ($kind !== '') {
            $sql .= ' AND kind = ?';
            $p[] = $kind;
        }
        $total = (int)val(str_replace('SELECT *', 'SELECT COUNT(*)', $sql), $p, 0);
        $rows = all($sql . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $p);
        json_out(['ok' => true, 'data' => array_map('api_entry_public', $rows),
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    }

    // ---- /v1/entries/{id} ----
    if (preg_match('#^/v1/entries/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') {
            if (!$caps['read']) {
                api_fail('readonly', '该 API Key 没有读权限', 403);
            }
            $row = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ? AND status = 1', [$id]);
            if (!$row) {
                api_fail('not_found', '条目不存在', 404);
            }
            if ((int)$row['group_id'] !== $caps['group_id']) {
                api_fail('forbidden', '该条目不在这个 Key 的分组内', 403);
            }
            $data = api_entry_public($row);
            if ($row['kind'] === 'doc') {
                $data['links'] = array_map(fn($r) => api_entry_public($r), doc_entries($id));
            }
            json_out(['ok' => true, 'data' => $data]);
        }
        if ($method === 'PUT' || $method === 'PATCH') {
            $requireWrite();
            $row = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ?', [$id]);
            if (!$row) {
                api_fail('not_found', '条目不存在', 404);
            }
            if ((int)$row['group_id'] !== $caps['group_id']) {
                api_fail('forbidden', '该条目不在这个 Key 的分组内', 403);
            }
            $b = api_body();
            $upd = [];
            foreach (['title', 'question', 'body', 'source', 'source_date'] as $f) {
                if (array_key_exists($f, $b)) {
                    $upd[$f] = (string)$b[$f];
                }
            }
            if (isset($b['kind']) && in_array($b['kind'], ['qa', 'doc'], true)) {
                $upd['kind'] = $b['kind'];
            }
            if (!$upd) {
                api_fail('bad_request', '没有可更新的字段');
            }
            $upd['version'] = (int)$row['version'] + 1;
            $upd['updated_at'] = now_str();
            if (array_key_exists('body', $upd)) {
                $upd['afp'] = entry_afp($upd['body']);
            }
            if (array_key_exists('question', $upd) || array_key_exists('title', $upd)) {
                $q = $upd['question'] ?? (string)$row['question'];
                $t = $upd['title'] ?? (string)$row['title'];
                $upd['qfp'] = entry_qfp((string)$q, (string)$t, (string)($upd['body'] ?? $row['body']));
            }
            snapshot_version($row, (int)$user['id']);
            update_row('entries', $id, $upd);
            $new = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ?', [$id]);
            reindex_entry($id, (string)$new['title'], (string)$new['question'], (string)$new['body']);
            audit('api_update_entry', 'id=' . $id);
            json_out(['ok' => true, 'data' => api_entry_public($new)]);
        }
        if ($method === 'DELETE') {
            $requireWrite();
            $row = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ?', [$id]);
            if (!$row) {
                api_fail('not_found', '条目不存在', 404);
            }
            if ((int)$row['group_id'] !== $caps['group_id']) {
                api_fail('forbidden', '该条目不在这个 Key 的分组内', 403);
            }
            update_row('entries', $id, ['status' => 0, 'updated_at' => now_str()]);
            q('DELETE FROM ' . tbl('entry_grams') . ' WHERE entry_id = ?', [$id]);
            audit('api_delete_entry', 'id=' . $id);
            json_out(['ok' => true, 'data' => ['id' => $id, 'deleted' => true]]);
        }
    }

    // ---- /v1/entries/{id}/versions  （版本历史，含当前版本）----
    if (preg_match('#^/v1/entries/(\d+)/versions$#', $path, $m) && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $id = (int)$m[1];
        $cur = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ?', [$id]);
        if (!$cur) {
            api_fail('not_found', '条目不存在', 404);
        }
        if ((int)$cur['group_id'] !== $caps['group_id']) {
            api_fail('forbidden', '该条目不在这个 Key 的分组内', 403);
        }
        $hist = entry_versions($id);
        $out = [];
        foreach ($hist as $v) {
            $out[] = [
                'version'     => (int)$v['version'],
                'title'       => $v['title'],
                'question'    => $v['question'],
                'body'        => $v['body'],
                'source'      => $v['source'],
                'source_date' => $v['source_date'],
                'created_at'  => $v['created_at'],
                'is_current'  => false,
            ];
        }
        $out[] = [
            'version'     => (int)$cur['version'],
            'title'       => $cur['title'],
            'question'    => $cur['question'],
            'body'        => $cur['body'],
            'source'      => $cur['source'],
            'source_date' => $cur['source_date'],
            'created_at'  => $cur['updated_at'],
            'is_current'  => true,
        ];
        json_out(['ok' => true, 'data' => $out, 'meta' => [
            'entry_id' => $id, 'version' => (int)$cur['version'], 'total' => count($out),
        ]]);
    }

    // ---- /v1/changes  （增量拉取：since 之后被改动的条目，含删除）----
    if ($path === '/v1/changes' && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $since = get_q('since');
        $limit = min(500, max(1, (int)get_q('limit', '200')));
        $sql = 'SELECT * FROM ' . tbl('entries') . ' WHERE group_id = ?';
        $p = [$caps['group_id']];
        if ($since !== '') {
            $sql .= ' AND updated_at > ?';
            $p[] = $since;
        }
        $rows = all($sql . ' ORDER BY updated_at ASC, id ASC LIMIT ' . $limit, $p);
        $items = [];
        $last = $since;
        foreach ($rows as $r) {
            $items[] = [
                'id'          => (int)$r['id'],
                'kind'        => $r['kind'],
                'title'       => $r['title'],
                'question'    => $r['question'],
                'version'     => (int)$r['version'],
                'source_date' => $r['source_date'],
                'updated_at'  => $r['updated_at'],
                'deleted'     => (int)$r['status'] === 0,
            ];
            $last = (string)$r['updated_at'];
        }
        json_out(['ok' => true, 'data' => $items, 'meta' => [
            'since'      => $since,
            'next_since' => $last,
            'limit'      => $limit,
            'has_more'   => count($rows) >= $limit,
            'server_time' => now_str(),
        ]]);
    }

    // ---- /v1/export  （全量分页导出，给 Agent 建本地缓存/索引）----
    if ($path === '/v1/export' && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $after = max(0, (int)get_q('after', '0'));
        $limit = min(500, max(1, (int)get_q('limit', '200')));
        $kindRaw = get_q('kind');
        $kind = in_array($kindRaw, ['qa', 'doc'], true) ? $kindRaw : '';
        $sql = 'SELECT * FROM ' . tbl('entries') . ' WHERE group_id = ? AND status = 1 AND id > ?';
        $p = [$caps['group_id'], $after];
        if ($kind !== '') {
            $sql .= ' AND kind = ?';
            $p[] = $kind;
        }
        $rows = all($sql . ' ORDER BY id ASC LIMIT ' . $limit, $p);
        $items = array_map('api_entry_public', $rows);
        $next = $rows ? (int)end($rows)['id'] : $after;
        json_out(['ok' => true, 'data' => $items, 'meta' => [
            'after' => $after, 'next_after' => $next, 'limit' => $limit,
            'has_more' => count($rows) >= $limit,
        ]]);
    }

    // ---- POST /v1/entries  （幂等写入：单条或 {items:[...]} 批量）----
    if ($path === '/v1/entries' && $method === 'POST') {
        $requireWrite();
        $b = api_body();
        $mode = (string)($b['mode'] ?? 'upsert');
        if (!in_array($mode, ['upsert', 'create'], true)) {
            api_fail('bad_request', 'mode 只能是 upsert（默认，按问题去重）或 create（强制新增）');
        }
        $items = isset($b['items']) && is_array($b['items']) ? $b['items'] : [$b];
        if (count($items) > 200) {
            api_fail('bad_request', '单次最多 200 条');
        }
        $ids = [];
        $results = [];
        $errors = [];
        $stat = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($items as $i => $it) {
                if (!is_array($it)) {
                    $errors[] = ['index' => $i, 'message' => '格式错误'];
                    continue;
                }
                $kind = isset($it['kind']) && $it['kind'] === 'doc' ? 'doc' : 'qa';
                $title = trim((string)($it['title'] ?? ''));
                $question = trim((string)($it['question'] ?? ''));
                $body = (string)($it['body'] ?? '');
                if ($title === '' && $question === '' && trim($body) === '') {
                    $errors[] = ['index' => $i, 'message' => 'title/question/body 至少填一项'];
                    continue;
                }
                // 写到哪儿由 Key 决定，请求体里带 group_id 一律忽略（防越权）
                $gid = $caps['group_id'];

                if ($kind === 'doc') {
                    $did = (int)($it['id'] ?? 0);
                    if ($did > 0) {
                        $ex = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ? AND kind = ?', [$did, 'doc']);
                        if ($ex) {
                            snapshot_version($ex, (int)$user['id']);
                            update_row('entries', $did, [
                                'title' => mb_substr($title !== '' ? $title : (string)$ex['title'], 0, 250, 'UTF-8'),
                                'body' => $body !== '' ? $body : (string)$ex['body'],
                                'version' => (int)$ex['version'] + 1,
                                'updated_at' => now_str(),
                            ]);
                            reindex_entry($did, $title, '', $body);
                            if (isset($it['entry_ids']) && is_array($it['entry_ids'])) {
                                save_doc_links($did, $it['entry_ids']);
                            }
                            $ids[] = $did;
                            $stat['updated']++;
                            $results[] = ['index' => $i, 'action' => 'updated', 'id' => $did, 'version' => (int)$ex['version'] + 1];
                            continue;
                        }
                    }
                    $did = insert_row('entries', [
                        'group_id' => $gid, 'kind' => 'doc',
                        'title' => mb_substr($title, 0, 250, 'UTF-8'),
                        'question' => '', 'body' => $body,
                        'source' => mb_substr((string)($it['source'] ?? 'api'), 0, 100, 'UTF-8'),
                        'source_date' => (string)($it['source_date'] ?? ''),
                        'qfp' => '', 'afp' => entry_afp($body),
                        'version' => 1, 'status' => 1,
                        'created_by' => (int)$user['id'],
                        'created_at' => now_str(), 'updated_at' => now_str(),
                    ]);
                    reindex_entry($did, $title, '', $body);
                    if (isset($it['entry_ids']) && is_array($it['entry_ids'])) {
                        save_doc_links($did, $it['entry_ids']);
                    }
                    $ids[] = $did;
                    $stat['created']++;
                    $results[] = ['index' => $i, 'action' => 'created', 'id' => $did, 'version' => 1];
                    continue;
                }

                $r = upsert_qa($gid, $it, (int)$user['id'], $mode);
                $ids[] = $r['id'];
                $stat[$r['action']]++;
                $results[] = ['index' => $i, 'action' => $r['action'], 'id' => $r['id'],
                    'version' => $r['version'], 'changed' => $r['changed']];
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            api_fail('server_error', '写入失败：' . $e->getMessage(), 500);
        }

        audit('api_write_entries', sprintf('created=%d updated=%d skipped=%d',
            $stat['created'], $stat['updated'], $stat['skipped']));
        json_out([
            'ok' => true,
            'data' => [
                'ids'     => $ids,
                'created' => $stat['created'],
                'updated' => $stat['updated'],
                'skipped' => $stat['skipped'],
                'results' => $results,
            ],
            'errors' => $errors,
        ], $errors && !$ids ? 400 : 200);
    }

    // ---- /v1/docs/{id} 只读视图（正文 + 关联问答）----
    if (preg_match('#^/v1/docs/(\d+)$#', $path, $m) && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $id = (int)$m[1];
        $row = one('SELECT * FROM ' . tbl('entries') . ' WHERE id = ? AND kind = ?', [$id, 'doc']);
        if (!$row) {
            api_fail('not_found', '文档不存在', 404);
        }
        if ((int)$row['group_id'] !== $caps['group_id']) {
            api_fail('forbidden', '该文档不在这个 Key 的分组内', 403);
        }
        $data = api_entry_public($row);
        $data['items'] = array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'question' => $r['question'],
            'body'     => $r['body'],
            'note'     => $r['note'] ?? null,
        ], doc_entries($id));
        json_out(['ok' => true, 'data' => $data]);
    }

    /* ---------------- 附件（1.7.0） ---------------- */

    // 列表：只返回这个 Key 所属分组的文件
    if ($path === '/v1/attachments' && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $limit = min(100, max(1, (int)get_q('limit', '50')));
        $offset = max(0, (int)get_q('offset', '0'));
        $entryId = (int)get_q('entry_id', '0');
        $sql = 'SELECT * FROM ' . tbl('attachments') . ' WHERE group_id = ? AND status = 1';
        $par = [$caps['group_id']];
        if ($entryId > 0) {
            $sql .= ' AND entry_id = ?';
            $par[] = $entryId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = all($sql, $par);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'name'       => $r['name'],
                'ext'        => $r['ext'],
                'mime'       => $r['mime'],
                'size'       => (int)$r['size'],
                'entry_id'   => $r['entry_id'] !== null ? (int)$r['entry_id'] : null,
                'created_at' => $r['created_at'],
                'download'   => base_url() . '/api.php?path=/v1/attachments/' . (int)$r['id'] . '/download',
                'markdown'   => in_array(strtolower((string)$r['ext']), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)
                    ? '![' . $r['name'] . '](' . base_url() . '/api.php?path=/v1/attachments/' . (int)$r['id'] . '/download)'
                    : '[' . $r['name'] . '](' . base_url() . '/api.php?path=/v1/attachments/' . (int)$r['id'] . '/download)',
            ];
        }
        json_out(['ok' => true, 'data' => $out, 'meta' => ['limit' => $limit, 'offset' => $offset]]);
    }

    // 上传：multipart（字段 file 或 files[]）或 JSON base64（name + data）
    if ($path === '/v1/attachments' && $method === 'POST') {
        if (!$caps['write']) {
            api_fail('readonly', '该 API Key 只读，不能上传文件', 403);
        }
        $entryId = null;
        $items = [];

        if (!empty($_FILES['file']['name']) || !empty($_FILES['files']['name'][0] ?? null)) {
            $bag = $_FILES['file'] ?? null;
            if ($bag && is_string($bag['name'] ?? null)) {
                $items[] = $bag;
            }
            $multi = $_FILES['files'] ?? null;
            if ($multi && is_array($multi['name'] ?? null)) {
                for ($i = 0; $i < count($multi['name']); $i++) {
                    $items[] = [
                        'name' => $multi['name'][$i], 'type' => $multi['type'][$i] ?? '',
                        'tmp_name' => $multi['tmp_name'][$i], 'error' => (int)($multi['error'][$i] ?? 0),
                        'size' => (int)($multi['size'][$i] ?? 0),
                    ];
                }
            }
            $entryId = (int)($_POST['entry_id'] ?? 0) ?: null;
        } else {
            $b = api_body();
            $name = trim((string)($b['name'] ?? ''));
            $data = (string)($b['data'] ?? $b['content'] ?? '');
            if ($name === '' || $data === '') {
                api_fail('bad_request', '请用 multipart 传 file，或传 JSON: {"name":"a.png","data":"<base64>"}');
            }
            $raw = base64_decode(preg_replace('/^data:[^,]*,/', '', $data) ?? $data, true);
            if ($raw === false || $raw === '') {
                api_fail('bad_request', 'data 不是合法的 base64');
            }
            $tmpDir = BASE_PATH . '/storage/tmp';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0775, true);
            }
            $tmp = $tmpDir . '/up-' . bin2hex(random_bytes(8));
            if (@file_put_contents($tmp, $raw) === false) {
                api_fail('server_error', '临时文件写入失败，检查 storage/tmp 权限', 500);
            }
            $items[] = ['name' => $name, 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen($raw), 'type' => ''];
            $entryId = (int)($b['entry_id'] ?? 0) ?: null;
        }

        $ids = [];
        $errors = [];
        foreach ($items as $it) {
            try {
                $r = file_store((int)$caps['group_id'], $entryId, $it, (int)$user['id']);
                $ids[] = (int)$r['attachment']['id'];
            } catch (Throwable $e) {
                $errors[] = ['name' => $it['name'] ?? '?', 'message' => $e->getMessage()];
            } finally {
                // JSON 模式下临时文件是我们自己写的，删掉
                if (!empty($it['tmp_name']) && str_contains((string)$it['tmp_name'], '/storage/tmp/')) {
                    @unlink((string)$it['tmp_name']);
                }
            }
        }
        if ($ids) {
            audit('api_upload_file', 'ids=' . implode(',', $ids));
        }
        json_out(['ok' => (bool)$ids, 'data' => ['ids' => $ids], 'errors' => $errors], $ids ? 200 : 400);
    }

    // 下载：直接吐字节（Agent 拿这个地址就能取图）
    if (preg_match('#^/v1/attachments/(\d+)/download$#', $path, $m) && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $att = attachment_by_id((int)$m[1]);
        if (!$att || (int)$att['group_id'] !== $caps['group_id']) {
            api_fail('not_found', '文件不存在', 404);
        }
        $got = attachment_fetch($att);
        if (!$got) {
            api_fail('storage_error', '所有存储平台都取不到这个文件', 502);
        }
        $ext = strtolower((string)$att['ext']);
        header('Content-Type: ' . (string)($att['mime'] ?: file_mime_by_ext($ext)));
        header('Content-Length: ' . filesize($got['path']));
        header('Content-Disposition: inline; filename="' . preg_replace('/["\\\\\r\n]/', '', (string)$att['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($got['path']);
        @unlink($got['path']);
        exit;
    }

    // 单个文件元信息
    if (preg_match('#^/v1/attachments/(\d+)$#', $path, $m) && $method === 'GET') {
        if (!$caps['read']) {
            api_fail('readonly', '该 API Key 没有读权限', 403);
        }
        $att = attachment_by_id((int)$m[1]);
        if (!$att || (int)$att['group_id'] !== $caps['group_id']) {
            api_fail('not_found', '文件不存在', 404);
        }
        json_out(['ok' => true, 'data' => [
            'id' => (int)$att['id'], 'name' => $att['name'], 'ext' => $att['ext'],
            'mime' => $att['mime'], 'size' => (int)$att['size'],
            'entry_id' => $att['entry_id'] !== null ? (int)$att['entry_id'] : null,
            'created_at' => $att['created_at'],
            'download' => base_url() . '/api.php?path=/v1/attachments/' . (int)$att['id'] . '/download',
        ]]);
    }

    /* ---------------- AI（1.8.0）：按次扣积分，走同一套 Key 鉴权 ---------------- */

    if ($path === '/v1/ai/me' && $method === 'GET') {
        $ent = ai_entitlement((int)$user['id']);
        json_out(['ok' => true, 'data' => [
            'enabled' => ai_on(),
            'credits' => $ent['credits'],
            'plan' => $ent['plan'],
            'custom_slots' => $ent['custom_slots'],
            'custom_used' => $ent['custom_used'],
            'daily_limit' => $ent['daily_limit'],
            'daily_used' => $ent['daily_used'],
            'is_admin' => $caps['is_admin'],
        ]]);
    }

    if ($path === '/v1/ai/models' && $method === 'GET') {
        $rows = ai_models_for((int)$user['id']);
        json_out(['ok' => true, 'data' => array_map('ai_model_public', $rows)]);
    }

    if (in_array($path, ['/v1/ai/chat', '/v1/ai/polish', '/v1/ai/extract', '/v1/ai/redeem'], true)) {
        if ($method !== 'POST') {
            api_fail('method_not_allowed', '这个接口只接受 POST', 405);
        }
        $b = api_body();
        $mid = (int)($b['model_id'] ?? 0);
        if ($mid <= 0) {
            $mid = ai_default_model_id((int)$user['id']);
        }
        try {
            if ($path === '/v1/ai/chat') {
                $hist = $b['history'] ?? [];
                $r = ai_chat_flow((int)$user['id'], $mid, (string)($b['text'] ?? ''), is_array($hist) ? $hist : []);
                json_out(['ok' => true, 'data' => [
                    'text' => $r['text'], 'model' => $r['model'], 'usage' => $r['usage'],
                    'credits' => $r['credits'], 'balance' => $r['balance'], 'latency_ms' => $r['latency_ms'],
                ]]);
            }
            if ($path === '/v1/ai/polish') {
                $r = ai_polish_answer((int)$user['id'], $mid, [
                    'entry_id' => (int)($b['entry_id'] ?? 0),
                    'text' => (string)($b['text'] ?? ''),
                ]);
                json_out(['ok' => true, 'data' => [
                    'text' => $r['text'], 'model' => $r['model'], 'usage' => $r['usage'],
                    'credits' => $r['credits'], 'balance' => $r['balance'], 'entry_id' => (int)($b['entry_id'] ?? 0),
                ]]);
            }
            if ($path === '/v1/ai/extract') {
                $r = ai_extract_qa((int)$user['id'], $mid, [
                    'text' => (string)($b['text'] ?? ''),
                    'max' => (int)($b['max'] ?? 8),
                ]);
                json_out(['ok' => true, 'data' => [
                    'items' => $r['items'], 'parse_ok' => $r['parse_ok'], 'raw' => $r['text'],
                    'credits' => $r['credits'], 'balance' => $r['balance'],
                ]]);
            }
            // redeem
            $r = ai_redeem((int)$user['id'], (string)($b['code'] ?? ''));
            json_out(['ok' => true, 'data' => $r]);
        } catch (AiError $e) {
            $status = $e->kind === 'insufficient' ? 402 : ($e->kind === 'forbidden' ? 403 : 400);
            api_fail($e->kind, $e->getMessage(), $status);
        } catch (Throwable $e) {
            api_fail('ai_error', $e->getMessage(), 400);
        }
    }

    api_fail('not_found', '接口不存在：' . $path, 404);
}
