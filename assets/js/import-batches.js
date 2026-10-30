/**
 * 导入批次列表与整批撤销（v1.12）
 */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  async function post(action, data) {
    var body = new URLSearchParams();
    Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) body.append(k, data[k]); });
    body.append('csrf_token', window._initialCsrf || '');
    var res = await fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
    });
    var json = null;
    try { json = await res.json(); } catch (e) {}
    if (!res.ok) throw new Error((json && json.error) || '请求失败（HTTP ' + res.status + '）');
    return json || {};
  }

  function money(n) { return '¥' + Number(n || 0).toFixed(2); }

  async function load() {
    var box = $('importBatches');
    if (!box) return;
    box.innerHTML = '<div class="loading"><div class="spinner"></div><p>加载中...</p></div>';
    try {
      var res = await fetch('api.php?action=import_batches', { credentials: 'same-origin' });
      var json = await res.json();
      if (!res.ok) throw new Error(json.error || '加载失败');
      var list = json.batches || [];
      if (!list.length) {
        box.innerHTML = '<div class="empty" style="padding:14px">暂无导入批次（通过 xlsx 导入后会自动记录）</div>';
        return;
      }
      var html = '<div class="table-wrap"><table><thead><tr><th>批次号</th><th>来源</th><th>文件</th><th>行数</th><th>金额</th><th>导入人</th><th>时间</th><th>状态</th><th>操作</th></tr></thead><tbody>';
      list.forEach(function (b) {
        var undone = !!b.undone_at;
        var live = Number(b.live_count || 0);
        html += '<tr>'
          + '<td style="font-size:11px;white-space:nowrap">' + esc(b.batch_no) + '</td>'
          + '<td>' + (b.source === 'xlsx' ? '📥 Excel' : '🧾 JSON') + '</td>'
          + '<td style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(b.filename || '') + '">' + esc(b.filename || '-') + '</td>'
          + '<td>' + live + ' / ' + Number(b.row_count || 0) + '</td>'
          + '<td>' + money(b.live_amount) + '</td>'
          + '<td>' + esc(b.created_by_name || '-') + '</td>'
          + '<td style="font-size:11px;white-space:nowrap">' + esc(b.created_at || '') + '</td>'
          + '<td>' + (undone ? '<span class="badge badge-role">已撤销</span>' : '<span class="badge badge-income">有效</span>') + '</td>'
          + '<td>' + (!undone && live > 0 && window._canFixHealth ? '<button type="button" class="btn btn-outline btn-sm" data-undo="' + esc(b.batch_no) + '">撤销整批</button>' : '-') + '</td>'
          + '</tr>';
      });
      html += '</tbody></table></div>';
      box.innerHTML = html;
      box.querySelectorAll('[data-undo]').forEach(function (btn) {
        btn.onclick = async function () {
          var no = btn.getAttribute('data-undo');
          if (!confirm('撤销批次 ' + no + '？\n该批次的收支会移入回收站，可逐条恢复。')) return;
          btn.disabled = true; btn.textContent = '撤销中...';
          try { await post('import_undo', { batch_no: no }); await load(); }
          catch (e) { alert(e.message); btn.disabled = false; btn.textContent = '撤销整批'; }
        };
      });
    } catch (e) {
      box.innerHTML = '<div class="empty" style="padding:14px;color:var(--danger)">' + esc(e.message) + '</div>';
    }
  }

  window._importBatchesLoad = load;

  function wrapSwitchPage() {
    if (!window.switchPage || window.switchPage.__importBatchesWrapped) return;
    var orig = window.switchPage;
    var wrapped = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'import') setTimeout(load, 260);
      return r;
    };
    wrapped.__importBatchesWrapped = true;
    window.switchPage = wrapped;
  }

  function init() {
    if (!$('importBatches')) return;
    wrapSwitchPage();
    var page = $('page-import');
    if (page && page.classList.contains('active')) load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
