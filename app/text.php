<?php
declare(strict_types=1);

/**
 * 文本处理：归一化、中文二字组、简单 Markdown 渲染、摘要高亮。
 * 中文不做分词，用「二字组倒排 + 命中数排序」实现可用的中文检索，
 * 好处是不依赖 MySQL ngram 解析器或额外搜索引擎，SQLite 也能跑。
 */

function text_norm(string $t): string
{
    $t = mb_strtolower($t, 'UTF-8');
    // 只保留中日韩、字母、数字、下划线和空格
    $t = preg_replace('/[^\p{Han}\p{Latin}\p{Nd}_\s]+/u', ' ', $t) ?? '';
    $t = preg_replace('/\s+/u', ' ', $t) ?? '';
    return trim($t);
}

/**
 * 生成检索单元：
 * - 连续中文：每两个相邻字一个单元（「库存清零」→ 库存/存清/清零）
 * - 单个中文字：本身
 * - 英文/数字：整词（长度 ≥2 才保留）
 */
function text_grams(string $t): array
{
    $t = text_norm($t);
    if ($t === '') {
        return [];
    }
    $grams = [];
    $len = mb_strlen($t, 'UTF-8');
    $buf = '';
    $bufType = '';

    $flush = function () use (&$buf, &$bufType, &$grams) {
        if ($buf === '') {
            return;
        }
        $n = mb_strlen($buf, 'UTF-8');
        if ($bufType === 'han') {
            if ($n === 1) {
                $grams[$buf] = 1;
            } else {
                for ($i = 0; $i < $n - 1; $i++) {
                    $g = mb_substr($buf, $i, 2, 'UTF-8');
                    $grams[$g] = 1;
                }
            }
        } else {
            if ($n >= 2) {
                $grams[$buf] = 1;
            }
        }
        $buf = '';
        $bufType = '';
    };

    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($t, $i, 1, 'UTF-8');
        if (preg_match('/^\p{Han}$/u', $ch)) {
            $type = 'han';
        } elseif (preg_match('/^[a-z0-9_]$/u', $ch)) {
            $type = 'word';
        } else {
            $flush();
            continue;
        }
        if ($type !== $bufType) {
            $flush();
            $bufType = $type;
        }
        $buf .= $ch;
    }
    $flush();

    return array_keys($grams);
}

