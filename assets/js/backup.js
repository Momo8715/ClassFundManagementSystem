/**
 * 数据备份 / 恢复（v1.13）
 */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var CF = window.CF;

  function size(n) {
    n = Number(n || 0);
    if (n > 1024 * 1024) return (n / 1024 / 1024).toFixed(2) + ' MB';
    if (n > 1024) return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
  }

  function render(data) {
    var box = $('backupPanel');
    if (!box) return;
    var files = data.files || [];
    var last = data.last;
    var html = '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">'
      + '<button type="button" class="btn btn-primary btn-sm" id="backupNow">💾 立即备份</button>'
      + '<button type="button" class="btn btn-outline btn-sm" id="backupReload">🔄 刷新</button>'
      + '<span style="font-size:12px;color:var(--text-secondary)">保留份数</span>'
      + '<input type="number" id="backupRetention" min="1" max="90" value="' + (data.retention || 14) + '" style="width:70px;padding:5px 8px;border:1px solid var(--border);border-radius:6px;background:transparent;color:var(--text)">'
      + '<button type="button" class="btn btn-outline btn-sm" id="backupRetentionSave">保存</button>'
      + '</div>'
      + '<div id="backupMsg" style="font-size:12px;color:var(--text-secondary);margin-bottom:8px">'
      + (last ? ('最近备份：' + CF.esc(last.mtime) + ' · ' + CF.esc(last.filename) + ' · ' + size(last.size)) : '还没有备份，建议先创建一份')
      + '</div>'
      + '<div style="background:var(--bg-main,#f8fafc);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:11px;line-height:1.8;margin-bottom:12px">'
      + '<div>备份内容：数据库全部表（JSONL 可回放）+ <code>uploads/</code> 凭证文件 + manifest 校验信息。</div>'
      + '<div>定时任务（宝塔计划任务 → Shell 脚本，每天执行一次）：</div>'
      + '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:4px">'
      + '<code id="cronAllUrl" style="flex:1;min-width:220px;word-break:break-all">' + CF.esc(data.cron_all_url || '') + '</code>'
      + '<button type="button" class="btn btn-outline btn-sm" id="cronCopy">复制</button>'
      + '</div></div>';

    if (!files.length) {
      html += '<div class="empty" style="padding:14px">暂无备份文件</div>';
    } else {
      html += '<div class="table-wrap"><table><thead><tr><th>文件</th><th>大小</th><th>时间</th><th>内容</th><th>状态</th><th>操作</th></tr></thead><tbody>';
      files.forEach(function (f) {
        var m = f.manifest || {};
        var tables = m.table_count != null ? m.table_count : '-';
        var uploads = m.uploads_count != null ? m.uploads_count : '-';
        html += '<tr>'
          + '<td style="font-size:11px;word-break:break-all">' + CF.esc(f.filename) + '</td>'
          + '<td style="white-space:nowrap">' + size(f.size) + '</td>'
          + '<td style="font-size:11px;white-space:nowrap">' + CF.esc(f.mtime) + '</td>'
          + '<td style="font-size:11px">' + tables + ' 表 / ' + uploads + ' 文件</td>'
          + '<td>' + (f.status === 'ok' ? '<span class="badge badge-income">有效</span>' : '<span class="badge badge-expense">损坏</span>') + '</td>'
          + '<td style="white-space:nowrap">'
          + '<button type="button" class="btn btn-outline btn-sm" data-dl="' + CF.esc(f.filename) + '">下载</button> '
          + '<button type="button" class="btn btn-danger btn-sm" data-restore="' + CF.esc(f.filename) + '">恢复</button> '
          + '<button type="button" class="btn btn-outline btn-sm" data-del="' + CF.esc(f.filename) + '">删除</button>'
          + '</td></tr>';
      });
      html += '</tbody></table></div>';
    }
    box.innerHTML = html;

    $('backupNow').onclick = createBackup;
    $('backupReload').onclick = load;
    $('cronCopy').onclick = function () { CF.copy(data.cron_all_url || '', this); };
    $('backupRetentionSave').onclick = async function () {
      try { await CF.post('backup_retention', { retention: $('backupRetention').value }); CF.toast('保留份数已保存'); await load(); }
      catch (e) { CF.toast(e.message, 'error'); }
    };
    box.querySelectorAll('[data-dl]').forEach(function (b) {
      b.onclick = function () { window.open('api.php?action=backup_download&file=' + encodeURIComponent(b.getAttribute('data-dl')), '_blank'); };
    });
    box.querySelectorAll('[data-restore]').forEach(function (b) {
      b.onclick = async function () {
        var file = b.getAttribute('data-restore');
        var confirmText = prompt('恢复会用备份覆盖当前数据库和同名 uploads 文件。\n请输入 RESTORE 确认：');
        if (confirmText !== 'RESTORE') return;
        b.disabled = true; b.textContent = '恢复中...';
        try {
          var res = await CF.post('backup_restore', { file: file, confirm: 'RESTORE' });
          CF.toast('恢复完成，正在刷新…');
          $('backupMsg').textContent = '恢复完成；恢复前备份：' + (res.result && res.result.safety_backup ? res.result.safety_backup : '—');
          setTimeout(function () { location.reload(); }, 1800);
        } catch (e) {
          CF.toast(e.message, 'error');
          b.disabled = false; b.textContent = '恢复';
        }
      };
    });
    box.querySelectorAll('[data-del]').forEach(function (b) {
      b.onclick = async function () {
        if (!confirm('删除备份 ' + b.getAttribute('data-del') + '？')) return;
        try { await CF.post('backup_delete', { file: b.getAttribute('data-del') }); await load(); }
        catch (e) { CF.toast(e.message, 'error'); }
      };
    });
  }

  async function createBackup() {
    var btn = $('backupNow');
    if (btn) { btn.disabled = true; btn.textContent = '备份中…'; }
    var msg = $('backupMsg');
    if (msg) msg.textContent = '正在备份数据库与 uploads，请稍候…';
    try {
      var res = await CF.post('backup_create', {});
      CF.toast('备份完成：' + (res.backup && res.backup.filename ? res.backup.filename : ''));
      await load();
    } catch (e) {
      CF.toast(e.message, 'error');
      if (msg) msg.textContent = '备份失败：' + e.message;
      if (btn) { btn.disabled = false; btn.textContent = '💾 立即备份'; }
    }
  }

  async function load() {
    var box = $('backupPanel');
    if (!box) return;
    box.innerHTML = '<div class="loading"><div class="spinner"></div><p>加载中...</p></div>';
    try { render(await CF.get('backup_list')); }
    catch (e) { box.innerHTML = '<div class="empty" style="padding:14px;color:var(--danger)">' + CF.esc(e.message) + '</div>'; }
  }

  window._backupLoad = load;

  function wrapSwitchPage() {
    if (!window.switchPage || window.switchPage.__backupWrapped) return;
    var orig = window.switchPage;
    var wrapped = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'config') setTimeout(load, 260);
      return r;
    };
    wrapped.__backupWrapped = true;
    window.switchPage = wrapped;
  }

  function init() {
    if (!$('backupPanel')) return;
    wrapSwitchPage();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
