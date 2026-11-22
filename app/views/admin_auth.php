<?php
/**
 * 后台 · 登录外观（2.6.0）
 *
 * @var array  $presets 内置背景清单 key=>[名称, 说明]
 * @var array  $slots   槽位 key=>名称
 * @var array  $cfgLogin
 * @var array  $cfgReg
 * @var bool   $regOpen
 * @var string $msg
 * @var string $err
 */
$slotLabel = ['login' => '登录页', 'register' => '注册页'];
// 两个槽位共用一套渲染，变量名照传入的配置走
$cfgs = ['login' => $cfgLogin, 'register' => $cfgReg];

/** 某槽位当前背景的缩略预览地址（预设没有静态图，用 canvas 现场画） */
function auth_bg_preview_src(array $cfg): string
{
    if ($cfg['type'] === 'image' || $cfg['type'] === 'url') {
        $src = $cfg['type'] === 'image' ? site_brand_src_value((string)$cfg['value']) : (string)$cfg['value'];
        return $src;
    }
    return '';
}
?>
<section class="page-head">
  <div>
    <h1 class="page-title">登录外观</h1>
    <p class="page-desc">登录与注册共用的那张 16:9 卡片，背景动效在这里选。</p>
  </div>
</section>

<?php if ($msg !== ''): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>

