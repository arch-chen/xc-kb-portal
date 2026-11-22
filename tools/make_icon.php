<?php
declare(strict_types=1);

/**
 * 生成内置默认网站图标（开发机跑一次即可，产物入库）。
 *
 * 服务器上没有 GD（php -m 只有 pdo_sqlite / sqlite3 / mbstring），
 * 所以不能用 imagecreatetruecolor，这里直接手写 PNG 像素流：
 *   - 自己拼 IHDR / IDAT / IEND，zlib 用 gzcompress（zlib 是 PHP 自带的，不依赖 GD）
 *   - 图形用圆角方块 + 文字「XC」的笔画，纯数学判断每个像素，抗锯齿用 4x4 超采样
 *
 * 产物：
 *   assets/icon.svg           矢量版（现代浏览器 <link rel="icon" type="image/svg+xml">）
 *   assets/icon-32.png        32x32（通用）
 *   assets/icon-180.png       180x180（iOS 添加到主屏）
 *   assets/favicon.ico        16+32 双尺寸（老浏览器 / 直接访问 /favicon.ico）
 *
 * 用法：php tools/make_icon.php
 */

if (PHP_SAPI !== 'cli') {
    exit("只能在命令行运行\n");
}

$root = dirname(__DIR__);
$assets = $root . '/assets';

/** 主题色：和 app.css 的 --accent 一致（亮色版 #2f6fed） */
const ICON_BG_A = [47, 111, 237];   // #2f6fed
const ICON_BG_B = [109, 156, 255];  // #6d9cff，渐变终点

/* ---------------- 绘制 ---------------- */

/**
 * 在 (0..1) 归一化坐标里判断是否落在「XC」笔画上。
 * 用两条斜线的线段带：X 的两笔 + C 的圆弧。
 */
function icon_ink(float $x, float $y): bool
{
    // X：左上 → 右下
    if (icon_near_seg($x, $y, 0.17, 0.27, 0.40, 0.73, 0.056)) {
        return true;
    }
    // X：左下 → 右上
    if (icon_near_seg($x, $y, 0.17, 0.73, 0.40, 0.27, 0.056)) {
        return true;
    }
    // C：右开口圆弧，圆心 (0.725, 0.50)、半径 0.17..0.275，缺 -50°..50° 一口
    $dx = $x - 0.725;
    $dy = $y - 0.50;
    $r = sqrt($dx * $dx + $dy * $dy);
    if ($r > 0.170 && $r < 0.275) {
        $ang = atan2($dy, $dx);            // -PI..PI
        $deg = $ang * 180 / M_PI;
        if (abs($deg) > 50) {
            return true;
        }
    }
    return false;
}

/** 点到线段的距离是否小于 half（带圆头） */
function icon_near_seg(float $px, float $py, float $x1, float $y1, float $x2, float $y2, float $half): bool
{
    $vx = $x2 - $x1;
    $vy = $y2 - $y1;
    $wx = $px - $x1;
    $wy = $py - $y1;
    $len2 = $vx * $vx + $vy * $vy;
    $t = $len2 > 0 ? ($wx * $vx + $wy * $vy) / $len2 : 0;
    $t = max(0.0, min(1.0, $t));
    $cx = $x1 + $t * $vx;
    $cy = $y1 + $t * $vy;
    $dx = $px - $cx;
    $dy = $py - $cy;
    return ($dx * $dx + $dy * $dy) <= $half * $half;
}

/** 圆角方块的圆角半径（归一化），返回该点是否在方块内 */
function icon_in_round_rect(float $x, float $y, float $r = 0.22): bool
{
    if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
        return false;
    }
    $cx = $x < $r ? $r : ($x > 1 - $r ? 1 - $r : $x);
    $cy = $y < $r ? $r : ($y > 1 - $r ? 1 - $r : $y);
    $dx = $x - $cx;
    $dy = $y - $cy;
    return ($dx * $dx + $dy * $dy) <= $r * $r;
}

/**
 * 生成 RGBA 像素缓冲（每像素 4 字节）。$ss 是超采样倍数。
 * 返回 ['w'=>, 'h'=>, 'px'=>string]
 */
