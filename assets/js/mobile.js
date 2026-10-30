/**
 * 移动端优化：收支详情 + 发票/图片凭证查看（v1）
 * 设计：不改动 app.js（已压缩），通过监听表格渲染后注入「详情」入口与弹窗；
 *      数据源复用 app.js 暴露的 window.__lastTxResp（当前页交易列表）。
 */
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function imgUrl(v, thumb) {
    if (typeof v === 'number') return 'api.php?action=tx_image&id=' + v + (thumb ? '&thumb=1' : '');
    var s = String(v == null ? '' : v);
    if (/^\s*(?:javascript|vbscript|data)\s*:/i.test(s) && !/^\s*data:image\//i.test(s)) return '';
    return s;
  }
  function toast(msg, kind) {
    var n = document.createElement('div');
    n.className = 'toast ' + (kind || 'success');
    n.textContent = msg;
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 3000);
  }
  function money(n) { return '¥' + Number(n || 0).toFixed(2); }

  // ---------------- 数据 ----------------
  function txById(id) {
    var r = window.__lastTxResp || {};
    var list = r.transactions || [];
    for (var i = 0; i < list.length; i++) if (String(list[i].id) === String(id)) return list[i];
    return null;
  }
  function collectImages(t) {
    var out = [];
    function push(v) {
      if (v == null || v === '') return;
      if (Array.isArray(v)) { for (var i = 0; i < v.length; i++) if (v[i] !== '' && v[i] != null) out.push(v[i]); }
      else out.push(v);
    }
    try { if (t.image_ids) push(typeof t.image_ids === 'string' ? JSON.parse(t.image_ids) : t.image_ids); } catch (e) {}
    if (!out.length) { try { if (t.images) push(typeof t.images === 'string' ? JSON.parse(t.images) : t.images); } catch (e) {} }
    if (!out.length && t.image_path) out.push(t.image_path);
    return out;
  }
  function payerText(t) {
    if (!t.payer_ids) return '';
    if (t.payer_ids === 'all') return '全班';
    try {
      var a = typeof t.payer_ids === 'string' ? JSON.parse(t.payer_ids) : t.payer_ids;
      if (Array.isArray(a)) return a.length + ' 人';
    } catch (e) {}
    return String(t.payer_ids);
  }

  // ---------------- 详情抽屉 ----------------
  var sheet = null;
  function ensureSheet() {
    if (sheet) return sheet;
    sheet = document.createElement('div');
    sheet.className = 'txd-overlay';
    sheet.innerHTML = '<div class="txd-sheet" role="dialog" aria-modal="true">'
      + '<div class="txd-head"><h3>收支详情</h3>'
      + '<button class="txd-close" type="button" aria-label="关闭">✕</button></div>'
      + '<div id="txdBody"></div></div>';
    document.body.appendChild(sheet);
    sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });
    sheet.querySelector('.txd-close').addEventListener('click', closeSheet);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSheet(); closeViewer(); } });
    return sheet;
  }
  function closeSheet() { if (sheet) sheet.classList.remove('open'); }

  function openDetail(id) {
    var t = txById(id);
    if (t) { renderDetail(t); return; }
    toast('正在加载详情…', 'success');
    fetch('api.php?action=transactions&id=' + encodeURIComponent(id) + '&per_page=1', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var list = (j && j.transactions) || [];
        if (!list.length) { toast('未找到该记录，请刷新后重试', 'error'); return; }
        var box = window.__lastTxResp || (window.__lastTxResp = {});
        if (!box.transactions) box.transactions = [];
        box.transactions.push(list[0]);
        renderDetail(list[0]);
      })
      .catch(function () { toast('加载失败，请检查网络', 'error'); });
  }
  function renderDetail(t) {
    ensureSheet();
    var imgs = collectImages(t);
    var rows = [];
    function row(k, v) {
      if (v === '' || v == null || v === undefined) return;
      rows.push('<div class="txd-row"><span class="k">' + esc(k) + '</span><span class="v">' + esc(v) + '</span></div>');
    }
    row('日期', t.date);
    row('类型', t.type === 'income' ? '收入' : '支出');
    row('分类', t.category);
    row('子分类', t.sub_category);
    row('来源', t.source_info);
    row('记录人', t.recorder_name || '未知');
    row('描述', t.description);
    if (t.type === 'income' && t.sub_category === '班费收缴') {
      row('每人应缴', (t.per_person != null && t.per_person !== '') ? money(t.per_person) : '');
      row('应收总额', (t.expected_amount != null && t.expected_amount !== '') ? money(t.expected_amount) : '');
      row('缴费名单', payerText(t));
    }
    row('记录时间', t.created_at);
    if (t.updated_at && t.updated_at !== t.created_at) row('更新时间', t.updated_at);

    var html = '<div class="txd-amount ' + (t.type === 'income' ? 'income' : 'expense') + '">'
      + (t.type === 'income' ? '+' : '-') + money(t.amount) + '</div>'
      + '<div class="txd-rows">' + rows.join('') + '</div>';
    if (imgs.length) {
      html += '<div class="txd-sec"><b>发票 / 图片凭证（' + imgs.length + '）</b><div class="txd-thumbs">'
        + imgs.map(function (v, i) {
            return '<button type="button" class="txd-thumb" data-iv="' + i + '">'
              + '<img src="' + esc(imgUrl(v, true)) + '" alt="凭证" loading="lazy">'
              + '<span class="tag">点击查看</span></button>';
          }).join('') + '</div></div>';
    } else {
      html += '<div class="txd-sec" style="color:var(--text-secondary);font-size:12px">该记录没有上传图片凭证</div>';
    }
    var canEdit = !!document.querySelector('#transactionTable button[onclick*="_editTx(' + t.id + ')"], #transactionTable button[onclick*="_editTx(\'' + t.id + '\')"]');
    html += '<div class="txd-actions">'
      + '<button type="button" class="btn btn-outline btn-sm" id="txdReceipt">🧾 打印收据</button>'
      + (canEdit ? '<button type="button" class="btn btn-primary btn-sm" id="txdEdit">✏️ 编辑</button>' : '')
      + '</div>';
    $('txdBody').innerHTML = html;
    $('txdBody').querySelectorAll('.txd-thumb').forEach(function (b) {
      b.addEventListener('click', function () { openViewer(imgs, parseInt(b.getAttribute('data-iv'), 10) || 0); });
    });
    var rb = $('txdReceipt');
    if (rb) rb.addEventListener('click', function () { if (window._receipt) window._receipt(t.id); });
    var eb = $('txdEdit');
    if (eb) eb.addEventListener('click', function () { closeSheet(); if (window._editTx) window._editTx(t.id); });
    sheet.classList.add('open');
    var body = sheet.querySelector('.txd-sheet');
    if (body) body.scrollTop = 0;
  }
  window._txDetail = openDetail;
  window.closeTxDetail = closeSheet;

  // ---------------- 图片查看器（缩放/拖动/切换） ----------------
  var iv = null, ivList = [], ivIdx = 0, sc = 1, tx = 0, ty = 0, dragging = false, lastX = 0, lastY = 0, pinchDist = 0;

  function ensureViewer() {
    if (iv) return iv;
    iv = document.createElement('div');
    iv.className = 'iv-overlay';
    iv.innerHTML = '<div class="iv-bar"><span class="iv-title" id="ivTitle"></span>'
      + '<span class="iv-btns">'
      + '<button class="iv-btn" id="ivOut" title="缩小">➖</button>'
      + '<button class="iv-btn" id="ivIn" title="放大">➕</button>'
      + '<button class="iv-btn" id="ivClose" title="关闭">✕</button>'
      + '</span></div>'
      + '<div class="iv-stage" id="ivStage">'
      + '<button class="iv-nav prev" id="ivPrev" title="上一张">‹</button>'
      + '<img class="iv-img" id="ivImg" alt="凭证">'
      + '<button class="iv-nav next" id="ivNext" title="下一张">›</button>'
      + '</div><div class="iv-hint">双击 / 双指缩放 · 拖动查看 · 左右切换</div>';
    document.body.appendChild(iv);
    $('ivClose').addEventListener('click', closeViewer);
    $('ivIn').addEventListener('click', function () { zoomBy(1.5); });
    $('ivOut').addEventListener('click', function () { zoomBy(1 / 1.5); });
    $('ivPrev').addEventListener('click', function () { step(-1); });
    $('ivNext').addEventListener('click', function () { step(1); });
    var img = $('ivImg');
    img.addEventListener('dblclick', function () { if (sc > 1) resetZoom(); else zoomBy(2.4); });
    img.addEventListener('wheel', function (e) { e.preventDefault(); zoomBy(e.deltaY < 0 ? 1.15 : 1 / 1.15); }, { passive: false });
    var stage = $('ivStage');
    stage.addEventListener('touchstart', function (e) {
      if (e.touches.length === 2) {
        pinchDist = dist(e.touches);
      } else if (e.touches.length === 1 && sc > 1) {
        dragging = true; lastX = e.touches[0].clientX; lastY = e.touches[0].clientY;
      }
    }, { passive: true });
    stage.addEventListener('touchmove', function (e) {
      if (e.touches.length === 2) {
        var d = dist(e.touches);
        if (pinchDist > 0) { zoomBy(d / pinchDist); pinchDist = d; }
        e.preventDefault();
      } else if (dragging && e.touches.length === 1) {
        tx += e.touches[0].clientX - lastX;
        ty += e.touches[0].clientY - lastY;
        lastX = e.touches[0].clientX; lastY = e.touches[0].clientY;
        apply();
        e.preventDefault();
      }
    }, { passive: false });
    stage.addEventListener('touchend', function (e) {
      if (e.touches.length < 2) pinchDist = 0;
      if (e.touches.length === 0) dragging = false;
    }, { passive: true });
    stage.addEventListener('mousedown', function (e) { if (sc > 1) { dragging = true; lastX = e.clientX; lastY = e.clientY; e.preventDefault(); } });
    window.addEventListener('mousemove', function (e) {
      if (!dragging) return;
      tx += e.clientX - lastX; ty += e.clientY - lastY;
      lastX = e.clientX; lastY = e.clientY;
      apply();
    });
    window.addEventListener('mouseup', function () { dragging = false; });
    return iv;
  }
  function dist(t) { var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY; return Math.sqrt(dx * dx + dy * dy); }
  function zoomBy(f) {
    sc = Math.min(8, Math.max(1, sc * f));
    if (sc === 1) { tx = 0; ty = 0; }
    apply();
  }
  function resetZoom() { sc = 1; tx = 0; ty = 0; apply(); }
  function apply() {
    var img = $('ivImg');
    if (!img) return;
    img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + sc + ')';
    img.classList.toggle('zoomed', sc > 1);
  }
  function render() {
    if (!ivList.length) return;
    var img = $('ivImg');
    img.src = imgUrl(ivList[ivIdx], false);
    resetZoom();
    $('ivTitle').textContent = (ivIdx + 1) + ' / ' + ivList.length;
    $('ivPrev').disabled = ivList.length < 2;
    $('ivNext').disabled = ivList.length < 2;
  }
  function step(d) {
    if (ivList.length < 2) return;
    ivIdx = (ivIdx + d + ivList.length) % ivList.length;
    render();
  }
  function openViewer(list, idx) {
    ensureViewer();
    ivList = list || []; ivIdx = idx || 0;
    render();
    iv.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeViewer() {
    if (!iv) return;
    iv.classList.remove('open');
    document.body.style.overflow = '';
  }
  window.openTxImage = openViewer;
  window.closeTxImage = closeViewer;

  // ---------------- 表格增强 ----------------
  var CONTAINERS = ['transactionTable', 'dashboardRecent'];

  function rowId(tr) {
    var v = tr.getAttribute && tr.getAttribute('data-tx-id');
    if (v) return v;
    var anyBtn = tr.querySelector('button[onclick]');
    if (anyBtn) { var m = /\((\d+)\)/.exec(anyBtn.getAttribute('onclick') || ''); if (m) return m[1]; }
    var cb = tr.querySelector('.txCb');
    if (cb && cb.value) return cb.value;
    return null;
  }

  function enhanceOne(wrap) {
    if (!wrap) return;
    var table = wrap.querySelector('table');
    if (!table) return;
    var ths = table.querySelectorAll('thead th');
    var idx = {};
    ths.forEach(function (th, i) { var t = (th.textContent || '').trim(); if (t && idx[t] == null) idx[t] = i; });
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      var tds = tr.children;
      if (idx['序号'] != null && tds[idx['序号']]) tds[idx['序号']].classList.add('m-hide-mobile');
      if (idx['描述'] != null && tds[idx['描述']]) tds[idx['描述']].classList.add('m-col-desc');
      var id = rowId(tr);
      if (!id) return;
      var last = tr.lastElementChild;
      if (last && !last.querySelector('.m-detail-btn')) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'btn btn-outline btn-sm m-detail-btn';
        b.title = '查看详情与凭证'; b.setAttribute('aria-label', '查看详情与凭证');
        b.textContent = '📄';
        b.addEventListener('click', function (ev) { ev.stopPropagation(); openDetail(rowId(tr) || id); });
        last.insertBefore(b, last.firstChild);
      }
      if (!tr.__mTap) {
        tr.__mTap = true;
        tr.addEventListener('click', function (ev) {
          if (ev.target.closest && ev.target.closest('button,a,input,label,select')) return;
          openDetail(rowId(tr) || id);
        });
      }
      tr.querySelectorAll('a[href*="tx_image"]').forEach(function (a) {
        if (a.__mImg) return;
        a.__mImg = true;
        a.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); openDetail(rowId(tr) || id); });
      });
    });
  }

  function enhanceTable() {
    for (var i = 0; i < CONTAINERS.length; i++) { var w = $(CONTAINERS[i]); if (w) enhanceOne(w); }
  }

  function boot() {
    enhanceTable();
    if (window.MutationObserver) {
      CONTAINERS.forEach(function (cid) {
        var w = $(cid);
        if (w && !w.__mObs) {
          w.__mObs = true;
          new MutationObserver(function () { enhanceOne(w); }).observe(w, { childList: true, subtree: true });
        }
      });
    }
    [300, 1200, 2500].forEach(function (ms) { setTimeout(enhanceTable, ms); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
