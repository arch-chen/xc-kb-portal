<?php
declare(strict_types=1);

/**
 * 极简 SMTP 客户端：手写协议，不依赖 Composer、不依赖 mail()。
 *
 * 支持：
 *   - 隐式 SSL（一般 465）
 *   - STARTTLS（一般 587 / 25）
 *   - 明文（内网中继，25）
 *   - AUTH LOGIN / AUTH PLAIN
 *   - multipart/alternative（纯文本 + HTML 两份）
 *
 * 所有函数都不抛异常到页面，smtp_send() 一定返回 ['ok'=>bool,'error'=>string,'log'=>array]。
 */

/** 读一次 SMTP 应答（自动处理多行 250-xxx 响应） */
function smtp_read($fp): array
{
    $buf = '';
    $lines = 0;
    while ($lines < 40) {
        $line = fgets($fp, 2048);
        if ($line === false) {
            break;
        }
        $buf .= $line;
        $lines++;
        // 3 位码 + 空格 表示这是最后一行；3 位码 + 横线 表示还有下一行
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    $code = (int)substr(ltrim($buf), 0, 3);
    return [$code, trim($buf)];
}

/** 发一条命令并读应答。$throw=true 时不符合预期就抛异常 */
function smtp_cmd($fp, string $cmd, array $expect = [250], bool $throw = true): array
{
    if ($cmd !== '') {
        @fwrite($fp, $cmd . "\r\n");
    }
    [$code, $line] = smtp_read($fp);
    if ($throw && !in_array($code, $expect, true)) {
        throw new RuntimeException(smtp_error($code, $line));
    }
    return [$code, $line];
}

/** 把 SMTP 返回码翻译成人能看懂的话 */
function smtp_error(int $code, string $line): string
{
    $map = [
        421 => '服务器暂时不可用（421），稍后再试',
        450 => '邮箱服务商拒收（450），可能是发信太频繁',
        454 => '认证暂时失败（454），请检查是否开启了 SMTP 服务或用了授权码',
        500 => '服务器不认识这条命令（500），可能不支持该加密方式',
        530 => '需要先认证（530），请填写 SMTP 账号密码',
        534 => '认证方式被拒绝（534），多数邮箱需要「授权码」而不是登录密码',
        535 => '认证失败（535），账号、密码或授权码不对',
        550 => '收件人被拒绝（550），地址可能不存在或被对方拦截',
        553 => '发件地址不合法（553），检查「发件人邮箱」',
        554 => '被服务器判为垃圾邮件（554），换内容或改用正规邮箱服务',
    ];
    $hint = $map[$code] ?? ('SMTP 返回 ' . $code . '，发送失败');
    return $hint . '：' . preg_replace('/\s+/', ' ', $line);
}

/** HELO/EHLO 用的域名：取发件人域，退化到 localhost */
function smtp_helo_name(string $from): string
{
    $at = strrchr($from, '@');
    $domain = $at !== false ? substr($at, 1) : '';
    if ($domain === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $domain)) {
        $domain = 'localhost';
    }
    return $domain;
}

/**
 * 拼 MIME 邮件。正文一律 base64，既避免点号转义问题，也不受行宽限制影响。
 */
