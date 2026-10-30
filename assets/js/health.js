/**
 * 数据体检 / 对账中心（v1.12）
 */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var fixLabels = {};

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function toast(msg, kind) {
    var n = document.createElement('div');
    n.className = 'toast ' + (kind || 'success');
    n.textContent = msg;
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 3200);
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

  function severityBadge(sev, count) {
    var map = {
      error: ['#fef2f2', '#dc2626', '❌ 错误'],
      warn: ['#fffbeb', '#b45309', '⚠️ 警告'],
      info: ['#eff6ff', '#2563eb', 'ℹ️ 提示']
    };
    var m = map[sev] || map.info;
    return '<span style="background:' + m[0] + ';color:' + m[1] + ';border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600">' + m[2] + (count ? ' ' + count : '') + '</span>';
  }

  function renderSummary(summary, checkedAt) {
    var box = $('healthSummary');
    if (!box) return;
    var s = summary || {};
    box.innerHTML = ''
      + '<div class="card"><div class="card-label">❌ 错误</div><div class="card-value expense">' + (s.error || 0) + '</div><div class="card-sub">需优先处理</div></div>'
      + '<div class="card"><div class="card-label">⚠️ 警告</div><div class="card-value" style="color:#f59e0b">' + (s.warn || 0) + '</div><div class="card-sub">建议核对</div></div>'
      + '<div class="card"><div class="card-label">ℹ️ 提示</div><div class="card-value" style="color:#2563eb">' + (s.info || 0) + '</div><div class="card-sub">可选优化</div></div>'
      + '<div class="card"><div class="card-label">🩺 检查项</div><div class="card-value">' + (s.total || 0) + '</div><div class="card-sub">' + esc(checkedAt || '') + '</div></div>';
    var chk = $('healthChecked');
    if (chk) chk.textContent = '检查时间：' + (checkedAt || '');
  }

  function renderSections(sections) {
    var box = $('healthSections');
    if (!box) return;
    if (!sections || !sections.length) {
      box.innerHTML = '<div class="empty" style="padding:20px">✅ 未发现问题</div>';
      return;
    }
    var html = '';
    sections.forEach(function (sec) {
      if (!sec.items || !sec.items.length) return;
      html += '<div style="background:var(--bg-card);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow);margin-bottom:12px">'
        + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">'
        + '<h4 style="font-size:14px;margin:0">' + esc(sec.title) + '</h4>'
        + severityBadge(sec.severity, sec.count) + '</div>';
      sec.items.forEach(function (it) {
        html += '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px;background:var(--bg-main,#f8fafc)">'
          + '<div style="display:flex;align-items:flex-start;gap:8px;justify-content:space-between;flex-wrap:wrap">'
          + '<div style="flex:1;min-width:220px"><b style="font-size:13px">' + esc(it.title) + '</b>'
          + '<div style="font-size:12px;color:var(--text-secondary);margin-top:3px;line-height:1.6">' + esc(it.detail || '') + '</div></div>'
          + (it.fix && window._canFixHealth ? '<button type="button" class="btn btn-outline btn-sm" data-fix="' + esc(it.fix) + '">' + esc(fixLabels[it.fix] || '修复') + '</button>' : '')
          + '</div></div>';
      });
      html += '</div>';
    });
    if (!html) html = '<div class="empty" style="padding:20px">✅ 未发现问题</div>';
    box.innerHTML = html;
    box.querySelectorAll('[data-fix]').forEach(function (btn) {
      btn.onclick = async function () {
        var action = btn.getAttribute('data-fix');
        var label = fixLabels[action] || action;
        if (!confirm('执行「' + label + '」？\n建议先确认相关数据，修复动作会写入操作日志。')) return;
        btn.disabled = true; btn.textContent = '处理中...';
        try {
          var res = await post('health_fix', { action: action });
          toast('已执行：' + label);
          await load();
          if (res && res.result) console.log('health_fix', res.result);
        } catch (e) {
          alert(e.message);
          btn.disabled = false; btn.textContent = label;
        }
      };
    });
  }

  async function load() {
    var summary = $('healthSummary');
    var sections = $('healthSections');
    if (!summary || !sections) return;
    summary.innerHTML = '<div class="empty" style="padding:14px">正在检查...</div>';
    sections.innerHTML = '';
    try {
      var res = await fetch('api.php?action=health_check', { credentials: 'same-origin' });
      var json = await res.json();
      if (!res.ok) throw new Error(json.error || '检查失败');
      fixLabels = json.fixes || {};
      renderSummary(json.summary, json.checked_at);
      renderSections(json.sections);
    } catch (e) {
      sections.innerHTML = '<div class="empty" style="padding:20px;color:var(--danger)">' + esc(e.message) + '</div>';
    }
  }

  window._healthLoad = load;

  function wrapSwitchPage() {
    if (!window.switchPage || window.switchPage.__healthWrapped) return;
    var orig = window.switchPage;
    var wrapped = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'health') setTimeout(load, 260);
      return r;
    };
    wrapped.__healthWrapped = true;
    window.switchPage = wrapped;
  }

  function init() {
    if (!$('healthSections')) return;
    wrapSwitchPage();
    var page = $('page-health');
    if (page && page.classList.contains('active')) load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
