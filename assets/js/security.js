/**
 * 班级班费管理系统 - 安全分析面板增强（v1.8 细化版）
 * 依赖 app.js；app.js 的 renderSecurity 渲染完成后会调用 window._renderSecurityExtra(data)
 */
(function () {
  'use strict';

  var DETAIL_PER = 20;

  function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function escJs(v) { return String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }
  function csrf() { return window._initialCsrf || ''; }
  function val(id) { var el = document.getElementById(id); return el ? String(el.value) : ''; }
  function set(id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; }
  function empty(msg) { return '<div class="empty">' + msg + '</div>'; }
  function card(label, value, color, sub) {
    return '<div class="card"><div class="card-label">' + esc(label) + '</div>' +
      '<div class="card-value" style="color:' + color + '">' + esc(value) + '</div>' +
      (sub ? '<div class="card-sub">' + esc(sub) + '</div>' : '') + '</div>';
  }
  function table(headers, rows, emptyMsg) {
    if (!rows || !rows.length) return empty('✅ ' + emptyMsg);
    return '<table><thead><tr>' + headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') +
      '</tr></thead><tbody>' + rows.join('') + '</tbody></table>';
  }
  function toast(msg, type) {
    var n = document.createElement('div');
    n.className = 'toast ' + (type || 'success');
    n.textContent = msg;
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 3000);
  }

  async function api(action, data, method) {
    method = method || 'GET';
    var url = 'api.php?action=' + encodeURIComponent(action);
    var opts = { method: method, credentials: 'same-origin', headers: {} };
    data = data || {};
    if (method === 'GET') {
      var q = new URLSearchParams(data).toString();
      if (q) url += '&' + q;
    } else {
      data.csrf_token = csrf();
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      opts.body = new URLSearchParams(data).toString();
    }
    var resp = await fetch(url, opts);
    var json = {};
    try { json = await resp.json(); } catch (e) { /* 非 JSON */ }
    if (!resp.ok) throw new Error(json.error || ('请求失败（HTTP ' + resp.status + '）'));
    return json;
  }

  function filterParams(page) {
    return {
      range: val('secRange') || '7d',
      f_user: val('secFUser') || '0',
      f_result: val('secFResult'),
      f_kw: val('secFkw'),
      f_ip: val('secFip'),
      detail_page: page || 1,
      detail_per: DETAIL_PER
    };
  }

  // 二级分类（页签）切换：只显示当前分组，避免长页面滚动
  window._secTab = function (name) {
    try { localStorage.setItem('secTab', name); } catch (e) { /* 隐私模式忽略 */ }
    var panes = document.querySelectorAll('#page-security .sec-pane');
    for (var i = 0; i < panes.length; i++) {
      panes[i].style.display = (panes[i].getAttribute('data-pane') === name) ? '' : 'none';
    }
    var tabs = document.querySelectorAll('#secTabs [data-sectab]');
    for (var j = 0; j < tabs.length; j++) {
      var on = tabs[j].getAttribute('data-sectab') === name;
      tabs[j].className = on ? 'btn btn-primary btn-sm' : 'btn btn-outline btn-sm';
    }
    var f = document.getElementById('secFilters');
    if (f) f.style.display = (name === 'manage' || name === 'system') ? 'none' : '';
  };

  // app.js renderSecurity 渲染完成后回调
  window._renderSecurityExtra = function (d) {
    if (!d || !d.summary) return;
    var s = d.summary;

    // 用户下拉（仅初始化一次）
    var uSel = document.getElementById('secFUser');
    if (uSel && uSel.options.length <= 1 && d.filter_users) {
      uSel.innerHTML = '<option value="0">全部用户</option>' + d.filter_users.map(function (u) {
        return '<option value="' + parseInt(u.id, 10) + '">' + esc(u.username) + '</option>';
      }).join('');
    }
    if (document.getElementById('secRange') && d.range) {
      var rEl = document.getElementById('secRange');
      // app.js 默认请求无 range 参数，后端默认 7d；仅当控件未被用户改动时同步
      if (!rEl.__touched) rEl.value = d.range;
    }

    var riskColor = { high: 'var(--danger)', medium: '#f59e0b', low: 'var(--success)' }[s.risk_level] || 'var(--text)';
    var riskText = { high: '高危', medium: '中危', low: '安全' }[s.risk_level] || '—';

    set('securitySummary',
      card('🩺 风险等级', riskText, riskColor, '评分 ' + s.risk_score) +
      card('📊 总尝试', s.total, 'var(--primary)', s.range_label) +
      card('🔐 成功', s.success, 'var(--success)', '') +
      card('🚨 失败', s.fail, 'var(--danger)', '') +
      card('🌐 独立 IP', s.ips, '#0ea5e9', '') +
      card('👥 独立账号', s.users, 'var(--text)', '')
    );
    set('secSummaryExtra',
      card('🚫 可疑 IP', s.suspect_ip, '#f59e0b', '失败≥3次') +
      card('⛔ IP 黑名单', s.blocked, 'var(--danger)', '已拦截') +
      card('🚷 封禁账号', s.banned, '#f59e0b', '禁止登录') +
      card('🧾 安全事件', s.events, 'var(--primary)', s.range_label)
    );

    // 风险评分构成
    var rb = d.risk_breakdown || [];
    set('riskBreakdown', rb.length
      ? '<table><thead><tr><th>风险项</th><th>分值</th></tr></thead><tbody>' + rb.map(function (r) {
          return '<tr><td>' + esc(r.label) + '</td><td style="color:var(--danger)">+' + esc(r.score) + '</td></tr>';
        }).join('') + '</tbody></table>'
      : empty('未发现风险项'));

    // 用户安全画像
    set('userProfileTable', table(['用户', '成功', '失败', '独立IP', '独立指纹', '最后登录', '状态', '操作'],
      (d.user_profiles || []).map(function (r) {
        var banned = parseInt(r.banned, 10) === 1;
        var pid = parseInt(r.user_id, 10) || 0;
        return '<tr><td>' + esc(r.username) + '</td>' +
          '<td style="color:var(--success)">' + esc(r.ok) + '</td>' +
          '<td style="color:var(--danger)">' + esc(r.fail) + '</td>' +
          '<td>' + esc(r.ips) + '</td><td>' + esc(r.fps) + '</td>' +
          '<td style="font-size:11px">' + esc(r.last_login || '-') + '</td>' +
          '<td>' + (banned ? '<span class="badge badge-expense">已封禁</span>' : '<span class="badge badge-income">正常</span>') + '</td>' +
          '<td>' + (banned
            ? '<button class="btn btn-success btn-sm" onclick="window._secUnban(' + pid + ')">解封</button>'
            : '<button class="btn btn-danger btn-sm" onclick="window._secBan(' + pid + ',\'' + escJs(r.username) + '\')">封禁</button>') + '</td></tr>';
      }), '暂无登录数据'));

    // 登录时段分布
    var hourly = d.hourly || [];
    var maxTotal = 0;
    hourly.forEach(function (h) { maxTotal = Math.max(maxTotal, (h.s || 0) + (h.f || 0)); });
    set('hourlyTable', hourly.length
      ? '<table><thead><tr><th>时段</th><th>成功</th><th>失败</th><th style="width:45%">分布</th></tr></thead><tbody>' +
        hourly.map(function (h) {
          var tot = (h.s || 0) + (h.f || 0);
          var w = maxTotal > 0 ? Math.round(tot / maxTotal * 100) : 0;
          var sw = tot > 0 ? Math.round((h.s || 0) / tot * 100) : 0;
          return '<tr><td>' + (h.h < 10 ? '0' : '') + h.h + ':00</td>' +
            '<td style="color:var(--success)">' + (h.s || 0) + '</td>' +
            '<td style="color:var(--danger)">' + (h.f || 0) + '</td>' +
            '<td><div style="background:var(--danger);opacity:.25;height:10px;border-radius:5px;width:' + w + '%">' +
            '<div style="background:var(--success);height:10px;border-radius:5px;width:' + sw + '%"></div></div></td></tr>';
        }).join('') + '</tbody></table>'
      : empty('暂无数据'));

    // 新 IP 登录
    set('newIpTable', table(['用户', 'IP', '首次出现', '最后出现', '次数'], (d.new_ip_logins || []).map(function (r) {
      return '<tr><td>' + esc(r.username) + '</td><td>' + esc(r.ip) + '</td>' +
        '<td style="font-size:11px">' + esc(r.first_seen) + '</td>' +
        '<td style="font-size:11px">' + esc(r.last_seen) + '</td><td>' + esc(r.cnt) + '</td></tr>';
    }), '范围内无新 IP 登录'));

    // 可疑 IP（可一键封禁 / 下钻）
    set('ipRiskTable', table(['IP', '失败次数', '尝试账号', '最后尝试', '操作'], (d.ip_risk || []).map(function (r) {
      return '<tr><td>' + esc(r.ip) + '</td><td style="color:var(--danger)">' + esc(r.attempts) + '</td>' +
        '<td>' + esc(r.names || '') + '</td><td style="font-size:11px">' + esc(r.last_attempt) + '</td>' +
        '<td><button class="btn btn-outline btn-sm" onclick="window._secDrillIp(\'' + escJs(r.ip) + '\')">明细</button> ' +
        '<button class="btn btn-danger btn-sm" onclick="window._blockIp(\'' + escJs(r.ip) + '\')">封禁</button></td></tr>';
    }), '无可疑 IP'));

    // 同 IP 多账号
    set('ipMultiAccountTable', table(['IP', '账号数', '账号', '登录次数', '最后登录'], (d.multi_ip_accounts || []).map(function (r) {
      return '<tr><td>' + esc(r.ipv4_address) + '</td><td style="color:#f59e0b">' + esc(r.accounts) + '</td>' +
        '<td>' + esc(r.names) + '</td><td>' + esc(r.logins) + '</td><td style="font-size:11px">' + esc(r.last_login) + '</td></tr>';
    }), '无同 IP 多账号'));

    // 失败原因分布
    set('failReasonTable', table(['失败原因', '次数'], (d.fail_reasons || []).map(function (r) {
      return '<tr><td>' + esc(r.reason) + '</td><td style="color:var(--danger)">' + esc(r.cnt) + '</td></tr>';
    }), '无失败记录'));

    // IP 黑名单
    set('blockedIpTable', table(['IP', '原因', '添加时间', '操作'], (d.blocked_ips || []).map(function (r) {
      var id = parseInt(r.id, 10) || 0;
      return '<tr><td>' + esc(r.ip) + '</td><td>' + esc(r.reason || '-') + '</td>' +
        '<td style="font-size:11px">' + esc(r.created_at) + '</td>' +
        '<td><button class="btn btn-outline btn-sm" onclick="window._unblockIp(' + id + ')">解封</button></td></tr>';
    }), '暂无封禁 IP'));

    // 已封禁账号
    set('bannedUsersTable', table(['用户', '封禁理由', '操作'], (d.banned_users || []).map(function (r) {
      var id = parseInt(r.id, 10) || 0;
      return '<tr><td>' + esc(r.username) + '</td><td>' + esc(r.ban_reason || '-') + '</td>' +
        '<td><button class="btn btn-success btn-sm" onclick="window._secUnban(' + id + ')">解封</button></td></tr>';
    }), '无封禁账号'));

    // 安全事件
    set('securityEventsTable', table(['时间', '事件', '用户', 'IP', '详情'], (d.events || []).map(function (r) {
      return '<tr><td style="font-size:11px;white-space:nowrap">' + esc(r.created_at) + '</td>' +
        '<td><span class="badge badge-role">' + esc(r.event) + '</span></td>' +
        '<td>' + esc(r.username || '-') + '</td><td style="font-size:11px">' + esc(r.ipv4 || '-') + '</td>' +
        '<td style="font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' +
        esc(r.detail || '') + '">' + esc(r.detail || '-') + '</td></tr>';
    }), '暂无安全事件'));

    // 登录明细
    set('loginDetailTable', table(['时间', '用户', '结果', '失败原因', 'IP', '浏览器', '指纹', '操作'], (d.detail || []).map(function (r) {
      var ok = parseInt(r.success, 10) === 1;
      var fp = String(r.fingerprint || '').substring(0, 12);
      return '<tr><td style="font-size:11px;white-space:nowrap">' + esc(r.created_at) + '</td>' +
        '<td>' + esc(r.username) + '</td>' +
        '<td>' + (ok ? '<span class="badge badge-income">成功</span>' : '<span class="badge badge-expense">失败</span>') + '</td>' +
        '<td style="font-size:11px">' + esc(r.fail_reason || '-') + '</td>' +
        '<td style="font-size:11px">' + esc(r.ipv4_address || '-') + '</td>' +
        '<td style="font-size:11px">' + esc(r.browser_info || '-') + '</td>' +
        '<td style="font-size:10px">' + esc(fp) + '</td>' +
        '<td>' + (r.ipv4_address ? '<button class="btn btn-outline btn-sm" onclick="window._secDrillIp(\'' + escJs(r.ipv4_address) + '\')">筛选此IP</button>' : '') + '</td></tr>';
    }), '无匹配记录'));

    // 分页
    var pg = d.detail_page || 1, tp = d.detail_pages || 0;
    set('loginDetailPagination', tp > 1
      ? '<button class="btn btn-outline btn-sm" ' + (pg <= 1 ? 'disabled' : '') + ' onclick="window._secReload(' + (pg - 1) + ')">‹ 上一页</button>' +
        '<span style="margin:0 10px;font-size:12px">第 ' + pg + ' / ' + tp + ' 页（共 ' + d.detail_total + ' 条）</span>' +
        '<button class="btn btn-outline btn-sm" ' + (pg >= tp ? 'disabled' : '') + ' onclick="window._secReload(' + (pg + 1) + ')">下一页 ›</button>'
      : (d.detail_total ? '<span style="font-size:12px;color:var(--text-secondary)">共 ' + d.detail_total + ' 条</span>' : ''));

    // 支付通道设置（管理界面，pay.js 提供）
    if (window._paySettingsLoad) { try { window._paySettingsLoad(); } catch (e) {} }

    // 恢复上次查看的二级分类页签
    var tab = 'overview';
    try { tab = localStorage.getItem('secTab') || 'overview'; } catch (e) {}
    if (window._secTab) window._secTab(tab);
  };

  window._secReload = async function (page) {
    try {
      var d = await api('security_analysis', filterParams(page));
      window._renderSecurityExtra(d);
    } catch (e) { toast(e.message, 'error'); }
  };

  window._secReset = function () {
    var r = document.getElementById('secRange'); if (r) { r.value = '7d'; r.__touched = false; }
    var u = document.getElementById('secFUser'); if (u) u.value = '0';
    var res = document.getElementById('secFResult'); if (res) res.value = '';
    var kw = document.getElementById('secFkw'); if (kw) kw.value = '';
    var ip = document.getElementById('secFip'); if (ip) ip.value = '';
    window._secReload(1);
  };

  window._secDrillIp = function (ip) {
    var el = document.getElementById('secFip'); if (el) el.value = ip;
    var kw = document.getElementById('secFkw'); if (kw) kw.value = '';
    toast('已按 IP ' + ip + ' 筛选登录明细');
    window._secReload(1);
  };

  window._secExport = function () {
    var q = new URLSearchParams(filterParams(1)).toString();
    window.location = 'api.php?action=export_security_csv&' + q;
  };

  // 记录用户是否手动改过范围（避免被 app.js 默认请求覆盖）
  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'secRange') e.target.__touched = true;
  }, true);

  // 封禁/解封/黑名单
  window._blockIp = async function (ip) {
    try {
      if (!ip) { var input = document.getElementById('blockIpInput'); ip = input ? input.value : ''; }
      ip = String(ip || '').trim();
      if (!ip) { toast('请输入 IP 地址', 'error'); return; }
      var reasonEl = document.getElementById('blockIpReason');
      await api('blocked_ip', { ip: ip, reason: reasonEl ? reasonEl.value : '' }, 'POST');
      toast('已封禁 ' + ip);
      window._secReload(1);
    } catch (e) { toast(e.message, 'error'); }
  };
  window._unblockIp = async function (id) {
    try { await api('blocked_ip', { id: id }, 'DELETE'); toast('已解封'); window._secReload(1); }
    catch (e) { toast(e.message, 'error'); }
  };
  window._secUnban = async function (id) {
    try { await api('ban_user', { user_id: id, ban: 0 }, 'POST'); toast('已解封账号'); window._secReload(1); }
    catch (e) { toast(e.message, 'error'); }
  };
  window._secBan = async function (id, name) {
    var reason = window.prompt('封禁 ' + (name || '') + ' 的理由：', '');
    if (!reason) return;
    try { await api('ban_user', { user_id: id, ban: 1, reason: reason }, 'POST'); toast('已封禁'); window._secReload(1); }
    catch (e) { toast(e.message, 'error'); }
  };
})();
