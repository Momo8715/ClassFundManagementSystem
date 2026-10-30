/**
 * 用户管理增强（v1.12）：新建用户时生成随机初始密码，并提供复制/重新生成。
 * 不修改 app.js；包装 window.showAddStudent。
 */
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }

  function randomPassword() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    var out = '';
    if (window.crypto && window.crypto.getRandomValues) {
      var buf = new Uint32Array(10);
      window.crypto.getRandomValues(buf);
      for (var i = 0; i < 10; i++) out += chars[buf[i] % chars.length];
    } else {
      for (var j = 0; j < 10; j++) out += chars[Math.floor(Math.random() * chars.length)];
    }
    return out;
  }

  function ensureTools() {
    var input = $('stuPass');
    if (!input) return;
    var old = $('stuPassTools');
    if (old) old.remove();
    var box = document.createElement('div');
    box.id = 'stuPassTools';
    box.style.cssText = 'display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px';
    box.innerHTML = '<span style="font-size:11px;color:var(--text-secondary)">初始密码已随机生成；学生首次登录必须修改</span>'
      + '<button type="button" class="btn btn-outline btn-sm" id="stuPassCopy">📋 复制</button>'
      + '<button type="button" class="btn btn-outline btn-sm" id="stuPassGen">🔁 重新生成</button>';
    input.parentNode.appendChild(box);
    $('stuPassCopy').onclick = function () {
      var v = input.value || '';
      if (!v) return;
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(v).catch(function () {});
      } else {
        input.type = 'text'; input.select();
        try { document.execCommand('copy'); } catch (e) {}
        input.type = 'password';
      }
      var btn = $('stuPassCopy');
      if (btn) { var oldT = btn.textContent; btn.textContent = '✅ 已复制'; setTimeout(function () { btn.textContent = oldT; }, 1500); }
    };
    $('stuPassGen').onclick = function () { input.value = randomPassword(); };
  }

  function wrapShowAddStudent() {
    if (!window.showAddStudent || window.showAddStudent.__pwdWrapped) return;
    var orig = window.showAddStudent;
    var wrapped = function () {
      var r = orig.apply(this, arguments);
      var input = $('stuPass');
      if (input) {
        input.value = randomPassword();
        setTimeout(ensureTools, 20);
      }
      return r;
    };
    wrapped.__pwdWrapped = true;
    window.showAddStudent = wrapped;
  }

  function wrapEditStudent() {
    if (!window._editStu || window._editStu.__pwdWrapped) return;
    var orig = window._editStu;
    var wrapped = function () {
      var t = $('stuPassTools');
      if (t) t.remove();
      return orig.apply(this, arguments);
    };
    wrapped.__pwdWrapped = true;
    window._editStu = wrapped;
  }

  function init() {
    if (!$('modalStudent') || !$('stuPass')) return;
    wrapShowAddStudent();
    wrapEditStudent();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
