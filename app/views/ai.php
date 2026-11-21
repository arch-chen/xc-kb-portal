<?php
/** @var array $models 可用模型 @var array $ent 权益 @var array $groups 可写分组 @var array $calls 最近调用 @var int $defaultId */
$route = 'ai';
$csrf = csrf_token();
$base = u('ai');
$hasModels = $models !== [];
$selfCount = 0;
foreach ($models as $m) {
    if ((int)$m['owner_id'] > 0) {
        $selfCount++;
    }
}
?>
<section class="page-head">
  <div>
    <h1 class="page-title">AI 助手</h1>
    <p class="page-desc">整理和优化知识库。结果只是建议，确认后才会写进条目——AI 不会自己改你的库。</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(u('recharge')) ?>">充值与订单</a>
    <a class="btn" href="<?= e(u('ai_models')) ?>">我的模型<?= $selfCount > 0 ? '（' . $selfCount . '）' : '' ?></a>
  </div>
</section>

<?= nav_bill_tabs('ai') ?>

<section class="panel">
  <div class="ai-head">
    <div class="ai-wallet">
      <div><span class="muted tiny">积分余额</span><br><b id="aiBal"><?= (int)$ent['credits'] ?></b></div>
      <div>
        <span class="muted tiny">套餐</span><br>
        <?php if ($ent['active']): ?>
          <b class="tag ok"><?= e((string)$ent['plan']['name']) ?></b>
          <span class="muted tiny">至 <?= e((string)($ent['plan']['expires_at'] ?? '长期')) ?></span>
        <?php else: ?>
          <span class="muted">未开通</span>
          <a class="link-btn" href="<?= e(u('recharge')) ?>">去看看</a>
        <?php endif; ?>
      </div>
      <div>
        <span class="muted tiny">今日调用</span><br>
        <b><?= (int)$ent['daily_used'] ?></b>
        <span class="muted tiny"><?= (int)$ent['daily_limit'] > 0 ? ' / ' . (int)$ent['daily_limit'] : ' / 不限' ?></span>
      </div>
      <div>
        <span class="muted tiny">自有模型槽位</span><br>
        <b><?= (int)$ent['custom_used'] ?></b>
        <span class="muted tiny"> / <?= $ent['active'] ? (int)$ent['custom_slots'] : 0 ?></span>
      </div>
    </div>
  </div>

  <?php if (!$hasModels): ?>
    <div class="hint" style="margin-top:14px">
      <p><b>还没有可用的模型。</b>让管理员在「管理后台 → AI 设置」里加一个系统模型；
      或者开通月付套餐后到「我的模型」里填自己的接口。</p>
    </div>
  <?php else: ?>
    <div class="ai-row" style="margin-top:14px">
      <label class="field-group">
        <span class="field-label">用哪个模型</span>
        <select id="aiModel">
          <?php foreach ($models as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $defaultId ? 'selected' : '' ?>>
              <?= e((string)$m['name']) ?><?= (int)$m['owner_id'] > 0 ? '（我的）' : '' ?>
              — <?= (int)$m['credits'] > 0 ? (int)$m['credits'] . ' 积分/次' : '不扣积分' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="ai-cost" id="aiCostBox"></div>
    </div>

    <div class="ai-tabs" id="aiTabs">
      <button type="button" data-pane="chat" class="on">对话</button>
      <button type="button" data-pane="polish">优化答案</button>
      <button type="button" data-pane="extract">文本 → QA 草稿</button>
    </div>

    <!-- 对话 -->
    <div class="ai-pane on" data-pane="chat">
      <div class="ai-chat" id="chatLog">
        <div class="ai-msg">
          <span class="ai-msg-who">助手</span>
          我只答两类事：<b>本知识库怎么用</b>，和<b>你能读到的知识库内容</b>（你自己建的分组 + 别人授权给你的）。
          提问时我会先在你可读的分组里检索，查到就按原文回答并标出分组与条目号；查不到会直说「知识库里没有」，不靠模型自己编。
          其他话题会礼貌拒绝。问点具体的，比如「Wayfair 的包装要求有哪些容易漏的细节」。
        </div>
      </div>
      <label class="field-group" style="margin-top:10px">
        <span class="field-label">你的问题</span>
        <textarea id="chatText" rows="3" placeholder="Ctrl/⌘ + Enter 发送"></textarea>
      </label>
      <div class="ai-bar">
        <button type="button" class="btn primary" id="chatSend">发送</button>
        <span class="ai-cost" id="chatCost"></span>
        <span class="muted tiny">每轮先检索你的知识库再回答；多轮带最近 10 条上下文</span>
      </div>
      <div class="ai-err" id="chatErr"></div>
    </div>

    <!-- 优化答案 -->
    <div class="ai-pane" data-pane="polish">
      <div class="ai-row">
        <label class="field-group" style="flex:0 1 160px">
          <span class="field-label">条目 ID（可选）</span>
          <input type="number" id="polishEntry" placeholder="如 1234" min="1">
        </label>
        <div class="field-hint" style="flex:1 1 240px;align-self:center">
          填了 ID 会直接读库里那条的问答；不填就粘贴原文。写回条目时会先存一份旧版本。
        </div>
      </div>
      <label class="field-group" style="margin-top:10px">
        <span class="field-label">答案原文</span>
        <textarea id="polishText" rows="6" placeholder="粘贴群里那段回答，或填上面的条目 ID 让系统自己读"></textarea>
      </label>
      <div class="ai-bar">
        <button type="button" class="btn primary" id="polishSend">开始优化</button>
        <span class="ai-cost" id="polishCost"></span>
      </div>
      <div class="ai-err" id="polishErr"></div>
      <div class="ai-out" id="polishOut" style="margin-top:12px"></div>
      <div class="ai-bar" id="polishActions" style="display:none">
        <button type="button" class="btn" data-copy="#polishOut">复制结果</button>
        <button type="button" class="btn" id="polishWrite" style="display:none">写回该条目</button>
        <span class="muted tiny" id="polishNote"></span>
      </div>
    </div>

    <!-- 文本转 QA -->
    <div class="ai-pane" data-pane="extract">
      <label class="field-group">
        <span class="field-label">群聊片段</span>
        <textarea id="exText" rows="7" placeholder="把群里的一问一答整段粘进来，越完整越好"></textarea>
      </label>
      <div class="ai-row" style="margin-top:8px">
        <label class="field-group" style="flex:0 1 140px">
          <span class="field-label">最多抽取</span>
          <input type="number" id="exMax" value="8" min="1" max="20">
        </label>
        <label class="field-group" style="flex:1 1 220px">
          <span class="field-label">入库到分组</span>
          <select id="exGroup">
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>"><?= e((string)$g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="ai-bar">
        <button type="button" class="btn primary" id="exSend">生成草稿</button>
        <span class="ai-cost" id="exCost"></span>
        <span class="muted tiny">生成的每条都要你点「入库」才会写进去</span>
      </div>
      <div class="ai-err" id="exErr"></div>
      <div id="exList" style="margin-top:12px"></div>
    </div>
  <?php endif; ?>
</section>

<?php if ($calls): ?>
<section class="panel">
  <h2 class="panel-title">最近调用</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>时间</th><th>场景</th><th>模型</th><th>tokens</th><th>积分</th><th>耗时</th><th>状态</th></tr></thead>
      <tbody>
        <?php foreach ($calls as $c): ?>
          <tr>
            <td class="tiny muted"><?= e((string)$c['created_at']) ?></td>
            <td><span class="tag"><?= e((string)$c['action']) ?></span></td>
            <td class="tiny"><?= e((string)$c['model_name']) ?></td>
            <td class="tiny mono"><?= (int)$c['tokens_in'] ?>/<?= (int)$c['tokens_out'] ?></td>
            <td class="tiny"><?= (int)$c['credits'] > 0 ? '-' . (int)$c['credits'] : '—' ?></td>
            <td class="tiny"><?= (int)$c['latency_ms'] ?> ms</td>
            <td class="tiny">
              <?php if ((string)$c['status'] === 'ok'): ?>
                <span class="tag ok">成功</span>
              <?php else: ?>
                <span class="tag" style="color:var(--err)" title="<?= e((string)$c['error']) ?>">失败</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<script>
(function () {
  var base = <?= json_encode($base, JSON_UNESCAPED_UNICODE) ?>;
  var csrf = <?= json_encode($csrf) ?>;
  var prices = <?= json_encode(array_map(fn($m) => ['id' => (int)$m['id'], 'credits' => (int)$m['credits'], 'self' => (int)$m['owner_id'] > 0, 'name' => (string)$m['name']], $models), JSON_UNESCAPED_UNICODE) ?>;

  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

  function post(doName, fields) {
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });
    return fetch(base + '&do=' + doName, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-Token': csrf },
      body: body.toString()
    }).then(function (r) { return r.json().catch(function () { throw new Error('服务器返回异常（HTTP ' + r.status + '）'); }); });
  }

  function modelId() { var el = $('#aiModel'); return el ? el.value : '0'; }
  function paintCost() {
    var id = modelId(), hit = null;
    prices.forEach(function (p) { if (String(p.id) === String(id)) { hit = p; } });
    var txt = hit ? (hit.credits > 0 ? '每次扣 <b>' + hit.credits + '</b> 积分' : '自有模型，不扣积分') : '';
    ['#aiCostBox', '#chatCost', '#polishCost', '#exCost'].forEach(function (s) {
      var el = $(s); if (!el) { return; }
      if (s === '#aiCostBox') { el.innerHTML = txt; } else { el.innerHTML = txt ? txt + '　' : ''; }
    });
  }
  function setBal(v) { var el = $('#aiBal'); if (el && typeof v !== 'undefined') { el.textContent = v; } }
  function err(sel, msg) { var el = $(sel); if (el) { el.textContent = msg || ''; } }
  function fmt(r) {
    var bits = [];
    if (r.credits) { bits.push('本次扣 ' + r.credits + ' 积分'); }
    if (r.latency_ms) { bits.push(r.latency_ms + ' ms'); }
    if (r.usage) { bits.push('tokens ' + (r.usage.in || 0) + '/' + (r.usage.out || 0)); }
    return bits.join(' · ');
  }

  var sel = $('#aiModel');
  if (sel) { sel.addEventListener('change', paintCost); paintCost(); }

  $$('#aiTabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('#aiTabs button').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      $$('.ai-pane').forEach(function (p) { p.classList.toggle('on', p.getAttribute('data-pane') === b.getAttribute('data-pane')); });
    });
  });

  document.addEventListener('click', function (ev) {
    var c = ev.target.closest ? ev.target.closest('[data-copy]') : null;
    if (!c) { return; }
    var src = $(c.getAttribute('data-copy'));
    if (!src) { return; }
    navigator.clipboard.writeText(src.innerText).then(function () {
      var old = c.textContent; c.textContent = '已复制'; setTimeout(function () { c.textContent = old; }, 1200);
    });
  });

  /* ---- 对话 ---- */
  var history = [];
  var chatLog = $('#chatLog');
  function bubble(who, text, me) {
    var d = document.createElement('div');
    d.className = 'ai-msg' + (me ? ' me' : '');
    var w = document.createElement('span');
    w.className = 'ai-msg-who';
    w.textContent = who;
    d.appendChild(w);
    d.appendChild(document.createTextNode(text));
    chatLog.appendChild(d);
    chatLog.scrollTop = chatLog.scrollHeight;
  }
  var chatSend = $('#chatSend');
  if (chatSend) {
    var send = function () {
      var t = $('#chatText').value.trim();
      if (!t) { return; }
      err('#chatErr', '');
      bubble('我', t, true);
      $('#chatText').value = '';
      chatSend.disabled = true;
      chatSend.textContent = '思考中…';
      post('chat', { model_id: modelId(), text: t, history: JSON.stringify(history.slice(-10)) })
        .then(function (d) {
          if (!d.ok) { err('#chatErr', d.error && d.error.message); bubble('助手', '（失败）' + ((d.error && d.error.message) || ''), false); return; }
          bubble('助手', d.data.text, false);
          history.push({ role: 'user', content: t }, { role: 'assistant', content: d.data.text });
          setBal(d.data.balance);
        })
        .catch(function (e) { err('#chatErr', e.message); })
        .finally(function () { chatSend.disabled = false; chatSend.textContent = '发送'; });
    };
    chatSend.addEventListener('click', send);
    $('#chatText').addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { send(); }
    });
  }

  /* ---- 优化答案 ---- */
  var polishSend = $('#polishSend');
  if (polishSend) {
    polishSend.addEventListener('click', function () {
      var eid = ($('#polishEntry').value || '').trim();
      var txt = $('#polishText').value.trim();
      if (!eid && !txt) { err('#polishErr', '请粘贴原文，或填条目 ID'); return; }
      err('#polishErr', '');
      $('#polishOut').textContent = '';
      $('#polishActions').style.display = 'none';
      polishSend.disabled = true; polishSend.textContent = '优化中…';
      post('polish', { model_id: modelId(), entry_id: eid || '0', text: txt })
        .then(function (d) {
          if (!d.ok) { err('#polishErr', (d.error && d.error.message) || '失败'); return; }
          $('#polishOut').textContent = d.data.text;
          $('#polishActions').style.display = '';
          $('#polishNote').textContent = fmt(d.data);
          $('#polishWrite').style.display = d.data.entry_id ? '' : 'none';
          $('#polishWrite').setAttribute('data-entry', d.data.entry_id || '');
          setBal(d.data.balance);
        })
        .catch(function (e) { err('#polishErr', e.message); })
        .finally(function () { polishSend.disabled = false; polishSend.textContent = '开始优化'; });
    });

    var writeBtn = $('#polishWrite');
    if (writeBtn) {
      writeBtn.addEventListener('click', function () {
        var eid = writeBtn.getAttribute('data-entry');
        if (!eid) { return; }
        var raw = $('#polishOut').innerText || '';
        var m = raw.match(/##\s*优化后答案\s*([\s\S]*?)(?=\n##\s|$)/);
        var body = (m ? m[1] : raw).trim();
        if (!body) { err('#polishErr', '没解析出「优化后答案」段落'); return; }
        if (!confirm('会把这一版写入条目 #' + eid + '，旧内容存进历史版本。继续？')) { return; }
        post('write_entry', { entry_id: eid, body: body })
          .then(function (d) {
            if (!d.ok) { err('#polishErr', (d.error && d.error.message) || '写入失败'); return; }
            $('#polishNote').textContent = '已写入条目 #' + d.data.id + '（现在 v' + d.data.version + '）';
          })
          .catch(function (e) { err('#polishErr', e.message); });
      });
    }
  }

  /* ---- 文本转 QA ---- */
  var exSend = $('#exSend');
  if (exSend) {
    exSend.addEventListener('click', function () {
      var t = $('#exText').value.trim();
      if (!t) { err('#exErr', '请粘贴群聊内容'); return; }
      err('#exErr', '');
      $('#exList').innerHTML = '';
      exSend.disabled = true; exSend.textContent = '生成中…';
      post('extract', { model_id: modelId(), text: t, max: $('#exMax').value || '8' })
        .then(function (d) {
          if (!d.ok) { err('#exErr', (d.error && d.error.message) || '失败'); return; }
          setBal(d.data.balance);
          var items = d.data.items || [];
          if (!items.length) {
            $('#exList').innerHTML = '<div class="hint">没抽出可用的问答对。模型的原始输出：<pre class="mono tiny" style="white-space:pre-wrap">'
              + (d.data.text || '').replace(/[<>&]/g, function (c) { return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c]; }) + '</pre></div>';
            return;
          }
          items.forEach(function (it, i) {
            var card = document.createElement('div');
            card.className = 'ai-card';
            var q = document.createElement('div'); q.className = 'ai-card-q'; q.textContent = (i + 1) + '. ' + it.question;
            var a = document.createElement('div'); a.className = 'ai-card-a'; a.textContent = it.answer;
            var bar = document.createElement('div'); bar.className = 'ai-bar';
            var btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn sm'; btn.textContent = '入库';
            var st = document.createElement('span'); st.className = 'muted tiny';
            btn.addEventListener('click', function () {
              if (btn.disabled) { return; }
              btn.disabled = true; st.textContent = '写入中…';
              post('write_qa', { group_id: $('#exGroup').value, question: it.question, answer: it.answer })
                .then(function (r) {
                  if (!r.ok) { st.textContent = (r.error && r.error.message) || '失败'; btn.disabled = false; return; }
                  st.textContent = '已入库 #' + r.data.id;
                  btn.textContent = '已入库';
                })
                .catch(function (e) { st.textContent = e.message; btn.disabled = false; });
            });
            bar.appendChild(btn); bar.appendChild(st);
            card.appendChild(q); card.appendChild(a); card.appendChild(bar);
            $('#exList').appendChild(card);
          });
        })
        .catch(function (e) { err('#exErr', e.message); })
        .finally(function () { exSend.disabled = false; exSend.textContent = '生成草稿'; });
    });
  }
})();
</script>
