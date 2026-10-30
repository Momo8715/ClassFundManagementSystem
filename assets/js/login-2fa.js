/**
 * 两步验证登录（v1.12）
 *
 * 不修改压缩后的 app.js：包装 window.doLogin。
 * - 密码通过但需要 2FA 时，弹出验证码面板；
 * - 登录成功统一 reload，让服务端按会话渲染应用（避免依赖 app.js 内部闭包）。
 */
(function () {
  'use strict';
  if (!window.doLogin || !document.getElementById('loginScreen')) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // 与 app.js 内 x() 完全一致的设备指纹（用于登录审计）
  function fingerprint() {
    var raw = [navigator.userAgent, navigator.language, screen.width + 'x' + screen.height,
      screen.colorDepth || 0, (new Date).getTimezoneOffset(), navigator.hardwareConcurrency || 0,
      navigator.deviceMemory || 0, navigator.platform || '', navigator.maxTouchPoints || 0].join('|');
    var a = 2166136261, r = 5381, o = 2166136261, i, c;
    for (i = 0; i < raw.length; i++) { c = raw.charCodeAt(i); a ^= c; a = Math.imul(a, 16777619) >>> 0; r = ((r << 5) + r + c) >>> 0; }
    for (i = raw.length - 1; i >= 0; i--) { c = raw.charCodeAt(i); o ^= c; o = Math.imul(o, 16777619) >>> 0; }
    function ph(n) { var s = (n >>> 0).toString(16); return '00000000'.substring(s.length) + s; }
    return ph(a) + ph(r) + ph(o);
  }

  async function post(action, data, withCsrf) {
    var body = new URLSearchParams();
    Object.keys(data || {}).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) body.append(k, data[k]); });
    if (withCsrf) body.append('csrf_token', window._initialCsrf || '');
    var res = await fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    });
    var json = null;
    try { json = await res.json(); } catch (e) { /* ignore */ }
    if (!res.ok) throw new Error((json && json.error) || '请求失败（HTTP ' + res.status + '）');
    return json || {};
  }

  function showError(msg) {
    var box = document.getElementById('loginError');
    if (box) { box.style.display = 'block'; box.textContent = msg; }
    var dbg = document.getElementById('loginDebug');
    if (dbg) dbg.style.display = 'none';
  }

  function showCodePanel(username, challenge) {
    var old = document.getElementById('twofaPanel');
    if (old) old.remove();
    var wrap = document.createElement('div');
    wrap.id = 'twofaPanel';
    wrap.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px';
    wrap.innerHTML = '<div style="width:380px;max-width:94vw;background:var(--bg-card,#fff);border-radius:14px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.25)">'
      + '<h3 style="margin:0 0 6px;font-size:18px">🔐 两步验证</h3>'
      + '<p style="margin:0 0 14px;font-size:13px;color:var(--text-secondary,#64748b)">账号 <b>' + esc(username) + '</b> 已启用两步验证，请输入验证器中的 6 位验证码。</p>'
      + '<input id="twofaCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6 位验证码" style="width:100%;padding:11px 12px;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;font-size:20px;letter-spacing:6px;text-align:center;background:transparent;color:inherit">'
      + '<div id="twofaErr" style="display:none;color:#ef4444;font-size:12px;margin-top:8px"></div>'
      + '<div style="display:flex;gap:8px;margin-top:14px">'
      + '<button type="button" id="twofaBack" class="btn btn-outline" style="flex:1">返回</button>'
      + '<button type="button" id="twofaGo" class="btn btn-primary" style="flex:2">验证并登录</button>'
      + '</div>'
      + '<p style="margin:12px 0 0;font-size:11px;color:var(--text-secondary,#64748b)">验证器丢失？可用启用时保存的备用码（格式 XXXXX-XXXXX）代替。</p>'
      + '</div>';
    document.body.appendChild(wrap);
    var input = document.getElementById('twofaCode');
    var err = document.getElementById('twofaErr');
    var go = document.getElementById('twofaGo');
    input.focus();
    document.getElementById('twofaBack').onclick = function () { wrap.remove(); };
    go.onclick = async function () {
      var code = (input.value || '').trim();
      if (!code) { err.style.display = 'block'; err.textContent = '请输入验证码'; return; }
      go.disabled = true; go.textContent = '验证中...';
      try {
        await post('login_2fa', { challenge: challenge, code: code, fingerprint: fingerprint() }, false);
        location.reload();
      } catch (e) {
        err.style.display = 'block'; err.textContent = e.message;
        go.disabled = false; go.textContent = '验证并登录';
        input.select();
      }
    };
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') go.click(); });
  }

  window.doLogin = async function () {
    if (window._loginBusy) return;
    var userEl = document.getElementById('loginUser');
    var passEl = document.getElementById('loginPass');
    var dbg = document.getElementById('loginDebug');
    var user = (userEl && userEl.value || '').trim();
    var pass = (passEl && passEl.value) || '';
    if (!user || !pass) { showError('请填写用户名和密码'); return; }
    window._loginBusy = true;
    if (dbg) { dbg.style.display = 'block'; dbg.textContent = '连接中...'; }
    var errBox = document.getElementById('loginError');
    if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }
    try {
      var res = await post('login', { username: user, pwd: pass, fingerprint: fingerprint(), client_2fa: 1 }, false);
      if (res && res.need_2fa) {
        if (dbg) dbg.style.display = 'none';
        showCodePanel(user, res.challenge);
      } else {
        location.reload();
      }
    } catch (e) {
      showError(e.message || '登录失败');
    } finally {
      window._loginBusy = false;
    }
  };
})();
