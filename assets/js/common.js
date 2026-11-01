/**
 * 班级班费管理系统 - 前端公共工具（v1.13）
 * 新模块统一使用 window.CF，避免每个模块复制 esc/post/toast/copy。
 */
(function () {
  'use strict';
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, kind) {
    var n = document.createElement('div');
    n.className = 'toast ' + (kind || 'success');
    n.textContent = String(msg == null ? '' : msg);
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 3200);
  }
  function csrf() { return window._initialCsrf || ''; }
  async function request(action, data, opts) {
    opts = opts || {};
    var method = (opts.method || 'POST').toUpperCase();
    var url = 'api.php?action=' + encodeURIComponent(action);
    var headers = {};
    var body = null;
    if (method === 'GET') {
      var qs = new URLSearchParams();
      Object.keys(data || {}).forEach(function (k) {
        if (data[k] !== undefined && data[k] !== null) qs.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
      });
      if (qs.toString()) url += '&' + qs.toString();
    } else if (opts.json) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(data || {});
    } else {
      var p = new URLSearchParams();
      Object.keys(data || {}).forEach(function (k) {
        if (data[k] !== undefined && data[k] !== null) p.append(k, typeof data[k] === 'object' ? JSON.stringify(data[k]) : data[k]);
      });
      if (opts.csrf !== false && csrf()) p.append('csrf_token', csrf());
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      body = p;
    }
    var res = await fetch(url, { method: method, credentials: 'same-origin', headers: headers, body: body });
    var json = null;
    try { json = await res.json(); } catch (e) {}
    if (!res.ok) {
      var err = new Error((json && json.error) || '请求失败（HTTP ' + res.status + '）');
      err.status = res.status;
      err.data = json;
      throw err;
    }
    return json || {};
  }
  function get(action, data) { return request(action, data, { method: 'GET' }); }
  function post(action, data, opts) { return request(action, data, Object.assign({ method: 'POST' }, opts || {})); }
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
  function money(n) { return '¥' + Number(n || 0).toFixed(2); }
  function copy(text, btn) {
    text = String(text == null ? '' : text);
    var done = function () {
      if (!btn) return;
      var old = btn.textContent;
      btn.textContent = '✅ 已复制';
      setTimeout(function () { btn.textContent = old; }, 1500);
    };
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
  function download(name, text) {
    var blob = new Blob([String(text == null ? '' : text)], { type: 'text/plain;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name || 'download.txt';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 3000);
  }
  function overlay(html, id) {
    var old = id ? document.getElementById(id) : null;
    if (old) old.remove();
    var w = document.createElement('div');
    if (id) w.id = id;
    w.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px';
    w.innerHTML = html;
    document.body.appendChild(w);
    return w;
  }
  window.CF = { esc: esc, toast: toast, request: request, get: get, post: post, fingerprint: fingerprint, money: money, copy: copy, download: download, overlay: overlay, csrf: csrf };
})();
