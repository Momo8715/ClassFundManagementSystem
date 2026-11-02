/**
 * 管理端：机器人通知队列 / Token 轮换（v1.18）
 */
(function () {
  'use strict';
  var CF = window.CF;
  function esc(s) { return CF ? CF.esc(s) : String(s == null ? '' : s); }
  async function load() {
    var box = document.getElementById('botNotifyPanel');
    if (!box) return;
    box.innerHTML = '加载中…';
    try {
      var d = await CF.get('bot_notifications');
      var s = d.summary || {};
      var rows = (d.items || []).map(function (it) {
        var push = it.push_status === 'sent' ? '已代推' : (it.push_status === 'failed' ? '代推失败' : '待代推');
        var ack = it.status === 'sent' ? '插件已收' : (it.status === 'failed' ? '插件失败' : '待插件');
        var content = String(it.content || '').replace(/\n/g, ' ');
        return '<tr><td>' + it.id + '</td><td style="font-size:11px">' + esc(it.event) + '</td>'
          + '<td style="max-width:300px;white-space:normal;font-size:11px">' + esc(content.slice(0, 120)) + '</td>'
          + '<td style="font-size:11px;white-space:nowrap">' + esc(ack) + '<br>' + esc(push) + '</td>'
          + '<td style="font-size:11px;white-space:nowrap">' + esc(it.created_at || '') + '</td>'
          + '<td>' + (it.push_status === 'failed' ? '<button type="button" class="btn btn-outline btn-sm" data-retry="' + it.id + '">重试</button>' : '') + '</td></tr>';
      }).join('');
      box.innerHTML = '<label style="display:flex;gap:6px;align-items:center;margin-bottom:8px"><input type="checkbox" id="botPushEnabledCb" ' + (d.push_enabled ? 'checked' : '') + '> 站点直接群推送（金额变动/到账）</label>'
        + '<div style="margin-bottom:8px">待插件确认 ' + (s.plugin_pending || 0) + ' 条 · 待代推 ' + (s.push_pending || 0)
        + ' 条 · 代推失败 ' + (s.push_failed || 0) + ' 条 · 共 ' + (s.total || 0) + ' 条'
        + (d.push_enabled ? '' : ' · 站点代推已关闭') + '</div>'
        + (rows ? '<div class="table-wrap"><table><thead><tr><th>ID</th><th>事件</th><th>内容</th><th>状态</th><th>时间</th><th>操作</th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<div class="empty" style="padding:10px">暂无通知</div>');
      var pushCb = document.getElementById('botPushEnabledCb');
      if (pushCb) pushCb.onchange = async function () {
        try { await CF.post('bot_push_toggle', { enabled: pushCb.checked ? 1 : 0 }); CF.toast('已保存群推送开关'); await load(); }
        catch (e) { CF.toast(e.message, 'error'); pushCb.checked = !pushCb.checked; }
      };
      box.querySelectorAll('[data-retry]').forEach(function (b) {
        b.onclick = async function () {
          b.disabled = true;
          try { await CF.post('bot_notification_retry', { id: b.getAttribute('data-retry') }); await load(); }
          catch (e) { CF.toast(e.message, 'error'); b.disabled = false; }
        };
      });
    } catch (e) { box.innerHTML = '<span style="color:var(--danger)">' + esc(e.message) + '</span>'; }
  }
  window._botNotifyLoad = load;
  window._botTokenRotate = async function () {
    if (!confirm('轮换后旧 Token 立即失效，所有机器人插件都要改成新 Token。确定？')) return;
    try {
      var d = await CF.post('bot_token_rotate', {});
      var inp = document.getElementById('botApiTokenInput');
      if (inp) inp.value = d.token || '';
      CF.toast('Token 已轮换');
    } catch (e) { CF.toast(e.message, 'error'); }
  };
  function init() { if (document.getElementById('botNotifyPanel')) load(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
