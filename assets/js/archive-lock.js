/**
 * 归档学期行锁定（v1.13）：前端提示已归档，禁用编辑/删除/勾选。
 * 不改 app.js，通过 data-tx-id 与接口返回的 locked 字段增强。
 */
(function () {
  'use strict';
  if (!window.CF) return;
  var CF = window.CF;
  var locked = {};
  var names = {};

  function indexResp(resp) {
    if (!resp || !Array.isArray(resp.transactions)) return false;
    resp.transactions.forEach(function (t) {
      locked[t.id] = !!(t.locked || t.semester_status === 'archived');
      names[t.id] = t.semester_name || '';
    });
    return true;
  }

  function decorate(container) {
    var el = document.getElementById(container);
    if (!el) return;
    el.querySelectorAll('tr[data-tx-id]').forEach(function (tr) {
      if (tr.getAttribute('data-lock-applied') === '1') return;
      var id = tr.getAttribute('data-tx-id');
      if (!locked[id]) return;
      tr.setAttribute('data-lock-applied', '1');
      tr.style.opacity = '0.82';
      var first = tr.querySelector('td');
      if (first && !first.querySelector('.cf-lock-badge')) {
        var b = document.createElement('span');
        b.className = 'cf-lock-badge';
        b.textContent = '🔒 已归档';
        b.title = names[id] ? ('所属学期：' + names[id] + '，历史数据只读') : '历史数据只读';
        b.style.cssText = 'display:inline-block;margin-left:6px;padding:1px 6px;border-radius:999px;background:#fef3c7;color:#b45309;font-size:10px;font-weight:600;white-space:nowrap';
        first.appendChild(b);
      }
      tr.querySelectorAll('button[onclick*="_editTx"],button[onclick*="_deleteTx"]').forEach(function (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.35';
        btn.title = '已归档学期，历史数据只读';
      });
      tr.querySelectorAll('input.txCb').forEach(function (cb) {
        cb.disabled = true;
        cb.checked = false;
      });
    });
  }

  function applyAll() {
    decorate('transactionTable');
    decorate('dashboardRecent');
  }

  async function refreshTransactions() {
    if (indexResp(window.__lastTxResp)) { applyAll(); return; }
    try {
      var text = document.getElementById('transactionTable');
      if (!text) return;
      var resp = await CF.get('transactions', { per_page: 100 });
      indexResp(resp);
      applyAll();
    } catch (e) { /* ignore */ }
  }

  async function refreshDashboard() {
    try {
      var resp = await CF.get('transactions', { per_page: 8 });
      indexResp(resp);
      decorate('dashboardRecent');
    } catch (e) { /* ignore */ }
  }

  function observe(id) {
    var el = document.getElementById(id);
    if (!el || el.__lockObserver) return;
    el.__lockObserver = true;
    if (window.MutationObserver) {
      new MutationObserver(function () { decorate(id); }).observe(el, { childList: true, subtree: true });
    }
  }

  function wrapSwitchPage() {
    if (!window.switchPage || window.switchPage.__archiveLockWrapped) return;
    var orig = window.switchPage;
    var wrapped = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'transactions') setTimeout(refreshTransactions, 320);
      if (page === 'dashboard') setTimeout(refreshDashboard, 320);
      return r;
    };
    wrapped.__archiveLockWrapped = true;
    window.switchPage = wrapped;
  }

  function init() {
    if (!document.getElementById('transactionTable') && !document.getElementById('dashboardRecent')) return;
    observe('transactionTable');
    observe('dashboardRecent');
    wrapSwitchPage();
    var tx = document.getElementById('page-transactions');
    if (tx && tx.classList.contains('active')) setTimeout(refreshTransactions, 350);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
