# 🚀 缓存加速配置说明（宝塔 + Cloudflare）

> 班级班费管理系统缓存加速配置（2026-08-25），含 502/525 故障修复、双域名架构、宝塔侧配置与 Cloudflare 面板操作步骤。

## 0️⃣ 双域名架构（重要）

| 域名 | 角色 | 入口 | Nginx 监听 |
|---|---|---|---|
| `classfund.hw.陌沫.cn` | **主域名（优选 IP 直连）** | Cloudflare 边缘 → 回源 443（带 PROXY 协议头） | 443：`ssl proxy_protocol` |
| `classfund.陌沫.cn` | 备用域名（Cloudflare Tunnel） | cloudflared → `localhost:80`（普通 HTTP，无 PROXY 头） | 80：普通 |

**关键**：80 与 443 的监听方式必须不同——
- **80 不带 `proxy_protocol`**：cloudflared 隧道转发到 80 时不发 PROXY 头；
- **443 带 `proxy_protocol`**：hw 优选 IP 的回源链路（CF 面板开启 PROXY 协议）依赖它。

⚠️ 两个监听不能互换，否则对应域名 502/525。

## 一、本次修复的两个故障

### 故障 1：全站 502（2026-08-25 排查）
- **根因**：所有站点 80/443 监听都带 `proxy_protocol`，而 Cloudflare Tunnel（备用域名入口）转发到 `localhost:80` 不带 PROXY 头 → nginx 等协议头超时 → 502。
- **修复**：80 全部去掉 `proxy_protocol`；443 保留（hw 需要）。

### 故障 2：hw 主域名 525（SSL 握手失败）
- **根因**：上一轮修复时把 443 的 `proxy_protocol` 也去掉了，而 hw 回源（CF 面板开启 PROXY 协议）带 PROXY 头 → nginx 不认 → 525。
- **修复**：恢复 443 的 `proxy_protocol`（`listen 443 ssl proxy_protocol;`），80 保持普通。

### 修改的 nginx 文件（均已备份 `*.bak.<时间戳>`）
| 文件 | 修改 |
|---|---|
| `/www/server/panel/vhost/nginx/classfund.xn--gvwz10g.cn.conf` | 80 去 proxy_protocol；443 保留 proxy_protocol |
| `/www/server/panel/vhost/nginx/cs.hw.xn--gvwz10g.cn.conf`、`0.default.conf`、`python_okxai.conf` | 80 去 proxy_protocol（消除 0.0.0.0:80 端口协议冲突） |
| `/www/server/nginx/conf/nginx.conf` | `real_ip_header` → `CF-Connecting-IP`（tunnel 入口取真实访客 IP） |

## 二、宝塔侧缓存配置（已完成）

| 项目 | 状态 | 说明 |
|---|---|---|
| PHP-FPM 进程模式 | ✅ 本次优化 | `pm = ondemand` → `dynamic`（5 worker 常驻，消除冷启动延迟；原 ondemand 空闲后首请求要拉起 worker，慢 1-4s） |
| Gzip 压缩 | ✅ 已有 | nginx 全局，comp_level 5 |
| PHP OPcache | ✅ 已有 | 128MB / 10000 文件 |
| 静态资源浏览器缓存 | ✅ 已有 | CSS/JS/图片/字体 **1 年 immutable**（assets 带 `?v=` 版本号） |
| 登录页（访客 HTML）缓存头 | ✅ 项目已输出 | `Cache-Control: public, max-age=7200, s-maxage=7200`（未登录）；已登录 `no-store` |
| API 响应 | ✅ 本次新增 | `api.php` 全部 `no-store`，数据实时 |
| 宝塔「网站加速」插件 | ✅ 已关闭 classfund | 其 `X_CACHE_KEY` Set-Cookie 会阻断 CF 缓存 HTML（config.json 中 `open=false`，勿重新开启） |
| HTTP/2 + HTTP/3(QUIC) | ✅ 已有 | 宝塔已开启 |

> 动态页**未**启用 Nginx FastCGI 缓存：已登录用户 no-store、访客页由 CF 边缘缓存（s-maxage=7200），双层缓存会引入陈旧/穿透风险，收益低。

## 三、Cloudflare 缓存规则（已通过 API 配置完成 ✅）

> 已用你提供的 CF API Token 配置完毕（2026-08-25），无需面板操作。

### 关键：两个域名分属两个 Zone，规则分别配置

| 域名 | 所在 CF Zone | 回源链路 |
|---|---|---|
| `classfund.hw.陌沫.cn`（主） | **`momocn.de5.net`**（SaaS 自定义主机名，回源 `yuan.momocn.de5.net` 内网穿透） | 优选 IP → CF 边缘 → 自定义主机名 → 穿透 → nginx 443（PROXY 协议） |
| `classfund.陌沫.cn`（备） | **`陌沫.cn`**（CNAME → cfargotunnel.com） | CF 边缘 → Cloudflare Tunnel → `localhost:80` |

