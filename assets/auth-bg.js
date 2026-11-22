/* ============ 登录 / 注册页动态背景引擎（2.6.0） ============
   十套内置动效，全部零依赖、零网络请求，纯 canvas 本地绘制。

   六条硬约束（每一套都必须遵守，不是建议）：
   1. 只是背景。绝不抢输入框的注意力，也绝不挡点击（CSS 里 pointer-events:none）。
   2. 尊重系统设置。prefers-reduced-motion: reduce 时直接画一帧静态图就停，
      不启动 rAF——无障碍优先于好看。
   3. 省电。标签页隐藏时停 rAF；手机降密度降帧；DPR 封顶 2（高分屏省一半像素）。
   4. 主题感知。亮色主题用浅底深色笔触，暗色主题用深底亮色，两边都看得清。
   5. 尺寸自适应。窗口变化 debounce 后重测，不重建事件监听。
   6. 不抛异常。任何一套里出错都不能让登录页白屏——整体包在 try 里，
      失败就退化成静态底。

   用法：AuthBg.mount(canvasEl) —— 从 data-auth-bg 读名字，找不到就按 digits 处理。
*/
(function (global) {
  'use strict';

  var TAU = Math.PI * 2;

  /* ---------- 公共工具 ---------- */

  function isDark() {
    var t = document.documentElement.getAttribute('data-theme') || 'system';
    if (t === 'dark') return true;
    if (t === 'light') return false;
    try {
      return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    } catch (e) { return false; }
  }

  /* 每套背景只要给出「笔触色」，底色统一从 CSS 变量取，保证和站点主题一致 */
  function ink(alpha) {
    var d = isDark();
    var rgb = d ? '160,200,255' : '47,111,237';
    return 'rgba(' + rgb + ',' + alpha + ')';
  }
  function accent(alpha) {
    var d = isDark();
    var rgb = d ? '120,255,214' : '21,153,132';
    return 'rgba(' + rgb + ',' + alpha + ')';
  }
  function warm(alpha) {
    var d = isDark();
    var rgb = d ? '255,196,140' : '219,120,40';
    return 'rgba(' + rgb + ',' + alpha + ')';
  }

  function rnd(a, b) { return a + Math.random() * (b - a); }

  /* ---------- 十套背景 ----------
     每套是一个函数，接收 ctx 与尺寸，返回 {draw, still}：
       draw()  —— 画一帧（会被节流调用）
       still() —— 画一帧静态的（reduce 模式用，不能有随机性带来的闪动）
  */

  /* 1. 数字雨：0-9 与 A-F 从上方落下 */
  function bgDigits(ctx, W, H, small) {
    var colW = small ? 13 : 17;
    var cols = Math.max(1, Math.floor(W / colW));
    var drops = [], speeds = [], chars = '0123456789ABCDEF'.split('');
    for (var i = 0; i < cols; i++) {
      drops.push(Math.random() * (H / colW));
      speeds.push(rnd(0.28, 0.83));
    }
    function font() { return (small ? 12 : 14) + 'px ui-monospace, "Cascadia Code", Consolas, monospace'; }
    return {
      draw: function () {
        // 拖尾：盖一层半透明底而不是整屏清空，旧字自然淡出
        ctx.globalAlpha = isDark() ? 0.16 : 0.20;
        ctx.fillStyle = isDark() ? 'rgb(10,13,18)' : 'rgb(246,247,249)';
        ctx.fillRect(0, 0, W, H);
        ctx.globalAlpha = 1;
        ctx.font = font();
        ctx.textBaseline = 'top';
        for (var i = 0; i < cols; i++) {
          var x = i * colW + 2, y = drops[i] * colW;
          for (var k = 0; k < 3; k++) {
            var yy = y - k * colW;
            if (yy < -colW || yy > H) continue;
            ctx.fillStyle = k === 0 ? ink(0.92) : ink(0.34);
            ctx.globalAlpha = k === 0 ? 1 : (k === 1 ? 0.55 : 0.25);
            ctx.fillText(chars[(Math.random() * chars.length) | 0], x, yy);
          }
          ctx.globalAlpha = 1;
          drops[i] += speeds[i];
          if (y > H && Math.random() > 0.972) {
            drops[i] = -Math.random() * 6;
            speeds[i] = rnd(0.28, 0.83);
          }
        }
      },
      still: function () {
        ctx.fillStyle = isDark() ? 'rgb(10,13,18)' : 'rgb(246,247,249)';
        ctx.fillRect(0, 0, W, H);
        ctx.font = font();
        ctx.fillStyle = ink(0.20);
        ctx.textBaseline = 'top';
        for (var i = 0; i < cols; i++) {
          for (var j = 0; j < H / colW; j += 3) {
            if ((i * 7 + j * 13) % 5 !== 0) continue;
            ctx.fillText(chars[(i * 31 + j * 17) % chars.length], i * colW + 2, j * colW);
          }
        }
      }
    };
  }

  /* 2. 星域：缓慢漂移的星点，偶尔一颗流星 */
  function bgStars(ctx, W, H, small) {
    var n = Math.round((W * H) / (small ? 9000 : 6500));
    var stars = [], meteor = null;
    for (var i = 0; i < n; i++) {
      stars.push({ x: Math.random() * W, y: Math.random() * H, r: rnd(0.5, 1.7), a: rnd(0.25, 0.9), v: rnd(0.03, 0.16) });
    }
    var t = 0;
    return {
      draw: function () {
        ctx.fillStyle = isDark() ? 'rgb(9,11,17)' : 'rgb(248,249,251)';
        ctx.fillRect(0, 0, W, H);
        t++;
        for (var i = 0; i < stars.length; i++) {
          var s = stars[i];
          s.y += s.v;
          if (s.y > H) { s.y = -2; s.x = Math.random() * W; }
          // 轻微闪烁：用正弦扰动，避免随机跳变带来的噪点感
          var tw = 0.72 + 0.28 * Math.sin((t + i * 11) * 0.03);
          ctx.fillStyle = ink(s.a * tw);
          ctx.beginPath();
          ctx.arc(s.x, s.y, s.r, 0, TAU);
          ctx.fill();
        }
        if (!meteor && Math.random() > 0.994) {
          meteor = { x: rnd(0, W * 0.8), y: rnd(-20, H * 0.3), len: rnd(60, 150), v: rnd(3.2, 5.4) };
        }
        if (meteor) {
          var g = ctx.createLinearGradient(meteor.x, meteor.y, meteor.x - meteor.len, meteor.y + meteor.len * 0.42);
          g.addColorStop(0, ink(0.75));
          g.addColorStop(1, ink(0));
          ctx.strokeStyle = g;
          ctx.lineWidth = 1.4;
          ctx.beginPath();
          ctx.moveTo(meteor.x, meteor.y);
          ctx.lineTo(meteor.x - meteor.len, meteor.y + meteor.len * 0.42);
          ctx.stroke();
          meteor.x += meteor.v * 1.7;
          meteor.y += meteor.v * 0.72;
          if (meteor.y > H + 120 || meteor.x > W + 160) meteor = null;
        }
      },
      still: function () {
        ctx.fillStyle = isDark() ? 'rgb(9,11,17)' : 'rgb(248,249,251)';
        ctx.fillRect(0, 0, W, H);
        for (var i = 0; i < stars.length; i++) {
          ctx.fillStyle = ink(stars[i].a * 0.6);
          ctx.beginPath();
          ctx.arc(stars[i].x, stars[i].y, stars[i].r, 0, TAU);
          ctx.fill();
        }
      }
    };
  }

  /* 3. 极光：青紫光带横向流动 */
  function bgAurora(ctx, W, H, small) {
    var bands = [
      { y: 0.32, amp: 0.075, sp: 0.0016, w: 0.30, c: 'accent' },
      { y: 0.10, amp: 0.05, sp: 0.0011, w: 0.18, c: 'ink' },
      { y: 0.02, amp: 0.06, sp: 0.0009, w: 0.12, c: 'warm' }
    ];
    var t = 0;
    function paint(moving) {
      ctx.fillStyle = isDark() ? 'rgb(8,12,18)' : 'rgb(247,249,252)';
      ctx.fillRect(0, 0, W, H);
      for (var b = 0; b < bands.length; b++) {
        var bd = bands[b];
        var grad = ctx.createLinearGradient(0, 0, W, 0);
        var col = bd.c === 'accent' ? accent(0.30) : (bd.c === 'warm' ? warm(0.18) : ink(0.26));
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(0.5, col);
        grad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.moveTo(0, H);
        for (var x = 0; x <= W; x += 12) {
          var p = x / W;
          var y = (bd.y + Math.sin(p * 4.4 + t * bd.sp * 12 + b) * bd.amp
            + Math.sin(p * 11 - t * bd.sp * 20) * bd.amp * 0.32) * H;
          ctx.lineTo(x, y);
        }
        ctx.lineTo(W, H);
        ctx.closePath();
        // 竖向渐隐让光带边缘柔和
        var vg = ctx.createLinearGradient(0, 0, 0, H);
        vg.addColorStop(0, 'rgba(0,0,0,0)');
        vg.addColorStop(Math.max(0.01, bd.y), col);
        vg.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = vg;
        ctx.fill();
      }
      if (moving) t++;
    }
    return {
      draw: function () { paint(true); },
      still: function () { paint(false); }
    };
  }

  /* 4. 粒子连线：距离越近连线越亮 */
  function bgNet(ctx, W, H, small) {
    var n = Math.max(18, Math.min(small ? 30 : 62, Math.round((W * H) / 22000)));
    var pts = [], maxD = small ? 96 : 128;
    for (var i = 0; i < n; i++) {
      pts.push({ x: Math.random() * W, y: Math.random() * H, vx: rnd(-0.22, 0.22), vy: rnd(-0.22, 0.22) });
    }
    function paint(moving) {
      ctx.fillStyle = isDark() ? 'rgb(9,12,18)' : 'rgb(248,249,251)';
      ctx.fillRect(0, 0, W, H);
      var i, j, p, q, dx, dy, d;
      for (i = 0; i < pts.length; i++) {
        p = pts[i];
        if (moving) {
          p.x += p.vx; p.y += p.vy;
          if (p.x < 0 || p.x > W) p.vx *= -1;
          if (p.y < 0 || p.y > H) p.vy *= -1;
        }
      }
      // 连线先画，点盖在上面
      for (i = 0; i < pts.length; i++) {
        for (j = i + 1; j < pts.length; j++) {
          p = pts[i]; q = pts[j];
          dx = p.x - q.x; dy = p.y - q.y;
          d = Math.sqrt(dx * dx + dy * dy);
          if (d > maxD) continue;
          ctx.strokeStyle = ink(0.30 * (1 - d / maxD));
          ctx.lineWidth = 0.7;
          ctx.beginPath();
          ctx.moveTo(p.x, p.y);
          ctx.lineTo(q.x, q.y);
          ctx.stroke();
        }
      }
      for (i = 0; i < pts.length; i++) {
        p = pts[i];
        ctx.fillStyle = ink(0.62);
        ctx.beginPath();
        ctx.arc(p.x, p.y, 1.5, 0, TAU);
        ctx.fill();
      }
    }
    return {
      draw: function () { paint(true); },
      still: function () { paint(false); }
    };
  }

  /* 5. 波纹：从中心向外扩散的同心圆 */
  function bgRipple(ctx, W, H, small) {
    var cx = W * 0.5, cy = H * 0.46;
    var maxR = Math.sqrt(W * W + H * H) * 0.55;
    var rings = [], t = 0;
    var gap = small ? 78 : 96;
    for (var i = 0; i < 14; i++) rings.push(i * gap);
    function paint(moving) {
      ctx.fillStyle = isDark() ? 'rgb(9,12,18)' : 'rgb(248,249,251)';
      ctx.fillRect(0, 0, W, H);
      for (var i = 0; i < rings.length; i++) {
        var r = rings[i];
        if (r <= 0 || r > maxR) continue;
        var a = 0.26 * (1 - r / maxR);
        ctx.strokeStyle = i % 3 === 0 ? accent(a) : ink(a);
        ctx.lineWidth = 1.1;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, TAU);
        ctx.stroke();
        if (moving) rings[i] += 0.55;
      }
      if (moving) {
        t++;
        for (var k = 0; k < rings.length; k++) {
          if (rings[k] > maxR) rings[k] = 0;
        }
      }
    }
    return {
      draw: function () { paint(true); },
      still: function () { paint(false); }
    };
  }

  /* 6. 矩阵网格：透视网格向前推进 + 扫描线 */
  function bgGrid(ctx, W, H, small) {
    var horizon = H * 0.42, t = 0;
    var lineGap = small ? 34 : 44;
    function paint(moving) {
      ctx.fillStyle = isDark() ? 'rgb(8,10,16)' : 'rgb(248,249,252)';
      ctx.fillRect(0, 0, W, H);
      ctx.strokeStyle = ink(0.16);
      ctx.lineWidth = 1;
      // 纵向：从消失点向两侧发散的线
      var spread = W * 1.9;
      for (var i = -6; i <= 6; i++) {
        var bx = W / 2 + (i / 6) * spread;
        ctx.beginPath();
        ctx.moveTo(W / 2, horizon);
        ctx.lineTo(bx, H);
        ctx.stroke();
      }
      // 横向：越往下间隔越大，靠 t 平移制造向前推进的感觉
      var off = moving ? (t * 0.9) % lineGap : 0;
      for (var k = 0; k < 26; k++) {
        var f = (k * lineGap + off) / (H - horizon);
        var y = horizon + f * (H - horizon) * 0.5;
        if (y > H) break;
        var depth = (y - horizon) / (H - horizon);
        ctx.strokeStyle = ink(0.10 + depth * 0.22);
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(W, y);
        ctx.stroke();
      }
      // 地平线高光
      var g = ctx.createLinearGradient(0, horizon - 2, 0, horizon + 2);
      g.addColorStop(0, 'rgba(0,0,0,0)');
      g.addColorStop(0.5, accent(0.55));
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g;
      ctx.fillRect(0, horizon - 2, W, 4);
      // 扫描线缓慢上下移动
      if (moving) {
        var sy = horizon + ((t * 0.7) % (H - horizon));
        var sg = ctx.createLinearGradient(0, sy - 24, 0, sy + 24);
        sg.addColorStop(0, 'rgba(0,0,0,0)');
        sg.addColorStop(0.5, accent(0.12));
        sg.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = sg;
        ctx.fillRect(0, sy - 24, W, 48);
      }
      if (moving) t++;
    }
    return {
      draw: function () { paint(true); },
      still: function () { paint(false); }
    };
  }

  /* 7. 渐变流光：大面积色块缓慢呼吸（最省电的一套） */
  function bgGradient(ctx, W, H, small) {
    var blobs = [
      { x: 0.22, y: 0.28, r: 0.55, c: 'ink', sp: 0.0009, ph: 0 },
      { x: 0.82, y: 0.34, r: 0.48, c: 'accent', sp: 0.0012, ph: 2.1 },
      { x: 0.56, y: 0.86, r: 0.52, c: 'warm', sp: 0.0007, ph: 4.2 }
    ];
    var t = 0;
    function paint(moving) {
      ctx.fillStyle = isDark() ? 'rgb(9,11,17)' : 'rgb(250,250,252)';
      ctx.fillRect(0, 0, W, H);
      var R = Math.max(W, H);
      for (var i = 0; i < blobs.length; i++) {
        var b = blobs[i];
        var phase = t * b.sp * 60 + b.ph;
        var x = (b.x + Math.sin(phase) * 0.07) * W;
        var y = (b.y + Math.cos(phase * 0.8) * 0.06) * H;
        var rad = b.r * R * (0.92 + 0.10 * Math.sin(phase * 1.3));
        var col = b.c === 'accent' ? accent(0.24) : (b.c === 'warm' ? warm(0.16) : ink(0.22));
        var g = ctx.createRadialGradient(x, y, 0, x, y, rad);
        g.addColorStop(0, col);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, W, H);
      }
      if (moving) t++;
    }
    return { draw: function () { paint(true); }, still: function () { paint(false); } };
  }

  /* 8. 几何漂浮：方块与三角缓慢旋转 */
  function bgShapes(ctx, W, H, small) {
    var n = small ? 9 : 15;
    var arr = [];
    for (var i = 0; i < n; i++) {
      arr.push({
        x: Math.random() * W, y: Math.random() * H,
        s: rnd(14, small ? 40 : 58), a: Math.random() * TAU,
        va: rnd(-0.006, 0.006), vx: rnd(-0.16, 0.16), vy: rnd(-0.13, 0.13),
        tri: Math.random() > 0.5, al: rnd(0.10, 0.26), warm: Math.random() > 0.72
      });
    }
    function paint(moving) {
      ctx.fillStyle = isDark() ? 'rgb(9,12,18)' : 'rgb(249,250,252)';
      ctx.fillRect(0, 0, W, H);
      for (var i = 0; i < arr.length; i++) {
        var o = arr[i];
        if (moving) {
          o.x += o.vx; o.y += o.vy; o.a += o.va;
          if (o.x < -80) o.x = W + 60;
          if (o.x > W + 80) o.x = -60;
          if (o.y < -80) o.y = H + 60;
          if (o.y > H + 80) o.y = -60;
        }
        ctx.save();
        ctx.translate(o.x, o.y);
        ctx.rotate(o.a);
        ctx.strokeStyle = o.warm ? warm(o.al * 2.2) : ink(o.al * 2.2);
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        if (o.tri) {
          for (var k = 0; k < 3; k++) {
            var ang = (k / 3) * TAU - Math.PI / 2;
            var px = Math.cos(ang) * o.s, py = Math.sin(ang) * o.s;
            if (k === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
          }
          ctx.closePath();
        } else {
          ctx.rect(-o.s / 2, -o.s / 2, o.s, o.s);
        }
        ctx.stroke();
        ctx.restore();
      }
    }
    return { draw: function () { paint(true); }, still: function () { paint(false); } };
  }

  /* 9. 代码流：纵向滚动的字符流，密度比数字雨低 */
  function bgStream(ctx, W, H, small) {
    var colW = small ? 30 : 40;
    var cols = Math.max(1, Math.floor(W / colW));
    var lanes = [];
    var words = '{}[]();=>const await return async data query index'.split(' ');
    for (var i = 0; i < cols; i++) {
      lanes.push({ y: Math.random() * H, v: rnd(0.35, 0.95), ch: words[(Math.random() * words.length) | 0] });
    }
    function font() { return (small ? 10 : 11) + 'px ui-monospace, "Cascadia Code", Consolas, monospace'; }
    return {
      draw: function () {
        ctx.globalAlpha = 0.18;
        ctx.fillStyle = isDark() ? 'rgb(8,11,16)' : 'rgb(248,249,251)';
        ctx.fillRect(0, 0, W, H);
        ctx.globalAlpha = 1;
        ctx.font = font();
        ctx.textBaseline = 'top';
        for (var i = 0; i < cols; i++) {
          var l = lanes[i];
          var x = i * colW + 4;
          ctx.fillStyle = ink(0.55);
          ctx.fillText(l.ch, x, l.y);
          // 每个字下面拖一小段渐隐的尾巴
          for (var k = 1; k <= 3; k++) {
            ctx.fillStyle = ink(0.18 / k);
            ctx.fillText(words[(i * 7 + k * 3) % words.length], x, l.y - k * (small ? 13 : 14));
          }
          l.y += l.v;
          if (l.y > H + 20) {
            l.y = -20;
            l.ch = words[(Math.random() * words.length) | 0];
            l.v = rnd(0.35, 0.95);
          }
        }
      },
      still: function () {
        ctx.fillStyle = isDark() ? 'rgb(8,11,16)' : 'rgb(248,249,251)';
        ctx.fillRect(0, 0, W, H);
        ctx.font = font();
        ctx.fillStyle = ink(0.16);
        ctx.textBaseline = 'top';
        for (var i = 0; i < cols; i++) {
          for (var y = 0; y < H; y += (small ? 40 : 46)) {
            if ((i * 5 + y) % 7 === 0) continue;
            ctx.fillText(words[(i + y) % words.length], i * colW + 4, y);
          }
        }
      }
    };
  }

  /* 10. 光斑呼吸：几团模糊光斑明暗起伏 */
  function bgOrbs(ctx, W, H, small) {
    var n = small ? 4 : 6;
    var orbs = [];
    for (var i = 0; i < n; i++) {
      orbs.push({
        x: Math.random(), y: Math.random(),
        r: rnd(0.14, 0.30), sp: rnd(0.0016, 0.0042), ph: Math.random() * TAU,
        warm: Math.random() > 0.62
      });
    }
    var t = 0;
    function paint(moving) {
      ctx.fillStyle = isDark() ? 'rgb(9,11,17)' : 'rgb(249,250,252)';
      ctx.fillRect(0, 0, W, H);
      var R = Math.max(W, H);
      for (var i = 0; i < orbs.length; i++) {
        var o = orbs[i];
        var pulse = 0.55 + 0.45 * Math.sin(t * o.sp * 60 + o.ph);
        var x = (o.x + Math.sin(t * 0.006 + o.ph) * 0.05) * W;
        var y = (o.y + Math.cos(t * 0.005 + o.ph) * 0.05) * H;
        var rad = o.r * R * (0.75 + 0.35 * pulse);
        var a = (isDark() ? 0.20 : 0.15) * (0.45 + 0.55 * pulse);
        var col = o.warm ? warm(a) : accent(a);
        var g = ctx.createRadialGradient(x, y, 0, x, y, rad);
        g.addColorStop(0, col);
        g.addColorStop(0.55, o.warm ? warm(a * 0.35) : accent(a * 0.35));
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(x, y, rad, 0, TAU);
        ctx.fill();
      }
      if (moving) t++;
    }
    return { draw: function () { paint(true); }, still: function () { paint(false); } };
  }

  var FACTORY = {
    digits: bgDigits, stars: bgStars, aurora: bgAurora, net: bgNet,
    ripple: bgRipple, grid: bgGrid, gradient: bgGradient, shapes: bgShapes,
    stream: bgStream, orbs: bgOrbs
  };

  /* ---------- 生命周期 ---------- */

  var running = [], reduce = false;

  function setup(canvas) {
    if (!canvas || !canvas.getContext) return null;
    var name = canvas.getAttribute('data-auth-bg') || 'digits';
    var make = FACTORY[name] || FACTORY.digits;

    var ctx = canvas.getContext('2d');
    var W = 0, H = 0, DPR = 1, small = false;
    var scene = null, rafId = 0, lastTs = 0, fps = 24, live = false;
    var rt = 0;
    // 初始是否激活：登录/注册两个背景都在 DOM 里，但只有当前 tab 那个跑动画，
    // 另一个只画一帧静态图待命。切换 tab 时再 start()，避免两套同时烧 CPU。
    var active = canvas.getAttribute('data-active') !== '0';

    function measure() {
      DPR = Math.min(window.devicePixelRatio || 1, 2);  // 高分屏封顶 2，省一半像素
      W = canvas.clientWidth || window.innerWidth;
      H = canvas.clientHeight || window.innerHeight;
      canvas.width = Math.max(1, Math.round(W * DPR));
      canvas.height = Math.max(1, Math.round(H * DPR));
      ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
      small = W < 760;
      fps = small ? 15 : 24;          // 这是背景不是游戏，不需要 60fps
      scene = make(ctx, W, H, small);
    }

    function loop(ts) {
      if (!live) return;
      var budget = 1000 / fps;
      if (!lastTs || ts - lastTs >= budget) {
        lastTs = ts;
        try { scene.draw(); } catch (e) { live = false; return; }
      }
      rafId = requestAnimationFrame(loop);
    }

    function start() {
      if (live || reduce || !active) return;
      live = true;
      lastTs = 0;
      rafId = requestAnimationFrame(loop);
    }

    function stop() {
      live = false;
      if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
    }

    function repaint() {
      stop();
      measure();
      if (reduce || !active) { try { scene.still(); } catch (e) {} }
      else if (!document.hidden) start();
    }

    /**
     * 切换 tab 时调用：false 停掉并留一帧静态，true 续跑。
     *
     * 必须把 data-active 属性一起写回去：这个属性不是装饰，它是「谁该跑」的唯一
     * 可见真源——CSS 靠它降亮度、测试靠它断言、下次 resize 重建画布时也靠它决定
     * 起不起。只在内存里改 active 变量，属性就会永远停在初始值上（实测切到注册后
     * login 层还挂着 data-active="1"，register 层是 "0"，看起来像没切成功）。
     */
    function setActive(on) {
      var was = active;
      active = !!on;
      canvas.setAttribute('data-active', active ? '1' : '0');
      if (!active) {
        stop();
        try { scene.still(); } catch (e) {}
        return;
      }
      // 亮起来时重新量一次：这一层之前是 hidden 的，量出来 clientWidth 是 0，
      // 只能退回视口尺寸。现在它真的占位了，按真实尺寸重建场景才不会被拉伸。
      if (!was) { repaint(); return; }
      if (!reduce && !document.hidden) { start(); }
    }

    measure();
    if (reduce || !active) { try { scene.still(); } catch (e) {} }
    else start();

    window.addEventListener('resize', function () {
      clearTimeout(rt);
      rt = setTimeout(repaint, 180);
    });

    // 切到别的标签页就停，回来再续——后台跑动画纯属浪费电
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop();
      else if (!reduce && active) start();
    });

    return { repaint: repaint, setActive: setActive, canvas: canvas };
  }

  function mount(canvas) {
    var h = setup(canvas);
    if (h) running.push(h);
    return h;
  }

  function repaintAll() {
    for (var i = 0; i < running.length; i++) {
      try { running[i].repaint(); } catch (e) {}
    }
  }

  try {
    reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  } catch (e) {}

  global.AuthBg = {
    mount: mount,
    repaintAll: repaintAll,
    /** 只让某一个 canvas 跑（登录/注册切 tab 用），选中即激活其余停掉 */
    activate: function (canvas) {
      for (var i = 0; i < running.length; i++) {
        var h = running[i];
        if (!h.canvas) continue;
        try { h.setActive(h.canvas === canvas); } catch (e) {}
      }
    },
    presets: function () { return Object.keys(FACTORY); }
  };

  function boot() {
    var list = document.querySelectorAll('canvas[data-auth-bg]');
    for (var i = 0; i < list.length; i++) mount(list[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // 主题切换后整套换配色：清屏重画，否则旧笔触色的拖尾会糊很久
  var themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      setTimeout(repaintAll, 60);
    });
  }
})(window);
