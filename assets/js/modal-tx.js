/**
 * 收入弹窗「班费收缴」简化模式（v1.9）
 * 班费收缴时只保留：每人应缴 + 缴费名单（含免缴）+ 日期 + 描述；
 * 隐藏 金额 / 分类 / 凭证图片 / 应收总额；金额自动 = 每人应缴 × 缴费人数。
 * 不修改 app.js，通过包装 showAddTransaction / _editTx / saveTransaction 实现。
 */
(function () {
  'use strict';
  function $(id) { return document.getElementById(id); }
  function isCollect() { var el = $('txSubCategory'); return el && el.value === '班费收缴'; }

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
    hint.textContent = '已选缴费 ' + paid + ' / ' + total + ' 人' + (per > 0 ? '，合计 ¥' + (per * paid).toFixed(2) : '');
  }

  function apply() {
    if (!isCollect()) {
      ['txAmountGroup', 'txCategoryGroup', 'txImageGroup'].forEach(function (id) { var el = $(id); if (el) el.style.display = ''; });
      return;
    }
    ['txAmountGroup', 'txCategoryGroup', 'txImageGroup', 'txExpectedGroup'].forEach(function (id) { var el = $(id); if (el) el.style.display = 'none'; });
    ['txRosterGroup', 'txPerPersonGroup'].forEach(function (id) { var el = $(id); if (el) el.style.display = 'block'; });
    updateHint();
  }

  function wrapOpen(name) {
    var orig = window[name];
    if (typeof orig !== 'function' || orig.__wrapped) return;
    var wrapped = function () { var r = orig.apply(this, arguments); setTimeout(apply, 30); return r; };
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
        if ($('txAmount')) $('txAmount').value = (per * paid).toFixed(2);
        if ($('txExpected')) $('txExpected').value = (per * eligible).toFixed(2);
      }
      return orig.apply(this, arguments);
    };
    wrapped.__wrapped = true;
    window.saveTransaction = wrapped;
  }

  function init() {
    wrapOpen('showAddTransaction');
    wrapOpen('_editTx');
    wrapSave();
    var sub = $('txSubCategory'); if (sub) sub.addEventListener('change', apply);
    var typ = $('txType'); if (typ) typ.addEventListener('change', function () { setTimeout(apply, 30); });
    var box = $('txRosterList');
    if (box) {
      box.addEventListener('change', updateHint);
      box.addEventListener('click', function () { setTimeout(updateHint, 30); });
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
