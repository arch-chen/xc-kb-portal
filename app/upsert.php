<?php
declare(strict_types=1);

/**
 * 幂等写入：Agent 每天推增量时的核心逻辑。
 *
 * 三条规则（和群聊知识库的沉淀规则一致）：
 *   1. 同一个问题只留一条 —— 用「问题指纹」匹配，重复推不会堆出副本
 *   2. 答案没变 —— 直接跳过，不动 updated_at（避免把「假更新」推给下游）
 *   3. 答案变了 —— 旧答案先存进 entry_versions，再覆盖，version + 1
 *
 * 指纹都基于 text_norm（去空白、去标点、转小写），所以
 * 「DS 仓库没货了怎么办？」和「ds仓库没货了怎么办」算同一个问题。
 */

/**
 * 指纹专用归一化：在 text_norm 基础上再去掉所有空白。
 * 中文不用空格断词，「DS 仓库」和「ds仓库」必须算同一个问题；
 * 英文多空格/换行差异同理，不该被当成新答案。
 */
function entry_norm(string $t): string
{
    $t = text_norm($t);
    return preg_replace('/[\s\x{3000}]+/u', '', $t) ?? '';
}

/**
 * 问题指纹：优先用问题，没问题就用标题，都没有就用正文首段。
 * 只取前 300 字，避免超长正文（比如整篇文档）导致指纹抖动。
 */
function entry_qfp(string $question, string $title = '', string $body = ''): string
{
    $base = trim($question) !== '' ? $question : (trim($title) !== '' ? $title : mb_substr($body, 0, 500, 'UTF-8'));
    $norm = mb_substr(entry_norm($base), 0, 300, 'UTF-8');
    return $norm === '' ? '' : sha1($norm);
}

/** 答案指纹：判断「这次推的答案和库里的是不是一样」 */
function entry_afp(string $body): string
{
    return sha1(mb_substr(entry_norm($body), 0, 8000, 'UTF-8'));
}

/** 把当前值快照进版本表（在覆盖之前调用） */
function snapshot_version(array $row, int $byUser = 0): void
{
    $exists = val(
        'SELECT id FROM ' . tbl('entry_versions') . ' WHERE entry_id = ? AND version = ?',
        [(int)$row['id'], (int)$row['version']]
    );
    if ($exists) {
        return; // 同一版本只存一次
    }
    insert_row('entry_versions', [
        'entry_id'    => (int)$row['id'],
        'version'     => (int)$row['version'],
        'title'       => $row['title'] ?? '',
        'question'    => $row['question'] ?? '',
        'body'        => $row['body'] ?? '',
        'source'      => $row['source'] ?? '',
        'source_date' => $row['source_date'] ?? '',
        'changed_by'  => $byUser,
        'created_at'  => now_str(),
    ]);
}

/** 某个条目的全部历史版本（不含当前，按版本号升序） */
function entry_versions(int $entryId): array
{
    return all(
        'SELECT * FROM ' . tbl('entry_versions') . ' WHERE entry_id = ? ORDER BY version ASC, id ASC',
        [$entryId]
    );
}

/**
 * 在分组内按问题指纹找已有条目。
 * qfp 为空的旧数据（1.0.0 装的老库）用问题原文精确匹配兜底，命中后顺手补上指纹。
 */
function find_by_qfp(int $groupId, string $qfp, string $question, string $title): ?array
{
    if ($qfp === '') {
        return null;
    }
    $row = one(
        'SELECT * FROM ' . tbl('entries') . ' WHERE group_id = ? AND kind = ? AND qfp = ? AND status = 1 LIMIT 1',
        [$groupId, 'qa', $qfp]
    );
    if ($row) {
        return $row;
    }
    $probe = trim($question) !== '' ? $question : trim($title);
    if ($probe === '') {
        return null;
    }
    $row = one(
        'SELECT * FROM ' . tbl('entries') . ' WHERE group_id = ? AND kind = ? AND qfp IS NULL
           AND (question = ? OR title = ?) AND status = 1 LIMIT 1',
        [$groupId, 'qa', $probe, $probe]
    );
    if ($row) {
        update_row('entries', (int)$row['id'], ['qfp' => $qfp, 'afp' => entry_afp((string)$row['body'])]);
        $row['qfp'] = $qfp;
    }
    return $row;
}

/**
 * 幂等写入一条问答。
 *
 * @param array  $it    {title,question,body,source,source_date,version_hint?}
 * @param int    $gid   目标分组
 * @param int    $uid   操作人
 * @param string $mode  upsert（默认，幂等）| create（强制新增，旧行为）
 * @return array{action:string,id:int,version:int,changed:array}
 *   action: created | updated | skipped
 */
