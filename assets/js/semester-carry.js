/**
 * 学期结转 / 归档（v1.12）
 *
 * 复用现有「学期管理」页签，在 #semesterCarry 中提供：
 *  - 归档并结转（可同时创建下一学期，期末余额作为下一学期期初）
 *  - 取消归档 / 重新结账
 * 不改压缩后的 app.js；仅包装 switchPage 与 _archiveSemester。
 */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var cache = [];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function money(n) { return '¥' + Number(n || 0).toFixed(2); }

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

  async function getSemesters() {
    var res = await fetch('api.php?action=semesters', { credentials: 'same-origin' });
    var json = await res.json();
    if (!res.ok) throw new Error(json.error || '加载学期失败');
    cache = json.semesters || [];
    return cache;
  }

  function fmtDate(d) {
    var y = d.getFullYear(), m = d.getMonth() + 1, day = d.getDate();
    return y + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
  }

  function defaultNext(sem) {
    var end = new Date((sem.end_date || '') + 'T00:00:00');
    if (isNaN(end.getTime())) end = new Date();
    var start = new Date(end.getTime());
    start.setDate(start.getDate() + 1);
    var end2 = new Date(start.getTime());
    end2.setMonth(end2.getMonth() + 6);
    end2.setDate(end2.getDate() - 1);
    var m = start.getMonth() + 1;
    var label;
    if (m >= 2 && m <= 7) label = start.getFullYear() + '春季学期';
    else if (m >= 8) label = start.getFullYear() + '秋季学期';
    else label = (start.getFullYear() - 1) + '秋季学期';
    return { name: label, start: fmtDate(start), end: fmtDate(end2) };
  }

  function render(list) {
    var box = $('semesterCarry');
    if (!box) return;
    if (!list.length) {
      box.innerHTML = '<div class="empty" style="padding:14px">暂无学期。先在上方新建学期，再使用「归档并结转」。</div>';
      return;
    }
    var active = list.filter(function (s) { return s.status === 'active'; });
    var archived = list.filter(function (s) { return s.status === 'archived'; });
    var html = '<div style="background:var(--bg-card);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow)">'
      + '<h4 style="font-size:14px;margin:0 0 4px">📦 学期结转 / 归档</h4>'
      + '<p style="font-size:11px;color:var(--text-secondary);margin:0 0 12px">归档会冻结该学期的期初/期末余额、收支与报表快照；归档期间的收支禁止手工修改。结转不生成额外收支记录，避免总账重复。</p>';

    if (active.length) {
      html += '<div style="font-size:12px;font-weight:600;margin:8px 0 6px;color:var(--primary)">进行中</div>';
      active.forEach(function (s) {
        html += '<div class="semester-card" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px">'
          + '<div><b>' + esc(s.name) + '</b><div style="font-size:11px;color:var(--text-secondary);margin-top:2px">' + esc(s.start_date) + ' ~ ' + esc(s.end_date) + ' · 当前结余 ' + money(s.end_balance) + '</div></div>'
          + '<button type="button" class="btn btn-primary btn-sm" data-archive="' + s.id + '">📦 归档并结转</button>'
          + '</div>';
      });
    }

    if (archived.length) {
      html += '<div style="font-size:12px;font-weight:600;margin:12px 0 6px;color:var(--text-secondary)">已归档（历史只读）</div>';
      archived.forEach(function (s) {
        var dirty = Number(s.snapshot_dirty) === 1 || s.stale;
        html += '<div class="semester-card" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;background:var(--bg-main,#f8fafc)">'
          + '<div><b>' + esc(s.name) + '</b> <span class="badge badge-role">已归档</span>'
          + (dirty ? ' <span class="badge badge-expense" style="background:#fef3c7;color:#b45309">快照待刷新</span>' : '')
          + '<div style="font-size:11px;color:var(--text-secondary);margin-top:2px">' + esc(s.start_date) + ' ~ ' + esc(s.end_date)
          + ' · 期初 ' + money(s.begin_balance) + ' · 期末 ' + money(s.end_balance)
          + (s.archived_at ? ' · 归档于 ' + esc(s.archived_at) : '') + '</div></div>'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap">'
          + '<button type="button" class="btn btn-outline btn-sm" data-rearchive="' + s.id + '">🔄 重新结账</button>'
          + '<button type="button" class="btn btn-outline btn-sm" data-unarchive="' + s.id + '">♻️ 取消归档</button>'
          + '</div></div>';
      });
    }
    html += '</div>';
    box.innerHTML = html;

    box.querySelectorAll('[data-archive]').forEach(function (b) {
      b.onclick = function () { openArchive(parseInt(b.getAttribute('data-archive'), 10)); };
    });
    box.querySelectorAll('[data-rearchive]').forEach(function (b) {
      b.onclick = async function () {
        if (!confirm('重新结账会按当前数据重算该学期的期初/期末余额与报表快照，确定？')) return;
        b.disabled = true;
        try { await post('semesters', { action: 'rearchive', id: parseInt(b.getAttribute('data-rearchive'), 10) }); await load(); }
        catch (e) { alert(e.message); b.disabled = false; }
      };
    });
    box.querySelectorAll('[data-unarchive]').forEach(function (b) {
      b.onclick = async function () {
        if (!confirm('取消归档后该学期恢复为进行中，历史数据可再次编辑。确定？')) return;
        b.disabled = true;
        try { await post('semesters', { action: 'unarchive', id: parseInt(b.getAttribute('data-unarchive'), 10) }); await load(); }
        catch (e) { alert(e.message); b.disabled = false; }
      };
    });
  }

  async function load() {
    if (!$('semesterCarry')) return;
    try { render(await getSemesters()); }
    catch (e) { $('semesterCarry').innerHTML = '<div class="empty" style="padding:12px;color:var(--danger)">' + esc(e.message) + '</div>'; }
  }

  async function openArchive(id) {
    var sem = cache.filter(function (s) { return Number(s.id) === Number(id); })[0];
    if (!sem) {
      try { await getSemesters(); sem = cache.filter(function (s) { return Number(s.id) === Number(id); })[0]; } catch (e) {}
    }
    if (!sem) { alert('学期不存在'); return; }
    var next = defaultNext(sem);
    var old = $('semesterArchiveModal');
    if (old) old.remove();
    var wrap = document.createElement('div');
    wrap.id = 'semesterArchiveModal';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px';
    wrap.innerHTML = '<div style="width:520px;max-width:94vw;background:var(--bg-card,#fff);border-radius:14px;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.3);max-height:90vh;overflow:auto">'
      + '<h3 style="margin:0 0 6px;font-size:18px">📦 归档并结转「' + esc(sem.name) + '」</h3>'
      + '<p style="font-size:12px;color:var(--text-secondary);margin:0 0 12px">' + esc(sem.start_date) + ' ~ ' + esc(sem.end_date) + ' · 预期期末结余 ' + money(sem.end_balance) + '</p>'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;font-weight:600;margin-bottom:10px"><input type="checkbox" id="carryCreateNext" checked> 同时创建下一学期并承接期末余额</label>'
      + '<div id="carryNextBox">'
      + '<div class="form-group"><label>下一学期名称</label><input id="carryName" value="' + esc(next.name) + '" style="width:100%"></div>'
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
      + '<div class="form-group"><label>开始日期</label><input type="date" id="carryStart" value="' + esc(next.start) + '" style="width:100%"></div>'
      + '<div class="form-group"><label>结束日期</label><input type="date" id="carryEnd" value="' + esc(next.end) + '" style="width:100%"></div>'
      + '</div></div>'
      + '<div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:9px 11px;font-size:11px;line-height:1.6;margin:4px 0 12px">归档后该学期的收支将只读；如后续仍收到在线缴费，快照会被标记为待刷新，可在「数据体检」重新结账。</div>'
      + '<div id="carryErr" style="display:none;color:#ef4444;font-size:12px;margin-bottom:8px"></div>'
      + '<div style="display:flex;gap:8px"><button type="button" class="btn btn-outline" id="carryCancel" style="flex:1">取消</button>'
      + '<button type="button" class="btn btn-primary" id="carryGo" style="flex:2">归档并结转</button></div>'
      + '</div>';
    document.body.appendChild(wrap);
    var chk = $('carryCreateNext');
    var box = $('carryNextBox');
    chk.onchange = function () { box.style.display = chk.checked ? '' : 'none'; };
    $('carryCancel').onclick = function () { wrap.remove(); };
    $('carryGo').onclick = async function () {
      var err = $('carryErr');
      var data = { action: 'archive', id: sem.id, create_next: chk.checked ? 1 : 0 };
      if (chk.checked) {
        data.next_name = ($('carryName').value || '').trim();
        data.next_start = $('carryStart').value;
        data.next_end = $('carryEnd').value;
        if (!data.next_name || !data.next_start || !data.next_end) {
          err.style.display = 'block'; err.textContent = '请填写下一学期名称和起止日期'; return;
        }
      }
      this.disabled = true; this.textContent = '归档中...';
      try { await post('semesters', data); location.reload(); }
      catch (e) { err.style.display = 'block'; err.textContent = e.message; this.disabled = false; this.textContent = '归档并结转'; }
    };
  }

  function wrapSwitchPage() {
    if (!window.switchPage || window.switchPage.__carryWrapped) return;
    var orig = window.switchPage;
    var wrapped = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'report') setTimeout(load, 260);
      return r;
    };
    wrapped.__carryWrapped = true;
    window.switchPage = wrapped;
  }

  function wrapArchive() {
    if (!window._archiveSemester || window._archiveSemester.__carryWrapped) return;
    var wrapped = function (id) { openArchive(id); };
    wrapped.__carryWrapped = true;
    window._archiveSemester = wrapped;
  }

  function init() {
    if (!$('semesterCarry')) return;
    wrapSwitchPage();
    wrapArchive();
    // 若直接落在报表页，也加载一次
    var page = $('page-report');
    if (page && page.classList.contains('active')) load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
