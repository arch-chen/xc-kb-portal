/*
 * 会话内「计费 / 模型门禁」引导卡片（2.2.5）。
 *
 * 后端 ai_preflight() / ai_guide_from_error() 会返回一份固定结构的 guide：
 *   { mode, code, title, message, reason, hints[], actions[{label,url,primary}], switch{id,name} }
 * 这里只负责把它画成一张卡片：模式徽标、说明、报错原因、排查建议、
 * 以及「去充值 / 接入自定义模型 / 去我的模型测试」等可点击按钮。
 *
 * 纯 DOM 构造，不拼接 innerHTML，文本一律 textContent（报错原因可能含上游原文）。
 */
(function () {
  'use strict';

  function el(tag, cls, txt) {
    var d = document.createElement(tag);
    if (cls) { d.className = cls; }
    if (txt !== undefined && txt !== null) { d.textContent = String(txt); }
    return d;
  }

  // 只放行站内相对路径与 http(s)，防住意外的 javascript: / data:
  function safeUrl(u) {
    var s = String(u || '');
    if (/^(https?:\/\/|\/|\.\/|\?)/i.test(s)) { return s; }
    return '#';
  }

  function render(g, opts) {
    if (!g || typeof g !== 'object' || !g.title) { return null; }
    opts = opts || {};
    var box = el('div', 'kb-guide kb-guide-' + String(g.code || 'info'));

    var head = el('div', 'kb-guide-head');
    var mode = String(g.mode || '');
    var modeTxt = mode === 'custom' ? '自定义接入模型'
      : (mode === 'credits' ? '积分扣除' : '模型设置');
    head.appendChild(el('span', 'kb-guide-mode kb-mode-' + (mode === 'custom' ? 'custom' : 'credits'), modeTxt));
    head.appendChild(el('div', 'kb-guide-title', g.title));
    box.appendChild(head);

    if (g.message) { box.appendChild(el('p', 'kb-guide-msg', g.message)); }

    if (g.reason) {
      var rbox = el('div', 'kb-guide-reason');
      rbox.appendChild(el('div', 'kb-guide-reason-h', '报错原因'));
      rbox.appendChild(el('div', 'kb-guide-reason-b mono', g.reason));
      box.appendChild(rbox);
    }

    if (g.hints && g.hints.length) {
      var ul = el('ul', 'kb-guide-hints');
      for (var i = 0; i < g.hints.length; i++) {
        if (g.hints[i]) { ul.appendChild(el('li', '', g.hints[i])); }
      }
      box.appendChild(ul);
    }

    var bar = el('div', 'kb-guide-actions');
    var acts = g.actions || [];
    for (var j = 0; j < acts.length; j++) {
      var a = acts[j];
      if (!a || !a.label) { continue; }
      var link = el('a', 'btn sm' + (a.primary ? ' primary' : ''), a.label);
      link.setAttribute('href', safeUrl(a.url));
      bar.appendChild(link);
    }
    if (g.switch && g.switch.id) {
      var b = el('button', 'btn sm', '切换到「' + String(g.switch.name || '可用模型') + '」');
      b.type = 'button';
      b.addEventListener('click', function () {
        if (typeof opts.onSwitch === 'function') {
          opts.onSwitch(g.switch.id, g.switch.name || '');
        }
      });
      bar.appendChild(b);
    }
    if (bar.childNodes.length) { box.appendChild(bar); }
    return box;
  }

  window.KBGuide = { render: render };
})();
