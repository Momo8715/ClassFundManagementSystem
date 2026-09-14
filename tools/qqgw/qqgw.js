#!/usr/bin/env node
'use strict';
/**
 * ClassFund QQ 官方机器人 WebSocket 网关客户端
 * - 连接 QQ 网关保持机器人在线（官方要求在线才能发消息）
 * - 收到事件(op=0)后按官方 Ed25519 规则签名并转发给现有 PHP webhook 处理
 * - 状态写入 status.json 供后台页面展示
 */
const fs = require('fs');
const crypto = require('crypto');
const path = require('path');
const http = require('http');
const https = require('https');

const DIR = __dirname;
const CFG_PATH = path.join(DIR, 'config.json');
const STATUS_PATH = path.join(DIR, 'status.json');
const FORWARD_URL = process.env.QQGW_FORWARD || 'http://127.0.0.1/api.php?action=qq_webhook';
const PID_PATH = path.join(DIR, 'gateway.pid');
// 需要转发给 PHP 的事件（其余 op=0 会话事件仅更新连接状态）：
// - 消息类：驱动指令回复
// - 进出群/好友类：携带 group_openid / user_openid，方便在后台「回调监测」里一键设为推送目标
const MSG_EVENTS = [
  'GROUP_AT_MESSAGE_CREATE', 'C2C_MESSAGE_CREATE', 'AT_MESSAGE_CREATE', 'DIRECT_MESSAGE_CREATE',
  'GROUP_ADD_ROBOT', 'GROUP_DEL_ROBOT', 'FRIEND_ADD', 'FRIEND_DEL',
];

let cfg = null, cfgMtime = 0;
let token = null, tokenExp = 0;
let ws = null, hb = null, seq = null;
let last = {};

function log() { console.log(new Date().toISOString(), ...arguments); }
function status(o) {
  last = Object.assign({ at: new Date().toISOString(), pid: process.pid }, o);
  try { fs.writeFileSync(STATUS_PATH, JSON.stringify(last)); } catch (e) {}
}

// 单实例锁：避免 systemd 与应用自启同时跑多个
function pidAlive(pid) {
  try { process.kill(pid, 0); return true; } catch (e) { return e && e.code === 'EPERM'; }
}
function acquireLock() {
  try {
    const old = parseInt(fs.readFileSync(PID_PATH, 'utf8').trim(), 10);
    if (old && old !== process.pid && pidAlive(old)) { log('已有网关实例运行 pid=' + old + '，本实例退出'); process.exit(0); }
  } catch (e) {}
  try { fs.writeFileSync(PID_PATH, String(process.pid)); } catch (e) {}
}
function releaseLock() {
  try {
    const p = parseInt(fs.readFileSync(PID_PATH, 'utf8').trim(), 10);
    if (p === process.pid) fs.unlinkSync(PID_PATH);
  } catch (e) {}
}
acquireLock();
process.on('exit', releaseLock);
process.on('SIGTERM', function () { releaseLock(); process.exit(0); });
process.on('SIGINT', function () { releaseLock(); process.exit(0); });

function readCfg() {
  try {
    const st = fs.statSync(CFG_PATH);
    if (st.mtimeMs !== cfgMtime) { cfg = JSON.parse(fs.readFileSync(CFG_PATH, 'utf8')); cfgMtime = st.mtimeMs; }
  } catch (e) { cfg = null; }
  return cfg;
}
function edSeed(secret) {
  let s = Buffer.from(secret, 'utf8');
  while (s.length < 32) s = Buffer.concat([s, Buffer.from(secret, 'utf8')]);
  return s.subarray(0, 32);
}
function signHex(secret, msg) {
  const pkcs8 = Buffer.concat([Buffer.from('302e020100300506032b657004220420', 'hex'), edSeed(secret)]);
  const key = crypto.createPrivateKey({ key: pkcs8, format: 'der', type: 'pkcs8' });
  return crypto.sign(null, Buffer.from(msg, 'utf8'), key).toString('hex');
}
async function getToken(c) {
  const r = await fetch('https://bots.qq.com/app/getAppAccessToken', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ appId: c.appid, clientSecret: c.secret }),
  });
  const j = await r.json().catch(() => ({}));
  if (!j.access_token) throw new Error('getAppAccessToken 失败: ' + JSON.stringify(j));
  token = j.access_token;
  tokenExp = Date.now() + (parseInt(j.expires_in || 7200, 10) - 120) * 1000;
  return token;
}
async function getGateway(c, tk) {
  const base = c.sandbox ? 'https://sandbox.api.sgroup.qq.com' : 'https://api.sgroup.qq.com';
  const r = await fetch(base + '/gateway', { headers: { Authorization: 'QQBot ' + tk } });
  const j = await r.json().catch(() => ({}));
  if (!j.url) throw new Error('获取网关失败: ' + JSON.stringify(j));
  return j.url;
}
async function forward(c, msg) {
  const body = JSON.stringify(msg);
  const ts = String(Math.floor(Date.now() / 1000));
  const sig = signHex(c.secret, ts + body);
  try {
    const u = new URL(FORWARD_URL);
    const headers = {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
      'X-Signature-Timestamp': ts,
      'X-Signature-Ed25519': sig,
      'User-Agent': 'ClassFund-QQGateway',
    };
    // 回环转发必须显式带上站点 Host，否则 nginx 命中默认站点
    if (c.host) { headers['Host'] = c.host; headers['X-Forwarded-Host'] = c.host; }
    const isTls = u.protocol === 'https:';
    const mod = isTls ? https : http;
    const opts = {
      host: u.hostname,
      port: u.port || (isTls ? 443 : 80),
      path: u.pathname + u.search,
      method: 'POST',
      headers,
    };
    if (isTls) { opts.servername = c.host || u.hostname; opts.rejectUnauthorized = false; }
    await new Promise(function (resolve, reject) {
      const req = mod.request(opts, function (res) { res.resume(); res.on('end', resolve); });
      req.setTimeout(15000, function () { req.destroy(new Error('forward timeout')); });
      req.on('error', reject);
      req.write(body); req.end();
    });
  } catch (e) { log('forward 失败', String(e)); }
}