/** 索引一个条目的检索单元 */
function reindex_entry(int $entryId, string $title, string $question, string $body): void
{
    q('DELETE FROM ' . tbl('entry_grams') . ' WHERE entry_id = ?', [$entryId]);
    $grams = text_grams(mb_substr($title . ' ' . $question . ' ' . $body, 0, 6000, 'UTF-8'));
    if (!$grams) {
        return;
    }
    $rows = [];
    foreach ($grams as $g) {
        $rows[] = [$entryId, $g];
    }
    $pdo = db();
    // 可能被包在批量写入的事务里（比如 API 批量 upsert），此时不再另开事务
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('INSERT INTO ' . tbl('entry_grams') . ' (entry_id, gram) VALUES (?, ?)');
        foreach ($rows as $r) {
            $st->execute($r);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 极简 Markdown：标题、列表、粗体、行内代码、围栏代码块、自动链接。
 * 先转义再套标签，避免 XSS。
 */
function md(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $out = [];
    $lines = explode("\n", $text);
    $inCode = false;
    $inList = false;
    $para = [];

    $flushPara = function () use (&$para, &$out) {
        if (!$para) {
            return;
        }
        $t = implode(' ', $para);
        $out[] = '<p>' . inline_md($t) . '</p>';
        $para = [];
    };
    $closeList = function () use (&$inList, &$out) {
        if ($inList) {
            $out[] = '</ul>';
            $inList = false;
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^```/', $line)) {
            $flushPara();
            $closeList();
            if ($inCode) {
                $out[] = '</code></pre>';
                $inCode = false;
            } else {
                $out[] = '<pre><code>';
                $inCode = true;
            }
            continue;
        }
        if ($inCode) {
            $out[] = e($line);
            continue;
        }
        $trim = trim($line);
        if ($trim === '') {
            $flushPara();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $trim, $m)) {
            $flushPara();
            $closeList();
            $lvl = min(6, mb_strlen($m[1]) + 2);
            $out[] = "<h$lvl>" . inline_md($m[2]) . "</h$lvl>";
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/u', $trim, $m)) {
            $flushPara();
            if (!$inList) {
                $out[] = '<ul>';
                $inList = true;
            }
            $out[] = '<li>' . inline_md($m[1]) . '</li>';
            continue;
        }
        $closeList();
        $para[] = $trim;
    }
    if ($inCode) {
        $out[] = '</code></pre>';
    }
    $flushPara();
    $closeList();

    return implode("\n", $out);
}

function inline_md(string $t): string
{
    $t = e($t);

    // 先把行内代码抽出来，避免里面的 ![](...) 或 URL 被后面几步误伤
    $codes = [];
    $t = preg_replace_callback('/`([^`]+)`/u', function (array $m) use (&$codes): string {
        $codes[] = $m[1];
        return "\x01C" . (count($codes) - 1) . "\x01";
    }, $t) ?? $t;

    $t = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $t) ?? $t;

    // 图片：只放行 http(s):// 与站内绝对路径（附件走 ?r=f&id=），挡掉 javascript: 之类
    $t = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/u', function (array $m): string {
        $src = $m[2];
        $ok = preg_match('#^https?://#i', $src) === 1
            || (str_starts_with($src, '/') && str_contains($src, 'r=f'));
        if (!$ok) {
            return $m[0];
        }
        return '<img src="' . $src . '" alt="' . $m[1] . '" loading="lazy"'
            . ' style="max-width:100%;height:auto;border-radius:8px;display:block;margin:8px 0">';
    }, $t) ?? $t;

    // 站内附件链接：[文件名](/index.php?r=f&id=12)
    $t = preg_replace('/\[([^\]]+)\]\((\/?[\w\-.?=&;%\/]*r=f[\w\-.?=&;%\/]*)\)/u', '<a href="$2">$1</a>', $t) ?? $t;

    // 裸链接自动加 a 标签；lookbehind 排除已经处在属性里的 URL（src="…" / href="…"）
    $t = preg_replace(
        '/(?<!["\'=])(https?:\/\/[^\s<>"\']+)/u',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $t
    ) ?? $t;

    // 还原行内代码
    $t = preg_replace_callback('/\x01C(\d+)\x01/', function (array $m) use ($codes): string {
        return '<code>' . ($codes[(int)$m[1]] ?? '') . '</code>';
    }, $t) ?? $t;

    return $t;
}

/** 提要：优先定位查询词，截取上下文 */
function snippet(string $body, string $query, int $len = 150): string
{
    $body = preg_replace('/\s+/u', ' ', trim($body)) ?? '';
    if ($body === '') {
        return '';
    }
    $q = text_norm($query);
    $pos = -1;
    if ($q !== '') {
        $hay = mb_strtolower($body, 'UTF-8');
        foreach ([$q, mb_substr($q, 0, 2, 'UTF-8')] as $needle) {
            if ($needle === '') {
                continue;
            }
            $p = mb_strpos($hay, $needle, 0, 'UTF-8');
            if ($p !== false) {
                $pos = $p;
                break;
            }
        }
    }
    if ($pos === false || $pos < 0) {
        $pos = 0;
    }
    $start = max(0, $pos - 40);
    $cut = mb_substr($body, $start, $len, 'UTF-8');
    $prefix = $start > 0 ? '…' : '';
    $suffix = mb_strlen($body, 'UTF-8') > $start + $len ? '…' : '';
    return e($prefix . $cut . $suffix);
}

/** 在已转义的 HTML 片段里高亮查询词（含二字组） */
function highlight(string $escaped, string $query): string
{
    $q = text_norm($query);
    if ($q === '') {
        return $escaped;
    }
    $words = [$q];
    if (mb_strlen($q, 'UTF-8') > 2) {
        $words = array_merge($words, text_grams($q));
    }
    usort($words, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
    $seen = [];
    foreach ($words as $w) {
        if ($w === '' || isset($seen[$w])) {
            continue;
        }
        $seen[$w] = 1;
        $escaped = preg_replace('/(' . preg_quote($w, '/') . ')/iu', '<mark>$1</mark>', $escaped) ?? $escaped;
    }
    // 避免嵌套 mark
    $escaped = preg_replace('/<mark>(\s*)<mark>/u', '<mark>$1', $escaped) ?? $escaped;
    return preg_replace('/<\/mark>(\s*)<\/mark>/u', '$1</mark>', $escaped) ?? $escaped;
}

function human_date(?string $d): string
{
    if (!$d) {
        return '';
    }
    $ts = strtotime($d);
    if (!$ts) {
        return $d;
    }
    $diff = time() - $ts;
    if ($diff >= 0 && $diff < 3600) {
        return max(1, (int)floor($diff / 60)) . ' 分钟前';
    }
    if ($diff >= 0 && $diff < 86400) {
        return (int)floor($diff / 3600) . ' 小时前';
    }
    if ($diff >= 0 && $diff < 86400 * 7) {
        return (int)floor($diff / 86400) . ' 天前';
    }
    return date('Y-m-d', $ts);
}
