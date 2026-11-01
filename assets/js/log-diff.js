/**
 * 操作日志变更对比（v1.13）：展开 update_transaction 的 before/after。
 */
(function () {
  'use strict';
  if (!window.CF) return;
  var CF = window.CF;
  var labels = {
    type: '类型', sub_category: '子分类', amount: '金额', date: '日期',
    description: '描述', category: '分类', payer_ids: '缴费名单',
    exempt_ids: '单次免缴', per_person: '每人应缴', expected_amount: '应收总额',
    semester_id: '学期'
  };

  function fmt(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'object') {
      try { return JSON.stringify(v); } catch (e) { return String(v); }
    }
    var s = String(v);
    return s.length > 300 ? s.slice(0, 300) + '…' : s;
  }

  function showDiff(details) {
    var before = details.before || {};
    var after = details.after || {};
    var changed = Array.isArray(details.changed) ? details.changed : Object.keys(after).filter(function (k) { return String(before[k] ?? '') !== String(after[k] ?? ''); });
    var rows = changed.map(function (k) {
      return '<tr><td style="font-size:12px;white-space:nowrap">' + CF.esc(labels[k] || k) + '</td>'
        + '<td style="font-size:12px;white-space:pre-wrap;word-break:break-all">' + CF.esc(fmt(before[k])) + '</td>'
        + '<td style="font-size:12px;white-space:pre-wrap;word-break:break-all;color:var(--primary)">' + CF.esc(fmt(after[k])) + '</td></tr>';
    }).join('');
    if (!rows) rows = '<tr><td colspan="3" style="font-size:12px;color:var(--text-secondary)">没有字段差异</td></tr>';
    var w = CF.overlay('<div style="width:760px;max-width:94vw;max-height:88vh;overflow:auto;background:var(--bg-card,#fff);border-radius:14px;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.25)">'
      + '<h3 style="margin:0 0 10px;font-size:17px">🔍 编辑前后对比</h3>'
      + '<div class="table-wrap"><table><thead><tr><th>字段</th><th>修改前</th><th>修改后</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
      + '<div style="text-align:right;margin-top:12px"><button type="button" class="btn btn-primary" id="diffClose">关闭</button></div></div>', 'cfDiffModal');
    w.querySelector('#diffClose').onclick = function () { w.remove(); };
    w.addEventListener('click', function (e) { if (e.target === w) w.remove(); });
  }

  function enhance() {
    var table = document.getElementById('logsTable');
    if (!table) return;
    table.querySelectorAll('tr').forEach(function (tr) {
      if (tr.getAttribute('data-diff-applied') === '1') return;
      var cells = tr.querySelectorAll('td');
      if (cells.length < 5) return;
      var raw = (cells[4].getAttribute('title') || cells[4].textContent || '').trim();
      if (raw.charAt(0) !== '{') return;
      var obj = null;
      try { obj = JSON.parse(raw); } catch (e) { return; }
      if (!obj || typeof obj !== 'object' || !obj.before || !obj.after) return;
      tr.setAttribute('data-diff-applied', '1');
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-outline btn-sm';
      b.textContent = '🔍 变更';
      b.style.marginLeft = '6px';
      b.onclick = function () { showDiff(obj); };
      cells[4].appendChild(b);
    });
  }

  function observe() {
    var table = document.getElementById('logsTable');
    if (!table || table.__diffObserver) return;
    table.__diffObserver = true;
    if (window.MutationObserver) new MutationObserver(enhance).observe(table, { childList: true, subtree: true });
    enhance();
  }

  function wrapSwitchPage() {
    if (!window.switchPage || window.switchPage.__logDiffWrapped) return;
    var orig = window.switchPage;
    var wrapped = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'logs') setTimeout(function () { observe(); enhance(); }, 320);
      return r;
    };
    wrapped.__logDiffWrapped = true;
    window.switchPage = wrapped;
  }

  function init() {
    if (!document.getElementById('logsTable')) return;
    observe();
    wrapSwitchPage();
    var page = document.getElementById('page-logs');
    if (page && page.classList.contains('active')) setTimeout(enhance, 400);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