async function connect() {
  const c = readCfg();
  if (!c || !c.appid || !c.secret) { status({ connected: false, phase: 'noconfig', error: '未配置 AppID/AppSecret' }); return setTimeout(connect, 15000); }
  try {
    if (!token || Date.now() > tokenExp) await getToken(c);
    const url = await getGateway(c, token);
    status({ connected: false, phase: 'connecting', error: '' });
    ws = new WebSocket(url);
    ws.addEventListener('open', function () { log('WS open'); });
    ws.addEventListener('message', function (ev) {
      let m; try { m = JSON.parse(typeof ev.data === 'string' ? ev.data : ev.data.toString()); } catch (e) { return; }
      if (m.op === 10) {
        const intents = c.intents || ((1 << 25) | (1 << 30) | 1);
        log('identify intents=' + intents + ' (GROUP_AND_C2C=' + (!!(intents & (1 << 25))) + ')');
        ws.send(JSON.stringify({ op: 2, d: { token: 'QQBot ' + token, intents: intents, shard: [0, 1], properties: { $os: 'linux', $browser: 'classfund', $device: 'classfund' } } }));
        if (hb) clearInterval(hb);
        hb = setInterval(function () { try { ws.send(JSON.stringify({ op: 1, d: seq })); } catch (e) {} }, (m.d && m.d.heartbeat_interval) ? m.d.heartbeat_interval : 45000);
      } else if (m.op === 0) {
        if (m.s !== undefined && m.s !== null) seq = m.s;
        // 原始事件日志：QQ 推来的每个事件都记一行，便于确认平台到底有没有发消息事件
        log('event t=' + (m.t || '(none)') + ' s=' + (seq === null ? '-' : seq));
        status({ connected: true, phase: 'online', lastEvent: new Date().toISOString(), type: m.t || '', error: '' });
        // 只转发「消息类」事件；READY/RESUMED 等会话事件属于连接状态，转发过去只会刷屏后台回调日志，
        // 把真实的群/单聊消息挤出 20 条上限（连接状态已由 status.json 展示）。
        if (m.t && MSG_EVENTS.indexOf(m.t) !== -1) forward(c, m);
      } else if (m.op === 11) {
        status({ connected: true, phase: 'online', lastHeartbeat: new Date().toISOString(), type: last.type || '', error: '' });
      } else if (m.op === 7) {
        log('op7 重连'); try { ws.close(); } catch (e) {}
      } else if (m.op === 9) {
        log('op9 session 失效'); token = null; try { ws.close(); } catch (e) {}
      }
    });
    ws.addEventListener('close', function () { if (hb) clearInterval(hb); status({ connected: false, phase: 'closed', error: '连接关闭，重连中' }); setTimeout(connect, 5000); });
    ws.addEventListener('error', function () { status({ connected: false, phase: 'error', error: 'WebSocket 错误' }); });
  } catch (e) {
    log('connect 失败', String(e && e.message ? e.message : e));
    status({ connected: false, phase: 'error', error: String(e && e.message ? e.message : e) });
    setTimeout(connect, 10000);
  }
}

setInterval(function () {
  const before = cfgMtime;
  readCfg();
  if (cfgMtime !== before && ws) { log('配置更新，重连'); try { ws.close(); } catch (e) {} }
}, 5000);

process.on('uncaughtException', function (e) { log('uncaught', String(e)); });
connect();
