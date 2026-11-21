/*!
 * qr.js —— 极简二维码生成（字节模式 / 纠错等级 M / 自动版本 1-20）
 *
 * 只服务于一件事：支付弹窗里把「扫码付」的二维码画出来，不依赖任何外部库或图片接口
 * （订单链接是私密信息，不能发给第三方二维码服务）。
 *
 * 算法按 ISO/IEC 18004：数据编码 → RS 纠错 → 块交织 → 矩阵布置 → 掩码 → 格式/版本信息。
 * 对外只暴露 KBQR.render(el, text, opts) 和 KBQR.encode(text)。
 */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.KBQR = api;
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /* ---------------- 码表（纠错等级 M，版本 1-20） ---------------- */

  // [块数, 每块数据码字数]，版本有多组时按顺序排列
  var BLOCKS_M = [
    null,
    [[1, 16]],
    [[1, 28]],
    [[1, 44]],
    [[2, 32]],
    [[2, 43]],
    [[4, 27]],
    [[4, 31]],
    [[2, 38], [2, 39]],
    [[3, 36], [2, 37]],
    [[4, 43], [1, 44]],
    [[1, 50], [4, 51]],
    [[6, 36], [2, 37]],
    [[8, 37], [1, 38]],
    [[4, 40], [5, 41]],
    [[5, 41], [5, 42]],
    [[7, 45], [3, 46]],
    [[10, 46], [1, 47]],
    [[9, 43], [4, 44]],
    [[3, 44], [11, 45]],
    [[3, 41], [13, 42]]
  ];

  // 每块纠错码字数
  var ECC_M = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];

  // 校正图案中心坐标
  var ALIGN = [
    [],
    [],
    [6, 18],
    [6, 22],
    [6, 26],
    [6, 30],
    [6, 34],
    [6, 22, 38],
    [6, 24, 42],
    [6, 26, 46],
    [6, 28, 50],
    [6, 30, 54],
    [6, 32, 58],
    [6, 34, 62],
    [6, 26, 46, 66],
    [6, 26, 48, 70],
    [6, 26, 50, 74],
    [6, 30, 54, 78],
    [6, 30, 56, 82],
    [6, 30, 58, 86],
    [6, 34, 62, 90]
  ];

  /* ---------------- GF(256) ---------------- */

  var EXP = new Array(512);
  var LOG = new Array(256);
  (function () {
    var x = 1;
    for (var i = 0; i < 255; i++) {
      EXP[i] = x;
      LOG[x] = i;
      x <<= 1;
      if (x & 0x100) {
        x ^= 0x11d;
      }
    }
    for (var j = 255; j < 512; j++) {
      EXP[j] = EXP[j - 255];
    }
  })();

  function gmul(a, b) {
    return a === 0 || b === 0 ? 0 : EXP[LOG[a] + LOG[b]];
  }

  // 生成多项式（x - a^0)(x - a^1)...(x - a^(deg-1)）
  function rsDivisor(deg) {
    var out = new Array(deg).fill(0);
    out[deg - 1] = 1;
    var root = 1;
    for (var i = 0; i < deg; i++) {
      for (var j = 0; j < out.length; j++) {
        out[j] = gmul(out[j], root);
        if (j + 1 < out.length) {
          out[j] ^= out[j + 1];
        }
      }
      root = gmul(root, 0x02);
    }
    return out;
  }

  function rsRemainder(data, divisor) {
    var res = new Array(divisor.length).fill(0);
    for (var i = 0; i < data.length; i++) {
      var factor = data[i] ^ res.shift();
      res.push(0);
      for (var j = 0; j < divisor.length; j++) {
        res[j] ^= gmul(divisor[j], factor);
      }
    }
    return res;
  }

  /* ---------------- BCH（格式信息 / 版本信息） ---------------- */

  function polyMod(value, poly, deg) {
    var v = value << deg;
    for (var i = 31 - Math.clz32(v); i >= deg; i--) {
      if ((v >>> i) & 1) {
        v ^= poly << (i - deg);
      }
    }
    return v >>> 0;
  }

  /* ---------------- 编码 ---------------- */

  function utf8Bytes(str) {
    if (typeof TextEncoder !== 'undefined') {
      var enc = new TextEncoder().encode(str);
      var out = new Array(enc.length);
      for (var i = 0; i < enc.length; i++) {
        out[i] = enc[i];
      }
      return out;
    }
    var s = unescape(encodeURIComponent(str));
    var arr = [];
    for (var k = 0; k < s.length; k++) {
      arr.push(s.charCodeAt(k));
    }
    return arr;
  }

  function dataCodewords(version) {
    var total = 0;
    var groups = BLOCKS_M[version];
    for (var i = 0; i < groups.length; i++) {
      total += groups[i][0] * groups[i][1];
    }
    return total;
  }

  function pushBits(bits, value, len) {
    for (var i = len - 1; i >= 0; i--) {
      bits.push((value >>> i) & 1);
    }
  }

  // 数据码字（含模式、长度、终止符、填充）
  function encodeData(bytes) {
    var version = 0;
    for (var v = 1; v <= 20; v++) {
      var cap = dataCodewords(v) * 8;
      var need = 4 + (v < 10 ? 8 : 16) + bytes.length * 8;
      if (need <= cap) {
        version = v;
        break;
      }
    }
    if (version === 0) {
      throw new Error('内容太长，超出二维码容量');
    }

    var bits = [];
    pushBits(bits, 0x4, 4); // 字节模式
    pushBits(bits, bytes.length, version < 10 ? 8 : 16);
    for (var i = 0; i < bytes.length; i++) {
      pushBits(bits, bytes[i], 8);
    }

    var capBits = dataCodewords(version) * 8;
    pushBits(bits, 0, Math.min(4, capBits - bits.length)); // 终止符
    pushBits(bits, 0, (8 - (bits.length % 8)) % 8);        // 对齐到字节

    var pads = [0xec, 0x11];
    var p = 0;
    while (bits.length < capBits) {
      pushBits(bits, pads[p++ & 1], 8);
    }

    var codewords = [];
    for (var b = 0; b < bits.length; b += 8) {
      var byte = 0;
      for (var j = 0; j < 8; j++) {
        byte = (byte << 1) | bits[b + j];
      }
      codewords.push(byte);
    }
    return { version: version, data: codewords };
  }

  // 分块 + 纠错 + 交织
  function interleave(version, data) {
    var groups = BLOCKS_M[version];
    var ecLen = ECC_M[version];
    var divisor = rsDivisor(ecLen);
    var blocks = [];
    var offset = 0;

    for (var g = 0; g < groups.length; g++) {
      var count = groups[g][0];
      var len = groups[g][1];
      for (var n = 0; n < count; n++) {
        var chunk = data.slice(offset, offset + len);
        offset += len;
        blocks.push({ data: chunk, ec: rsRemainder(chunk, divisor) });
      }
    }

    var maxData = 0;
    for (var i = 0; i < blocks.length; i++) {
      maxData = Math.max(maxData, blocks[i].data.length);
    }

    var out = [];
    for (var c = 0; c < maxData; c++) {
      for (var b = 0; b < blocks.length; b++) {
        if (c < blocks[b].data.length) {
          out.push(blocks[b].data[c]);
        }
      }
    }
    for (var e = 0; e < ecLen; e++) {
      for (var k = 0; k < blocks.length; k++) {
        out.push(blocks[k].ec[e]);
      }
    }
    return out;
  }

  /* ---------------- 矩阵 ---------------- */

  function maskAt(mask, x, y) {
    switch (mask) {
      case 0: return (x + y) % 2 === 0;
      case 1: return y % 2 === 0;
      case 2: return x % 3 === 0;
      case 3: return (x + y) % 3 === 0;
      case 4: return (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0;
      case 5: return ((x * y) % 2) + ((x * y) % 3) === 0;
      case 6: return (((x * y) % 2) + ((x * y) % 3)) % 2 === 0;
      case 7: return (((x + y) % 2) + ((x * y) % 3)) % 2 === 0;
      default: return false;
    }
  }

  function buildMatrix(version, codewords, mask) {
    var size = version * 4 + 17;
    var mod = [];
    var fn = [];
    for (var i = 0; i < size; i++) {
      mod.push(new Array(size).fill(false));
      fn.push(new Array(size).fill(false));
    }

    function set(x, y, dark) {
      mod[y][x] = !!dark;
      fn[y][x] = true;
    }

    // 时序图案先画，随后定位图案会覆盖掉穿过去的那几格（顺序不能反）
    for (var t = 0; t < size; t++) {
      set(6, t, t % 2 === 0);
      set(t, 6, t % 2 === 0);
    }

    // 定位图案（含分隔符）
    function finder(cx, cy) {
      for (var dy = -4; dy <= 4; dy++) {
        for (var dx = -4; dx <= 4; dx++) {
          var x = cx + dx;
          var y = cy + dy;
          if (x < 0 || x >= size || y < 0 || y >= size) {
            continue;
          }
          var dist = Math.max(Math.abs(dx), Math.abs(dy));
          set(x, y, dist !== 2 && dist !== 4);
        }
      }
    }
    finder(3, 3);
    finder(size - 4, 3);
    finder(3, size - 4);

    // 校正图案
    var pos = ALIGN[version];
    for (var a = 0; a < pos.length; a++) {
      for (var b = 0; b < pos.length; b++) {
        var ax = pos[a];
        var ay = pos[b];
        if ((ax === 6 && ay === 6) || (ax === 6 && ay === size - 7) || (ax === size - 7 && ay === 6)) {
          continue;
        }
        for (var dy2 = -2; dy2 <= 2; dy2++) {
          for (var dx2 = -2; dx2 <= 2; dx2++) {
            set(ax + dx2, ay + dy2, Math.max(Math.abs(dx2), Math.abs(dy2)) !== 1);
          }
        }
      }
    }

    // 格式信息（ECC M = 0b00，与掩码一起构成 5 位数据）
    var fmt = ((mask << 10) | polyMod(mask, 0x537, 10)) ^ 0x5412;
    for (var f = 0; f <= 5; f++) {
      set(8, f, ((fmt >>> f) & 1) === 1);
    }
    set(8, 7, ((fmt >>> 6) & 1) === 1);
    set(8, 8, ((fmt >>> 7) & 1) === 1);
    set(7, 8, ((fmt >>> 8) & 1) === 1);
    for (var f2 = 9; f2 < 15; f2++) {
      set(14 - f2, 8, ((fmt >>> f2) & 1) === 1);
    }
    for (var f3 = 0; f3 < 8; f3++) {
      set(size - 1 - f3, 8, ((fmt >>> f3) & 1) === 1);
    }
    for (var f4 = 8; f4 < 15; f4++) {
      set(8, size - 15 + f4, ((fmt >>> f4) & 1) === 1);
    }
    set(8, size - 8, true); // 固定黑点

    // 版本信息（版本 7 起）
    if (version >= 7) {
      var vin = (version << 12) | polyMod(version, 0x1f25, 12);
      for (var vv = 0; vv < 18; vv++) {
        var bit = ((vin >>> vv) & 1) === 1;
        var p1 = size - 11 + (vv % 3);
        var p2 = Math.floor(vv / 3);
        set(p1, p2, bit);
        set(p2, p1, bit);
      }
    }

    // 数据位（右下起，两列一组蛇形）
    var totalBits = codewords.length * 8;
    var idx = 0;
    for (var right = size - 1; right >= 1; right -= 2) {
      if (right === 6) {
        right = 5;
      }
      for (var vert = 0; vert < size; vert++) {
        for (var j = 0; j < 2; j++) {
          var x2 = right - j;
          var upward = ((right + 1) & 2) === 0;
          var y2 = upward ? size - 1 - vert : vert;
          if (fn[y2][x2]) {
            continue;
          }
          if (idx < totalBits) {
            var byteIdx = idx >> 3;
            mod[y2][x2] = ((codewords[byteIdx] >>> (7 - (idx & 7))) & 1) === 1;
            idx++;
          } else {
            mod[y2][x2] = false;
          }
        }
      }
    }

    // 掩码
    for (var y3 = 0; y3 < size; y3++) {
      for (var x3 = 0; x3 < size; x3++) {
        if (!fn[y3][x3] && maskAt(mask, x3, y3)) {
          mod[y3][x3] = !mod[y3][x3];
        }
      }
    }

    return { size: size, modules: mod };
  }

  /* ---------------- 掩码评分（只影响观感，选偏了也能扫） ---------------- */

  function penalty(m) {
    var size = m.length;
    var score = 0;
    var x, y, run, darkCount;

    // 规则 1：同色连续 5 个以上
    for (y = 0; y < size; y++) {
      run = 1;
      for (x = 1; x < size; x++) {
        if (m[y][x] === m[y][x - 1]) {
          run++;
        } else {
          if (run >= 5) {
            score += 3 + (run - 5);
          }
          run = 1;
        }
      }
      if (run >= 5) {
        score += 3 + (run - 5);
      }
    }
    for (x = 0; x < size; x++) {
      run = 1;
      for (y = 1; y < size; y++) {
        if (m[y][x] === m[y - 1][x]) {
          run++;
        } else {
          if (run >= 5) {
            score += 3 + (run - 5);
          }
          run = 1;
        }
      }
      if (run >= 5) {
        score += 3 + (run - 5);
      }
    }

    // 规则 2：2x2 同色块
    for (y = 0; y < size - 1; y++) {
      for (x = 0; x < size - 1; x++) {
        var v = m[y][x];
        if (v === m[y][x + 1] && v === m[y + 1][x] && v === m[y + 1][x + 1]) {
          score += 3;
        }
      }
    }

    // 规则 4：黑白比例偏离 50%
    darkCount = 0;
    for (y = 0; y < size; y++) {
      for (x = 0; x < size; x++) {
        if (m[y][x]) {
          darkCount++;
        }
      }
    }
    var ratio = (darkCount * 100) / (size * size);
    score += Math.floor(Math.abs(ratio - 50) / 5) * 10;
    return score;
  }

  /* ---------------- 对外 ---------------- */

  function encode(text, forcedMask) {
    var bytes = utf8Bytes(String(text));
    var packed = encodeData(bytes);
    var cw = interleave(packed.version, packed.data);
    var best = null;
    var bestScore = Infinity;
    var masks = typeof forcedMask === 'number' ? [forcedMask] : [0, 1, 2, 3, 4, 5, 6, 7];

    for (var i = 0; i < masks.length; i++) {
      var qr = buildMatrix(packed.version, cw, masks[i]);
      var s = penalty(qr.modules);
      if (s < bestScore) {
        bestScore = s;
        best = qr;
        best.mask = masks[i];
      }
    }
    best.version = packed.version;
    if (typeof forcedMask === 'string' && forcedMask === 'debug') {
      best.codewords = cw;
      best.data = packed.data;
    }
    return best;
  }

  /**
   * 把二维码画进容器（替换原有内容）。
   * opts.quiet 静区模块数（默认 2）、opts.px 视觉边长（默认 100%，用 CSS 控）
   */
  function render(el, text, opts) {
    opts = opts || {};
    var qr = encode(text, typeof opts.mask === 'number' ? opts.mask : undefined);
    var quiet = typeof opts.quiet === 'number' ? opts.quiet : 2;
    var span = qr.size + quiet * 2;
    var path = [];
    for (var y = 0; y < qr.size; y++) {
      for (var x = 0; x < qr.size; x++) {
        if (qr.modules[y][x]) {
          path.push('M' + (x + quiet) + ' ' + (y + quiet) + 'h1v1h-1z');
        }
      }
    }
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + span + ' ' + span +
      '" shape-rendering="crispEdges" role="img" aria-label="支付二维码">' +
      '<rect width="' + span + '" height="' + span + '" fill="#fff"/>' +
      '<path d="' + path.join('') + '" fill="#000"/>' +
      '</svg>';
    if (el) {
      el.innerHTML = svg;
      return svg;
    }
    return svg;
  }

  return { encode: encode, render: render };
});
