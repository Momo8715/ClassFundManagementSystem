/**
 * 旧版直连凭证路径兼容（v1.13）：uploads/... -> api.php?action=tx_image&path=...
 * 配合 nginx 禁止 /uploads/ 直连，避免图片绕过登录访问。
 */
(function () {
  'use strict';
  function rewrite(root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('img[src]').forEach(function (el) {
      var raw = el.getAttribute('src') || '';
      if (/^\/?uploads\//.test(raw) && raw.indexOf('action=tx_image') === -1) {
        el.setAttribute('src', 'api.php?action=tx_image&path=' + encodeURIComponent(raw.replace(/^\//, '')));
      }
    });
    root.querySelectorAll('a[href]').forEach(function (el) {
      var raw = el.getAttribute('href') || '';
      if (/^\/?uploads\//.test(raw) && raw.indexOf('action=tx_image') === -1) {
        el.setAttribute('href', 'api.php?action=tx_image&path=' + encodeURIComponent(raw.replace(/^\//, '')));
      }
    });
  }
  function init() {
    rewrite(document.body);
    if (window.MutationObserver) {
      new MutationObserver(function (muts) {
        muts.forEach(function (m) {
          m.addedNodes.forEach(function (n) {
            if (n.nodeType === 1) rewrite(n);
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