function upsert_qa(int $gid, array $it, int $uid, string $mode = 'upsert'): array
{
    $title    = trim((string)($it['title'] ?? ''));
    $question = trim((string)($it['question'] ?? ''));
    $body     = (string)($it['body'] ?? '');
    $source   = mb_substr(trim((string)($it['source'] ?? 'api')), 0, 100, 'UTF-8');
    $sdate    = trim((string)($it['source_date'] ?? ''));

    if ($title === '' && $question !== '') {
        $title = mb_substr(preg_replace('/\s+/u', ' ', $question) ?? $question, 0, 60, 'UTF-8');
    }
    $title = mb_substr($title, 0, 250, 'UTF-8');

    $qfp = entry_qfp($question, $title, $body);
    $afp = entry_afp($body);

    if ($mode !== 'create') {
        $old = find_by_qfp($gid, $qfp, $question, $title);
        if ($old) {
            $id = (int)$old['id'];

            // 老数据可能没 afp，用当时的正文算一遍再比
            $oldAfp = (string)($old['afp'] ?? '');
            if ($oldAfp === '' && (string)$old['body'] !== '') {
                $oldAfp = entry_afp((string)$old['body']);
            }
            // 只有「答案」变了才算内容更新 —— 问题的错别字/空格/标点差异不值得涨版本
            $bodyChanged = $body !== '' && $afp !== $oldAfp;

            if (!$bodyChanged) {
                // 补齐缺失的字段，但不涨版本、不动 updated_at
                $patch = [];
                if ((string)($old['qfp'] ?? '') === '' && $qfp !== '') {
                    $patch['qfp'] = $qfp;
                }
                if ((string)($old['afp'] ?? '') === '' && $afp !== '') {
                    $patch['afp'] = $afp;
                }
                if ((string)$old['question'] === '' && $question !== '') {
                    $patch['question'] = $question;
                }
                if ((string)$old['source'] === '' && $source !== '') {
                    $patch['source'] = $source;
                }
                if ((string)$old['source_date'] === '' && $sdate !== '') {
                    $patch['source_date'] = $sdate;
                }
                if ($patch) {
                    update_row('entries', $id, $patch);
                }
                return ['action' => 'skipped', 'id' => $id, 'version' => (int)$old['version'], 'changed' => []];
            }

            snapshot_version($old, $uid);
            $upd = [
                'body'       => $body,
                'afp'        => $afp,
                'version'    => (int)$old['version'] + 1,
                'updated_at' => now_str(),
            ];
            // 顺手把问题/标题换成这次的写法（更完整的那份），不影响版本语义
            if ($question !== '') {
                $upd['question'] = $question;
            }
            if ($title !== '') {
                $upd['title'] = $title;
            }
            if ($sdate !== '') {
                $upd['source_date'] = $sdate;
            }
            if ($source !== '') {
                $upd['source'] = $source;
            }
            update_row('entries', $id, $upd);
            reindex_entry($id, (string)$upd['title'], (string)$upd['question'], (string)$upd['body']);
            $changed = ['body'];
            if ($sdate !== '' && $sdate !== (string)$old['source_date']) {
                $changed[] = 'source_date';
            }
            return ['action' => 'updated', 'id' => $id, 'version' => (int)$upd['version'], 'changed' => $changed];
        }
    }

    $id = insert_row('entries', [
        'group_id'    => $gid,
        'kind'        => 'qa',
        'title'       => $title,
        'question'    => $question,
        'body'        => $body,
        'source'      => $source,
        'source_date' => $sdate,
        'qfp'         => $qfp,
        'afp'         => $afp,
        'version'     => min(99, max(1, (int)($it['version_hint'] ?? 1))),
        'status'      => 1,
        'created_by'  => $uid,
        'created_at'  => now_str(),
        'updated_at'  => now_str(),
    ]);
    reindex_entry($id, $title, $question, $body);
    return ['action' => 'created', 'id' => $id, 'version' => 1, 'changed' => []];
}

/** 回填历史条目的指纹（1.0.0 升级上来后跑一次） */
function backfill_fingerprints(): int
{
    $rows = all('SELECT id, title, question, body FROM ' . tbl('entries')
        . ' WHERE (qfp IS NULL OR qfp = ?) AND kind = ?', ['', 'qa']);
    $n = 0;
    foreach ($rows as $r) {
        $qfp = entry_qfp((string)($r['question'] ?? ''), (string)($r['title'] ?? ''), (string)($r['body'] ?? ''));
        if ($qfp === '') {
            continue;
        }
        update_row('entries', (int)$r['id'], [
            'qfp' => $qfp,
            'afp' => entry_afp((string)($r['body'] ?? '')),
        ]);
        $n++;
    }
    return $n;
}
