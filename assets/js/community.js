/* 公告 / 投票 */
(function(){
  'use strict';
  var csrf=window._initialCsrf||'';
  function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
  async function api(action,data,method){
    var url='api.php?action='+encodeURIComponent(action), opt={method:method||'GET',credentials:'same-origin',headers:{}};
    if(opt.method==='GET'){ if(data)url+='&'+new URLSearchParams(data); }
    else { data=data||{}; data.csrf_token=csrf; opt.headers['Content-Type']='application/x-www-form-urlencoded'; opt.body=new URLSearchParams(data); }
    var r=await fetch(url,opt); var j=await r.json().catch(function(){return {};});
    if(!r.ok||j.error) throw new Error(j.error||('HTTP '+r.status)); return j;
  }
  function isAdmin(){var r=(window._initialUser&&window._initialUser.roles)||[];return r.some(function(x){return ['head_teacher','admin','monitor','vice_monitor'].indexOf(x)>=0;});}
  function fmt(t){return t?String(t).replace('T',' ').slice(0,16):'';}
  async function load(){
    var ann=document.getElementById('announcementList'), vote=document.getElementById('voteList');
    if(!ann||!vote)return;
    ann.innerHTML='<div class="loading"><div class="spinner"></div></div>';
    vote.innerHTML='<div class="loading"><div class="spinner"></div></div>';
    try{
      var a=await api('announcements'), v=await api('votes');
      var items=a.items||[];
      if(!items.length) ann.innerHTML='<div class="empty">暂无公告</div>';
      else ann.innerHTML=items.map(function(x){return '<div class="card" style="margin-bottom:10px"><div class="card-label">'+esc(fmt(x.created_at))+' · '+esc(x.created_by||'班委')+(x.pinned?' · 📌':'')+'</div><div style="font-size:15px;font-weight:600;margin:4px 0">'+esc(x.title)+'</div><div style="font-size:13px;white-space:pre-wrap">'+esc(x.content)+'</div></div>';}).join('');
      var votes=v.items||[];
      if(!votes.length) vote.innerHTML='<div class="empty">暂无投票</div>';
      else vote.innerHTML=votes.map(function(x){
        var opts=(x.options||[]).map(function(o){
          var checked=(x.my||[]).indexOf(o)>=0?' checked':'';
          var type=x.multi?'checkbox':'radio';
          return '<label style="display:block;padding:3px 0"><input type="'+type+'" name="vote_'+x.id+'" value="'+esc(o)+'"'+checked+'> '+esc(o)+' <span style="color:var(--text-secondary);font-size:11px">('+((x.counts||{})[o]||0)+')</span></label>';
        }).join('');
        return '<div class="card" style="margin-bottom:10px"><div style="font-weight:600">'+esc(x.title)+'</div><div class="muted" style="font-size:11px">共 '+x.total+' 人参与'+(x.deadline?' · 截止 '+esc(x.deadline):'')+(x.closed?' · 已结束':'')+'</div><div style="margin:8px 0">'+opts+'</div><button class="btn btn-primary btn-sm" onclick="window._voteCast('+x.id+')"'+(x.closed?' disabled':'')+'>提交投票</button></div>';
      }).join('');
      var admin=isAdmin();
      var aa=document.getElementById('announcementAdmin'), va=document.getElementById('voteAdmin');
      if(aa)aa.style.display=admin?'block':'none'; if(va)va.style.display=admin?'block':'none';
    }catch(e){ann.innerHTML='<div class="empty">'+esc(e.message)+'</div>';vote.innerHTML='';}
  }
  window._communityLoad=load;
  document.addEventListener('DOMContentLoaded',function(){var n=document.getElementById('navCommunity');if(n&&window._initialUser&&window._initialUser.is_guest)n.style.display='none';});
  window._annCreate=async function(){
    var title=(document.getElementById('annTitle')||{}).value||'', content=(document.getElementById('annContent')||{}).value||'';
    if(!title||!content){alert('请填写公告标题和内容');return;}
    try{await api('announcements',{op:'create',title:title,content:content},'POST');document.getElementById('annTitle').value='';document.getElementById('annContent').value='';await load();}
    catch(e){alert(e.message);}
  };
  window._voteCreate=async function(){
    var title=(document.getElementById('voteTitle')||{}).value||'', options=(document.getElementById('voteOptions')||{}).value||'';
    if(!title||!options){alert('请填写投票问题和选项');return;}
    try{await api('votes',{op:'create',title:title,options:options,multi:!!(document.getElementById('voteMulti')||{}).checked},'POST');document.getElementById('voteTitle').value='';document.getElementById('voteOptions').value='';await load();}
    catch(e){alert(e.message);}
  };
  window._voteCast=async function(id){
    var opts=Array.prototype.slice.call(document.querySelectorAll('input[name="vote_'+id+'"]:checked')).map(function(x){return x.value;});
    if(!opts.length){alert('请选择选项');return;}
    try{await api('votes',{op:'cast',id:id,options:JSON.stringify(opts)},'POST');await load();}
    catch(e){alert(e.message);}
  };
})();