<section class="panel">
  <?= panel_title('背景动效',
      '<p>内置 10 套 canvas 动效，全部零依赖、不请求任何外部资源，因此不受网络与 CDN 影响。'
      . '登录页与注册页可以各自选一套 —— 它们在同一个卡片里切换，背景也会跟着淡出淡入。</p>'
      . '<p>也可以换成自己的图片、填一个远程地址，或者直接粘一段 HTML / CSS / JS 代码做完全自定义的动画。</p>'
      . '<p class="muted">所有背景都遵守同一组约束：不拦截鼠标点击、对读屏隐藏、'
      . '系统开启「减少动效」时只画一帧静态图、标签页切走时停掉动画、手机上降帧降分辨率。</p>', '10 套内置') ?>

  <form method="post" action="<?= e(u('admin_auth')) ?>" class="form" enctype="multipart/form-data" id="authBgForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="auth_bg">

    <?php foreach ($slots as $slot => $slotName): ?>
      <?php $cfg = $cfgs[$slot]; $p = 'auth_bg_' . $slot . '_'; ?>
      <div class="authcfg" data-slot="<?= e($slot) ?>">
        <div class="authcfg-head">
          <h3 class="authcfg-title"><?= e($slotLabel[$slot] ?? $slotName) ?></h3>
          <span class="authcfg-cur tiny muted">
            当前：<?= $cfg['type'] === 'preset'
              ? e($presets[$cfg['value']][0] ?? $cfg['value'])
              : ($cfg['type'] === 'none' ? '纯色' : ($cfg['type'] === 'code' ? '自定义代码' : '图片')) ?>
          </span>
        </div>

        <?php /* 类型选择：单选按钮做成卡片，点一下换一组输入项 */ ?>
        <div class="authcfg-types">
          <?php
          $types = [
            'preset' => ['内置动效', '10 套 canvas 动画'],
            'image'  => ['上传图片', '自己的一张图'],
            'url'    => ['图片 / 视频地址', '外链或站内路径'],
            'code'   => ['自定义代码', 'HTML / CSS / JS'],
            'none'   => ['纯色', '不要背景'],
          ];
          foreach ($types as $tk => $tv):
            $id = $p . 'type_' . $tk;
          ?>
            <label class="authcfg-type" for="<?= e($id) ?>">
              <input type="radio" id="<?= e($id) ?>" name="<?= e($p) ?>type" value="<?= e($tk) ?>"
                     data-bg-type="<?= e($slot) ?>" <?= $cfg['type'] === $tk ? 'checked' : '' ?>>
              <span class="authcfg-type-name"><?= e($tv[0]) ?></span>
              <span class="authcfg-type-note"><?= e($tv[1]) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <?php /* ---- 内置动效 ---- */ ?>
        <div class="authcfg-body" data-bg-body="<?= e($slot) ?>" data-for="preset">
          <div class="authpreset-grid">
            <?php foreach ($presets as $pk => $pv): ?>
              <label class="authpreset" for="<?= e($p . 'preset_' . $pk) ?>">
                <input type="radio" id="<?= e($p . 'preset_' . $pk) ?>" name="<?= e($p) ?>preset"
                       value="<?= e($pk) ?>" <?= $cfg['value'] === $pk && $cfg['type'] === 'preset' ? 'checked' : '' ?>>
                <span class="authpreset-thumb">
                  <canvas class="authpreset-canvas" data-preview="<?= e($pk) ?>" width="160" height="90"
                          aria-hidden="true"></canvas>
                </span>
                <span class="authpreset-name"><?= e($pv[0]) ?></span>
                <span class="authpreset-note"><?= e($pv[1]) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php /* ---- 上传图片 ---- */ ?>
        <div class="authcfg-body" data-bg-body="<?= e($slot) ?>" data-for="image">
          <div class="form row-form">
            <label><?= field_label('上传一张图', 'png / jpg / gif / webp，≤4 MB。建议横图（16:9 或更宽），竖图会被裁掉两侧。不收 svg —— svg 直链打开会执行脚本。', '≤4 MB') ?>
              <input type="file" name="bg_image_<?= e($slot) ?>" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
            <label><?= field_label('或填图片地址', '不想上传就填外链或站内路径。填了会覆盖上传的文件。', '') ?>
              <input type="text" name="<?= e($p) ?>image_url" maxlength="500"
                     placeholder="https://… 或 /static/bg.jpg">
            </label>
          </div>
          <?php $pv = auth_bg_preview_src($cfg); ?>
          <?php if ($pv !== '' && $cfg['type'] === 'image'): ?>
            <div class="authcfg-preview">
              <img src="<?= e($pv) ?>" alt="">
              <span class="tiny muted">当前图片</span>
            </div>
          <?php endif; ?>
        </div>

        <?php /* ---- 远程地址 ---- */ ?>
        <div class="authcfg-body" data-bg-body="<?= e($slot) ?>" data-for="url">
          <label><?= field_label('图片或视频地址', '图片按铺满裁切；.mp4 / .webm 会自动用 <video> 循环播放。必须 http(s):// 或 / 开头。', '') ?>
            <input type="text" name="<?= e($p) ?>url" maxlength="500"
                   value="<?= $cfg['type'] === 'url' ? e((string)$cfg['value']) : '' ?>"
                   placeholder="https://example.com/bg.mp4">
          </label>
          <?php if ($cfg['type'] === 'url' && $cfg['value'] !== ''): ?>
            <div class="authcfg-preview">
              <img src="<?= e((string)$cfg['value']) ?>" alt="">
              <span class="tiny muted">当前地址（视频不会在这里预览）</span>
            </div>
          <?php endif; ?>
        </div>

        <?php /* ---- 自定义代码 ---- */ ?>
        <div class="authcfg-body" data-bg-body="<?= e($slot) ?>" data-for="code">
          <label><?= field_label('自定义 HTML / CSS / JS', '这段代码会跑在一个 sandbox iframe 里（只给 allow-scripts，不给 allow-same-origin），所以它拿不到本站的 cookie 和登录态，也碰不到页面上的表单 —— 这是安全边界，不是限制。直接写标签就行，不用写 <html> / <body>。', '≤60 KB') ?>
            <textarea name="<?= e($p) ?>code" rows="10" class="mono" spellcheck="false"
                      placeholder="&lt;canvas id=&quot;c&quot;&gt;&lt;/canvas&gt;&#10;&lt;style&gt;body{margin:0;background:#0b1220}canvas{display:block;width:100vw;height:100vh}&lt;/style&gt;&#10;&lt;script&gt;/* 你的动画 */&lt;/script&gt;"><?= $cfg['type'] === 'code' ? e((string)$cfg['value']) : '' ?></textarea>
          </label>
          <div class="hint">
            小提示：iframe 内的背景是透明的，所以要让动画自己有底色（或让它透出下面的主题色）。
            <code>100vw / 100vh</code> 在 iframe 里就是这块区域的尺寸，可以直接用。
          </div>
        </div>

        <?php /* ---- 纯色 ---- */ ?>
        <div class="authcfg-body" data-bg-body="<?= e($slot) ?>" data-for="none">
          <div class="hint">不显示任何背景，只用站点主题的底色。想专心看表单、或者服务器很在意性能时选这个。</div>
        </div>

        <?php /* ---- 压暗 ---- */ ?>
        <div class="authcfg-dim">
          <label><?= field_label('背景压暗程度', '在背景上加一层站点底色的遮罩，让表单上的字更清楚。0% 完全不压（2.5.0 的观感），越高背景越淡。') ?>
            <div class="authcfg-dim-row">
              <input type="range" name="<?= e($p) ?>dim" min="0" max="90" step="5"
                     value="<?= (int)$cfg['dim'] ?>" data-dim-range="<?= e($slot) ?>">
              <span class="authcfg-dim-val" data-dim-val="<?= e($slot) ?>"><?= (int)$cfg['dim'] ?>%</span>
            </div>
          </label>
        </div>

        <label class="check-line tiny authcfg-clear">
          <input type="checkbox" name="<?= e($slot) ?>_clear" value="1">
          本页恢复默认（数字雨、不压暗<?= $cfg['type'] === 'image' ? '，并删除已上传的图片' : '' ?>）
          <?= label_tip('勾选并保存后，这个页面会回到刚装好时的观感。已上传的背景图会一并删除，外链不会动。') ?>
        </label>
      </div>
    <?php endforeach; ?>

    <?php if (!$regOpen): ?>
      <div class="hint">注意：本站当前没有开放自主注册，注册页背景暂时看不到效果；重新开放后自动生效。</div>
    <?php endif; ?>

    <div class="form-actions">
      <button class="btn primary" type="submit">保存外观</button>
      <a class="btn" href="<?= e(u('login')) ?>" target="_blank" rel="noopener">看登录页</a>
      <?php if ($regOpen): ?>
        <a class="btn" href="<?= e(u('register')) ?>" target="_blank" rel="noopener">看注册页</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<section class="panel">
  <details class="fold">
    <summary>自定义动画可以怎么写 <span class="fold-note">几个能直接用的起点</span></summary>
    <div class="fold-body">
      <div class="prose small">
        <p>代码跑在受限 iframe 里，所以可以放心用 <code>&lt;canvas&gt;</code>、<code>&lt;style&gt;</code>、<code>&lt;script&gt;</code>，
           但拿不到父页面的东西（<code>parent.document</code> 会被浏览器拦掉）。</p>
        <p>最省事的一个：铺一层渐变，用 CSS animation 让它缓慢移动。</p>
        <pre><code>&lt;style&gt;
  body { margin: 0; background: #0b1220; }
  .g {
    position: fixed; inset: -20%;
    background: conic-gradient(from 0deg, #123, #2f6fed, #0b1220, #123);
    filter: blur(60px);
    animation: spin 40s linear infinite;
  }
  @keyframes spin { to { transform: rotate(1turn); } }
&lt;/style&gt;
&lt;div class="g"&gt;&lt;/div&gt;</code></pre>
        <p>想要粒子效果，用 canvas 自己画就行，尺寸取 <code>innerWidth / innerHeight</code>：</p>
        <pre><code>&lt;canvas id="c"&gt;&lt;/canvas&gt;&lt;style&gt;
  body{margin:0}canvas{display:block;width:100vw;height:100vh}
&lt;/style&gt;&lt;script&gt;
  var c = document.getElementById('c'), x = c.getContext('2d');
  function fit(){ c.width = innerWidth; c.height = innerHeight; }
  fit(); addEventListener('resize', fit);
  var p = [];
  for (var i = 0; i &lt; 80; i++) p.push({x:Math.random()*innerWidth, y:Math.random()*innerHeight, v:Math.random()*0.6+0.2});
  (function loop(){
    x.fillStyle = 'rgba(11,18,32,.25)'; x.fillRect(0,0,c.width,c.height);
    x.fillStyle = 'rgba(109,156,255,.8)';
    p.forEach(function(o){ o.y += o.v; if (o.y &gt; c.height) o.y = -4;
      x.fillRect(o.x, o.y, 1.6, 6); });
    requestAnimationFrame(loop);
  })();
&lt;/script&gt;</code></pre>
        <p class="muted">后台保存后刷新登录页即可看到效果。如果代码有语法错误，背景会是一块空白 —— 但登录本身不受影响，不会白屏。</p>
      </div>
    </div>
  </details>
</section>

<script>
/* 类型切换：只显示当前选中的那组输入项。
   注意所有输入项都始终在 DOM 里（隐藏不等于不提交），所以服务端只读需要的那个字段，
   不会因为隐藏块的值串味——auth_bg_save() 按 type 分支取字段。 */
(function () {
  'use strict';
  var form = document.getElementById('authBgForm');
  if (!form) return;

  function sync(slot) {
    var checked = form.querySelector('input[data-bg-type="' + slot + '"]:checked');
    var type = checked ? checked.value : 'preset';
    var bodies = form.querySelectorAll('[data-bg-body="' + slot + '"]');
    for (var i = 0; i < bodies.length; i++) {
      bodies[i].classList.toggle('on', bodies[i].getAttribute('data-for') === type);
    }
  }

  var radios = form.querySelectorAll('input[data-bg-type]');
  for (var i = 0; i < radios.length; i++) {
    radios[i].addEventListener('change', function () {
      sync(this.getAttribute('data-bg-type'));
    });
  }
  // 初始化：先跑一次，把不该显示的收起来
  var slots = {};
  for (var k = 0; k < radios.length; k++) slots[radios[k].getAttribute('data-bg-type')] = 1;
  Object.keys(slots).forEach(sync);

  // 压暗滑块旁边显示百分比
  var ranges = form.querySelectorAll('input[data-dim-range]');
  for (var r = 0; r < ranges.length; r++) {
    ranges[r].addEventListener('input', function () {
      var out = form.querySelector('[data-dim-val="' + this.getAttribute('data-dim-range') + '"]');
      if (out) out.textContent = this.value + '%';
    });
  }
})();

/* 预设缩略图：用同一套引擎在一个 160x90 的小 canvas 上跑几帧，
   让管理员不用来回切页面就能看出每套动效长什么样。
   这里直接把每个 canvas 当作独立场景跑——数量少（10 个）、尺寸小，开销可以接受。 */
(function () {
  'use strict';
  var nodes = document.querySelectorAll('canvas[data-preview]');
  if (!nodes.length) return;

  // 缩略图是另一套精简实现：只画几帧静态的代表图形，不跑 rAF。
  // 理由：后台页面已经够重，10 个动画同时跑会明显卡；而缩略图只需要「看得出长相」。
  function thumb(canvas, kind) {
    var w = canvas.width, h = canvas.height;
    var ctx = canvas.getContext('2d');
    var dark = document.documentElement.getAttribute('data-theme') === 'dark'
      || (document.documentElement.getAttribute('data-theme') !== 'light'
          && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var bgc = dark ? 'rgb(12,15,21)' : 'rgb(247,248,250)';
    var inkA = dark ? '160,200,255' : '47,111,237';
    var accA = dark ? '120,255,214' : '21,153,132';

    ctx.fillStyle = bgc;
    ctx.fillRect(0, 0, w, h);

    var i, j;
    if (kind === 'digits') {
      ctx.font = '9px ui-monospace, monospace';
      ctx.textBaseline = 'top';
      for (i = 0; i < w; i += 11) {
        for (j = 0; j < h; j += 12) {
          ctx.fillStyle = 'rgba(' + inkA + ',' + (0.15 + Math.random() * 0.65) + ')';
          ctx.fillText('0123456789ABCDEF'[(Math.random() * 16) | 0], i, j);
        }
      }
    } else if (kind === 'stars') {
      for (i = 0; i < 70; i++) {
        ctx.fillStyle = 'rgba(' + inkA + ',' + (0.2 + Math.random() * 0.7) + ')';
        ctx.beginPath();
        ctx.arc(Math.random() * w, Math.random() * h, Math.random() * 1.4 + 0.3, 0, 6.283);
        ctx.fill();
      }
    } else if (kind === 'aurora') {
      for (i = 0; i < 3; i++) {
        var g = ctx.createLinearGradient(0, 0, w, 0);
        g.addColorStop(0, 'rgba(0,0,0,0)');
        g.addColorStop(0.5, 'rgba(' + (i === 1 ? accA : inkA) + ',0.45)');
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.moveTo(0, h);
        for (var xx = 0; xx <= w; xx += 8) {
          ctx.lineTo(xx, h * (0.32 + i * 0.14) + Math.sin(xx / 26 + i) * h * 0.13);
        }
        ctx.lineTo(w, h);
        ctx.closePath();
        ctx.fill();
      }
    } else if (kind === 'net') {
      var pts = [];
      for (i = 0; i < 16; i++) pts.push([Math.random() * w, Math.random() * h]);
      for (i = 0; i < pts.length; i++) {
        for (j = i + 1; j < pts.length; j++) {
          var dx = pts[i][0] - pts[j][0], dy = pts[i][1] - pts[j][1];
          var d = Math.sqrt(dx * dx + dy * dy);
          if (d > 46) continue;
          ctx.strokeStyle = 'rgba(' + inkA + ',' + (0.35 * (1 - d / 46)) + ')';
          ctx.lineWidth = 0.6;
          ctx.beginPath();
          ctx.moveTo(pts[i][0], pts[i][1]);
          ctx.lineTo(pts[j][0], pts[j][1]);
          ctx.stroke();
        }
      }
      for (i = 0; i < pts.length; i++) {
        ctx.fillStyle = 'rgba(' + inkA + ',0.75)';
        ctx.beginPath();
        ctx.arc(pts[i][0], pts[i][1], 1.4, 0, 6.283);
        ctx.fill();
      }
    } else if (kind === 'ripple') {
      for (i = 1; i <= 5; i++) {
        ctx.strokeStyle = 'rgba(' + (i % 2 ? inkA : accA) + ',' + (0.5 / i) + ')';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.arc(w / 2, h * 0.46, i * 17, 0, 6.283);
        ctx.stroke();
      }
    } else if (kind === 'grid') {
      var hz = h * 0.42;
      ctx.strokeStyle = 'rgba(' + inkA + ',0.28)';
      ctx.lineWidth = 0.7;
      for (i = -4; i <= 4; i++) {
        ctx.beginPath();
        ctx.moveTo(w / 2, hz);
        ctx.lineTo(w / 2 + (i / 4) * w * 1.6, h);
        ctx.stroke();
      }
      for (i = 1; i <= 5; i++) {
        var yy = hz + (i / 5) * (h - hz) * 0.62;
        ctx.strokeStyle = 'rgba(' + inkA + ',' + (0.12 + i * 0.05) + ')';
        ctx.beginPath();
        ctx.moveTo(0, yy);
        ctx.lineTo(w, yy);
        ctx.stroke();
      }
      ctx.strokeStyle = 'rgba(' + accA + ',0.7)';
      ctx.beginPath();
      ctx.moveTo(0, hz);
      ctx.lineTo(w, hz);
      ctx.stroke();
    } else if (kind === 'gradient' || kind === 'orbs') {
      var cols = kind === 'orbs' ? [[accA, 0.34], [inkA, 0.26], [accA, 0.2]]
                                 : [[inkA, 0.4], [accA, 0.34], [inkA, 0.24]];
      for (i = 0; i < cols.length; i++) {
        var cx = w * (0.2 + i * 0.32), cy = h * (0.3 + (i % 2) * 0.44);
        var rr = (kind === 'orbs' ? 26 : 44) + i * 6;
        var rg = ctx.createRadialGradient(cx, cy, 0, cx, cy, rr);
        rg.addColorStop(0, 'rgba(' + cols[i][0] + ',' + cols[i][1] + ')');
        rg.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = rg;
        ctx.fillRect(0, 0, w, h);
      }
    } else if (kind === 'shapes') {
      for (i = 0; i < 7; i++) {
        ctx.save();
        ctx.translate(Math.random() * w, Math.random() * h);
        ctx.rotate(Math.random() * 6.283);
        ctx.strokeStyle = 'rgba(' + (i % 3 === 0 ? accA : inkA) + ',0.5)';
        ctx.lineWidth = 1;
        var s = 8 + Math.random() * 14;
        if (i % 2) {
          ctx.beginPath();
          ctx.moveTo(0, -s / 2); ctx.lineTo(s / 2, s / 2); ctx.lineTo(-s / 2, s / 2);
          ctx.closePath();
        } else {
          ctx.beginPath(); ctx.rect(-s / 2, -s / 2, s, s);
        }
        ctx.stroke();
        ctx.restore();
      }
    } else if (kind === 'stream') {
      ctx.font = '8px ui-monospace, monospace';
      ctx.textBaseline = 'top';
      var words = ['{}', '()', '=>', 'const', 'await', 'data', 'index', '()=>'];
      for (i = 4; i < w; i += 22) {
        for (j = 0; j < h; j += 13) {
          ctx.fillStyle = 'rgba(' + inkA + ',' + (0.15 + Math.random() * 0.5) + ')';
          ctx.fillText(words[(Math.random() * words.length) | 0], i, j);
        }
      }
    }
  }

  for (var i = 0; i < nodes.length; i++) {
    thumb(nodes[i], nodes[i].getAttribute('data-preview'));
  }
})();
</script>
