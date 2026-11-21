<?php
declare(strict_types=1);

/**
 * 1.0.0 → 1.1.0
 *
 * 引入「幂等写入 + 版本记录」：
 *   - entries 加 qfp（问题指纹）/ afp（答案指纹），用于按问题去重
 *   - 新建 entry_versions，每次答案变更前把旧版本快照存下来
 *   - 回填历史条目的指纹
 *
 * 注意：迁移执行时进程里加载的还是**旧版代码**（文件覆盖后才 require 本文件），
 * 所以这里不能调用 app/upsert.php 里的函数，归一化逻辑就地实现。
 */
return function (PDO $pdo): void {
    // 与 app/upsert.php 的 entry_norm() 保持一致：去标点、去空白、转小写
    $norm = static function (string $t): string {
        $t = mb_strtolower($t, 'UTF-8');
        $t = preg_replace('/[^\p{Han}\p{Latin}\p{Nd}_\s]+/u', ' ', $t) ?? '';
        $t = preg_replace('/[\s\x{3000}]+/u', '', $t) ?? '';
        return trim($t);
    };
    $qfpOf = static function (string $question, string $title, string $body) use ($norm): string {
        $base = trim($question) !== '' ? $question : (trim($title) !== '' ? $title : mb_substr($body, 0, 500, 'UTF-8'));
        $n = mb_substr($norm($base), 0, 300, 'UTF-8');
        return $n === '' ? '' : sha1($n);
    };
    $afpOf = static function (string $body) use ($norm): string {
        return sha1(mb_substr($norm($body), 0, 8000, 'UTF-8'));
    };

    $my = db_driver() === 'mysql';
    $VC = fn(int $n) => $my ? "VARCHAR($n)" : 'TEXT';
    $PK = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $INT = $my ? 'INT' : 'INTEGER';
    $TEXT = $my ? 'LONGTEXT' : 'TEXT';
    $DT = $my ? 'DATETIME' : 'TEXT';

    // 1. 指纹列（已存在就跳过）
    foreach (['qfp' => $VC(64), 'afp' => $VC(64)] as $col => $type) {
        try {
            $pdo->exec('ALTER TABLE ' . tbl('entries') . " ADD COLUMN {$col} {$type} NULL");
        } catch (Throwable $e) {
            // 列已存在
        }
    }

    // 2. 版本表
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . tbl('entry_versions') . " (
        id {$PK},
        entry_id {$INT} NOT NULL,
        version {$INT} NOT NULL DEFAULT 1,
        title {$VC(255)} NULL,
        question {$TEXT} NULL,
        body {$TEXT} NULL,
        source {$VC(120)} NULL,
        source_date {$VC(20)} NULL,
        changed_by {$INT} NULL,
        created_at {$DT} NULL
    )" . ($my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));

    // 3. 索引
    foreach ([
        'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . tbl('idx_entries_qfp') . ' ON ' . tbl('entries') . ' (group_id, qfp)',
        'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . tbl('idx_versions_entry') . ' ON ' . tbl('entry_versions') . ' (entry_id)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // 索引已存在
        }
    }

    // 4. 回填历史条目的指纹（分块，避免一次全压进内存）
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE ' . tbl('entries') . ' SET qfp = ?, afp = ? WHERE id = ?');
        $last = 0;
        while (true) {
            $rows = all(
                'SELECT id, title, question, body FROM ' . tbl('entries')
                . ' WHERE kind = ? AND id > ? AND (qfp IS NULL OR qfp = ?) ORDER BY id ASC LIMIT 300',
                ['qa', $last, '']
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $last = (int)$r['id'];
                $qfp = $qfpOf((string)($r['question'] ?? ''), (string)($r['title'] ?? ''), (string)($r['body'] ?? ''));
                if ($qfp === '') {
                    continue;
                }
                $stmt->execute([$qfp, $afpOf((string)($r['body'] ?? '')), (int)$r['id']]);
            }
            if (count($rows) < 300) {
                break;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
};
