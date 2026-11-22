/* ============ 登录页数字动态画面（2.5.0） ============
   原则：
   1. 只是背景。绝不能抢输入框的注意力，也不能挡点击（pointer-events:none）。
   2. 尊重系统设置。prefers-reduced-motion: reduce 时直接不启动，
      只留一层静态暗色底——无障碍优先于好看。
   3. 省电。手机降列密度和帧率，标签页隐藏时停 rAF。
   4. 主题感知。亮色主题用浅底蓝字，暗色主题用深底亮字，都读得出来。
   5. 无依赖、不请求任何资源。纯 canvas + 随机数。

   字形用 0/1 加少量十六进制，比纯 0/1 更像「数据」而不是「代码雨」。
*/
(function () {
  'use strict';

  var canvas = document.getElementById('digitBg');
  if (!canvas || !canvas.getContext) return;

  var reduce = false;
  try {
    reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch (e) {}

  var ctx = canvas.getContext('2d');
  var W = 0, H = 0, DPR = 1;
  var cols = 0, colW = 16, rows = 0;
  // drops[i] = 当前这一列「笔头」的行号（可以是小数，让下落更平滑）
  var drops = [];
  var speeds = [];
  var chars = '01 0123456789ABCDEF'.replace(/ /g, '').split('');
  var rafId = 0, lastTs = 0, running = false;

  /* 帧率上限：桌面 24fps 已经很顺（这是背景不是游戏），手机 15fps */
  var targetFps = 24;
  var isSmall = false;

  function isDark() {
    var t = document.documentElement.getAttribute('data-theme') || 'system';
    if (t === 'dark') return true;
    if (t === 'light') return false;
    try {
      return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    } catch (e) {
      return false;
    }
  }

  function palette() {
    return isDark()
      ? { bg: 'rgba(10,13,18,1)', head: 'rgba(160,200,255,0.92)', tail: 'rgba(109,156,255,0.34)' }
      : { bg: 'rgba(246,247,249,1)', head: 'rgba(47,111,237,0.62)', tail: 'rgba(47,111,237,0.20)' };
  }

  function measure() {
    DPR = Math.min(window.devicePixelRatio || 1, 2);   // 高分屏封顶 2，省一半像素
    W = canvas.clientWidth || window.innerWidth;
    H = canvas.clientHeight || window.innerHeight;
    canvas.width = Math.round(W * DPR);
    canvas.height = Math.round(H * DPR);
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);

    isSmall = W < 760;
    targetFps = isSmall ? 15 : 24;

    // 手机上列窄一点、密一点，字小但看得见；桌面上放宽
    colW = isSmall ? 13 : 17;
    cols = Math.max(1, Math.floor(W / colW));
    rows = Math.ceil(H / colW) + 1;

    drops = [];
    speeds = [];
    for (var i = 0; i < cols; i++) {
      // 起始位置随机散开，避免开场所有列齐刷刷从上往下掉
      drops.push(Math.random() * rows);
      speeds.push(0.28 + Math.random() * 0.55);
    }
  }

  function draw() {
    var p = palette();

    // 拖尾：不整屏清空，而是盖一层半透明底色，让旧字形自然淡出
    ctx.fillStyle = p.bg;
    ctx.globalAlpha = isDark() ? 0.16 : 0.20;
    ctx.fillRect(0, 0, W, H);
    ctx.globalAlpha = 1;

    ctx.font = (isSmall ? 12 : 14) + 'px ui-monospace, "Cascadia Code", Consolas, monospace';
    ctx.textBaseline = 'top';

    for (var i = 0; i < cols; i++) {
      var x = i * colW + 2;
      var y = drops[i] * colW;

      // 笔头一个字最亮，后面两个字稍淡，形成「正在生成」的感觉
      for (var k = 0; k < 3; k++) {
        var yy = y - k * colW;
        if (yy < -colW || yy > H) continue;
        var ch = chars[(Math.random() * chars.length) | 0];
        ctx.fillStyle = k === 0 ? p.head : p.tail;
        ctx.globalAlpha = k === 0 ? 1 : (k === 1 ? 0.55 : 0.25);
        ctx.fillText(ch, x, yy);
      }
      ctx.globalAlpha = 1;

      drops[i] += speeds[i];

      // 落地后随机重置：不是立刻回到 0，而是留一段空档，看起来更自然
      if (y > H && Math.random() > 0.972) {
        drops[i] = -Math.random() * 6;
        speeds[i] = 0.28 + Math.random() * 0.55;
      }
    }
  }

  function loop(ts) {
    if (!running) return;
    var budget = 1000 / targetFps;
    if (!lastTs || ts - lastTs >= budget) {
      lastTs = ts;
      draw();
    }
    rafId = requestAnimationFrame(loop);
  }

  function start() {
    if (running || reduce) return;
    running = true;
    lastTs = 0;
    rafId = requestAnimationFrame(loop);
  }

  function stop() {
    running = false;
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = 0;
    }
  }

  /* 静态底板：reduce 或启动前先铺一层底色，避免白闪 */
  function paintStill() {
    var p = palette();
    ctx.fillStyle = p.bg;
    ctx.fillRect(0, 0, W, H);
    // 再撒一层很淡的固定字形，让「减少动效」的用户也看到这是个数据主题的页面
    ctx.font = (isSmall ? 12 : 14) + 'px ui-monospace, "Cascadia Code", Consolas, monospace';
    ctx.fillStyle = p.tail;
    ctx.textBaseline = 'top';
    for (var i = 0; i < cols; i++) {
      for (var j = 0; j < rows; j += 3) {
        if ((i * 7 + j * 13) % 5 !== 0) continue;
        ctx.fillText(chars[(i * 31 + j * 17) % chars.length], i * colW + 2, j * colW);
      }
    }
  }

  function boot() {
    measure();
    paintStill();
    if (!reduce) start();
  }

  boot();

  /* 窗口变化：重新测量。用 debounce，避免拖动窗口时疯狂重建 */
  var rt = 0;
  window.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () {
      stop();
      measure();
      paintStill();
      if (!reduce && !document.hidden) start();
    }, 180);
  });

  /* 切到别的标签页就停，回来再继续——后台跑动画纯属浪费电 */
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop();
    else if (!reduce) start();
  });

  /* 主题按钮切换后要换配色：清屏重来，否则旧字色的拖尾会糊很久 */
  var themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      setTimeout(function () {
        stop();
        measure();
        paintStill();
        if (!reduce) start();
      }, 60);
    });
  }
})();