function smtp_build_message(string $from, string $fromName, string $to, string $subject,
                            string $html, string $text = ''): string
{
    $enc = static fn(string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';

    if ($text === '') {
        $text = html_entity_decode(strip_tags(
            preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $html) ?? $html
        ), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    $domain = smtp_helo_name($from);
    $mid = bin2hex(random_bytes(10)) . '@' . $domain;
    $b = 'alt_' . bin2hex(random_bytes(8));

    $h = [
        'Date: ' . date('r'),
        'From: ' . ($fromName !== '' ? $enc($fromName) . ' ' : '') . '<' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $enc($subject),
        'Message-ID: <' . $mid . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
        'X-Mailer: kb-portal',
    ];

    $body = "--{$b}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text), 76, "\r\n")
        . "--{$b}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html), 76, "\r\n")
        . "--{$b}--\r\n";

    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

/**
 * 发送一封邮件。$c 结构见 mail_cfg()。
 *
 * @return array{ok:bool,error:string,log:array}
 */
function smtp_send(array $c, string $to, string $subject, string $html, string $text = ''): array
{
    $host = trim((string)($c['host'] ?? ''));
    $port = (int)($c['port'] ?? 0);
    $secure = strtolower(trim((string)($c['secure'] ?? 'ssl')));
    if (!in_array($secure, ['ssl', 'tls', 'none'], true)) {
        $secure = 'ssl';
    }
    if ($port <= 0) {
        $port = $secure === 'ssl' ? 465 : ($secure === 'tls' ? 587 : 25);
    }

    $from = trim((string)($c['from'] ?? ''));
    $fromName = str_replace(["\r", "\n"], '', (string)($c['from_name'] ?? ''));
    $user = (string)($c['user'] ?? '');
    $pass = (string)($c['pass'] ?? '');

    if ($host === '') {
        return ['ok' => false, 'error' => '还没有填写 SMTP 服务器地址', 'log' => []];
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '「发件人邮箱」不是合法地址（很多邮箱服务商要求它与 SMTP 账号一致）', 'log' => []];
    }
    $to = trim(str_replace(["\r", "\n"], '', $to));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '收件地址不合法：' . $to, 'log' => []];
    }
    $subject = str_replace(["\r", "\n"], ' ', $subject);

    $message = smtp_build_message($from, $fromName, $to, $subject, $html, $text);
    $helo = smtp_helo_name($from);
    $timeout = 20;

    $sslOpts = [
        'verify_peer' => empty($c['no_verify']),
        'verify_peer_name' => empty($c['no_verify']),
        'allow_self_signed' => !empty($c['no_verify']),
        'SNI_enabled' => true,
        'peer_name' => $host,
    ];
    $ctx = stream_context_create(['ssl' => $sslOpts]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $why = $errstr !== '' ? $errstr : '连接超时';
        $hint = '';
        if ($secure === 'ssl') {
            $hint = '；如果服务器只支持 STARTTLS，把「加密方式」改成 TLS（587）再试';
        } elseif ($secure === 'tls') {
            $hint = '；如果服务器要求直接 SSL，把加密方式改成 SSL（465）再试';
        }
        return [
            'ok' => false,
            'error' => '连接 ' . $host . ':' . $port . ' 失败（' . $why . '）' . $hint
                . '。也可能是主机商封了外发 25/465/587 端口。',
            'log' => [],
        ];
    }
    stream_set_timeout($fp, $timeout);
    $log = [];

    try {
        [$code, $line] = smtp_read($fp);
        $log[] = 'S: ' . $line;
        if ($code !== 220) {
            throw new RuntimeException('服务器没有正常问候（' . $code . '）：' . $line);
        }

        smtp_cmd($fp, 'EHLO ' . $helo, [250]);
        $log[] = 'C: EHLO ' . $helo;

        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) {
                throw new RuntimeException('STARTTLS 握手失败：对方证书不被信任或协议不匹配。可勾选「跳过证书校验」，或改用 SSL 465');
            }
            smtp_cmd($fp, 'EHLO ' . $helo, [250]);
            $log[] = 'C: STARTTLS 已建立';
        }

        if ($user !== '') {
            [$code] = smtp_cmd($fp, 'AUTH LOGIN', [334], false);
            if ($code === 334) {
                smtp_cmd($fp, base64_encode($user), [334]);
                smtp_cmd($fp, base64_encode($pass), [235]);
            } else {
                // 有的服务器只认 PLAIN
                smtp_cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
            }
            $log[] = 'C: AUTH 成功（' . $user . '）';
        }

        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);
        @fwrite($fp, $message . "\r\n.\r\n");
        [$code, $line] = smtp_read($fp);
        if ($code !== 250) {
            throw new RuntimeException(smtp_error($code, $line));
        }
        $log[] = 'C: 已投递 ' . $to;

        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return ['ok' => true, 'error' => '', 'log' => $log];
    } catch (Throwable $e) {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return ['ok' => false, 'error' => $e->getMessage(), 'log' => $log];
    }
}
