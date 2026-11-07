/* 学生「我的班费」 */
(function(){
  'use strict';
  function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  function money(v){var n=Number(v||0);return '¥'+n.toFixed(2);}
  function status(s){return s==='paid'?'已支付':s==='pending'?'待支付':s==='closed'?'已关闭':(s||'-');}
  async function load(){
    var box=document.getElementById('myFeeContent'); if(!box) return;
    box.innerHTML='<div class="loading"><div class="spinner"></div><p>加载中...</p></div>';
    try{
      var r=await fetch('api.php?action=my_fee',{credentials:'same-origin'});
      var j=await r.json().catch(function(){return {};});
      if(!r.ok||j.error) throw new Error(j.error||('HTTP '+r.status));
      var f=j.fee||{}, rounds=j.rounds||[], orders=j.orders||[];
      var html='<div class="cards">'+
        '<div class="card"><div class="card-label">应缴</div><div class="card-value">'+money(f.due)+'</div></div>'+
        '<div class="card"><div class="card-label">已缴</div><div class="card-value income">'+money(f.paid)+'</div></div>'+
        '<div class="card"><div class="card-label">待缴</div><div class="card-value '+(Number(f.outstanding)>0?'expense':'income')+'">'+money(f.outstanding)+'</div></div></div>';
      html+='<div class="section-header" style="margin-top:16px"><h3>📒 '+esc((j.student||{}).name||'我的班费')+'</h3>'+
        '<div style="display:flex;gap:8px"><a class="btn btn-outline btn-sm" href="api.php?action=my_receipt" target="_blank" rel="noopener">🧾 电子收据</a>'+
        (Number(f.outstanding)>0?'<a class="btn btn-primary btn-sm" href="?pay='+encodeURIComponent((j.student||{}).id||'0')+'#page-pay">💳 去缴费</a>':'')+'</div></div>';
      html+='<div class="table-wrap"><table><thead><tr><th>日期</th><th>轮次</th><th>每人应缴</th><th>本轮应缴</th><th>本轮已缴</th><th>状态</th></tr></thead><tbody>';
      if(!rounds.length) html+='<tr><td colspan="6" class="empty">暂无班费轮次</td></tr>';
      rounds.forEach(function(x){
        var st=x.exempt?'免缴':(Number(x.paid)>=Number(x.due)&&Number(x.due)>0?'已缴清':(Number(x.paid)>0?'部分已缴':'未缴'));
        html+='<tr><td>'+esc(x.date)+'</td><td>'+esc(x.description||'班费')+'</td><td>'+money(x.per_person)+'</td><td>'+money(x.due)+'</td><td>'+money(x.paid)+'</td><td>'+esc(st)+'</td></tr>';
      });
      html+='</tbody></table></div>';
      html+='<div class="section-header" style="margin-top:16px"><h3>🧾 我的订单</h3><button class="btn btn-outline btn-sm" onclick="window._myFeeLoad&&window._myFeeLoad()">🔄 刷新</button></div>';
      html+='<div class="table-wrap"><table><thead><tr><th>订单号</th><th>金额</th><th>方式</th><th>状态</th><th>时间</th></tr></thead><tbody>';
      if(!orders.length) html+='<tr><td colspan="5" class="empty">暂无订单</td></tr>';
      orders.forEach(function(o){html+='<tr><td style="font-size:11px">'+esc(o.order_no)+'</td><td>'+money(o.amount)+'</td><td>'+esc(o.channel||'-')+'</td><td>'+esc(status(o.status))+(Number(o.settled)?' · 已核销':'')+'</td><td style="font-size:11px">'+esc(o.paid_at||o.created_at||'')+'</td></tr>';});
      html+='</tbody></table></div>';
      box.innerHTML=html;
    }catch(e){box.innerHTML='<div class="empty">'+esc(e.message)+'</div>';}
  }
  window._myFeeLoad=load;
  document.addEventListener('DOMContentLoaded',function(){
    var nav=document.getElementById('navMyFee');
    if(nav && window._initialUser && window._initialUser.is_guest) nav.style.display='none';
  });
})();
