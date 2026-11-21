<?php
/** 弹窗 iframe 兜底页：把用户送到支付平台。@var array $st @var array $order @var bool $dark */
$isJump = (string)$st['mode'] === 'jump' && (string)$st['url'] !== '';
$bg  = !empty($dark) ? '#161b23' : '#ffffff';
$fg  = !empty($dark) ? '#b3bac6' : '#454b57';
$acc = !empty($dark) ? '#6d9cff' : '#2f6fed';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>跳转支付</title>
<style>
  html, body { margin: 0; height: 100%; }
  body {
    background: <?= $bg ?>;
    color: <?= $fg ?>;
    font: 14px/1.8 "Segoe UI", "PingFang SC", "Microsoft YaHei", system-ui, sans-serif;
    display: flex; align-items: center; justify-content: center;
    text-align: center; padding: 18px; box-sizing: border-box;
  }
  a { color: <?= $acc ?>; }
  .t { font-size: 13px; }
</style>
</head>
<body>
<?php if ($isJump): ?>
  <div>
    <div>正在打开支付页面…</div>
    <div class="t"><a href="<?= e((string)$st['url']) ?>">如果页面没有自动跳转，点这里继续</a></div>
  </div>
  <script>location.replace(<?= json_encode((string)$st['url'], JSON_UNESCAPED_SLASHES) ?>);</script>
<?php else: ?>
  <form id="payFrameForm" method="post" action="<?= e((string)$st['action']) ?>" accept-charset="utf-8">
    <?php foreach ((array)$st['params'] as $k => $v): ?>
      <input type="hidden" name="<?= e((string)$k) ?>" value="<?= e((string)$v) ?>">
    <?php endforeach; ?>
  </form>
  <div>
    <div>正在连接支付平台…</div>
    <div class="t">如果长时间没有反应，请关闭弹窗后重试，或换一种支付方式。</div>
  </div>
  <script>document.getElementById('payFrameForm').submit();</script>
<?php endif; ?>
</body>
</html>
