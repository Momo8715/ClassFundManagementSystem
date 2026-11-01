
const fs = require('fs');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const API_BASE = process.env.API_BASE || 'http://127.0.0.1:18098/api.php';
const base = API_BASE + '?action=';
const root = process.env.APP_ROOT || process.cwd();
const mysql = process.env.MYSQL_BIN || 'mysql';
const dbArgs = ['--host=' + (process.env.DB_HOST || '127.0.0.1'),
  '--port=' + (process.env.DB_PORT || '3306'),
  '--user=' + (process.env.DB_USER || 'root'),
  '--database=' + (process.env.DB_NAME || 'classfund_test'),
  '--default-character-set=utf8mb4'];
if (process.env.DB_PASS) dbArgs.push('--password=' + process.env.DB_PASS);
let cookie = "", csrf = "", pass = 0, fail = 0;
function ok(c, n, e) { if (c) { pass++; console.log("  ✅", n); } else { fail++; console.log("  ❌", n, e === undefined ? "" : JSON.stringify(e).slice(0,700)); } }
function sql(q) { return execFileSync(mysql, [...dbArgs, '-N', '-B', '-e', q], { encoding: 'utf8' }); }
function capture(res) { const sc = res.headers.getSetCookie ? res.headers.getSetCookie() : (res.headers.get('set-cookie') ? [res.headers.get('set-cookie')] : []); for (const c of sc) { const m = String(c).match(/PHPSESSID=([^;]+)/); if (m) cookie = 'PHPSESSID=' + m[1]; } }
async function req(action, method = 'GET', data = null, json = false) {
  let url = base + encodeURIComponent(action); let headers = {}, body;
  if (method === 'GET' && data) { const q = new URLSearchParams(); for (const [k,v] of Object.entries(data)) q.append(k,v); url += '&' + q; }
  else if (method !== 'GET') { if (json) { headers['Content-Type']='application/json'; body=JSON.stringify(data||{}); } else { const p=new URLSearchParams(); for (const [k,v] of Object.entries(data||{})) { if(v===undefined||v===null) continue; p.append(k, typeof v==='object'?JSON.stringify(v):String(v)); } if(csrf) p.append('csrf_token',csrf); headers['Content-Type']='application/x-www-form-urlencoded'; body=p; } }
  if (cookie) headers['Cookie'] = cookie;
  const res = await fetch(url, { method, headers, body }); capture(res);
  const buf = Buffer.from(await res.arrayBuffer()); let j=null; try { j=JSON.parse(buf.toString('utf8')); } catch(e){}
  return { status: res.status, json: j, buf };
}
async function uploadImage() {
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  const fd = new FormData();
  fd.append('csrf_token', csrf);
  fd.append('image', new Blob([png], { type: 'image/png' }), 'test.png');
  const res = await fetch(base + 'upload_image', { method: 'POST', credentials: 'same-origin', headers: cookie ? { Cookie: cookie } : {}, body: fd });
  capture(res); const text = await res.text(); let j; try { j=JSON.parse(text); } catch(e){ j={raw:text.slice(0,300)}; }
  return { status: res.status, json: j };
}
function b32(s) { const a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits=''; for (const ch of s.replace(/=+$/,'').toUpperCase()) { const i=a.indexOf(ch); if(i<0)continue; bits+=i.toString(2).padStart(5,'0'); } const out=[]; for(let i=0;i+8<=bits.length;i+=8) out.push(parseInt(bits.slice(i,i+8),2)); return Buffer.from(out); }
function totp(secret) { const key=b32(secret); const step=Math.floor(Date.now()/1000/30); const b=Buffer.alloc(8); b.writeUInt32BE(0,0); b.writeUInt32BE(step,4); const h=crypto.createHmac('sha1',key).update(b).digest(); const o=h[19]&15; const v=((h[o]&127)<<24)|((h[o+1]&255)<<16)|((h[o+2]&255)<<8)|(h[o+3]&255); return String(v%1000000).padStart(6,'0'); }

