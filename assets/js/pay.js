/**
 * 班级班费管理系统 - 在线缴费面板（v1.8.1）
 * 依赖 app.js；switchPage('pay') 会调用 window._payInit()
 */
(function () {
  'use strict';

  var meta = null;

  function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function val(id) { var el = document.getElementById(id); return el ? String(el.value) : ''; }
  function set(id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; }
  function csrf() { return window._initialCsrf || ''; }
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

  function statusBadge(s, settled) {
    if (s === 'paid') return settled ? '<span class="badge badge-income">已支付·已核销</span>' : '<span class="badge badge-income">已支付</span> <span class="badge badge-role">待核销</span>';
    if (s === 'pending') return '<span class="badge badge-role">待支付</span>';
    return '<span class="badge badge-expense">已关闭</span>';
  }

  window._payInit = async function () {
    try {
      meta = await api('pay_meta');
      var cfg = meta.config || {};
      var sel = document.getElementById('payStudent');
      if (sel) {
        sel.innerHTML = '<option value="0">请选择姓名</option>' + (meta.roster || []).map(function (s) {
          return '<option value="' + parseInt(s.id, 10) + '">' + esc(s.name) + (parseInt(s.exempt, 10) === 1 ? '（免缴）' : '') + '</option>';
        }).join('');
      }
      var rnd = document.getElementById('payRound');
      if (rnd) {
        rnd.innerHTML = '<option value="0">本次班费（通用）</option>' + (meta.rounds || []).map(function (r) {
          var pp = parseFloat(r.per_person || 0);
          var label = (r.date || '') + ' ' + (r.description || '').slice(0, 14) + (pp > 0 ? (' 每人¥' + pp.toFixed(2)) : (' 共¥' + r.amount));
          return '<option value="' + parseInt(r.id, 10) + '">' + esc(label) + '</option>';
        }).join('');
        rnd.value = '0'; // 默认「本次班费（通用）」：金额 = 该生各轮每人应缴相加后的待缴余额
      }
      // 金额提示：随所选「轮次 / 学生」动态更新，与下单金额一致
      var upd = function () {
        var sid = val('payStudent');
        var rid = val('payRound');
        var fs = (meta.fee_status && meta.fee_status[sid]) || null;
        if (rid && rid !== '0') {
          var per = 0, rounds = meta.rounds || [];
          for (var i = 0; i < rounds.length; i++) {
            if (String(rounds[i].id) === rid) { per = parseFloat(rounds[i].per_person || 0) || 0; break; }
          }
          var t1 = per > 0 ? ('本轮每人应缴 ¥' + per.toFixed(2)) : '该轮次未设置每人应缴';
          if (fs) t1 += '　累计应缴 ¥' + Number(fs.due).toFixed(2) + ' / 已缴 ¥' + Number(fs.paid).toFixed(2);
          set('payAmountHint', t1);
        } else if (fs) {
          set('payAmountHint', '累计应缴 ¥' + Number(fs.due).toFixed(2) + '　已缴 ¥' + Number(fs.paid).toFixed(2) + '　待缴 ¥' + Number(fs.outstanding).toFixed(2));
        } else {
          set('payAmountHint', '请选择缴费学生，查看应缴 / 已缴 / 待缴金额');
        }
      };
      var rSel = document.getElementById('payRound');
      if (rSel) rSel.onchange = upd;
      var sSel = document.getElementById('payStudent');
      if (sSel) sSel.onchange = upd;
      upd();
      // 专属缴费链接 ?pay=<学生ID>：自动选中该生并刷新金额提示
      if (window.__payPreselect) {
        var pv = String(window.__payPreselect);
        if (sSel && sSel.querySelector('option[value="' + pv + '"]')) { sSel.value = pv; upd(); }
        window.__payPreselect = null;
        try { history.replaceState(null, '', location.pathname); } catch (e) { /* 忽略 */ }
      }

      // 支付方式下拉：仅显示当前可用通道支持的支付方式（名称取自配置）
      var labelOf = { alipay: '支付宝', wxpay: '微信支付', qqpay: 'QQ 钱包' };
      (cfg.methods || []).forEach(function (m) { if (m && m.code) labelOf[m.code] = m.label || m.code; });
      var avail = cfg.available_types || [];
      var chSel = document.getElementById('payChannel');
      if (chSel) {
        chSel.innerHTML = avail.length
          ? avail.map(function (t) { return '<option value="' + t + '">' + (labelOf[t] || t) + '</option>'; }).join('')
          : '<option value="">无可用支付方式</option>';
        if (cfg.default_type && avail.indexOf(cfg.default_type) >= 0) chSel.value = cfg.default_type;
      }
      var hint = document.getElementById('payConfigHint');
      if (hint) hint.textContent = cfg.enabled
        ? ('可用支付方式：' + (avail.map(function (t) { return labelOf[t] || t; }).join(' / ') || '无') + '　核销模式：' + (cfg.settle_mode === 'auto' ? '自动核销' : '班委手动确认核销'))
        : '⚠️ 支付通道尚未配置或未开启（请管理员在「安全分析 → 支付通道设置」中配置）';
      var btn = document.getElementById('btnPayCreate');
      if (btn) btn.disabled = !cfg.enabled || avail.length === 0;
      window._payLoadOrders();
    } catch (e) { toast(e.message, 'error'); }
  };

  window._payCreate = async function () {
    var btn = document.getElementById('btnPayCreate');
    try {
      var sid = val('payStudent');
      if (!sid || sid === '0') { toast('请先选择姓名', 'error'); return; }
      if (btn) btn.disabled = true;
      var d = await api('pay_create', {
        student_id: sid,
        round_id: val('payRound') || '0',
        channel: val('payChannel') || 'alipay'
      }, 'POST');
      var html = '<div class="card" style="max-width:360px;text-align:center">' +
        '<div class="card-label">订单号</div><div style="font-size:12px;word-break:break-all">' + esc(d.order_no) + '</div>' +
        '<div class="card-value" style="color:var(--danger)">¥' + Number(d.amount).toFixed(2) + '</div>';
      if (d.qrcode) {
        html += '<img src="' + esc(d.qrcode) + '" alt="支付二维码" style="width:200px;height:200px;margin:8px auto;display:block">';
      }
      if (d.payurl) {
        html += '<a class="btn btn-primary btn-sm" href="' + esc(d.payurl) + '" target="_blank" rel="noopener">打开支付页面</a>';
      }
      html += '<p style="font-size:11px;color:var(--text-secondary);margin-top:8px">支付完成后系统会自动核销，可稍后刷新查看</p></div>';
      set('payResult', html);
      toast('订单已创建，请扫码/打开支付');
      window._payLoadOrders();
    } catch (e) { toast(e.message, 'error'); }
    finally { if (btn) btn.disabled = false; }
  };

  window._payLoadOrders = async function () {
    var el = document.getElementById('payOrdersTable');
    if (!el || !window._initialUser) return;
    try {
      var d = await api('pay_orders', { status: val('payOrderStatus') });
      var s = d.summary || {};
      set('payOrderSummary', '已支付 ' + (s.paid_cnt || 0) + ' 笔 / ¥' + Number(s.paid_amount || 0).toFixed(2) +
        '　待支付 ' + (s.pending_cnt || 0) + ' 笔　待核销 ' + (s.unsettled_cnt || 0) + ' 笔');
      var rows = (d.orders || []).map(function (o) {
        var canSettle = (o.status === 'paid' && parseInt(o.settled, 10) === 0);
        var canCancel = (o.status === 'pending');
        var ops = '';
        if (canSettle) ops += '<button class="btn btn-success btn-sm" onclick="window._payConfirm(' + parseInt(o.id, 10) + ')">手动核销</button> ';
        if (canCancel) ops += '<button class="btn btn-outline btn-sm" onclick="window._payCancel(' + parseInt(o.id, 10) + ')">关闭</button>';
        return '<tr><td style="font-size:11px;word-break:break-all">' + esc(o.order_no) + '</td>' +
          '<td>' + esc(o.student_name || '-') + '</td>' +
          '<td>¥' + Number(o.amount).toFixed(2) + '</td>' +
          '<td>' + esc(o.channel || '-') + '</td>' +
          '<td>' + statusBadge(o.status, parseInt(o.settled, 10)) + '</td>' +
          '<td style="font-size:11px">' + esc(o.created_at || '') + '</td>' +
          '<td style="font-size:11px">' + esc(o.paid_at || '-') + '</td>' +
          '<td>' + ops + '</td></tr>';
      });
      el.innerHTML = rows.length
        ? '<table><thead><tr><th>订单号</th><th>学生</th><th>金额</th><th>渠道</th><th>状态</th><th>创建</th><th>支付</th><th>操作</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>'
        : '<div class="empty">暂无支付订单</div>';
    } catch (e) { el.innerHTML = '<div class="empty">' + esc(e.message) + '</div>'; }
  };

  window._payConfirm = async function (id) {
    try { await api('pay_confirm', { id: id }, 'POST'); toast('核销成功'); window._payLoadOrders(); }
    catch (e) { toast(e.message, 'error'); }
  };
  window._payCancel = async function (id) {
    try { await api('pay_cancel', { id: id }, 'POST'); toast('已关闭订单'); window._payLoadOrders(); }
    catch (e) { toast(e.message, 'error'); }
  };

  // ==================== 支付通道设置（管理员界面 · 多通道 · 可自定义支付方式） ====================
  function setv(id, v) { var el = document.getElementById(id); if (el && v !== undefined && v !== null) el.value = v; }
  var inpStyle = 'padding:6px 9px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)';
  function q1(sel, root) { return (root || document).querySelector(sel); }
  function qaAll(sel, root) { return (root || document).querySelectorAll(sel); }

  // 读取当前「支付方式」列表
  function currentMethods() {
    var out = [];
    var rows = qaAll('#payCfgMethods .pay-method');
    for (var i = 0; i < rows.length; i++) {
      var code = ((q1('.m-code', rows[i]) || {}).value || '').replace(/[^A-Za-z0-9_\-]/g, '');
      if (!code) continue;
      var label = (q1('.m-label', rows[i]) || {}).value || code;
      out.push({ code: code, label: label });
    }
    return out;
  }

  function methodRow(m) {
    m = m || {};
    var wrap = document.createElement('div');
    wrap.className = 'pay-method';
    wrap.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';
    wrap.innerHTML =
      '<input type="text" class="m-code" placeholder="代码，如 alipay" value="' + esc(m.code || '') + '" style="width:150px;' + inpStyle + '">' +
      '<input type="text" class="m-label" placeholder="显示名，如 支付宝" value="' + esc(m.label || '') + '" style="width:170px;' + inpStyle + '">' +
      '<button type="button" class="btn btn-outline btn-sm" onclick="this.closest(\'.pay-method\').remove();window._payRefreshTypes()">删除</button>';
    wrap.querySelector('.m-code').addEventListener('input', function () { window._payRefreshTypes(); });
    return wrap;
  }

  window._payCfgAddMethod = function (m) {
    var box = document.getElementById('payCfgMethods');
    if (!box) return;
    box.appendChild(methodRow(m));
    window._payRefreshTypes();
  };

  // 通道的支付方式复选框按当前方式列表重建（保留已勾选项）
  window._payRefreshTypes = function () {
    var methods = currentMethods();
    var rows = qaAll('#payCfgChannels .pay-channel');
    for (var i = 0; i < rows.length; i++) {
      var box = q1('.ch-types', rows[i]);
      if (!box) continue;
      var checked = [];
      var tcs = qaAll('.ch-type', rows[i]);
      for (var k = 0; k < tcs.length; k++) if (tcs[k].checked) checked.push(tcs[k].value);
      box.innerHTML = methods.length
        ? methods.map(function (m) {
            return '<label style="font-size:12px;margin-right:12px"><input type="checkbox" class="ch-type" value="' + m.code + '"' +
              (checked.indexOf(m.code) >= 0 ? ' checked' : '') + '> ' + esc(m.label || m.code) + '</label>';
          }).join('')
        : '<span style="font-size:12px;color:var(--text-secondary)">请先添加支付方式</span>';
    }
  };

  function renderChannelTypes(wrap, types) {
    var box = wrap.querySelector('.ch-types');
    var methods = currentMethods();
    box.innerHTML = methods.length
      ? methods.map(function (m) {
          return '<label style="font-size:12px;margin-right:12px"><input type="checkbox" class="ch-type" value="' + m.code + '"' +
            (types.indexOf(m.code) >= 0 ? ' checked' : '') + '> ' + esc(m.label || m.code) + '</label>';
        }).join('')
      : '<span style="font-size:12px;color:var(--text-secondary)">请先添加支付方式</span>';
  }

  // V免签不需要商户ID；网关填 vmqfox 服务地址、密钥填通讯密钥
  function syncDriverUi(wrap) {
    var drv = wrap.querySelector('.ch-driver');
    var pid = wrap.querySelector('.ch-pid');
    var key = wrap.querySelector('.ch-key');
    var vm = drv.value === 'vmqfox';
    pid.disabled = vm;
    pid.placeholder = vm ? 'V免签不需要' : '1000';
    key.placeholder = vm ? '通讯密钥（32位）' : (key.getAttribute('data-hint') || '请输入商户密钥');
    var gw = wrap.querySelector('.ch-gateway');
    if (vm) gw.setAttribute('data-ph', gw.placeholder);
    gw.placeholder = vm ? 'https://vmqfox.example.com' : (gw.getAttribute('data-ph') || 'https://pay.example.com');
  }

  function channelRow(ch) {
    ch = ch || {};
    var types = ch.types || [];
    var wrap = document.createElement('div');
    wrap.className = 'pay-channel';
    wrap.setAttribute('data-chid', ch.id || '');
    wrap.style.cssText = 'border:1px solid var(--border);border-radius:10px;padding:12px';
    wrap.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">' +
        '<b style="font-size:13px">通道</b>' +
        '<button type="button" class="btn btn-outline btn-sm" onclick="this.closest(\'.pay-channel\').remove()">删除</button>' +
      '</div>' +
      '<div style="display:grid;grid-template-columns:90px 1fr;gap:6px 10px;align-items:center">' +
        '<label style="font-size:12px">名称</label><input type="text" class="ch-name" placeholder="如：主通道" style="' + inpStyle + ';width:100%">' +
        '<label style="font-size:12px">类型</label><select class="ch-driver" style="' + inpStyle + ';width:100%">' +
          '<option value="epay">易支付</option>' +
          '<option value="vmqfox">V免签</option>' +
        '</select>' +
        '<label style="font-size:12px">网关</label><input type="text" class="ch-gateway" placeholder="https://pay.example.com" style="' + inpStyle + ';width:100%">' +
        '<label style="font-size:12px">商户ID</label><input type="text" class="ch-pid" placeholder="1000" style="' + inpStyle + ';width:100%">' +
        '<label style="font-size:12px">密钥</label><input type="password" class="ch-key" placeholder="留空表示不修改" autocomplete="new-password" style="' + inpStyle + ';width:100%">' +
        '<label style="font-size:12px">支付方式</label><div class="ch-types"></div>' +
        '<label style="font-size:12px">启用</label><label style="font-size:12px"><input type="checkbox" class="ch-enabled"> 启用此通道</label>' +
      '</div>';
    wrap.querySelector('.ch-name').value = ch.name || '';
    wrap.querySelector('.ch-gateway').value = ch.gateway || '';
    wrap.querySelector('.ch-pid').value = ch.pid || '';
    wrap.querySelector('.ch-key').placeholder = ch.key_set ? ('已设置（' + (ch.key_hint || '') + '），留空不修改') : '请输入商户密钥';
    wrap.querySelector('.ch-key').setAttribute('data-hint', wrap.querySelector('.ch-key').placeholder);
    wrap.querySelector('.ch-enabled').checked = !!ch.enabled;
    var drv = wrap.querySelector('.ch-driver');
    drv.value = ch.driver === 'vmqfox' ? 'vmqfox' : 'epay';
    syncDriverUi(wrap);
    drv.addEventListener('change', function () { syncDriverUi(wrap); });
    renderChannelTypes(wrap, types);
    return wrap;
  }

  window._payCfgAddChannel = function (ch) {
    var box = document.getElementById('payCfgChannels');
    if (!box) return;
    if (box.children.length >= 20) { toast('最多 20 个通道', 'error'); return; }
    if (!ch) ch = { id: 'ch' + Date.now().toString(36) + Math.floor(Math.random() * 1000), types: [] };
    box.appendChild(channelRow(ch));
  };

  window._paySettingsLoad = async function () {
    var st = document.getElementById('payCfgStatus');
    if (!st) return;
    try {
      var d = await api('pay_config_get');
      var c = d.config || {};
      setv('payCfgSettle', c.settle_mode);
      setv('payCfgDefaultType', c.default_type);
      setv('payCfgMin', c.min_amount);
      setv('payCfgMax', c.max_amount);
      setv('payCfgSitename', c.sitename);
      var en = document.getElementById('payCfgEnabled');
      if (en) en.checked = !!c.enabled;
      // 先渲染支付方式，再渲染通道（通道的方式复选框依赖方式列表）
      var mbox = document.getElementById('payCfgMethods');
      if (mbox) { mbox.innerHTML = ''; (c.methods || []).forEach(function (m) { mbox.appendChild(methodRow(m)); }); }
      var box = document.getElementById('payCfgChannels');
      if (box) { box.innerHTML = ''; (c.channels || []).forEach(function (ch) { box.appendChild(channelRow(ch)); }); }

      var methodLabel = {};
      currentMethods().forEach(function (m) { methodLabel[m.code] = m.label; });
      st.innerHTML = (c.configured
        ? '<span style="color:var(--success)">✅ 已启用并配置</span>'
        : (c.enabled ? '<span style="color:#f59e0b">⚠️ 已启用但配置不完整</span>' : '<span style="color:var(--text-secondary)">○ 未启用（不影响记账功能）</span>'))
        + '　可用支付方式：' + esc((c.available_types || []).map(function (t) { return methodLabel[t] || t; }).join(' / ') || '无')
        + (c.writable ? '' : '　<span style="color:var(--danger)">pay_config.php 不可写，请检查站点目录权限</span>');
      set('payCfgNotify', esc(c.notify_url || '-'));
      set('payCfgReturn', esc(c.return_url || '-'));
    } catch (e) { st.textContent = e.message; }
  };

  window._paySettingsSave = async function () {
    try {
      var methods = currentMethods();
      var channels = [];
      var rows = qaAll('#payCfgChannels .pay-channel');
      for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var types = [];
        var tcs = qaAll('.ch-type', r);
        for (var k = 0; k < tcs.length; k++) if (tcs[k].checked) types.push(tcs[k].value);
        channels.push({
          id: r.getAttribute('data-chid') || '',
          name: r.querySelector('.ch-name').value,
          driver: r.querySelector('.ch-driver').value,
          gateway: r.querySelector('.ch-gateway').value,
          pid: r.querySelector('.ch-pid').value,
          key: r.querySelector('.ch-key').value,
          types: types,
          enabled: r.querySelector('.ch-enabled').checked ? '1' : '0'
        });
      }
      var en = document.getElementById('payCfgEnabled');
      await api('pay_config_save', {
        settle_mode: val('payCfgSettle'),
        default_type: val('payCfgDefaultType'),
        min_amount: val('payCfgMin'),
        max_amount: val('payCfgMax'),
        sitename: val('payCfgSitename'),
        enabled: (en && en.checked) ? '1' : '0',
        methods: JSON.stringify(methods),
        channels: JSON.stringify(channels)
      }, 'POST');
      toast('支付设置已保存');
      window._paySettingsLoad();
    } catch (e) { toast(e.message, 'error'); }
  };

  // ==================== 催缴通知（v1.11） ====================
  var reminderData = null;

  function payLink(sid) {
    var base = location.origin + location.pathname;
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'pay=' + encodeURIComponent(sid);
  }

  function copyText(text, btn) {
    function done() {
      if (btn) { var old = btn.textContent; btn.textContent = '✅ 已复制'; setTimeout(function () { btn.textContent = old; }, 1200); }
      else { toast('已复制'); }
    }
    function fallback() {
      try {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        document.execCommand('copy'); ta.remove(); done();
      } catch (e) { toast('复制失败，请手动选择复制', 'error'); }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else { fallback(); }
  }

  function studentText(u) {
    var site = (meta && meta.config && meta.config.sitename) || '班级班费';
    return '【班费催缴】' + u.name + ' 同学：\n'
      + '累计应缴 ¥' + Number(u.due).toFixed(2) + '，已缴 ¥' + Number(u.paid).toFixed(2) + '，待缴 ¥' + Number(u.outstanding).toFixed(2) + '。\n'
      + '请点开在线缴费入口，选择本人姓名即可缴纳：\n' + payLink(u.id) + '\n（' + site + '）';
  }

  window._payReminderLoad = async function () {
    var el = document.getElementById('payReminderTable');
    if (el) el.innerHTML = '<div class="loading"><div class="spinner"></div><p>加载中...</p></div>';
    try {
      var d = await api('pay_reminders');
      reminderData = d;
      var sum = document.getElementById('payReminderSummary');
      if (sum) sum.textContent = d.count > 0 ? ('共 ' + d.count + ' 人未缴清，合计待缴 ¥' + Number(d.total).toFixed(2)) : '🎉 全部已缴清';
      var rows = (d.unpaid || []).map(function (u, i) {
        return '<tr>'
          + '<td>' + (i + 1) + '</td>'
          + '<td>' + esc(u.name) + '</td>'
          + '<td>¥' + Number(u.due).toFixed(2) + '</td>'
          + '<td>¥' + Number(u.paid).toFixed(2) + '</td>'
          + '<td style="color:var(--danger);font-weight:600">¥' + Number(u.outstanding).toFixed(2) + '</td>'
          + '<td style="white-space:nowrap">'
          + '<button class="btn btn-outline btn-sm" onclick="window._payReminderCopyOne(' + u.id + ',this)">📋 文案</button> '
          + '<button class="btn btn-outline btn-sm" onclick="window._payReminderCopyLink(' + u.id + ',this)">🔗 链接</button> '
          + '<a class="btn btn-outline btn-sm" href="' + esc(payLink(u.id)) + '" target="_blank" rel="noopener">💳 缴费</a>'
          + '</td></tr>';
      });
      if (el) el.innerHTML = rows.length
        ? '<table><thead><tr><th>#</th><th>姓名</th><th>应缴</th><th>已缴</th><th>待缴</th><th>操作</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>'
        : '<div class="empty" style="padding:20px;color:var(--success)">🎉 全部已缴清，无需催缴</div>';
      if (window._payReminderCfgLoad) window._payReminderCfgLoad();
    } catch (e) {
      if (el) el.innerHTML = '<div class="empty">' + esc(e.message) + '</div>';
    }
  };

  window._payReminderCopyAll = function (btn) {
    var text = reminderData && (reminderData.text || reminderData.summary);
    if (!text) { toast('请先点「生成催缴名单」', 'error'); return; }
    copyText(text, btn);
  };
  window._payReminderCopyOne = function (sid, btn) {
    var list = (reminderData && reminderData.unpaid) || [];
    var u = list.filter(function (x) { return String(x.id) === String(sid); })[0];
    if (!u) { toast('请先生成催缴名单', 'error'); return; }
    copyText(studentText(u), btn);
  };
  window._payReminderCopyLink = function (sid, btn) { copyText(payLink(sid), btn); };

  window._payReminderPush = async function (btn) {
    if (!confirm('确定把当前欠费汇总推送到群机器人？')) return;
    if (btn) btn.disabled = true;
    try {
      var d = await api('pay_reminder_send', {}, 'POST');
      toast(d.count > 0 ? ('已推送：' + d.count + ' 人待缴 ¥' + Number(d.total).toFixed(2)) : '全部已缴清，无需推送');
    } catch (e) { toast(e.message, 'error'); }
    finally { if (btn) btn.disabled = false; }
  };

  // 自定义消息推送（所有具备缴费查看权限的班委可用）
  window._payReminderPushText = async function (btn) {
    var el = document.getElementById('payReminderMsg');
    var text = el ? String(el.value || '').trim() : '';
    if (!text) { toast('请先输入要发送的内容', 'error'); return; }
    if (!confirm('确定把这条消息发送到群里？')) return;
    if (btn) btn.disabled = true;
    try {
      await api('pay_reminder_push_text', { text: text }, 'POST');
      toast('消息已发送到群');
      if (el) el.value = '';
    } catch (e) { toast(e.message, 'error'); }
    finally { if (btn) btn.disabled = false; }
  };

  // 管理员：群机器人推送配置
  window._payReminderCfgToggle = function () {
    var type = (document.getElementById('remCfgType') || {}).value || 'wecom';
    var isQq = type === 'qq';
    ['remCfgWebhookLabel', 'remCfgWebhook'].forEach(function (id) {
      var e = document.getElementById(id); if (e) e.style.display = isQq ? 'none' : '';
    });
    // QQ 专属设置整块显示 / 隐藏
    var qbox = document.getElementById('remCfgQqBox');
    if (qbox) qbox.style.display = isQq ? 'block' : 'none';
    var cb = document.getElementById('remCfgQqCallback');
    if (cb) cb.textContent = location.origin + '/api.php?action=qq_webhook';
    // 当前类型的获取方式提示
    var help = {
      wecom: '获取：企业微信群里点右上角「···」→ 群机器人 → 添加机器人 → 复制 Webhook 地址。',
      feishu: '获取：飞书群 → 设置 → 群机器人 → 添加机器人 → 自定义机器人 → 复制 Webhook 地址。',
      dingtalk: '获取：钉钉群 → 群设置 → 智能群助手 → 添加机器人 → 自定义 → 复制 Webhook 地址。',
      qq: '获取：QQ 开放平台创建机器人后，在「开发设置」获取 AppID / AppSecret；「推送目标」可添加多个方向：QQ用户（单聊 openid）/ QQ群（group_openid）/ 频道（channel_id）。',
      custom: '获取：由你的机器人服务 / 中转服务提供可接收 POST JSON 的 https 地址。'
    };
    set('remCfgCurrentHelp', '当前类型获取方式：' + (help[type] || ''));
    // 按类型给出 Webhook 占位示例
    var ph = {
      wecom: 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...',
      feishu: 'https://open.feishu.cn/open-apis/bot/v2/hook/...',
      dingtalk: 'https://oapi.dingtalk.com/robot/send?access_token=...',
      custom: 'https://... （接收 POST JSON）'
    };
    var w = document.getElementById('remCfgWebhook');
    if (w && !w.value && w.getAttribute('data-set') !== '1') w.placeholder = ph[type] || 'https://...';
  };

  // QQ 推送目标行（方向 + ID）
  function qqTargetRow(scope, id) {
    var wrap = document.createElement('div');
    wrap.className = 'qq-target';
    wrap.style.cssText = 'display:flex;gap:6px;align-items:center;flex-wrap:wrap';
    var opts = [['c2c', 'QQ 用户（单聊）'], ['group', 'QQ 群（群聊）'], ['channel', 'QQ 频道']];
    var sel = '<select class="qt-scope" style="padding:6px 9px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text);width:160px">'
      + opts.map(function (o) { return '<option value="' + o[0] + '"' + (scope === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('')
      + '</select>';
    wrap.innerHTML = sel
      + '<input type="text" class="qt-id" placeholder="openid / 频道ID" value="' + esc(id || '') + '" style="flex:1;min-width:170px;padding:6px 9px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)">'
      + "<button type=\"button\" class=\"btn btn-outline btn-sm\" onclick=\"this.closest('.qq-target').remove()\">删除</button>";
    return wrap;
  }
  window._payReminderCfgAddTarget = function (scope, id) {
    var box = document.getElementById('remCfgQqTargets');
    if (!box) return;
    if (box.children.length >= 20) { toast('最多 20 个目标', 'error'); return; }
    box.appendChild(qqTargetRow(scope || 'c2c', id || ''));
  };
  window._payReminderCopyText = function (id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    copyText(el.textContent || el.innerText || '', btn);
  };
  // 把监测到的目标 ID（群 openid / 用户 openid / 频道 ID）一键填入推送目标
  window._payReminderUseTarget = function (ev, tid) {
    var el = document.getElementById(tid);
    if (!el) return;
    var target = (el.textContent || '').trim();
    if (!target) return;
    var scope = ev === 'GROUP_AT_MESSAGE_CREATE' ? 'group' : (ev === 'C2C_MESSAGE_CREATE' ? 'c2c' : 'channel');
    if (typeof window._payReminderCfgAddTarget === 'function') window._payReminderCfgAddTarget(scope, target);
    toast('已填入推送目标，记得点「保存配置」');
  };

  // 自定义关键词回复行
  function qqReplyRow(k, v) {
    var wrap = document.createElement('div');
    wrap.className = 'qq-reply';
    wrap.style.cssText = 'display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap';
    wrap.innerHTML = '<input type="text" class="qr-key" placeholder="关键词，如 班规" value="' + esc(k || '') + '" style="width:130px;padding:6px 9px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)">'
      + '<textarea class="qr-val" rows="1" placeholder="回复内容" style="flex:1;min-width:200px;padding:6px 9px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)">' + esc(v || '') + '</textarea>'
      + "<button type=\"button\" class=\"btn btn-outline btn-sm\" onclick=\"this.closest('.qq-reply').remove()\">删除</button>";
    return wrap;
  }
  window._payReminderCfgAddReply = function (k, v) {
    var box = document.getElementById('remCfgCustomReplies');
    if (!box) return;
    if (box.children.length >= 20) { toast('最多 20 条自定义回复', 'error'); return; }
    box.appendChild(qqReplyRow(k || '', v || ''));
  };
  // 生成管理员绑定码（网页端，仅管理员；10 分钟有效、一次性）
  window._qqAdminCode = async function () {
    try {
      var d = await api('qq_admin_code', {}, 'POST');
      var el = document.getElementById('remCfgAdminCode');
      if (el) el.textContent = d.code || '-';
      var hint = document.getElementById('remCfgAdminCodeHint');
      if (hint) hint.textContent = '10 分钟内有效；在 QQ 里发送：绑定管理员 ' + (d.code || '');
      toast('绑定码已生成：绑定管理员 ' + (d.code || ''));
    } catch (e) { toast(e.message, 'error'); }
  };

  // 渲染指令面板（复选框）
  function renderQqCommands(cmds) {
    var list = document.getElementById('remCfgQqCmdList');
    if (!list) return;
    list.innerHTML = '';
    (cmds || []).forEach(function (cmd) {
      var lab = document.createElement('label');
      lab.style.cssText = 'font-size:12px;display:flex;align-items:center;gap:4px;white-space:nowrap';
      lab.innerHTML = '<input type="checkbox" class="qq-cmd" value="' + esc(cmd.key) + '"' + (cmd.enabled ? ' checked' : '') + (cmd.always ? ' disabled' : '') + '> '
        + esc(cmd.cmd) + '<span style="color:var(--text-secondary)">（' + esc(cmd.desc) + '）</span>';
      list.appendChild(lab);
    });
  }

  // 渲染 QQ 网关连接状态
  function renderGateway(g) {
    var el = document.getElementById('remCfgGateway');
    if (!el) return;
    if (!g) { el.textContent = '—'; return; }
    var ok = !!g.connected;
    var extra = '';
    if (g.node_ok === false) extra = '　<span style="color:var(--danger)">未检测到 Node.js 18+，网关无法自动启动</span>';
    else if (!ok) extra = '　<span style="color:var(--text-secondary)">正在自动启动…</span>';
    el.innerHTML = (ok
      ? '<span style="color:var(--success)">✅ 已连接</span>'
      : '<span style="color:var(--danger)">❌ 未连接</span>')
      + '<span style="color:var(--text-secondary)"> ' + esc(g.phase || '')
      + (g.error ? '：' + esc(g.error) : '')
      + (g.at ? '（' + esc(g.at) + '）' : '') + '</span>' + extra;
  }

  // 渲染 QQ 回调协议监测日志
  function renderWebhookLog(logs) {
    var box = document.getElementById('remCfgWebhookLog');
    if (!box) return;
    if (!logs || !logs.length) { box.innerHTML = '<div class="empty" style="padding:12px">暂无回调记录（保存 AppID/AppSecret 后，去 QQ 开放平台点「校验」即可看到）</div>'; return; }
    var rows = logs.map(function (l, i) {
      var ok = String(l.resp || '').indexOf('200') === 0;
      var tid = 'qqlog-target-' + i;
      var targetCell = l.target
        ? '<code id="' + tid + '" style="font-size:11px;word-break:break-all">' + esc(l.target) + '</code> '
          + '<button type="button" class="btn btn-outline btn-sm" onclick="window._payReminderCopyText(\'' + tid + '\',this)">📋</button> '
          + (l.event ? '<button type="button" class="btn btn-outline btn-sm" onclick="window._payReminderUseTarget(\'' + esc(l.event) + '\',\'' + tid + '\')">设为目标</button>' : '')
        : '<span style="color:var(--text-secondary)">-</span>';
      return '<tr>'
        + '<td style="font-size:11px;white-space:nowrap">' + esc(l.t || '') + '</td>'
        + '<td style="font-size:11px">' + esc(l.ip || '') + '</td>'
        + '<td style="font-size:11px">' + esc(l.ua || '') + '</td>'
        + '<td style="font-size:11px">op=' + esc(l.op) + (l.event ? ' / ' + esc(l.event) : '') + '</td>'
        + '<td style="font-size:11px">' + targetCell + '</td>'
        + '<td style="font-size:11px">' + (l.sig ? '有' : '无') + '</td>'
        + '<td style="font-size:11px;color:' + (ok ? 'var(--success)' : 'var(--danger)') + '" title="' + esc(l.body || '') + '">' + esc(l.resp || '') + '</td>'
        + '</tr>';
    });
    box.innerHTML = '<table><thead><tr><th>时间</th><th>来源 IP</th><th>UA</th><th>op / 事件</th><th>目标 ID（群 openid 等）</th><th>签名</th><th>结果（悬停看请求体）</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>';
  }
  window._payReminderCfgLoad = async function () {
    var box = document.getElementById('payReminderCfg');
    if (!box) return;
    try {
      var d = await api('pay_reminder_cfg_get');
      var c = d.config || {};
      var t = document.getElementById('remCfgType'); if (t) t.value = c.type || 'wecom';
      var en = document.getElementById('remCfgEnabled'); if (en) en.checked = !!c.enabled;
      var w = document.getElementById('remCfgWebhook');
      if (w) {
        w.value = '';
        w.setAttribute('data-set', c.webhook_set ? '1' : '0');
        w.placeholder = c.webhook_set ? ('已设置（' + (c.webhook_hint || '') + '），留空不修改') : 'https://... 群机器人 Webhook';
      }
      var qa = document.getElementById('remCfgQqAppid'); if (qa) qa.value = c.qq_appid || '';
      var qs = document.getElementById('remCfgQqSecret');
      if (qs) { qs.value = ''; qs.placeholder = c.qq_secret_set ? ('已设置（' + (c.qq_secret_hint || '') + '），留空不修改') : 'Bot AppSecret'; }
      var qbox = document.getElementById('remCfgQqTargets');
      if (qbox) {
        qbox.innerHTML = '';
        var tgs = c.qq_targets || [];
        if (!tgs.length && c.qq_target) tgs = [{ scope: c.qq_scope || 'group', id: c.qq_target }];
        for (var i = 0; i < tgs.length; i++) window._payReminderCfgAddTarget(tgs[i].scope, tgs[i].id);
      }
      var qb = document.getElementById('remCfgQqSandbox'); if (qb) qb.checked = !!c.qq_sandbox;
      var qco = document.getElementById('remCfgQqC2cOpen'); if (qco) qco.checked = (c.qq_c2c_open !== false);
      renderQqCommands(c.commands);
      var rbox = document.getElementById('remCfgCustomReplies');
      if (rbox) { rbox.innerHTML = ''; (c.qq_custom_replies || []).forEach(function (cr) { window._payReminderCfgAddReply(cr.k, cr.v); }); }
      var mi = document.getElementById('remCfgMenuIntro'); if (mi) mi.value = c.qq_menu_intro || '';
      var mf = document.getElementById('remCfgMenuFooter'); if (mf) mf.value = c.qq_menu_footer || '';
      var mp = document.getElementById('remCfgMenuPreview'); if (mp) mp.textContent = c.menu_preview || '-';
      renderWebhookLog(c.webhook_log);
      renderGateway(c.gateway);
      window._payReminderCfgToggle();
    } catch (e) { /* 忽略 */ }
  };
  window._payReminderCfgSave = async function () {
    try {
      var disabled = [], cmdBoxes = qaAll('#remCfgQqCmdList .qq-cmd');
      for (var ci = 0; ci < cmdBoxes.length; ci++) { if (!cmdBoxes[ci].checked && !cmdBoxes[ci].disabled) disabled.push(cmdBoxes[ci].value); }
      var replies = [], rrows = qaAll('#remCfgCustomReplies .qq-reply');
      for (var ri = 0; ri < rrows.length; ri++) {
        var rk = String((rrows[ri].querySelector('.qr-key') || {}).value || '').trim();
        var rv = String((rrows[ri].querySelector('.qr-val') || {}).value || '').trim();
        if (rk && rv) replies.push({ k: rk, v: rv });
      }
      await api('pay_reminder_cfg_save', {
        enabled: (document.getElementById('remCfgEnabled') || {}).checked ? '1' : '0',
        type: val('remCfgType'),
        webhook: val('remCfgWebhook'),
        qq_appid: val('remCfgQqAppid'),
        qq_secret: val('remCfgQqSecret'),
        qq_targets: JSON.stringify((function () {
          var out = [], rows = qaAll('#remCfgQqTargets .qq-target');
          for (var i = 0; i < rows.length; i++) {
            var sc = (rows[i].querySelector('.qt-scope') || {}).value || 'group';
            var id = String((rows[i].querySelector('.qt-id') || {}).value || '').trim();
            if (id) out.push({ scope: sc, id: id });
          }
          return out;
        })()),
        qq_cmd_disabled: JSON.stringify(disabled),
        qq_menu_intro: val('remCfgMenuIntro'),
        qq_menu_footer: val('remCfgMenuFooter'),
        qq_custom_replies: JSON.stringify(replies),
        qq_sandbox: (document.getElementById('remCfgQqSandbox') || {}).checked ? '1' : '0',
        qq_c2c_open: (document.getElementById('remCfgQqC2cOpen') || {}).checked ? '1' : '0'
      }, 'POST');
      toast('催缴推送设置已保存');
      window._payReminderCfgLoad();
    } catch (e) { toast(e.message, 'error'); }
  };

  // 专属缴费链接深链：?pay=<学生ID>
  function initPayDeepLink() {
    var m = /[?&]pay=(\d+)/.exec(location.search || '');
    if (!m) return;
    window.__payPreselect = String(parseInt(m[1], 10));
    function go() { if (window.switchPage) window.switchPage('pay'); }
    if (window._initialUser) { setTimeout(go, 250); return; }
    var app = document.getElementById('app');
    if (app && window.MutationObserver) {
      var obs = new MutationObserver(function () {
        if (app.classList.contains('active')) { obs.disconnect(); setTimeout(go, 350); }
      });
      obs.observe(app, { attributes: true, attributeFilter: ['class'] });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPayDeepLink);
  else initPayDeepLink();
})();
