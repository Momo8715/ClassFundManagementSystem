/**
 * 数据体检 / 对账中心（v1.13）
 */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var CF = window.CF;
  var fixLabels = {};
  var ignoredCount = 0;

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
      + '<div class="card"><div class="card-label">🩺 检查项</div><div class="card-value">' + (s.total || 0) + '</div><div class="card-sub">' + CF.esc(checkedAt || '') + '</div></div>';
    var chk = $('healthChecked');
    if (chk) chk.textContent = '检查时间：' + (checkedAt || '') + (ignoredCount ? ' · 已忽略 ' + ignoredCount + ' 项' : '');
    var bar = $('healthAckBar');
    if (bar) {
      bar.innerHTML = ignoredCount
        ? '<button type="button" class="btn btn-outline btn-sm" id="healthUnackAll">显示已忽略的 ' + ignoredCount + ' 项</button>'
        : '';
      if (ignoredCount && $('healthUnackAll')) {
        $('healthUnackAll').onclick = async function () {
          if (!confirm('恢复显示所有已忽略的体检项？')) return;
          try { await CF.post('health_unack', { all: 1 }); await load(); }
          catch (e) { CF.toast(e.message, 'error'); }
        };
      }
    }
  }

  function renderSections(sections) {
    var box = $('healthSections');
    if (!box) return;
    var html = '';
    (sections || []).forEach(function (sec) {
      if (!sec.items || !sec.items.length) return;
      html += '<div style="background:var(--bg-card);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow);margin-bottom:12px">'
        + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">'
        + '<h4 style="font-size:14px;margin:0">' + CF.esc(sec.title) + '</h4>'
        + severityBadge(sec.severity, sec.count) + '</div>';
      sec.items.forEach(function (it) {
        html += '<div style="border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:8px;background:var(--bg-main,#f8fafc)">'
          + '<div style="display:flex;align-items:flex-start;gap:8px;justify-content:space-between;flex-wrap:wrap">'
          + '<div style="flex:1;min-width:220px"><b style="font-size:13px">' + CF.esc(it.title) + '</b>'
          + '<div style="font-size:12px;color:var(--text-secondary);margin-top:3px;line-height:1.6">' + CF.esc(it.detail || '') + '</div></div>'
          + '<div style="display:flex;gap:6px;flex-wrap:wrap">'
          + (it.fix && window._canFixHealth ? '<button type="button" class="btn btn-outline btn-sm" data-fix="' + CF.esc(it.fix) + '">' + CF.esc(fixLabels[it.fix] || '修复') + '</button>' : '')
          + (it.key && window._canFixHealth ? '<button type="button" class="btn btn-outline btn-sm" data-ack="' + CF.esc(it.key) + '" data-code="' + CF.esc(it.code || '') + '" data-target="' + CF.esc(it.target || '') + '">忽略 30 天</button>' : '')
          + '</div></div></div>';
      });
      html += '</div>';
    });
    if (!html) html = '<div class="empty" style="padding:20px">✅ 未发现问题</div>';
    box.innerHTML = html;

    box.querySelectorAll('[data-fix]').forEach(function (btn) {
      btn.onclick = async function () {
        var action = btn.getAttribute('data-fix');
        var label = fixLabels[action] || action;
        try {
          var plan = await CF.post('health_fix', { action: action, dry_run: 1 });
          var p = plan.plan || {};
          var desc = Object.keys(p).filter(function (k) { return k !== 'action'; }).map(function (k) { return k + ': ' + p[k]; }).join('\n');
          if (!confirm('执行「' + label + '」？\n\n预演结果：\n' + (desc || '(无变化)') + '\n\n破坏性动作会先自动创建整站备份。')) return;
          btn.disabled = true; btn.textContent = '处理中...';
          var res = await CF.post('health_fix', { action: action });
          CF.toast('已执行：' + label + (res.result && res.result.backup ? '（已备份）' : ''));
          await load();
        } catch (e) {
          alert(e.message);
          btn.disabled = false; btn.textContent = label;
        }
      };
    });
    box.querySelectorAll('[data-ack]').forEach(function (btn) {
      btn.onclick = async function () {
        var reason = prompt('忽略原因（可选）：', '已确认，暂不处理');
        if (reason === null) return;
        try {
          await CF.post('health_ack', {
            item_key: btn.getAttribute('data-ack'),
            code: btn.getAttribute('data-code'),
            target: btn.getAttribute('data-target'),
            reason: reason,
            days: 30
          });
          await load();
        } catch (e) { CF.toast(e.message, 'error'); }
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
      var json = await CF.get('health_check');
      fixLabels = json.fixes || {};
      ignoredCount = Number(json.ignored || 0);
      renderSummary(json.summary, json.checked_at);
      renderSections(json.sections);
    } catch (e) {
      sections.innerHTML = '<div class="empty" style="padding:20px;color:var(--danger)">' + CF.esc(e.message) + '</div>';
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