function icon_render(int $size, int $ss = 4): array
{
    $px = '';
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $inBlock = 0;
            $inInk = 0;
            $gradSum = 0.0;
            for ($sy = 0; $sy < $ss; $sy++) {
                for ($sx = 0; $sx < $ss; $sx++) {
                    $nx = ($x + ($sx + 0.5) / $ss) / $size;
                    $ny = ($y + ($sy + 0.5) / $ss) / $size;
                    if (icon_in_round_rect($nx, $ny)) {
                        $inBlock++;
                        // 45° 斜向渐变
                        $gradSum += ($nx + $ny) / 2;
                        if (icon_ink($nx, $ny)) {
                            $inInk++;
                        }
                    }
                }
            }
            $total = $ss * $ss;
            $aBlock = $inBlock / $total;
            if ($aBlock <= 0) {
                $px .= "\x00\x00\x00\x00";
                continue;
            }
            $avg = $inBlock > 0 ? $gradSum / $inBlock : 0.0;
            $rBg = (int)round(ICON_BG_A[0] + (ICON_BG_B[0] - ICON_BG_A[0]) * $avg);
            $gBg = (int)round(ICON_BG_A[1] + (ICON_BG_B[1] - ICON_BG_A[1]) * $avg);
            $bBg = (int)round(ICON_BG_A[2] + (ICON_BG_B[2] - ICON_BG_A[2]) * $avg);

            // 笔画用白色，按覆盖率混合
            $inkRatio = $inInk / $inBlock;
            $r = (int)round($rBg + (255 - $rBg) * $inkRatio);
            $g = (int)round($gBg + (255 - $gBg) * $inkRatio);
            $b = (int)round($bBg + (255 - $bBg) * $inkRatio);
            $a = (int)round($aBlock * 255);
            $px .= chr($r) . chr($g) . chr($b) . chr($a);
        }
    }
    return ['w' => $size, 'h' => $size, 'px' => $px];
}

/* ---------------- PNG 编码 ---------------- */

function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function png_encode(array $img): string
{
    $w = $img['w'];
    $h = $img['h'];
    $px = $img['px'];

    // 每行前面加 filter byte 0（None）
    $raw = '';
    $stride = $w * 4;
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00" . substr($px, $y * $stride, $stride);
    }

    $ihdr = pack('NN', $w, $h) . "\x08\x06\x00\x00\x00";  // 8bit, RGBA
    return "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', gzcompress($raw, 9))
        . png_chunk('IEND', '');
}

/**
 * 用 PNG 数据组 ICO（Vista+ 支持 PNG 内嵌；16/32 小图也走 PNG，
 * 现代浏览器都识别，体积比 BMP 小很多）。
 */
function ico_encode(array $pngs): string
{
    $n = count($pngs);
    $head = pack('vvv', 0, 1, $n);   // reserved, type=1(icon), count
    $offset = 6 + 16 * $n;
    $dir = '';
    $body = '';
    foreach ($pngs as $p) {
        $w = $p['w'] >= 256 ? 0 : $p['w'];
        $h = $p['h'] >= 256 ? 0 : $p['h'];
        $data = $p['png'];
        $dir .= pack('CCCCvvVV', $w, $h, 0, 0, 1, 32, strlen($data), $offset);
        $body .= $data;
        $offset += strlen($data);
    }
    return $head . $dir . $body;
}

/* ---------------- SVG ---------------- */

function icon_svg(): string
{
    // 和位图同构：圆角方块 + 渐变 + XC 笔画（这里用真正的 <path>，比位图锐利）
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="XC">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#2f6fed"/>
      <stop offset="1" stop-color="#6d9cff"/>
    </linearGradient>
  </defs>
  <rect width="64" height="64" rx="14" fill="url(#g)"/>
  <g fill="none" stroke="#fff" stroke-width="6.6" stroke-linecap="round">
    <path d="M11 17.3 L25.6 46.7"/>
    <path d="M11 46.7 L25.6 17.3"/>
    <path d="M53 22.6 A16.4 16.4 0 1 0 53 41.4"/>
  </g>
</svg>

SVG;
}

/* ---------------- 执行 ---------------- */

if (!is_dir($assets)) {
    fwrite(STDERR, "assets/ 不存在\n");
    exit(1);
}

$made = [];

$svg = icon_svg();
file_put_contents($assets . '/icon.svg', $svg);
$made[] = ['icon.svg', strlen($svg)];

foreach ([16, 32, 180] as $sz) {
    $img = icon_render($sz, $sz <= 32 ? 4 : 3);
    $png = png_encode($img);
    $name = $sz === 180 ? 'icon-180.png' : 'icon-' . $sz . '.png';
    file_put_contents($assets . '/' . $name, $png);
    $made[] = [$name, strlen($png)];
    $cache[$sz] = ['w' => $sz, 'h' => $sz, 'png' => $png];
}

$ico = ico_encode([$cache[16], $cache[32]]);
file_put_contents($assets . '/favicon.ico', $ico);
$made[] = ['favicon.ico', strlen($ico)];

foreach ($made as [$n, $len]) {
    printf("%-16s %7d bytes\n", $n, $len);
}
echo "完成\n";
