/*
 * 左侧栏分组重命名（2.6.5）
 *
 * 需求：名字要在侧栏就地能改，不要藏在分组页底部的「分组设置」卡片里。
 * 交互：鼠标移到分组行 → 出现「⋯」→ 点它 → 这一行变成输入框 → 回车保存 / Esc 取消。
 *
 * 只做交互，权限判定全在服务端（?r=group_rename 走 can_rename_group）。
 * 侧栏只在「这个人能改名」时才渲染这个按钮，所以正常情况下不会出现
 * 「点了才被拒绝」——但服务端仍然独立校验，因为前端按钮只是提示，不是授权。
 *
 * 私有分组的完整名称是「昵称 · 自定义名」，前面那段昵称由账户昵称决定，
 * 不该让用户在这里改（改了也会被 identity.php 的规则覆盖回去）。
 * 所以输入框里只放后半截，前缀作为不可编辑的标签显示在旁边。
 */
(function () {
  'use strict';

  var rows = document.querySelectorAll('.side-group-row[data-id]');
  if (!rows.length) { return; }

  function csrf() {
    // csrf_field() 在页面上会渲染多个（每个表单一个），值都相同，取第一个就够了
    var f = document.querySelector('input[name="_csrf"]');
    return f ? f.value : '';
  }

  function status(msg, bad) {
    var box = document.getElementById('sideGroupMsg');
    if (!box) {
      box = document.createElement('div');
      box.id = 'sideGroupMsg';
      box.className = 'side-group-err';
      var host = document.querySelector('.side-section');
      if (host) { host.appendChild(box); }
    }
    box.textContent = msg || '';
    box.style.color = bad ? '#e05252' : 'var(--muted)';
    box.hidden = !msg;
    if (msg) {
      clearTimeout(status._t);
      status._t = setTimeout(function () { box.hidden = true; }, 3200);
    }
  }

  function save(row, value, input, done) {
    var id = row.getAttribute('data-id');
    var body = new URLSearchParams();
    body.set('_csrf', csrf());
    body.set('id', id);
    body.set('name', value);
    fetch('index.php?r=group_rename', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-CSRF-Token': csrf()
      },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () {
        throw new Error('服务器返回异常（HTTP ' + r.status + '）');
      });
    }).then(function (d) {
      if (!d.ok) {
        throw new Error((d.error && d.error.message) || '改名失败');
      }
      var name = d.data.name;
      row.setAttribute('data-name', name);
      // 侧栏文字同步；页面标题如果是这个分组，也跟着变
      var txt = row.querySelector('.side-item-text');
      if (txt) { txt.textContent = name; }
      var title = document.querySelector('.page-title');
      if (title && document.body.getAttribute('data-cur-group-id') === String(id)) {
        title.textContent = name;
        document.title = name + ' · ' + document.title.split(' · ').pop();
      }
      status('已改名为「' + name + '」');
      done(true);
    }).catch(function (er) {
      status(er.message, true);
      done(false);
      // 失败时把输入框留着，让用户能改完重试，而不是丢掉他刚打的字
      if (input && input.isConnected) { input.focus(); }
    });
  }

  function startEdit(row) {
    if (row.querySelector('.side-group-edit')) { return; }

    var oldName = row.getAttribute('data-name') || '';
    var prefix = row.getAttribute('data-prefix') || '';
    // 私有分组只编辑后半截；共享分组没有前缀，整串都是可编辑的
    var base = prefix && oldName.indexOf(prefix) === 0
      ? oldName.slice(prefix.length)
      : oldName;

    var link = row.querySelector('.side-item');
    if (link) { link.style.display = 'none'; }
    var more = row.querySelector('.side-group-more');
    if (more) { more.setAttribute('aria-expanded', 'true'); }

    var wrap = document.createElement('div');
    wrap.className = 'side-group-edit';
    if (prefix) {
      var pf = document.createElement('span');
      pf.className = 'side-group-prefix';
      pf.textContent = prefix;
      wrap.appendChild(pf);
    }
    var input = document.createElement('input');
    input.type = 'text';
    input.value = base;
    input.maxLength = 60;
    input.setAttribute('aria-label', '分组名称');
    wrap.appendChild(input);
    row.appendChild(wrap);

    input.focus();
    input.select();

    var finished = false;
    function finish(commit) {
      if (finished) { return; }
      var val = input.value.trim();
      if (!commit) {
        teardown();
        return;
      }
      if (val === '') {
        status('分组名称不能为空', true);
        input.focus();
        return;
      }
      if (val === base) { teardown(); return; }
      finished = true;
      input.readOnly = true;
      save(row, val, null, function (ok) {
        teardown();
        finished = false;
        if (!ok) { /* 输入框已经拆了，提示在底部消息条里 */ }
      });
    }

    function teardown() {
      wrap.remove();
      if (link) { link.style.display = ''; }
      if (more) { more.setAttribute('aria-expanded', 'false'); }
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    // 失焦保存：和侧栏会话列表的手感一致（点别处就当确认）
    input.addEventListener('blur', function () {
      if (!finished) { finish(true); }
    });
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var more = t.closest ? t.closest('.side-group-more') : null;
    if (!more) { return; }
    e.preventDefault();
    e.stopPropagation();
    var row = more.closest('.side-group-row');
    if (!row) { return; }
    if (row.querySelector('.side-group-edit')) {
      // 再点一次「⋯」收起
      var inp = row.querySelector('.side-group-edit input');
      if (inp) { inp.blur(); }
      return;
    }
    startEdit(row);
  });
})();
