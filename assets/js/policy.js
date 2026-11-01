/**
 * 管理员 2FA 强制策略（v1.13）
 */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var CF = window.CF;

  function render(data) {
    var box = $('twofaPolicyPanel');
    if (!box) return;
    var p = data.policy || {};
    var users = data.privileged_without_2fa || [];
    var html = '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">'
      + '<label style="display:flex;gap:8px;align-items:center;font-size:13px;font-weight:600"><input type="checkbox" id="policyEnabled" ' + (p.enabled ? 'checked' : '') + '> 要求管理员/班主任启用两步验证</label>'
      + '<span style="font-size:12px;color:var(--text-secondary)">宽限期</span>'
      + '<input type="number" id="policyDays" min="0" max="90" value="7" style="width:70px;padding:5px 8px;border:1px solid var(--border);border-radius:6px;background:transparent;color:var(--text)">'
      + '<span style="font-size:12px;color:var(--text-secondary)">天</span>'
      + '<button type="button" class="btn btn-primary btn-sm" id="policySave">保存策略</button>'
      + '<button type="button" class="btn btn-outline btn-sm" id="policyReload">刷新</button>'
      + '</div>'
      + '<div style="font-size:12px;color:var(--text-secondary);line-height:1.8;margin-bottom:10px">'
      + (p.enabled
          ? ('当前：已启用；宽限截止 <b>' + CF.esc(p.grace_until || '—') + '</b>' + (p.expired ? '（已到期，未启用 2FA 的管理员将被限制使用）' : '（宽限期内仍可使用）'))
          : '当前：未启用（仅作为健康检查建议）')
      + '</div>'
      + '<div style="font-size:12px;font-weight:600;margin-bottom:6px">尚未启用 2FA 的管理员/班主任（' + users.length + '）</div>';
    if (!users.length) {
      html += '<div class="empty" style="padding:12px">✅ 全部已启用</div>';
    } else {
      html += '<div class="table-wrap"><table><thead><tr><th>账号</th><th>操作</th></tr></thead><tbody>';
      users.forEach(function (u) {
        html += '<tr><td>' + CF.esc(u.username) + '</td><td style="font-size:11px;color:var(--text-secondary)">登录后到「🔐 账号安全」启用</td></tr>';
      });
      html += '</tbody></table></div>';
    }
    html += '<div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:9px 11px;font-size:11px;line-height:1.7;margin-top:10px">'
      + '注意：宽限期到期后，未启用 2FA 的管理员只能访问「账号安全 / 修改密码 / 退出」，其他接口会被拒绝。'
      + '请先确保至少一位管理员已保存备用码；如被锁死，可用服务器脚本 <code>php tools/reset_2fa.php --disable-policy</code> 恢复。'
      + '</div>';
    box.innerHTML = html;

    $('policySave').onclick = async function () {
      var enabled = $('policyEnabled').checked;
      var days = parseInt($('policyDays').value || '7', 10);
      if (enabled && !confirm('启用后，宽限期到期会限制未开启 2FA 的管理员使用。确定？')) return;
      try {
        var res = await CF.post('twofa_policy', { enabled: enabled ? 1 : 0, grace_days: days });
        CF.toast('策略已保存');
        if (res.must_enable && window._accountSecurityOpen) {
          alert('策略已生效：当前账号尚未启用 2FA，请立即设置，否则其他页面会被限制。');
          window._accountSecurityOpen();
        }
        await load();
      } catch (e) { CF.toast(e.message, 'error'); }
    };
    $('policyReload').onclick = load;
  }

  async function load() {
    var box = $('twofaPolicyPanel');
    if (!box) return;
    box.innerHTML = '<div class="loading"><div class="spinner"></div><p>加载中...</p></div>';
    try { render(await CF.get('twofa_policy')); }
    catch (e) { box.innerHTML = '<div class="empty" style="padding:14px;color:var(--danger)">' + CF.esc(e.message) + '</div>'; }
  }

  window._twofaPolicyLoad = load;

  function wrapSwitchPage() {
    if (!window.switchPage || window.switchPage.__policyWrapped) return;
    var orig = window.switchPage;
    var wrapped = function (page) {
      var r = orig.apply(this, arguments);
      if (page === 'config') setTimeout(load, 260);
      return r;
    };
    wrapped.__policyWrapped = true;
    window.switchPage = wrapped;
  }

  function init() {
    if (!$('twofaPolicyPanel')) return;
    wrapSwitchPage();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