(async () => {
  console.log('[迁移与登录]');
  let r = await req('login', 'POST', { username: 'admin', pwd: 'admin123', client_2fa: 1 });
  ok(r.status === 200 && r.json.ok, '登录（触发 autoMigrate）', r); csrf = r.json.user.csrf_token;
  const cols = sql("SHOW COLUMNS FROM tx_images").toLowerCase();
  ok(cols.includes('sha256') && cols.includes('web') && cols.includes('byte_size'), 'tx_images 新列已迁移');
  const tables = sql("SHOW TABLES").toLowerCase();
  ok(tables.includes('health_ack') && tables.includes('backup_runs'), 'health_ack / backup_runs 已建表');
  const ver = sql("SELECT meta_value FROM system_meta WHERE meta_key='schema_version'").trim();
  ok(ver === '1.20', 'SCHEMA_VERSION=1.20', ver);

  console.log('[审计 Diff]');
  r = await req('transactions', 'GET', { per_page: 20 });
  const expense = (r.json.transactions || []).find(t => t.type === 'expense');
  ok(!!expense && expense.locked === false, '未归档记录 locked=false', expense && expense.locked);
  r = await req('transactions', 'PUT', { id: expense.id, description: '班级活动（已改）' });
  ok(r.status === 200, '编辑收支', r);
  r = await req('logs', 'GET', { per_page: 20 });
  const log = (r.json.logs || []).find(l => l.action === 'update_transaction' && l.target_id === expense.id);
  const det = log ? (typeof log.details === 'string' ? JSON.parse(log.details) : log.details) : null;
  ok(!!det && det.before && det.after && Array.isArray(det.changed), '日志包含 before/after/changed', det);

  console.log('[凭证上传与自愈]');
  r = await uploadImage();
  ok(r.status === 200 && r.json.ok && r.json.id, '图片上传成功', r);
  const imgId = r.json.id;
  const imgRow = sql("SELECT full_path, sha256, byte_size, OCTET_LENGTH(web) FROM tx_images WHERE id=" + imgId).trim().split('\t');
  ok(imgRow[0].startsWith('uploads/receipts/') && imgRow[1].length === 64 && Number(imgRow[3]) > 0, '保存路径/哈希/Web 回退版', imgRow);
  const abs = root + '/' + imgRow[0];
  ok(fs.existsSync(abs), '原图文件已写入');
  fs.unlinkSync(abs);
  r = await req('tx_image', 'GET', { id: imgId });
  ok(r.status === 200 && r.buf.length > 0, '原图缺失时回退 Web 版（不再 404）', { status: r.status, len: r.buf.length });
  r = await req('health_check');
  const imageSec = (r.json.sections || []).find(s => s.key === 'image');
  ok(imageSec && imageSec.items.some(i => i.code === 'image_file_missing_recoverable'), '体检识别可回退原图缺失', imageSec);
  const recoverable = imageSec.items.find(i => i.code === 'image_file_missing_recoverable');
  // 旧直连路径改走 API，并阻止路径穿越
  const legacyAbs = root + '/uploads/receipts/legacy-test.png';
  fs.writeFileSync(legacyAbs, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
  r = await req('tx_image', 'GET', { path: 'uploads/receipts/legacy-test.png' });
  ok(r.status === 200 && r.buf.length > 0, '旧直连路径经 API 读取', { status: r.status, len: r.buf.length });
  r = await req('tx_image', 'GET', { path: '../config.php' });
  ok(r.status === 400 || r.status === 404, '路径穿越被拒绝', r.status);
  fs.unlinkSync(legacyAbs);
  r = await req('health_fix', 'POST', { action: 'repair_image_paths', dry_run: 1 });
  ok(r.status === 200 && r.json.plan && r.json.plan.images >= 1, '修复预演', r);
  r = await req('health_fix', 'POST', { action: 'repair_image_paths' });
  ok(r.status === 200 && r.json.ok && r.json.result && r.json.result.count >= 1 && r.json.result.backup, '修复并自动备份', r);

  console.log('[备份 / 下载 / 恢复]');
  r = await req('backup_create', 'POST', {});
  ok(r.status === 200 && r.json.ok && r.json.backup && r.json.backup.filename, '创建备份', r);
  const backupFile = r.json.backup.filename;
  r = await req('backup_list');
  ok(r.status === 200 && (r.json.files || []).some(f => f.filename === backupFile), '备份列表包含新备份', r.json && r.json.files && r.json.files.length);
  r = await req('backup_download', 'GET', { file: backupFile });
  ok(r.status === 200 && r.buf.slice(0,2).toString() === 'PK', '下载备份 zip', { status: r.status, head: r.buf.slice(0,4).toString('hex') });
  // 备份后新增一条记录，再恢复，验证被回滚
  r = await req('transactions', 'POST', { type: 'expense', amount: 9, date: '2025-10-02', description: '恢复测试记录', category: '其他支出', sub_category: '其他支出' });
  ok(r.status === 201, '新增恢复测试记录', r);
  const beforeCount = Number(sql("SELECT COUNT(*) FROM transactions WHERE deleted_at IS NULL").trim());
  r = await req('backup_restore', 'POST', { file: backupFile, confirm: 'RESTORE' });
  ok(r.status === 200 && r.json.ok && r.json.result && r.json.result.safety_backup, '恢复备份（含恢复前备份）', r);
  ok(Number(r.json.result.uploads || 0) >= 1, '恢复 uploads 文件（含 .htaccess）', r.json.result);
  const afterCount = Number(sql("SELECT COUNT(*) FROM transactions WHERE deleted_at IS NULL").trim());
  ok(afterCount === beforeCount - 1, '恢复后新增记录被回滚', { beforeCount, afterCount });
  r = await req('dashboard');
  ok(r.status === 200 && r.json.summary, '恢复后会话仍可用', r.status);

  console.log('[体检忽略]');
  r = await req('health_check');
  const item = (r.json.sections || []).flatMap(s => s.items || []).find(i => i.key);
  ok(!!item, '存在可忽略项', item && item.key);
  if (item) {
    r = await req('health_ack', 'POST', { item_key: item.key, code: item.code, target: '', reason: '测试忽略', days: 30 });
    ok(r.status === 200 && r.json.ok, '忽略体检项', r);
    r = await req('health_check');
    ok(Number(r.json.ignored || 0) >= 1, '体检返回已忽略数量', r.json.ignored);
    r = await req('health_unack', 'POST', { all: 1 });
    ok(r.status === 200, '恢复已忽略项', r);
  }

  console.log('[归档锁定]');
  r = await req('semesters');
  const activeId = (r.json.semesters || []).find(s => s.status === 'active').id;
  r = await req('semesters', 'POST', { action: 'archive', id: activeId, create_next: 0 });
  ok(r.status === 200 && r.json.ok, '归档学期', r);
  r = await req('transactions', 'GET', { per_page: 20 });
  const lockedTx = (r.json.transactions || [])[0];
  ok(lockedTx && lockedTx.locked === true, '列表返回 locked=true', lockedTx && lockedTx.locked);
  r = await req('transactions', 'PUT', { id: lockedTx.id, description: '不应成功' });
  ok(r.status === 403, '归档行编辑被拒绝', r.status);

  console.log('[2FA 强制策略]');
  r = await req('twofa_policy', 'GET');
  ok(r.status === 200 && r.json.policy, '读取策略', r);
  r = await req('twofa_policy', 'POST', { enabled: 1, grace_days: 0 });
  ok(r.status === 200 && r.json.policy.enabled, '启用策略（宽限 0 天）', r);
  r = await req('dashboard');
  ok(r.status === 403 && r.json.must_enable_2fa === true, '未启用 2FA 的管理员被限制', r);
  r = await req('twofa_policy', 'GET');
  ok(r.status === 200 && r.json.policy, '锁定时仍可读取策略（用于设置页）', r);
  r = await req('twofa_setup', 'POST', { op: 'start' });
  ok(r.status === 200 && !!r.json.secret, '开始设置 2FA', r);
  const secret = r.json.secret;
  r = await req('twofa_setup', 'POST', { op: 'confirm', code: totp(secret) });
  ok(r.status === 200 && r.json.enabled, '启用 2FA', r);
  r = await req('dashboard');
  ok(r.status === 200, '启用后接口恢复', r.status);
  r = await req('twofa_policy', 'POST', { enabled: 0, grace_days: 7 });
  ok(r.status === 200 && r.json.policy.enabled === false, '关闭策略', r);

  console.log('[cron]');
  r = await req('backup_list');
  const cronAll = r.json.cron_all_url || '';
  ok(cronAll.includes('cron.php?token='), '返回 cron URL', cronAll);
  const origin = new URL(API_BASE).origin;
  const cronRes = await fetch(cronAll.replace(/^https?:\/\/[^/]+/, origin), { credentials: 'same-origin' });
  const cronJson = await cronRes.json().catch(() => ({}));
  ok(cronRes.status === 200 && cronJson.ok === true && cronJson.backup && cronJson.health, 'cron task=all', { status: cronRes.status, json: cronJson });

  console.log('\n结果：' + pass + ' 通过 / ' + fail + ' 失败');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('TEST ERROR', e); process.exit(1); });
