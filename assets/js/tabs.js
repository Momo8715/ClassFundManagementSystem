/**
 * 通用二级分类页签（多分栏页面）
 * 用法：
 *   按钮：<button data-tabscope="scopeId" data-tabbtn="key">…</button>
 *   容器：<div id="scopeId"><div class="tab-pane" data-pane="key">…</div>…</div>
 */
(function () {
  'use strict';
  window._tab = function (scopeId, name) {
    var scope = document.getElementById(scopeId);
    if (!scope) return;
    var panes = scope.querySelectorAll('.tab-pane');
    for (var i = 0; i < panes.length; i++) {
      panes[i].style.display = (panes[i].getAttribute('data-pane') === name) ? '' : 'none';
    }
    var btns = document.querySelectorAll('[data-tabscope="' + scopeId + '"] [data-tabbtn]');
    for (var j = 0; j < btns.length; j++) {
      btns[j].className = (btns[j].getAttribute('data-tabbtn') === name) ? 'btn btn-primary btn-sm' : 'btn btn-outline btn-sm';
    }
    try { localStorage.setItem('tab:' + scopeId, name); } catch (e) { /* 隐私模式忽略 */ }
    // 可见性变化后通知 Chart.js 等重算尺寸
    setTimeout(function () { try { window.dispatchEvent(new Event('resize')); } catch (e) {} }, 60);
  };
  window._tabRestore = function (scopeId, def) {
    var t = def || '';
    try { t = localStorage.getItem('tab:' + scopeId) || def; } catch (e) {}
    if (t) window._tab(scopeId, t);
  };
  document.addEventListener('DOMContentLoaded', function () {
    ['dashTabs', 'reportTabs', 'rosterTabs', 'payTabs'].forEach(function (s) { window._tabRestore(s, ''); });
  });
})();
