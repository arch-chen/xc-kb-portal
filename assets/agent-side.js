/*
 * 全站左栏「AI 工作台」会话块的交互（2.2.2）。
 *
 * AI 工作台页面自己有一套完整脚本（agent.php 内嵌），会接管新建 / 改名 / 删除，
 * 所以这里检测到 .main.main-agent 就直接退出，避免重复绑定。
 * 首页、分组页等其它页面没有那套脚本，由本文件让「＋ 新对话 / 改名 / 删除」照样可用：
 * 新建成功后跳到 AI 工作台并打开新会话，改名 / 删除就地完成。
 */
(function () {
  if (document.querySelector('.main.main-agent')) { return; }

  var runs = document.getElementById('agRuns');
  var newBtn = document.getElementById('agNew');
  if (!runs) { return; }
  var csrf = runs.getAttribute('data-csrf') ||
             (newBtn && newBtn.getAttribute('data-csrf')) || '';
  var base = 'index.php?r=agent';

  function api(cmd, fields) {
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(base + '&do=' + cmd, {
      method: 'POST', credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': csrf
      },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () { throw new Error('服务器返回异常（HTTP ' + r.status + '）'); });
    }).then(function (d) {
      if (!d.ok) { throw new Error((d.error && d.error.message) || '请求失败'); }
      return d.data;
    });
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  function closeMenus() {
    var list = document.querySelectorAll('.agent-run-ops');
    for (var i = 0; i < list.length; i++) { list[i].remove(); }
  }

  if (newBtn) {
    newBtn.addEventListener('click', function () {
      if (newBtn.disabled) { return; }
      newBtn.disabled = true;
      newBtn.textContent = '新建中…';
      // 其它页面没有模型下拉，传 0 让后端回落到默认模型
      api('new', { model_id: 0 }).then(function (d) {
        window.location.href = base + '&id=' + d.run_id;
      }).catch(function (e) {
        newBtn.disabled = false;
        newBtn.textContent = '＋ 新对话';
        alert('新建失败：' + e.message);
      });
    });
  }

  function startInlineRename(row, id) {
    if (row.querySelector('.agent-run-edit')) { return; }
    var lab = row.querySelector('.agent-run-t');
    if (!lab) { return; }
    var old = row.getAttribute('data-title') || lab.textContent || '新对话';
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'agent-run-edit';
    input.maxLength = 60;
    input.value = old;
    lab.replaceWith(input);
    input.focus();
    input.select();
    var done = false;
    function finish(save) {
      if (done) { return; }
      done = true;
      var val = input.value.trim();
      var back = el('span', 'agent-run-t', save && val ? val : old);
      input.replaceWith(back);
      if (!save || !val || val === old) { return; }
      api('rename', { run_id: id, title: val }).then(function (d) {
        row.setAttribute('data-title', d.title);
        back.textContent = d.title;
      }).catch(function (er) {
        back.textContent = old;
        alert('改名失败：' + er.message);
      });
    }
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    input.addEventListener('blur', function () { finish(true); });
  }

  runs.addEventListener('click', function (e) {
    var t = e.target;
    var more = t.closest ? t.closest('.agent-run-more') : null;
    if (more) {
      e.preventDefault(); e.stopPropagation();
      var row = more.closest('.agent-run-row');
      var had = row.querySelector('.agent-run-ops');
      closeMenus();
      if (had) { return; }
      var ops = el('div', 'agent-run-ops');
      var br = el('button', '', '✎ 重命名');
      br.type = 'button'; br.setAttribute('data-act', 'rename');
      var bd = el('button', 'danger', '✕ 删除对话');
      bd.type = 'button'; bd.setAttribute('data-act', 'del');
      ops.appendChild(br); ops.appendChild(bd);
      row.appendChild(ops);
      return;
    }
    var op = t.closest ? t.closest('.agent-run-ops button') : null;
    if (op) {
      e.preventDefault(); e.stopPropagation();
      var row2 = op.closest('.agent-run-row');
      var id = parseInt(row2.getAttribute('data-id'), 10);
      var act = op.getAttribute('data-act');
      closeMenus();
      if (act === 'rename') { startInlineRename(row2, id); }
      if (act === 'del') {
        if (!confirm('删除这个对话？聊天记录会一起删掉（写进知识库的内容不会动）')) { return; }
        api('del', { run_id: id }).then(function () { row2.remove(); })
          .catch(function (er) { alert(er.message); });
      }
    }
  });

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t.closest && (t.closest('.agent-run-ops') || t.closest('.agent-run-more')))) {
      closeMenus();
    }
  });
})();
