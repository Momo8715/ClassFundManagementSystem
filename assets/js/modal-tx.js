/**
 * 收入弹窗「班费收缴」简化模式（v1.9；v2 增强提示与默认全选）
 * 班费收缴时只保留：每人应缴 + 缴费名单（含免缴）+ 日期 + 描述；
 * 隐藏 金额 / 分类 / 凭证图片 / 应收总额；金额自动 = 每人应缴 × 缴费人数。
 * 不修改 app.js，通过包装 showAddTransaction / _editTx / saveTransaction 实现。
 */
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }
  function isCollect() { var el = $('txSubCategory'); return el && el.value === '班费收缴'; }
  var autoDone = false; // 每次打开新增弹窗只自动全选一次，尊重用户手动「清除」

  function toast(msg, kind) {
    var n = document.createElement('div');
    n.className = 'toast ' + (kind || 'success');
    n.textContent = msg;
    document.body.appendChild(n);
    setTimeout(function () { n.remove(); }, 3200);
  }

  function updateHint() {
    var box = $('txRosterList');
    if (!box) return;
    var total = box.querySelectorAll('.rosterCb').length;
    var paid = box.querySelectorAll('.rosterCb:checked').length;
    var hint = $('txCollectHint');
    if (!hint) {
      hint = document.createElement('div');
      hint.id = 'txCollectHint';
      hint.style.cssText = 'font-size:11px;color:var(--text-secondary);margin-top:6px';
      box.parentNode.appendChild(hint);
    }
    var per = parseFloat(($('txPerPerson') || {}).value || '0') || 0;
    hint.textContent = '已选缴费 ' + paid + ' / ' + total + ' 人'
      + (per > 0
          ? (paid > 0 ? '，合计 ¥' + (per * paid).toFixed(2) : '（还没人交？可直接保存，先按 ¥0 建轮次）')
          : '（请填写「每人应缴」）');
  }

  // 进入班费收缴模式后，若花名册已加载且一个都没勾选，默认「全部缴纳」
  function autoSelectIfEmpty() {
    if (autoDone || !isCollect()) return;
    var box = $('txRosterList');
    if (!box) return;
    if (box.querySelectorAll('.rosterCb').length === 0) return;   // 还没加载出来，等 MutationObserver 再试
    var cbs = box.querySelectorAll('.rosterCb:not([disabled])');
    if (cbs.length === 0) { autoDone = true; return; }
    if (box.querySelectorAll('.rosterCb:checked').length > 0) { autoDone = true; return; }
    cbs.forEach(function (cb) { cb.checked = true; });
    autoDone = true;
    updateHint();
  }

  function apply() {
    if (!isCollect()) {
      ['txAmountGroup', 'txCategoryGroup', 'txImageGroup'].forEach(function (id) { var el = $(id); if (el) el.style.display = ''; });
      return;
    }
    ['txAmountGroup', 'txCategoryGroup', 'txImageGroup', 'txExpectedGroup'].forEach(function (id) { var el = $(id); if (el) el.style.display = 'none'; });
    ['txRosterGroup', 'txPerPersonGroup'].forEach(function (id) { var el = $(id); if (el) el.style.display = 'block'; });
    autoSelectIfEmpty();
    updateHint();
  }

  function wrapOpen(name, allowAuto) {
    var orig = window[name];
    if (typeof orig !== 'function' || orig.__wrapped) return;
    var wrapped = function () {
      autoDone = !allowAuto;               // 编辑已有记录时不自动全选，避免覆盖原有名单
      var r = orig.apply(this, arguments);
      setTimeout(apply, 30);
      return r;
    };
    wrapped.__wrapped = true;
    window[name] = wrapped;
  }

  function wrapSave() {
    var orig = window.saveTransaction;
    if (typeof orig !== 'function' || orig.__wrapped) return;
    var wrapped = function () {
      if (isCollect()) {
        var per = parseFloat(($('txPerPerson') || {}).value || '0') || 0;
        var box = $('txRosterList');
        var paid = box ? box.querySelectorAll('.rosterCb:checked').length : 0;
        var eligible = box ? box.querySelectorAll('.rosterCb:not([disabled])').length : 0;
        // 给出明确原因，而不是笼统的「无效金额」
        if (eligible <= 0) { toast('花名册为空或全部免缴，请先在「花名册」导入学生', 'error'); return; }
        if (per <= 0) { toast('请先填写「每人应缴」金额', 'error'); return; }
        // 不勾人也能建轮次：按 ¥0 记录，之后勾选或在线缴费到账会自动并入本轮
        if (paid <= 0) { toast('本轮尚无缴费学生，先按 ¥0 建轮次（应收 ¥' + (per * eligible).toFixed(2) + '）'); }
        if ($('txAmount')) $('txAmount').value = (per * paid).toFixed(2);
        if ($('txExpected')) $('txExpected').value = (per * eligible).toFixed(2);
      }
      return orig.apply(this, arguments);
    };
    wrapped.__wrapped = true;
    window.saveTransaction = wrapped;
  }

  function init() {
    wrapOpen('showAddTransaction', true);
    wrapOpen('_editTx', false);
    wrapSave();
    var sub = $('txSubCategory'); if (sub) sub.addEventListener('change', apply);
    var typ = $('txType'); if (typ) typ.addEventListener('change', function () { setTimeout(apply, 30); });
    var box = $('txRosterList');
    if (box) {
      box.addEventListener('change', updateHint);
      box.addEventListener('click', function () { setTimeout(updateHint, 30); });
      if (window.MutationObserver) {
        new MutationObserver(function () { autoSelectIfEmpty(); updateHint(); }).observe(box, { childList: true });
      }
    }
    var per = $('txPerPerson'); if (per) per.addEventListener('input', updateHint);
    var modal = $('modalTx');
    if (modal && window.MutationObserver) {
      new MutationObserver(function () { if (modal.classList.contains('active')) apply(); })
        .observe(modal, { attributes: true, attributeFilter: ['class'] });
    }
    apply();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
