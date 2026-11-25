(function () {
  'use strict';

  var MODES = ['system', 'light', 'dark'];
  var LABEL = { system: '跟随系统', light: '亮色', dark: '暗色' };
  var ICON = { system: '◐', light: '☀', dark: '☾' };

  function current() {
    var t = document.documentElement.getAttribute('data-theme') || 'system';
    return MODES.indexOf(t) >= 0 ? t : 'system';
  }

  function apply(mode) {
    document.documentElement.setAttribute('data-theme', mode);
    try { localStorage.setItem('kb-theme', mode); } catch (e) {}
    var l = document.getElementById('themeLabel');
    var i = document.getElementById('themeIco');
    if (l) l.textContent = LABEL[mode];
    if (i) i.textContent = ICON[mode];
  }

  var btn = document.getElementById('themeBtn');
  if (btn) {
    var lbl = document.getElementById('themeLabel');
    var ico = document.getElementById('themeIco');
    if (lbl) lbl.textContent = LABEL[current()];
    if (ico) ico.textContent = ICON[current()];
    btn.addEventListener('click', function () {
      var next = MODES[(MODES.indexOf(current()) + 1) % MODES.length];
      apply(next);
    });
  }

  // 移动端菜单
  var menuBtn = document.getElementById('menuBtn');
  var sidebar = document.getElementById('sidebar');
  if (menuBtn && sidebar) {
    menuBtn.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
    document.addEventListener('click', function (ev) {
      if (window.innerWidth > 900) return;
      if (!sidebar.classList.contains('open')) return;
      if (sidebar.contains(ev.target) || menuBtn.contains(ev.target)) return;
      sidebar.classList.remove('open');
    });
  }

  // 复制新 Key
  var copyBtn = document.getElementById('copyKey');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var el = document.getElementById('newKeyText');
      if (!el) return;
      var text = el.textContent || '';
      var done = function () {
        var old = copyBtn.textContent;
        copyBtn.textContent = '已复制';
        setTimeout(function () { copyBtn.textContent = old; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        done();
      }
    });
  }

  // 已创建的 Key：默认脱敏，点「显示」才取明文，点「复制」直接写剪贴板
  var keyCache = {};

  function fetchKey(url) {
    if (keyCache[url]) return Promise.resolve(keyCache[url]);
    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return null; });
    }).then(function (d) {
      if (!d || !d.ok) {
        throw new Error((d && d.error && d.error.message) || '读取失败，请刷新后重试');
      }
      keyCache[url] = d.data.key;
      return d.data.key;
    });
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.top = '-999px';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      resolve();
    });
  }

  function flashLabel(btn, text) {
    if (!btn.getAttribute('data-label')) {
      btn.setAttribute('data-label', btn.textContent);
    }
    btn.textContent = text;
    setTimeout(function () { btn.textContent = btn.getAttribute('data-label'); }, 1500);
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var btn = t.closest('[data-key-act]');
    if (!btn) return;
    var cell = btn.closest('[data-key-id]');
    if (!cell) return;

    var code = cell.querySelector('.key-text');
    var url = btn.getAttribute('data-url');
    var act = btn.getAttribute('data-key-act');
    var revealed = cell.getAttribute('data-revealed') === '1';

    if (act === 'reveal') {
      if (revealed) {
        code.textContent = cell.getAttribute('data-mask');
        cell.setAttribute('data-revealed', '0');
        btn.textContent = '显示';
        return;
      }
      fetchKey(url).then(function (k) {
        code.textContent = k;
        cell.setAttribute('data-revealed', '1');
        btn.textContent = '隐藏';
      }).catch(function (e) { alert(e.message); });
      return;
    }

    if (act === 'copy') {
      fetchKey(url).then(function (k) {
        return copyText(k);
      }).then(function () {
        flashLabel(btn, '已复制');
      }).catch(function (e) { alert(e.message); });
      return;
    }

    // 复制「带 Key 的 MCP 地址」：ChatGPT 连接器只认这种形式
    if (act === 'copymcp') {
      var mcp = cell.getAttribute('data-mcp') || '';
      fetchKey(url).then(function (k) {
        return copyText(mcp + '?key=' + k);
      }).then(function () {
        flashLabel(btn, '已复制');
      }).catch(function (e) { alert(e.message); });
      return;
    }

    // 复制「接入配置」：两行环境变量，直接贴进 Agent
    if (act === 'copyenv') {
      var base = cell.getAttribute('data-base') || '';
      fetchKey(url).then(function (k) {
        return copyText('KB_URL=' + base + '\nKB_KEY=' + k);
      }).then(function () {
        flashLabel(btn, '已复制两行');
      }).catch(function (e) { alert(e.message); });
    }
  });

  // 通用复制按钮：[data-copy]
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var btn = t.closest('[data-copy]');
    if (!btn) return;
    copyText(btn.getAttribute('data-copy')).then(function () {
      flashLabel(btn, '已复制');
    });
  });

  // 接口连通自检：请求一次 /v1/health
  var checkBtn = document.getElementById('apiCheck');
  if (checkBtn) {
    checkBtn.addEventListener('click', function () {
      var out = document.getElementById('apiCheckOut');
      var base = checkBtn.getAttribute('data-base') || '';
      checkBtn.disabled = true;
      if (out) {
        out.className = 'ep-check muted';
        out.textContent = '正在请求…';
      }
      fetch(base + '/api.php?path=/v1/health', { credentials: 'same-origin' })
        .then(function (r) {
          return r.text().then(function (txt) { return { status: r.status, txt: txt }; });
        })
        .then(function (res) {
          var ok = false;
          var ver = '';
          try {
            var d = JSON.parse(res.txt);
            ok = d.ok === true;
            ver = (d.data && d.data.version) || '';
          } catch (e) {}
          if (!out) return;
          if (ok) {
            out.className = 'ep-check ok';
            out.textContent = '接口正常（HTTP ' + res.status + (ver ? '，版本 ' + ver : '') + '）';
          } else {
            out.className = 'ep-check err';
            out.textContent = '返回的不是正常 JSON（HTTP ' + res.status + '）：'
              + (res.txt || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 60);
          }
        })
        .catch(function (err) {
          if (!out) return;
          out.className = 'ep-check err';
          out.textContent = '请求失败：' + err.message + '（PHP 未运行或地址不对）';
        })
        .then(function () { checkBtn.disabled = false; });
    });
  }

  // 建 Key 时按用户实际权限禁用「写」
  var groupSel = document.getElementById('keyGroup');
  var canWrite = document.getElementById('canWrite');
  var canRead = document.getElementById('canRead');
  var hint = document.getElementById('scopeHint');
  if (groupSel && canWrite && canRead) {
    var sync = function () {
      var opt = groupSel.options[groupSel.selectedIndex];
      if (!opt) return;
      var hasWrite = opt.getAttribute('data-write') === '1';
      var hasRead = opt.getAttribute('data-read') === '1';
      canWrite.disabled = !hasWrite;
      if (!hasWrite) canWrite.checked = false;
      canRead.checked = hasRead ? canRead.checked : true;
      if (hint) {
        hint.textContent = hasWrite
          ? '该分组你可以写，勾上「写入」后 Agent 才能新增或修改内容。'
          : '你在这个分组只有读权限，写选项已禁用。';
      }
    };
    groupSel.addEventListener('change', sync);
    sync();
  }

  // 条目编辑：切换问答/文档
  var kindSelect = document.getElementById('kindSelect');
  var questionRow = document.getElementById('questionRow');
  var linksPanel = document.getElementById('linksPanel');
  if (kindSelect) {
    var syncKind = function () {
      var isDoc = kindSelect.value === 'doc';
      if (linksPanel) linksPanel.style.display = isDoc ? '' : 'none';
      if (questionRow) questionRow.style.display = isDoc ? 'none' : '';
    };
    kindSelect.addEventListener('change', syncKind);
    syncKind();
  }

  /* ===== 邮箱验证码：发送 + 倒计时 =====
     按钮约定：data-send-code="发码接口" data-purpose="register|reset|verify|change"
     邮箱来源：data-email（写死）> data-email-from（CSS 选择器）> 同表单 input[name=email] */
  var codeTimers = [];

  function codeMsg(box, text, kind) {
    var el = box.querySelector('.code-msg');
    if (!el) {
      el = document.createElement('div');
      el.className = 'code-msg';
      box.parentNode.insertBefore(el, box.nextSibling);
    }
    el.textContent = text || '';
    el.className = 'code-msg' + (kind ? ' ' + kind : '');
  }

  function startCooldown(btn, secs) {
    var label = btn.getAttribute('data-label') || btn.textContent;
    btn.setAttribute('data-label', label);
    var n = secs;
    btn.disabled = true;
    btn.textContent = n + ' 秒后可重发';
    var timer = setInterval(function () {
      n--;
      if (n <= 0) {
        clearInterval(timer);
        btn.disabled = false;
        btn.textContent = label;
        return;
      }
      btn.textContent = n + ' 秒后可重发';
    }, 1000);
    codeTimers.push(timer);
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var btn = t.closest('[data-send-code]');
    if (!btn || btn.disabled) return;
    var form = btn.closest('form');
    if (!form) return;
    var box = btn.closest('.code-row') || btn.parentNode;

    var email = btn.getAttribute('data-email') || '';
    if (!email) {
      var sel = btn.getAttribute('data-email-from');
      var el = sel ? document.querySelector(sel) : form.querySelector('input[name="email"]');
      email = el ? el.value.trim() : '';
    }
    if (!email) {
      codeMsg(box, '请先填写邮箱', 'err');
      var e2 = form.querySelector('input[name="email"]');
      if (e2) e2.focus();
      return;
    }
    var csrfEl = form.querySelector('input[name="_csrf"]');
    var body = new URLSearchParams();
    body.set('_csrf', csrfEl ? csrfEl.value : '');
    body.set('purpose', btn.getAttribute('data-purpose') || 'register');
    body.set('email', email);

    var old = btn.textContent;
    btn.disabled = true;
    btn.textContent = '发送中…';
    codeMsg(box, '', '');

    fetch(btn.getAttribute('data-send-code'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'Accept': 'application/json'
      },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () { return null; });
    }).then(function (d) {
      if (!d) throw new Error('服务器返回的不是 JSON，可能被跳转了，刷新页面试试');
      if (!d.ok) throw new Error((d.error && d.error.message) || '发送失败');
      codeMsg(box, (d.data && d.data.message) || '验证码已发送', 'ok');
      btn.setAttribute('data-label', old);
      startCooldown(btn, (d.data && d.data.cooldown) || 60);
    }).catch(function (err) {
      btn.disabled = false;
      btn.textContent = old;
      codeMsg(box, err.message, 'err');
    });
  });
})();

