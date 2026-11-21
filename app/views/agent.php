<?php
/**
 * AI 工作台：能动手的对话窗口。
 *
 * @var array  $runs      会话列表
 * @var ?array $run       当前会话
 * @var array  $msgs      当前会话的消息（已转成前端结构）
 * @var array  $models    可用模型
 * @var array  $ent       权益
 * @var int    $defaultId 默认模型
 * @var int    $maxSteps  单轮最多几步
 * @var array  $scope     检索范围 ['read'=>可读分组数, 'write'=>可写分组数]
 */
$route = 'agent';
$scope = $scope ?? ['read' => 0, 'write' => 0];
$csrf = csrf_token();
$base = u('agent');
// 往 <script> 里塞 JSON 必须转义尖括号：抓回来的网页正文里一旦有 </script> 就会跑出脚本块
$js = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$hasModels = $models !== [];
$runId = (int)($run['id'] ?? 0);

// 会话列表改由 layout.php 的 nav_agent_side() 全站常驻渲染（首页 / 分组页也能看到），
// 这里只把当前会话 id 交给它做高亮；主区依旧只放对话窗口，不出现第二根滚动条。
$GLOBALS['agent_run_id'] = $runId;
?>
<section class="page-head agent-head">
  <div>
    <h1 class="page-title">AI 工作台</h1>
    <p class="page-desc">能抓网页、建分组，把内容写进知识库；每一步可见，写错能撤销。</p>
  </div>
  <div class="page-actions">
    <span class="muted tiny">积分余额 <b id="agBal"><?= (int)$ent['credits'] ?></b></span>
    <a class="btn" href="<?= e(u('recharge')) ?>">账单</a>
    <a class="btn" href="<?= e(u('ai_models')) ?>">我的模型</a>
  </div>
</section>

<?php if (!$hasModels): ?>
  <section class="panel">
    <div class="hint">
      <p><b>还没有可用的模型。</b>让管理员在「管理后台 → AI 设置」里加一个系统模型；
      或者开通月付套餐后到「我的模型」里填自己的接口。</p>
    </div>
  </section>
