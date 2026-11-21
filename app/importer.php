<?php
declare(strict_types=1);

/**
 * CSV 导入：把「问答总表」这类数据灌进某个分组。
 * 命令行（tools/import_wayfair.php）和后台页面上传都调这里。
 */

/**
 * @return array{inserted:int,skipped:int,qa:int,doc:int,docs_made:int}
 */
function import_csv(string $csvPath, int $groupId, int $userId, string $sourceLabel, int $docLimit = 400): array
{
    $pdo = db();
    $fh = fopen($csvPath, 'r');
    if (!$fh) {
        throw new RuntimeException('无法打开 CSV 文件');
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        throw new RuntimeException('CSV 是空的');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $idx = array_flip(array_map(fn($h) => trim((string)$h), $header));

    // 兼容几种列名写法
    $pick = function (array $row, array $names) use ($idx): string {
        foreach ($names as $n) {
            if (isset($idx[$n])) {
                return trim((string)($row[$idx[$n]] ?? ''));
            }
        }
        return '';
    };

    $existing = [];
    foreach (all('SELECT id, question, title, body, qfp FROM ' . tbl('entries') . ' WHERE group_id = ? AND kind = ?', [$groupId, 'qa']) as $r) {
        $key = (string)($r['qfp'] ?? '');
        if ($key === '') {
            $key = entry_qfp((string)$r['question'], (string)$r['title'], (string)$r['body']);
        }
        if ($key !== '') {
            $existing[$key] = (int)$r['id'];
        }
    }

    $inserted = 0;
    $skipped = 0;
    $byBook = [];
    $gramStmt = $pdo->prepare('INSERT INTO ' . tbl('entry_grams') . ' (entry_id, gram) VALUES (?, ?)');

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($fh)) !== false) {
            if (!$row || !array_filter($row)) {
                continue;
            }
            $question = $pick($row, ['问题', '提问', 'question']);
            $answer = $pick($row, ['当前答案', '答案', 'answer', 'body']);
            $book = $pick($row, ['分册', '分类', 'book']);
            if ($book !== '' && !preg_match('/^\d{2}_/u', $book)) {
                $book = '09_其他';
            }
            $first = $pick($row, ['首次提问', '日期', 'source_date']);
            $updated = $pick($row, ['最近更新', 'updated_at']);
            // 「版本数」列偶尔会混进日期之类的脏值，夹到合理区间
            $versions = min(99, max(1, (int)$pick($row, ['版本数', 'version'])));
            if ($question === '' && $answer === '') {
                $skipped++;
                continue;
            }
            $title = $question !== ''
                ? mb_substr(preg_replace('/\s+/u', ' ', $question) ?? $question, 0, 60, 'UTF-8')
                : '';
            $fingerprint = entry_qfp($question, $title, $answer);
            if ($fingerprint !== '' && isset($existing[$fingerprint])) {
                $eid = $existing[$fingerprint];
                $skipped++;
            } else {
                $eid = insert_row('entries', [
                    'group_id' => $groupId,
                    'kind' => 'qa',
                    'title' => $title,
                    'question' => $question,
                    'body' => $answer,
                    'source' => $sourceLabel,
                    'source_date' => $first !== '' ? substr($first, 0, 10) : '',
                    'qfp' => $fingerprint,
                    'afp' => entry_afp($answer),
                    'version' => $versions,
                    'status' => 1,
                    'created_by' => $userId,
                    'created_at' => $first !== '' ? $first : now_str(),
                    'updated_at' => $updated !== '' ? $updated : now_str(),
                ]);
                $existing[$fingerprint] = $eid;
                $inserted++;
                foreach (text_grams(mb_substr($title . ' ' . $question . ' ' . $answer, 0, 6000, 'UTF-8')) as $g) {
                    $gramStmt->execute([$eid, $g]);
                }
            }
            if ($book !== '') {
                $byBook[$book][] = $eid;
            }
        }

        $docsMade = 0;
        $linkStmt = $pdo->prepare('INSERT INTO ' . tbl('doc_links') . ' (doc_id, entry_id, sort, note) VALUES (?, ?, ?, ?)');
        foreach ($byBook as $book => $ids) {
            $book = (string)$book;
            $short = preg_replace('/^\d{2}_/u', '', $book) ?: $book;
            $docTitle = $short . ' · 手册';
            $doc = one('SELECT * FROM ' . tbl('entries') . ' WHERE group_id = ? AND kind = ? AND title = ?', [$groupId, 'doc', $docTitle]);
            $ids = array_slice($ids, 0, $docLimit);
            if ($doc) {
                $did = (int)$doc['id'];
                update_row('entries', $did, [
                    'updated_at' => now_str(),
                    'body' => "本手册由「{$short}」分类下的问答自动汇编，共收录 " . count($ids) . " 条。\n\n内容来自 {$sourceLabel} 的历史答疑，按首次提问时间排列。",
                ]);
                q('DELETE FROM ' . tbl('doc_links') . ' WHERE doc_id = ?', [$did]);
            } else {
                $did = insert_row('entries', [
                    'group_id' => $groupId,
                    'kind' => 'doc',
                    'title' => $docTitle,
                    'question' => '',
                    'body' => "本手册由「{$short}」分类下的问答自动汇编，共收录 " . count($ids) . " 条。\n\n内容来自 {$sourceLabel} 的历史答疑，按首次提问时间排列。",
                    'source' => $sourceLabel,
                    'source_date' => '',
                    'version' => 1,
                    'status' => 1,
                    'created_by' => $userId,
                    'created_at' => now_str(),
                    'updated_at' => now_str(),
                ]);
                $docsMade++;
                foreach (text_grams($docTitle . ' ' . $short) as $g) {
                    $gramStmt->execute([$did, $g]);
                }
            }
            $i = 0;
            foreach ($ids as $eid) {
                $linkStmt->execute([$did, $eid, $i++, '']);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fclose($fh);
        throw $e;
    }
    fclose($fh);

    return [
        'inserted' => $inserted,
        'skipped' => $skipped,
        'qa' => (int)val('SELECT COUNT(*) FROM ' . tbl('entries') . ' WHERE group_id = ? AND kind = ?', [$groupId, 'qa'], 0),
        'doc' => (int)val('SELECT COUNT(*) FROM ' . tbl('entries') . ' WHERE group_id = ? AND kind = ?', [$groupId, 'doc'], 0),
        'docs_made' => $docsMade ?? 0,
    ];
}

/** 保证分组存在并给用户读写权限 */
function ensure_group_and_perm(string $groupName, string $username): array
{
    $group = one('SELECT * FROM ' . tbl('groups') . ' WHERE name = ?', [$groupName]);
    if (!$group) {
        $id = insert_row('groups', [
            'name' => $groupName,
            'slug' => make_slug($groupName),
            'description' => '导入创建的分组',
            'sort' => 1,
            'created_at' => now_str(),
        ]);
        $group = group_by_id($id);
    }
    $user = one('SELECT * FROM ' . tbl('users') . ' WHERE username = ?', [$username]);
    if (!$user) {
        throw new RuntimeException("找不到用户 {$username}");
    }
    $gid = (int)$group['id'];
    $uid = (int)$user['id'];
    $has = one('SELECT * FROM ' . tbl('group_user') . ' WHERE group_id = ? AND user_id = ?', [$gid, $uid]);
    if ($has) {
        update_row('group_user', (int)$has['id'], ['can_read' => 1, 'can_write' => 1]);
    } else {
        insert_row('group_user', [
            'group_id' => $gid, 'user_id' => $uid,
            'can_read' => 1, 'can_write' => 1, 'created_at' => now_str(),
        ]);
    }
    return [$group, $user];
}