/* ============ 感叹号提示（全站通用） ============
   气泡本身是纯 CSS（hover / :focus 显示），这里只补两个 CSS 做不了的细节：
   1. 提示图标放在 <label> 里时，点它会让复选框或输入框聚焦，必须拦掉默认行为；
   2. 触屏没有 hover，点一下图标聚焦、点页面其它地方收起。 */
(function () {
  function closer(el) {
    return el && el.closest ? el.closest('.tip') : null;
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var tip = closer(t);
    if (!tip) return;
    // 图标在 label 内：阻止冒泡到 label，避免顺带勾选/聚焦
    ev.preventDefault();
    if (document.activeElement === tip) {
      tip.blur();          // 再点一次收起（触屏）
    } else {
      try { tip.focus({ preventScroll: true }); } catch (e) { tip.focus(); }
    }
  }, true);

  // 点空白处收起所有聚焦中的气泡
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t && t.closest && t.closest('.tip')) return;
    var act = document.activeElement;
    if (act && act.classList && act.classList.contains('tip')) act.blur();
  });

  // Esc 收起
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape' && ev.keyCode !== 27) return;
    var act = document.activeElement;
    if (act && act.classList && act.classList.contains('tip')) {
      ev.stopPropagation();
      act.blur();
    }
  }, true);
})();

/* ============ 抽屉（2.0.4 后台收敛） ============ */
(function () {
  var lastFocus = null;

  function closeAll() {
    var opened = document.querySelectorAll('.sheet-wrap:not([hidden])');
    for (var i = 0; i < opened.length; i++) {
      opened[i].hidden = true;
    }
    document.documentElement.classList.remove('sheet-open');
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus({ preventScroll: true }); } catch (e) { lastFocus.focus(); }
    }
    lastFocus = null;
  }

  function open(id, trigger) {
    var el = id ? document.getElementById(id) : null;
    if (!el) return;
    closeAll();
    el.hidden = false;
    document.documentElement.classList.add('sheet-open');
    lastFocus = trigger || null;
    var btn = el.querySelector('.sheet-close');
    if (btn) { try { btn.focus({ preventScroll: true }); } catch (e) {} }
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;

    var trigger = t.closest('[data-sheet]');
    if (trigger) {
      ev.preventDefault();
      open(trigger.getAttribute('data-sheet'), trigger);
      return;
    }
    if (t.closest('[data-sheet-close]') || t.classList.contains('sheet-mask')) {
      ev.preventDefault();
      closeAll();
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' || ev.keyCode === 27) closeAll();
  });
})();