<?php else: ?>
<div class="agent-shell">
  <section class="agent-main">
    <header class="agent-bar">
      <div class="agent-title-wrap">
        <div class="agent-title" id="agTitle"><?= $run ? e((string)$run['title']) : '新对话' ?></div>
        <?php if ($run): ?>
          <button type="button" class="btn sm agent-rename" id="agRename" title="给这个对话改个名字">✎ 重命名</button>
        <?php endif; ?>
      </div>
      <label class="agent-model">
        <span class="tiny muted">模型</span>
        <select id="aiModel">
          <?php foreach ($models as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $defaultId ? 'selected' : '' ?>>
              <?= e((string)$m['name']) ?><?= (int)$m['owner_id'] > 0 ? '（我的）' : '' ?>
              — <?= (int)$m['credits'] > 0 ? (int)$m['credits'] . ' 积分/步' : '不扣积分' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($run): ?>
        <button type="button" class="btn sm agent-del" id="agDel">删除对话</button>
      <?php endif; ?>
    </header>

    <div class="agent-log" id="agLog"></div>

    <div class="agent-tip" id="agTip">
      <span class="tiny muted" id="agTipScope">
        检索范围：你能读的 <b><?= (int)$scope['read'] ?></b> 个分组（自己建的 + 别人授权的），其中 <b><?= (int)$scope['write'] ?></b> 个可写。
        只答「本知识库怎么用」和「你能读到的库内容」，其他话题直接拒绝；单轮最多 <?= (int)$maxSteps ?> 步，每步一次模型调用。
      </span>
      <span class="tiny ag-tip-run" id="agTipRun" hidden></span>
    </div>

    <form class="agent-form" id="agForm" autocomplete="off">
      <textarea id="agText" rows="2" placeholder="比如说：把这个页面的内容采集回来，整理成问答，存进新建的分组「Wayfair 包装要求」https://…"></textarea>
      <div class="agent-form-bar">
        <span class="tiny muted">Ctrl / ⌘ + Enter 发送</span>
        <button type="submit" class="btn primary" id="agSend">发送</button>
      </div>
    </form>
  </section>
</div>

<script>
(function () {
  var base = <?= $js($base) ?>;
  var csrf = <?= $js($csrf) ?>;
  var runId = <?= $runId ?>;
  var groupTpl = <?= $js(u('g', ['slug' => '__SLUG__'])) ?>;
  var initial = <?= $js($msgs) ?>;
  var examples = [
    '你会做什么？能操作知识库的哪些功能？',
    '把这个页面采集回来，整理成问答存进新建的分组 https://',
    '搜一下知识库里关于「退货」的条目，整理成一份清单',
    '新建分组「Wayfair 包装要求」，把下面这段整理成问答存进去：'
  ];

  function $(s, r) { return (r || document).querySelector(s); }
  // 手机上一进页面就聚焦输入框会把整页拉到中间，聚焦但不滚动
  function focusSoft(el) {
    try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
  }
  function el(tag, cls, txt) {
    var d = document.createElement(tag);
    if (cls) { d.className = cls; }
    if (txt !== undefined && txt !== null) { d.textContent = txt; }
    return d;
  }
  var log = $('#agLog');
  var textarea = $('#agText');
  var sendBtn = $('#agSend');
  var busy = false;

  function api(cmd, fields) {
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(base + '&do=' + cmd, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-Token': csrf },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () { throw new Error('服务器返回异常（HTTP ' + r.status + '）'); });
    }).then(function (d) {
      if (!d.ok) { throw new Error((d.error && d.error.message) || '请求失败'); }
      return d.data;
    });
  }

  function bottom() { log.scrollTop = log.scrollHeight; }

  /* ---------- 消息渲染 ---------- */
  function renderMsg(m) {
    if (m.role === 'user') {
      var u = el('div', 'ag-msg me');
      u.appendChild(el('div', 'ag-who', '我'));
      var ub = el('div', 'ag-body');
      ub.textContent = m.text || '';
      u.appendChild(ub);
      return u;
    }
    if (m.role === 'notice') {
      return el('div', 'ag-notice', m.text || '');
    }
    if (m.role === 'tool') {
      var box = el('div', 'ag-tool' + (m.ok ? '' : ' fail'));
      var head = el('div', 'ag-tool-head');
      head.appendChild(el('span', 'ag-tool-ico', m.ok ? '✓' : '!'));
      head.appendChild(el('span', 'ag-tool-name', m.label || m.tool));
      head.appendChild(el('span', 'ag-tool-sum', m.summary || ''));
      box.appendChild(head);

      var det = document.createElement('details');
      det.className = 'ag-tool-det';
      var sm = document.createElement('summary');
      sm.textContent = '看参数与结果';
      det.appendChild(sm);
      var pre = el('pre', 'ag-pre');
      pre.textContent = '参数：' + JSON.stringify(m.args || {}, null, 2)
        + (m.data && Object.keys(m.data).length ? '\n\n返回：' + JSON.stringify(m.data, null, 2) : '');
      det.appendChild(pre);
      box.appendChild(det);

      var bar = el('div', 'ag-tool-bar');
      if (m.data && m.data.slug) {
        var a = document.createElement('a');
        a.className = 'link-btn';
        a.href = groupTpl.replace('__SLUG__', encodeURIComponent(m.data.slug));
        a.textContent = '打开分组「' + (m.data.group || m.data.name || '') + '」';
        bar.appendChild(a);
      }
      if (m.undo) {
        var un = el('button', 'link-btn', '撤销这次写入');
        un.type = 'button';
        un.addEventListener('click', function () {
          if (!confirm('会删掉刚写进去的内容，确定？')) { return; }
          un.disabled = true;
          un.textContent = '撤销中…';
          api('undo', { msg_id: m.id }).then(function (d) {
            un.textContent = d.message || '已撤销';
            box.classList.add('undone');
          }).catch(function (e) {
            un.disabled = false;
            un.textContent = '撤销失败：' + e.message;
          });
        });
        bar.appendChild(un);
      }
      if (bar.childNodes.length) { box.appendChild(bar); }
      return box;
    }
    // assistant
    var b = el('div', 'ag-msg');
    b.appendChild(el('div', 'ag-who', '助手'));
    var body = el('div', 'ag-body');
    body.innerHTML = m.html || '';
    b.appendChild(body);
    return b;
  }

  function appendAll(list) {
    (list || []).forEach(function (m) { log.appendChild(renderMsg(m)); });
    bottom();
  }

  /* ---------- 空态 ---------- */
  function emptyState() {
    log.innerHTML = '';
    var wrap = el('div', 'ag-empty');
    wrap.appendChild(el('div', 'ag-empty-t', '让它去干活，别只聊'));
    wrap.appendChild(el('div', 'ag-empty-d', '下面这些点一下就能用，地址和内容自己替换'));
    var list = el('div', 'ag-chips');
    examples.forEach(function (t) {
      var c = el('button', 'ag-chip', t);
      c.type = 'button';
      c.addEventListener('click', function () {
        textarea.value = t;
        focusSoft(textarea);
      });
      list.appendChild(c);
    });
    wrap.appendChild(list);
    log.appendChild(wrap);
  }

  /* ---------- 执行循环 ---------- */
  function setBusy(on, label) {
    busy = on;
    sendBtn.disabled = on;
    sendBtn.textContent = on ? (label || '执行中…') : '发送';
    var tip = $('#agTip');
    var scope = $('#agTipScope');
    var run = $('#agTipRun');
    if (tip) { tip.classList.toggle('on', !!on); }
    if (scope) { scope.hidden = !!on; }
    if (run) {
      run.hidden = !on;
      if (on) { run.textContent = label || '执行中…'; }
    }
  }

  function fail(msg) {
    log.appendChild(el('div', 'ag-err', msg));
    bottom();
  }

  function paintTitle(t) {
    if (!t) { return; }
    var n = $('#agTitle');
    if (n) { n.textContent = t; }
    var on = $('.agent-run-row.on');
    if (on) {
      on.setAttribute('data-title', t);
      var lab = $('.agent-run-t', on);
      if (lab) { lab.textContent = t; }
    }
  }

  /* ---------- 会话管理：改名 / 删除 ---------- */
  function renameRun(id, title, row) {
    return api('rename', { run_id: id, title: title }).then(function (d) {
      if (row) {
        row.setAttribute('data-title', d.title);
        var lab = $('.agent-run-t', row);
        if (lab) { lab.textContent = d.title; }
      }
      if (id === runId) { paintTitle(d.title); }
      return d;
    });
  }

  function delRun(id, row) {
    return api('del', { run_id: id }).then(function () {
      if (id === runId) { window.location.href = base; return; }
      if (row) { row.remove(); }
    });
  }

  function closeMenus() {
    var list = document.querySelectorAll('.agent-run-ops');
    for (var i = 0; i < list.length; i++) { list[i].remove(); }
  }

  /** 侧栏里就地改名，回车保存 / Esc 取消 / 失焦保存 */
  function startInlineRename(row, id) {
    if ($('.agent-run-edit', row)) { return; }
    var lab = $('.agent-run-t', row);
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
      renameRun(id, val, row).catch(function (er) {
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

  function bindRuns() {
    var box = $('#agRuns');
    if (!box) { return; }
    box.addEventListener('click', function (e) {
      var t = e.target;
      var more = t.closest ? t.closest('.agent-run-more') : null;
      if (more) {
        e.preventDefault(); e.stopPropagation();
        var row = more.closest('.agent-run-row');
        var had = $('.agent-run-ops', row);
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
          delRun(id, row2).catch(function (er) { alert(er.message); });
        }
      }
    });
    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!(t.closest && (t.closest('.agent-run-ops') || t.closest('.agent-run-more')))) { closeMenus(); }
    });
  }

  function afterStep(d) {
    appendAll(d.msgs);
    if (typeof d.balance !== 'undefined') { $('#agBal').textContent = d.balance; }
    paintTitle(d.title);
    if (d.status === 'running') {
      setBusy(true, '第 ' + (d.step + 1) + ' 步：思考中…');
      setTimeout(next, 120);
      return;
    }
    setBusy(false);
    if (d.status === 'ask') {
      log.appendChild(el('div', 'ag-notice', '助手在等你补充信息，直接回复就行。'));
    }
    refreshRuns();
    bottom();
  }

  function next() {
    if (!runId) { return; }
    api('step', { run_id: runId, model_id: $('#aiModel').value })
      .then(afterStep)
      .catch(function (e) { setBusy(false); fail(e.message); });
  }

  function refreshRuns() {
    // 会话列表只更新当前那条的时间和步数，避免整页刷新打断对话
    var cur = $('.agent-run.on .agent-run-m');
    if (cur) { cur.textContent = '刚刚 · 进行中'; }
  }

  function send() {
    if (busy) { return; }
    var text = textarea.value.trim();
    if (!text) { focusSoft(textarea); return; }
    if (!runId) {
      api('new', { model_id: $('#aiModel').value })
        .then(function (d) { runId = d.run_id; })
        .catch(function (e) { fail(e.message); })
        .then(function () { if (runId) { send(); } });
      return;
    }
    var mine = { role: 'user', text: text };
    if ($('.ag-empty')) { log.innerHTML = ''; }
    log.appendChild(renderMsg(mine));
    bottom();
    textarea.value = '';
    setBusy(true, '第 1 步：思考中…');
    api('send', { run_id: runId, text: text, model_id: $('#aiModel').value })
      .then(afterStep)
      .catch(function (e) { setBusy(false); fail(e.message); });
  }

  $('#agForm').addEventListener('submit', function (e) { e.preventDefault(); send(); });
  textarea.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); send(); }
  });

  // 「新对话」要真的建一条会话再跳过去。早期版本只是跳回 ?r=agent，
  // 但后端会把不带 id 的访问自动回落到最近一条会话，于是点了跟没点一样。
  var newBtn = $('#agNew');
  if (newBtn) {
    newBtn.addEventListener('click', function () {
      if (busy || newBtn.disabled) { return; }
      newBtn.disabled = true;
      newBtn.textContent = '新建中…';
      api('new', { model_id: $('#aiModel').value })
        .then(function (d) { window.location.href = base + '&id=' + d.run_id; })
        .catch(function (e) {
          newBtn.disabled = false;
          newBtn.textContent = '＋ 新对话';
          alert('新建失败：' + e.message);
        });
    });
  }
  var renBtn = $('#agRename');
  if (renBtn) {
    renBtn.addEventListener('click', function () {
      if (!runId) { return; }
      var cur = ($('#agTitle') || {}).textContent || '新对话';
      var t = window.prompt('给这个对话起个名字（最多 60 字）', cur.trim());
      if (t === null) { return; }
      t = t.trim();
      if (!t) { return; }
      renameRun(runId, t, $('.agent-run-row.on')).catch(function (e) { alert('改名失败：' + e.message); });
    });
  }
  var delBtn = $('#agDel');
  if (delBtn) {
    delBtn.addEventListener('click', function () {
      if (!runId || !confirm('删除这个对话？聊天记录会一起删掉（写进知识库的内容不会动）')) { return; }
      delRun(runId, $('.agent-run-row.on')).catch(function (e) { alert(e.message); });
    });
  }
  bindRuns();

  /* ---------- 初始化 ---------- */
  if (initial.length) {
    appendAll(initial);
    if (runId) { bottom(); }
  } else if (!runId) {
    emptyState();
  } else {
    log.appendChild(el('div', 'ag-notice', '接着上次说，或者换个说法。'));
  }
  if (initial.length && initial[initial.length - 1].role === 'user') {
    // 上一步没跑完就刷新了页面，自动接着跑
    setBusy(true, '继续执行…');
    setTimeout(next, 200);
  }
  focusSoft(textarea);
})();
</script>
<?php endif; ?>