⚠️ Cache Rules 必须配在**各自域名所属的 zone**——之前配错 zone 导致 hw 一直 DYNAMIC。

### 已生效的规则（每个 zone 各 2 条）

**规则 A（HTML）**：无 cookie 的 GET/HEAD → Cache Everything + 边缘缓存 2h + 浏览器缓存 2h
```
http.host eq "<该域名>" and http.request.method in {"GET" "HEAD"} and not http.cookie contains "PHPSESSID"
```
**规则 B（静态资源）**：`/assets/` → Cache Everything + 边缘+浏览器缓存 1 年
```
http.host eq "<该域名>" and http.request.uri.path contains "/assets/"
```

> 登录用户带 PHPSESSID cookie 自动绕过缓存（DYNAMIC，数据实时）；POST 写操作不缓存。

### 全局 Browser Cache TTL（未能改，无需担心）
API 对该设置返回 405（认证方案限制），但**规则级 browser_ttl 已覆盖**（HTML 2h、静态 1 年），效果等价。如想在面板顺手改：Caching → Configuration → Browser Cache TTL → **1 month**。

## 四、验证结果（2026-08-25）

| 测试 | 结果 |
|---|---|
| hw 首页 | **cf-cache-status: HIT**，首字节 **0.37~0.85s**（缓存前 1.7~7s） |
| tunnel 首页 | **HIT**，首字节 0.6~1.4s |
| 静态资源（两域名） | HIT（1 年 immutable） |
| 带 PHPSESSID 请求 | DYNAMIC（绕过缓存 ✅） |
| POST 写操作 | DYNAMIC（不缓存 ✅） |

## 五、改动文件清单（本仓库）

登录 CF 控制台 → 域名 `陌沫.cn` → **Caching / 缓存**。

### 1️⃣ 创建缓存规则（Caching → Cache Rules → Create rule）

**规则 A：HTML 页面缓存（核心提速项）**
- 名称：`classfund HTML`
- 匹配表达式（**两个域名都要覆盖**）：
  ```
  (http.host in {"classfund.hw.xn--gvwz10g.cn", "classfund.xn--gvwz10g.cn"}) and not http.cookie contains "PHPSESSID"
  ```
- 操作：Cache eligibility = **Eligible for cache**；Edge TTL = **2 hours**；Browser TTL = **2 hours**
- **Deploy**

> 效果：未登录访客页面由 CF 全球边缘缓存 2 小时，命中后**不再回源**（当前 hw 主域名每次回源跨境 1.7-7s，命中边缘缓存后首字节 ~0.2s）。登录用户带 PHPSESSID cookie 自动绕过，数据实时。

**规则 B：静态资源长缓存（可选，CF 默认已缓存静态）**
- 匹配：`http.request.uri.path starts_with "/assets/"`
- 操作：Edge TTL **1 month**，Browser TTL **1 month**

### 2️⃣ 调整浏览器缓存 TTL（重要）
Caching → Configuration → **Browser Cache TTL** → **1 month**（默认 4h 会截断源站 1 年缓存头）。

### 3️⃣（可选）hw 域名慢时换优选 IP 节点
hw 走 `cf.cloudflare.182682.xyz` 的优选 IP（当前解析 104.18.34.x）。跨境回源偶发 TLS 建连 3-7s，可尝试把优选 IP 换成香港（HKG）等离源站更近的节点，或在规则 A 生效后观察（边缘缓存命中后回源极少，此问题基本消失）。

## 四、验证方法

```bash
# 两个域名都应 200
curl -sI https://classfund.hw.陌沫.cn/ | head -1
curl -sI https://classfund.陌沫.cn/  | head -1
# 规则 A 生效后，HTML 出现 cf-cache-status: HIT（之前 DYNAMIC）
# 静态资源
curl -sI https://classfund.hw.陌沫.cn/assets/css/style.css | grep -i 'cf-cache-status'
```

### 兜底：前端 API 自动重试（2026-08-25 补充）
偶发 525 时（CF 回源跨境抖动，~10%），前端 `api()` 已对 **525/502/504** 自动重试 1 次（延迟 800ms，请求未到达业务层故安全）。app.js 版本号升至 `?v=21`。
> 用户浏览器首次需 Ctrl+F5 强制刷新一次（HTML 缓存 2 小时）。

## 五、改动文件清单（本仓库）

- `api.php`：新增 API no-store 响应头
- `.htaccess`：补充静态资源浏览器缓存段（Apache/虚拟主机场景）
- `CACHE.md`：本文档
