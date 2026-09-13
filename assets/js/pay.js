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
    wrap.querySelector('.ch-enabled').checked = !!ch.enabled;
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
})();
