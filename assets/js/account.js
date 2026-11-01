/**
 * 账号安全（v1.12）：两步验证设置 / 备用码 / 首次登录强制改密
 */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };

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
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    });
    var json = null;
    try { json = await res.json(); } catch (e) {}
    if (!res.ok) throw new Error((json && json.error) || '请求失败（HTTP ' + res.status + '）');
    return json || {};
  }

  function copyText(text, btn) {
    var done = function () { if (btn) { var old = btn.textContent; btn.textContent = '✅ 已复制'; setTimeout(function () { btn.textContent = old; }, 1500); } };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(function () { prompt('请手动复制：', text); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { prompt('请手动复制：', text); }
      ta.remove();
    }
  }

  function downloadCodes(codes) {
    var text = '班级班费管理系统 - 两步验证备用码\n生成时间：' + new Date().toLocaleString() + '\n每个备用码只能使用一次，请离线保存。\n\n' + codes.join('\n') + '\n';
    var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'classfund-2fa-backup-codes.txt';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 3000);
  }

  function renderBackupCodes(codes) {
    var body = $('accountBody');
    if (!body) return;
    body.innerHTML = '<div style="padding:4px 0">'
      + '<h4 style="margin:0 0 8px;color:#f59e0b">⚠️ 请立即保存备用码</h4>'
      + '<p style="font-size:12px;color:var(--text-secondary);margin:0 0 10px">只显示这一次。验证器丢失时，每个备用码可使用一次。</p>'
      + '<pre style="background:var(--bg-main,#f8fafc);border:1px dashed var(--border,#e2e8f0);border-radius:8px;padding:12px;font-size:14px;line-height:1.8;white-space:pre-wrap">' + esc(codes.join('\n')) + '</pre>'
      + '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">'
      + '<button type="button" class="btn btn-outline btn-sm" id="codesCopy">📋 复制全部</button>'
      + '<button type="button" class="btn btn-outline btn-sm" id="codesDown">💾 下载 txt</button>'
      + '<button type="button" class="btn btn-primary btn-sm" id="codesDone" style="margin-left:auto">我已保存</button>'
      + '</div></div>';
    $('codesCopy').onclick = function () { copyText(codes.join('\n'), this); };
    $('codesDown').onclick = function () { downloadCodes(codes); };
    $('codesDone').onclick = function () {
      if (window._initialUser && window._initialUser.must_enable_2fa) { location.reload(); return; }
      loadStatus();
    };
  }

  function renderStatus(st) {
    var body = $('accountBody');
    if (!body) return;
    var on = !!st.enabled;
    var policyHtml = '';
    if (st.policy && st.policy.enabled && st.policy_applies) {
      if (!on) {
        policyHtml = '<div style="background:' + (st.policy.expired ? '#fef2f2' : '#fffbeb') + ';border:1px solid ' + (st.policy.expired ? '#fecaca' : '#fde68a') + ';color:' + (st.policy.expired ? '#b91c1c' : '#92400e') + ';border-radius:8px;padding:9px 11px;font-size:12px;line-height:1.6;margin-bottom:10px">'
          + (st.policy.expired ? '⚠️ 系统要求管理员开启两步验证，宽限期已过，当前账号已被限制使用。' : '系统要求管理员在 ' + esc(st.policy.grace_until || '') + ' 前开启两步验证。')
          + '</div>';
      }
    }
    var html = policyHtml + '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">'
      + '<div><b style="font-size:14px">' + (on ? '✅ 两步验证已开启' : '⚪ 两步验证未开启') + '</b>'
      + '<div style="font-size:11px;color:var(--text-secondary);margin-top:3px">账号：' + esc(st.account || '') + (on ? ' · 剩余备用码 ' + (st.remaining_backup_codes || 0) + ' 个' : '') + '</div></div>'
      + (on ? '<span class="badge badge-income">受保护</span>' : '<span class="badge badge-role">建议开启</span>') + '</div>';
    if (!on) {
      html += '<p style="font-size:12px;color:var(--text-secondary);line-height:1.7;margin:0 0 12px">开启后，登录时除密码外还需输入验证器（如 Microsoft Authenticator / Google Authenticator / 1Password）中的 6 位动态码。即使密码泄露，他人也无法登录。</p>'
        + '<button type="button" class="btn btn-primary" id="twofaStart">🔐 启用两步验证</button>';
    } else {
      html += '<div style="display:flex;gap:8px;flex-wrap:wrap">'
        + '<button type="button" class="btn btn-outline btn-sm" id="twofaBackup">🔁 重新生成备用码</button>'
        + (st.policy && st.policy.enabled && st.policy_applies ? '' : '<button type="button" class="btn btn-danger btn-sm" id="twofaDisable">关闭两步验证</button>')
        + '</div>';
    }
    body.innerHTML = html;
    if (!on && $('twofaStart')) $('twofaStart').onclick = startEnable;
    if (on && $('twofaBackup')) $('twofaBackup').onclick = function () { showVerifyForm('backup'); };
    if (on && $('twofaDisable')) $('twofaDisable').onclick = function () { showVerifyForm('disable'); };
  }

  async function loadStatus() {
    var body = $('accountBody');
    if (body) body.innerHTML = '<div class="loading"><div class="spinner"></div><p>加载中...</p></div>';
    try {
      var st = await post('twofa_setup', { op: 'status' });
      renderStatus(st);
    } catch (e) {
      if (body) body.innerHTML = '<div class="alert alert-error" style="margin:0">' + esc(e.message) + '</div>';
    }
  }

  async function startEnable() {
    var body = $('accountBody');
    try {
      var st = await post('twofa_setup', { op: 'start' });
      body.innerHTML = '<div style="padding:2px 0">'
        + '<h4 style="margin:0 0 8px">1. 在验证器中添加账号</h4>'
        + '<p style="font-size:12px;color:var(--text-secondary);margin:0 0 8px">支持 TOTP 的验证器 App 均可。手动输入下方密钥（不要分享给他人）：</p>'
        + '<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap">'
        + '<code style="flex:1;min-width:200px;background:var(--bg-main,#f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:9px 10px;font-size:14px;letter-spacing:1px;word-break:break-all">' + esc(st.secret) + '</code>'
        + '<button type="button" class="btn btn-outline btn-sm" id="secretCopy">复制密钥</button></div>'
        + '<div style="font-size:11px;color:var(--text-secondary);margin-bottom:12px;word-break:break-all">或粘贴链接：<br><code>' + esc(st.uri) + '</code> '
        + '<button type="button" class="btn btn-outline btn-sm" id="uriCopy" style="margin-left:4px">复制链接</button></div>'
        + '<h4 style="margin:0 0 8px">2. 输入验证器当前 6 位验证码</h4>'
        + '<input id="enableCode" inputmode="numeric" maxlength="6" placeholder="6 位验证码" style="width:100%;padding:11px 12px;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;font-size:20px;letter-spacing:6px;text-align:center;background:transparent;color:inherit">'
        + '<div id="enableErr" style="display:none;color:#ef4444;font-size:12px;margin-top:8px"></div>'
        + '<div style="display:flex;gap:8px;margin-top:12px"><button type="button" class="btn btn-outline" id="enableBack" style="flex:1">返回</button>'
        + '<button type="button" class="btn btn-primary" id="enableGo" style="flex:2">确认启用</button></div></div>';
      $('secretCopy').onclick = function () { copyText(st.secret, this); };
      $('uriCopy').onclick = function () { copyText(st.uri, this); };
      $('enableBack').onclick = loadStatus;
      $('enableCode').focus();
      $('enableGo').onclick = async function () {
        var code = ($('enableCode').value || '').trim();
        var err = $('enableErr');
        if (!code) { err.style.display = 'block'; err.textContent = '请输入验证码'; return; }
        this.disabled = true; this.textContent = '验证中...';
        try {
          var res = await post('twofa_setup', { op: 'confirm', code: code });
          renderBackupCodes(res.backup_codes || []);
        } catch (e) {
          err.style.display = 'block'; err.textContent = e.message;
          this.disabled = false; this.textContent = '确认启用';
        }
      };
    } catch (e) {
      toast(e.message, 'error');
      loadStatus();
    }
  }

  function showVerifyForm(mode) {
    var body = $('accountBody');
    var isDisable = mode === 'disable';
    body.innerHTML = '<h4 style="margin:0 0 10px">' + (isDisable ? '关闭两步验证' : '重新生成备用码') + '</h4>'
      + '<p style="font-size:12px;color:var(--text-secondary);margin:0 0 10px">为确认是本人操作，请输入当前密码和验证器动态码（或一个未使用的备用码）。</p>'
      + '<div class="form-group"><label>当前密码</label><input type="password" id="secPwd" autocomplete="current-password" style="width:100%"></div>'
      + '<div class="form-group"><label>验证码 / 备用码</label><input id="secCode" autocomplete="one-time-code" placeholder="6 位验证码或 XXXXX-XXXXX" style="width:100%"></div>'
      + '<div id="secErr" style="display:none;color:#ef4444;font-size:12px;margin-bottom:8px"></div>'
      + '<div style="display:flex;gap:8px"><button type="button" class="btn btn-outline" id="secBack" style="flex:1">返回</button>'
      + '<button type="button" class="btn ' + (isDisable ? 'btn-danger' : 'btn-primary') + '" id="secGo" style="flex:2">' + (isDisable ? '确认关闭' : '生成新备用码') + '</button></div>';
    $('secBack').onclick = loadStatus;
    $('secPwd').focus();
    $('secGo').onclick = async function () {
      var pwd = $('secPwd').value || '';
      var code = ($('secCode').value || '').trim();
      var err = $('secErr');
      if (!pwd || !code) { err.style.display = 'block'; err.textContent = '请填写密码和验证码'; return; }
      this.disabled = true; this.textContent = '处理中...';
      try {
        var res = await post('twofa_setup', { op: mode, password: pwd, code: code });
        if (res.backup_codes) renderBackupCodes(res.backup_codes);
        else { toast('已关闭两步验证'); loadStatus(); }
      } catch (e) {
        err.style.display = 'block'; err.textContent = e.message;
        this.disabled = false; this.textContent = isDisable ? '确认关闭' : '生成新备用码';
      }
    };
  }

  window._accountSecurityOpen = function () {
    var u = window._initialUser;
    if (!u || !u.id) { toast('访客不能设置账号安全', 'error'); return; }
    var modal = $('modalAccount');
    if (!modal) return;
    modal.classList.add('active');
    loadStatus();
  };

  // ==================== 首次登录强制改密 ====================
  function forcePasswordChange() {
    var u = window._initialUser;
    if (!u || !u.id || !u.must_change_password) return;
    var wrap = document.createElement('div');
    wrap.id = 'forcePwdPanel';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.7);display:flex;align-items:center;justify-content:center;padding:16px';
    wrap.innerHTML = '<div style="width:400px;max-width:94vw;background:var(--bg-card,#fff);border-radius:14px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.3)">'
      + '<h3 style="margin:0 0 6px;font-size:18px">🔑 首次登录请修改密码</h3>'
      + '<p style="margin:0 0 14px;font-size:12px;color:var(--text-secondary,#64748b)">为了账号安全，初始密码/管理员重置的密码必须修改后才能继续使用。</p>'
      + '<div class="form-group"><label>当前密码</label><input type="password" id="fpOld" autocomplete="current-password" style="width:100%"></div>'
      + '<div class="form-group"><label>新密码（至少 6 位）</label><input type="password" id="fpNew" autocomplete="new-password" style="width:100%"></div>'
      + '<div class="form-group"><label>确认新密码</label><input type="password" id="fpNew2" autocomplete="new-password" style="width:100%"></div>'
      + '<div id="fpErr" style="display:none;color:#ef4444;font-size:12px;margin-bottom:8px"></div>'
      + '<button type="button" id="fpGo" class="btn btn-primary" style="width:100%">修改并进入系统</button>'
      + '</div>';
    document.body.appendChild(wrap);
    $('fpOld').focus();
    $('fpGo').onclick = async function () {
      var oldP = $('fpOld').value || '';
      var n1 = $('fpNew').value || '';
      var n2 = $('fpNew2').value || '';
      var err = $('fpErr');
      if (!oldP || !n1) { err.style.display = 'block'; err.textContent = '请填写当前密码和新密码'; return; }
      if (n1.length < 6) { err.style.display = 'block'; err.textContent = '新密码至少 6 位'; return; }
      if (n1 !== n2) { err.style.display = 'block'; err.textContent = '两次输入的新密码不一致'; return; }
      this.disabled = true; this.textContent = '提交中...';
      try {
        await post('change_password', { old_pwd: oldP, new_pwd: n1 });
        location.reload();
      } catch (e) {
        err.style.display = 'block'; err.textContent = e.message;
        this.disabled = false; this.textContent = '修改并进入系统';
      }
    };
  }


  // ==================== 管理员 2FA 策略强制 ====================
  function forceEnable2fa() {
    var u = window._initialUser;
    if (!u || !u.id || !u.must_enable_2fa || u.must_change_password) return;
    var wrap = document.createElement('div');
    wrap.id = 'force2faPanel';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.75);display:flex;align-items:center;justify-content:center;padding:16px';
    wrap.innerHTML = '<div style="width:430px;max-width:94vw;background:var(--bg-card,#fff);border-radius:14px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.3)">'
      + '<h3 style="margin:0 0 6px;font-size:18px">🔐 管理员需要开启两步验证</h3>'
      + '<p style="margin:0 0 12px;font-size:12px;color:var(--text-secondary,#64748b);line-height:1.7">系统安全策略已过宽限期。开启后，登录时除密码外还需验证器动态码；请同时保存 8 个备用码。</p>'
      + '<button type="button" id="force2faGo" class="btn btn-primary" style="width:100%">立即设置两步验证</button>'
      + '<p style="margin:12px 0 0;font-size:11px;color:var(--text-secondary,#64748b);line-height:1.6">无法使用验证器？请联系另一位管理员，或在服务器运行 <code>php tools/reset_2fa.php --disable-policy</code> 后重新登录。</p>'
      + '</div>';
    document.body.appendChild(wrap);
    $('force2faGo').onclick = function () {
      wrap.remove();
      window._accountSecurityOpen && window._accountSecurityOpen();
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { forcePasswordChange(); forceEnable2fa(); });
  } else {
    forcePasswordChange();
    forceEnable2fa();
  }
})();
