<?php
declare(strict_types=1);

/**
 * 检索：二字组倒排召回 + 命中数排序 + 字段加权。
 * 只依赖 entry_grams 索引表，SQLite / MySQL 表现一致。
 */

function search_entries(string $query, array $groupIds, string $kind = '', int $limit = 20, int $offset = 0): array
{
    $query = trim($query);
    if ($query === '' || !$groupIds) {
        return ['total' => 0, 'items' => []];
    }

    $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
    $ph = implode(',', array_fill(0, count($groupIds), '?'));

    $baseWhere = "e.status = 1 AND e.group_id IN ($ph)";
    $params = $groupIds;
    if ($kind !== '') {
        $baseWhere .= ' AND e.kind = ?';
        $params[] = $kind;
    }

    $grams = text_grams($query);
    $hits = [];

    if ($grams) {
        // 单次最多 120 个检索单元，够用且避免 SQL 过长
        $grams = array_slice($grams, 0, 120);
        $gph = implode(',', array_fill(0, count($grams), '?'));
        $sql = "SELECT g.entry_id AS eid, COUNT(*) AS h
                FROM " . tbl('entry_grams') . " g
                INNER JOIN " . tbl('entries') . " e ON e.id = g.entry_id
                WHERE g.gram IN ($gph) AND $baseWhere
                GROUP BY g.entry_id";
        try {
            foreach (all($sql, array_merge($grams, $params)) as $r) {
                $hits[(int)$r['eid']] = (int)$r['h'];
            }
        } catch (Throwable $e) {
            $hits = [];
        }

        // 只命中一两个单元的结果太杂，按覆盖率筛一遍；筛完为空则保留原结果
        $need = max(1, (int)ceil(count($grams) * 0.34));
        if ($need > 1 && $hits) {
            $filtered = array_filter($hits, fn($h) => $h >= $need);
            if ($filtered) {
                $hits = $filtered;
            }
        }
    }

    // 召回为空时退回 LIKE（极短查询或拼音式输入）
    if (!$hits) {
        $like = '%' . $query . '%';
        $sql = "SELECT e.id AS eid FROM " . tbl('entries') . " e
                WHERE $baseWhere AND (e.title LIKE ? OR e.question LIKE ? OR e.body LIKE ?)";
        try {
            foreach (all($sql, array_merge($params, [$like, $like, $like])) as $r) {
                $hits[(int)$r['eid']] = 1;
            }
        } catch (Throwable $e) {
            $hits = [];
        }
    }

    if (!$hits) {
        return ['total' => 0, 'items' => []];
    }

    arsort($hits);
    $total = count($hits);
    $slice = array_slice($hits, $offset, $limit, true);

    $ids = array_keys($slice);
    if (!$ids) {
        return ['total' => $total, 'items' => []];
    }

    $iph = implode(',', array_fill(0, count($ids), '?'));
    $rows = all("SELECT * FROM " . tbl('entries') . " WHERE id IN ($iph)", $ids);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }

    $qLower = mb_strtolower($query, 'UTF-8');
    $items = [];
    foreach ($ids as $id) {
        $r = $byId[$id] ?? null;
        if (!$r) {
            continue;
        }
        $score = ($slice[$id] ?? 1) * 10;
        $q = mb_strtolower((string)$r['question'], 'UTF-8');
        $t = mb_strtolower((string)$r['title'], 'UTF-8');
        $b = mb_strtolower((string)$r['body'], 'UTF-8');
        if ($q !== '' && mb_strpos($q, $qLower, 0, 'UTF-8') !== false) {
            $score += 30;
        }
        if ($t !== '' && mb_strpos($t, $qLower, 0, 'UTF-8') !== false) {
            $score += 20;
        }
        if ($b !== '' && mb_strpos($b, $qLower, 0, 'UTF-8') !== false) {
            $score += 8;
        }
        $items[] = ['entry' => $r, 'score' => $score];
    }
    usort($items, fn($a, $b) => $b['score'] <=> $a['score']);

    return ['total' => $total, 'items' => $items];
}

/** 最近更新（主页用） */
function recent_entries(array $groupIds, int $limit = 8): array
{
    if (!$groupIds) {
        return [];
    }
    $groupIds = array_map('intval', $groupIds);
    $ph = implode(',', array_fill(0, count($groupIds), '?'));
    return all(
        "SELECT * FROM " . tbl('entries') . " WHERE status = 1 AND group_id IN ($ph)
         ORDER BY updated_at DESC, id DESC LIMIT $limit",
        $groupIds
    );
}

function group_stats(array $groupIds): array
{
    if (!$groupIds) {
        return [];
    }
    $groupIds = array_map('intval', $groupIds);
    $ph = implode(',', array_fill(0, count($groupIds), '?'));
    $rows = all(
        "SELECT group_id, kind, COUNT(*) c FROM " . tbl('entries') . "
         WHERE status = 1 AND group_id IN ($ph) GROUP BY group_id, kind",
        $groupIds
    );
    $out = [];
    foreach ($rows as $r) {
        $gid = (int)$r['group_id'];
        $out[$gid][$r['kind']] = (int)$r['c'];
    }
    return $out;
}
