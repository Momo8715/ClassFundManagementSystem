<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>班级班费在线缴费</title>
<style>
  :root { color-scheme: dark; --bg:#0f1115; --card:#171a21; --line:rgba(255,255,255,.08); --text:#e8edf6; --muted:#8c9ab3; --green:#07c160; --blue:#3b82f6; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; background:radial-gradient(1200px 600px at 50% -20%,#1b2a3a 0%,var(--bg) 55%); color:var(--text); font:14px/1.65 system-ui,-apple-system,"Microsoft YaHei",sans-serif; }
  .wrap { max-width:560px; margin:0 auto; padding:28px 16px 40px; }
  .brand { display:flex; align-items:center; gap:10px; margin-bottom:18px; }
  .logo { width:34px; height:34px; border-radius:10px; background:linear-gradient(135deg,#07c160,#0ea5e9); display:grid; place-items:center; font-weight:800; color:#052e16; }
  h1 { font-size:18px; margin:0; }
  .muted { color:var(--muted); font-size:12px; }
  .card { background:rgba(23,26,33,.92); border:1px solid var(--line); border-radius:16px; padding:18px; box-shadow:0 12px 40px rgba(0,0,0,.25); }
  .field { margin-bottom:14px; }
  label { display:block; font-size:12px; color:var(--muted); margin-bottom:6px; }
  select, button { width:100%; border-radius:10px; border:1px solid var(--line); background:#10141c; color:var(--text); padding:11px 12px; font-size:14px; outline:none; }
  select:focus { border-color:rgba(59,130,246,.7); }
  button { margin-top:6px; background:var(--green); border-color:var(--green); color:#04220f; font-weight:700; cursor:pointer; }
  button:disabled { opacity:.5; cursor:default; }
  .note { margin-top:12px; font-size:11px; color:var(--muted); line-height:1.7; }
  .result { display:none; margin-top:16px; padding-top:14px; border-top:1px solid var(--line); }
  .result.show { display:block; }
  .amount { font-size:28px; font-weight:800; color:var(--green); margin:4px 0 8px; }
  .order { font-size:11px; color:var(--muted); word-break:break-all; }
  .qr { display:block; width:200px; height:200px; margin:14px auto; object-fit:contain; background:#fff; border-radius:10px; padding:6px; }
  .paylink { display:inline-block; margin-top:8px; color:#93c5fd; word-break:break-all; }
  .err { color:#fca5a5; }
  .ok { color:#86efac; }
  .spinner { display:none; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="logo">班</div><div><h1>班级班费在线缴费</h1><div class="muted">无需登录 · 选择学生后在线支付</div></div></div>
  <div class="card">
    <div class="field"><label for="student">缴费学生</label><select id="student"><option value="0">加载中…</option></select></div>
    <div class="field"><label for="round">缴费轮次</label><select id="round"><option value="0">本次班费（通用）</option></select></div>
    <div class="field"><label for="channel">支付方式</label><select id="channel"></select></div>
    <button id="pay" disabled>生成支付订单</button>
    <div id="msg" class="note"></div>
    <div class="note">名单仅用于选择缴费人，不会展示个人欠费明细，请勿转发。支付完成后系统会自动核销。</div>
    <div id="result" class="result">
      <div class="muted">应付金额</div><div class="amount" id="amount">¥0.00</div>
      <div class="order" id="orderNo"></div>
      <img id="qr" class="qr" alt="支付二维码" style="display:none">
      <div id="actions"></div>
      <div class="note ok" id="done">支付完成后请返回本页查看结果</div>
    </div>
  </div>
  <div class="note" style="text-align:center;margin-top:14px">班级班费管理系统 · 在线缴费</div>
</div>
<script>
(function(){
  var meta=null;
  var $=function(id){return document.getElementById(id)};
  function setMsg(t, err){ var el=$('msg'); el.textContent=t||''; el.className='note'+(err?' err':''); }
  async function api(action, data){
    var opt={method:'GET',credentials:'same-origin'};
    if(data){opt.method='POST';opt.headers={'Content-Type':'application/json'};opt.body=JSON.stringify(data);}
    var r=await fetch('api.php?action='+encodeURIComponent(action),opt);
    var j={};try{j=await r.json()}catch(e){}
    if(!r.ok||j.error)throw new Error(j.error||('请求失败（HTTP '+r.status+'）'));
    return j;
  }
  function option(sel,value,label){var o=document.createElement('option');o.value=String(value);o.textContent=label;sel.appendChild(o);}
  function money(v){var n=Number(v||0);return '¥'+n.toFixed(2);}
  function fillRounds(list){
    var sel=$('round');sel.innerHTML='';option(sel,'0','本次班费（通用）');
    (list||[]).forEach(function(rd){
      var pp=Number(rd.per_person||0);
      var label=(rd.date||'')+' '+(rd.description||'班费')+(pp>0?(' · 每人 ¥'+pp.toFixed(2)):'');
      option(sel,rd.id,label);
    });
  }
  function fillChannels(cfg){
    var sel=$('channel');sel.innerHTML='';var types=(cfg&&cfg.available_types)||[];var labels={alipay:'支付宝',wxpay:'微信支付',qqpay:'QQ 钱包'};
    ((cfg&&cfg.methods)||[]).forEach(function(m){if(m&&m.code)labels[m.code]=m.label||m.code;});
    if(!types.length){option(sel,'','无可用支付方式');$('pay').disabled=true;return;}
    types.forEach(function(t){option(sel,t,labels[t]||t);});
  }
  function preselect(){
    var q=new URLSearchParams(location.search);var want=parseInt(q.get('pay')||q.get('student')||'0',10);
    if(want>0){var sel=$('student');sel.value=String(want);}
  }
  async function load(){
    try{
      meta=await api('pay_public_meta');
      var sel=$('student');sel.innerHTML='';option(sel,'0','请选择姓名');
      (meta.roster||[]).forEach(function(s){option(sel,s.id,s.name);});
      fillRounds(meta.rounds||[]);
      fillChannels(meta.config||{});
      var enabled=!!(meta.config&&meta.config.enabled);
      if(!enabled){setMsg('支付通道尚未配置或未开启，请联系班委。',true);$('pay').disabled=true;}
      else setMsg('请选择学生和轮次，点击生成订单。');
      preselect();
      $('pay').disabled=!enabled;
    }catch(e){setMsg('加载失败：'+e.message,true);$('student').innerHTML='<option value="0">加载失败</option>';}
  }
  $('pay').addEventListener('click', async function(){
    var sid=parseInt($('student').value||'0',10);var rid=$('round').value||'0';var ch=$('channel').value||'';
    if(!sid){setMsg('请选择缴费学生',true);return;}
    if(!ch){setMsg('请选择支付方式',true);return;}
    var btn=$('pay');btn.disabled=true;setMsg('正在生成订单…');
    try{
      var d=await api('pay_public_create',{student_id:sid,round_id:rid,channel:ch});
      $('result').classList.add('show');
      $('amount').textContent=money(d.amount);
      $('orderNo').textContent='订单号：'+(d.order_no||'');
      var qr=$('qr');qr.style.display='none';
      if(d.qrcode){qr.src=d.qrcode;qr.style.display='block';}
      var actions=$('actions');actions.innerHTML='';
      if(d.payurl){
        var a=document.createElement('a');a.className='paylink';a.href=d.payurl;a.target='_blank';a.rel='noopener';a.textContent='打开支付页面 →';actions.appendChild(a);
      }
      setMsg('订单已生成，请完成支付。');
    }catch(e){setMsg('生成订单失败：'+e.message,true);}
    finally{btn.disabled=!(meta&&meta.config&&meta.config.enabled);}
  });
  load();
})();
</script>
</body>
</html>
