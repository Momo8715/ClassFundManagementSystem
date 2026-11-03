<div align="center">

# 📒 班级班费管理系统

**简单 · 安全 · 开箱即用** 的班级收支管理工具

> 上传即用 · 无需命令行 · 宝塔面板友好 · 支持远程一键升级

[![版本](https://img.shields.io/badge/版本-v1.18.1-6366f1?style=for-the-badge)](https://github.com/Momo8715/ClassFundManagementSystem/releases)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com)
[![Status](https://img.shields.io/badge/状态-稳定-22c55e?style=for-the-badge)](https://github.com/Momo8715/ClassFundManagementSystem)
[![License](https://img.shields.io/badge/许可证-保留所有权利-8b5cf6?style=for-the-badge)](LICENSE)

[![Stars](https://img.shields.io/github/stars/Momo8715/ClassFundManagementSystem?style=for-the-badge&logo=github&label=Stars&color=gold)](https://github.com/Momo8715/ClassFundManagementSystem/stargazers)
[![Forks](https://img.shields.io/github/forks/Momo8715/ClassFundManagementSystem?style=for-the-badge&logo=github&label=Forks&color=4f46e5)](https://github.com/Momo8715/ClassFundManagementSystem/forks)
[![Watchers](https://img.shields.io/github/watchers/Momo8715/ClassFundManagementSystem?style=for-the-badge&logo=github&label=Watchers&color=0ea5e9)](https://github.com/Momo8715/ClassFundManagementSystem/watchers)
[![Issues](https://img.shields.io/github/issues/Momo8715/ClassFundManagementSystem?style=for-the-badge&logo=github&label=Issues&color=22c55e)](https://github.com/Momo8715/ClassFundManagementSystem/issues)
[![Last Commit](https://img.shields.io/github/last-commit/Momo8715/ClassFundManagementSystem?style=for-the-badge&logo=github&label=最近提交&color=10b981)](https://github.com/Momo8715/ClassFundManagementSystem/commits)
[![Repo Size](https://img.shields.io/github/repo-size/Momo8715/ClassFundManagementSystem?style=for-the-badge&logo=github&label=仓库大小&color=f59e0b)](https://github.com/Momo8715/ClassFundManagementSystem)

---

## 🧭 导航

[✨ 功能特性](#-功能特性) · [🚀 快速开始](#-快速开始) · [🎨 界面亮点](#-界面亮点) · [🔧 系统要求](#-系统要求) · [📁 项目结构](#-项目结构) · [🛡️ 安全设计](#-安全设计) · [🔄 远程升级](#-远程升级) · [📌 版本历史](#-版本历史) · [🤝 贡献](#-贡献) · [📄 许可证](#-许可证)

</div>

---

## ✨ 功能特性

<table align="center">
<tr>
<td width="33%"><b>💰 收支管理</b><br><sub>收入/支出分类管理 · 子分类 + 来源信息<br>凭证图片上传 · Excel 一键导入导出</sub></td>
<td width="33%"><b>👥 多角色权限</b><br><sub>班主任 / 管理员 / 班长 / 副班长<br>财务委员 / 同学 · 细粒度权限矩阵</sub></td>
<td width="33%"><b>📋 花名册 & 缴费</b><br><sub>班级名单一键导入 · 免缴学生设置<br>多轮缴费明细追踪 · 欠费名单导出</sub></td>
</tr>
<tr>
<td width="33%"><b>📜 操作日志</b><br><sub>全操作留痕 · 不可删除不可修改<br>记录 IP / UA / 浏览器指纹</sub></td>
<td width="33%"><b>🛡️ 安全分析</b><br><sub>同指纹多账号检测 · 同账号多 IP 告警<br>登录失败统计 · 登录历史审计</sub></td>
<td width="33%"><b>🌗 主题 & 体验</b><br><sub>暗色 / 亮色自动切换<br>QQ / 微信内打开友好 · 移动端响应式</sub></td>
</tr>
<tr>
<td width="33%"><b>🔄 远程升级</b><br><sub>后台一键检查更新 · 自动备份后升级<br>可随时回滚</sub></td>
<td width="33%"><b>🗑️ 回收站</b><br><sub>软删除保护 · 误删可恢复<br>彻底删除需二次确认</sub></td>
<td width="33%"><b>📊 数据仪表盘</b><br><sub>收支趋势图表 · 支出分类占比<br>最近收支速览 · 学期报表</sub></td>
</tr>
<tr>
<td width="33%"><b>📦 学期结转</b><br><sub>归档冻结 · 期初承接 · 快照报表<br>可取消归档 / 重新结账</sub></td>
<td width="33%"><b>🩺 数据体检</b><br><sub>轮次对账 · 支付核销 · 凭证/账号<br>一键修复可安全处理的项</sub></td>
<td width="33%"><b>🔐 两步验证</b><br><sub>TOTP 动态码 · 一次性备用码<br>首次登录强制改密 / 管理员策略</sub></td>
</tr>
<tr>
<td width="33%"><b>💾 数据备份</b><br><sub>数据库 + uploads 一键备份<br>下载 / 恢复 / 定时任务</sub></td>
<td width="33%"><b>🖼️ 凭证自愈</b><br><sub>Web 回退版 · 校验哈希 · 去 EXIF<br>原图丢失仍能查看</sub></td>
<td width="33%"><b>🧾 审计 Diff</b><br><sub>编辑前后字段对比<br>归档行只读锁定</sub></td>
</tr>
<tr>
<td width="33%"><b>🤖 机器人插件</b><br><sub>远端绑定 · 缴费链接/下单<br>通知轮询 · 群推送</sub></td>
<td width="33%"><b>📨 通知队列</b><br><sub>支付成功 / 金额变动<br>插件 poll/ack</sub></td>
<td width="33%"><b>🔗 自动选学生</b><br><sub>?pay= / ?student=<br>登录学生默认本人</sub></td>
</tr>
</table>

---

## 🚀 快速开始

> ⏱️ 全程约 **3 分钟**，无需任何命令行操作

1. **上传** — 把项目文件上传到网站根目录
2. **访问** — 浏览器打开你的域名
3. **安装** — 自动进入安装向导，填写数据库信息并一键建表
4. **完成** — 创建管理员账号，开始使用 ✅

### 📦 宝塔面板部署（推荐）

| 步骤 | 操作 |
|:---:|------|
| **1** | 宝塔面板 → 网站 → 添加站点（PHP 版本选 **8.2**） |
| **2** | 将项目文件上传至站点根目录 |
| **3** | 宝塔 → 数据库 → 创建数据库（记下库名 / 用户名 / 密码） |
| **4** | 访问站点域名，跟随安装向导完成部署 |
| **5** | 🛡️ 建议删除 `install.php` 并开启 SSL |

### ☁️ 其他环境

| 环境 | 说明 |
|------|------|
| **虚拟主机** | 上传至 `public_html`，在主机面板创建 MySQL 数据库后访问安装向导 |
| **Docker** | 使用 `php:8.2-apache` 或 `php:8.2-fpm` + MySQL 8 镜像，挂载项目目录 |

---

## 🎨 界面亮点

<div align="center">

| 特性 | 效果 |
|:---:|:---:|
| 🎇 登录页 | 深蓝紫渐变 + 光晕动效 + 玻璃拟态卡片 + 入场动画 |
| 🎨 视觉升级 | 渐变徽章 / 扫光按钮 / 卡片光条 / 暗色主题全适配 |
| 📱 多设备自适应 | 手机 / 平板 / 桌面 / 超宽屏 / 横屏全尺寸适配 |
| ⚡ 缓存加速 | Cloudflare 边缘缓存 + 静态资源 1 年 immutable + gzip |
| 🛡️ 安全兜底 | 网关错误自动重试 · API 禁止缓存 · 真实 IP 审计 |

</div>

---

## 🔧 系统要求

| 依赖 | 版本 | 说明 |
|:---:|:---:|------|
| 🐘 PHP | ≥ 8.0 | 需要 `pdo_mysql`、`json`、`mbstring` 扩展 |
| 🗄️ MySQL | ≥ 5.7 | 建议 8.0，字符集 `utf8mb4` |
| 📦 ZipArchive | — | Excel 导入导出需要 |

> 💡 宝塔面板 → 软件商店 → PHP 设置 中可一键启用所需扩展

---

## 📁 项目结构

```text
ClassFundManagementSystem/
├── index.php               # 前端入口（单页应用 SPA）
├── api.php                 # API 路由器（全部请求入口）
├── config.php              # 配置 + 自动迁移 + 权限矩阵
├── install.php             # 安装向导（部署后可删除）
├── schema.sql              # 数据库结构
├── version.json            # 版本信息（远程升级用）
├── sw.js                   # Service Worker（PWA 离线缓存）
├── manifest.json           # PWA 应用清单
├── .htaccess               # Apache 安全规则
├── pay_config.example.php  # 支付通道配置示例（复制为 pay_config.php）
├── assets/
│   ├── css/style.css       # 样式（暗色主题 / 响应式）
│   ├── js/app.js           # 核心前端逻辑
│   ├── js/pay.js           # 在线缴费 / 催缴 / 群机器人配置
│   ├── js/security.js      # 安全分析面板
│   ├── js/tabs.js          # 通用二级分类页签
│   ├── js/modal-tx.js      # 收支弹窗增强
│   ├── js/login-2fa.js     # 两步验证登录
│   ├── js/account.js       # 账号安全 / 强制改密
│   ├── js/semester-carry.js# 学期结转 / 归档
│   ├── js/import-batches.js# 导入批次 / 撤销
│   ├── js/health.js        # 数据体检 / 对账中心
│   ├── js/backup.js        # 数据备份 / 恢复
│   ├── js/policy.js        # 管理员 2FA 策略
│   ├── js/archive-lock.js  # 归档行锁定
│   ├── js/log-diff.js      # 编辑前后 Diff
│   ├── js/legacy-image.js  # 旧直连图片改走 API
│   ├── js/bot-notify.js    # 机器人通知队列管理
│   ├── js/common.js        # 新模块公共 API/转义/toast
│   ├── img/                # 图标与图片
│   └── vendor/             # 第三方前端库（Chart.js 等）
├── src/
│   ├── auth.php            # 登录 / 访客 / 修改密码
│   ├── transactions.php    # 收支 CRUD + 仪表盘
│   ├── users.php           # 用户与角色 / 封禁
│   ├── payments.php        # 花名册 + 缴费追踪
│   ├── pay.php             # 支付模块入口（拆分后加载 core/reminder/qq_bot）
│   ├── pay_core.php        # 在线支付：配置 / 下单 / 回调 / 核销
│   ├── pay_reminder.php    # 催缴通知 / 群机器人推送
│   ├── qq_bot.php          # QQ 官方机器人指令 / 网关托管
│   ├── bot_api.php         # 机器人插件 API（绑定/缴费/通知轮询）
│   ├── bot_notify.php      # 通知队列 / 支付与金额变动 / 群推送
│   ├── fee_ledger.php      # 统一费用账本（应缴/已缴/待缴唯一口径）
│   ├── semesters.php       # 学期结转 / 归档快照 / 冻结
│   ├── health.php          # 数据体检 / 对账中心
│   ├── backup.php          # 数据库 + uploads 备份 / 恢复
│   ├── cron.php            # 定时备份/清理/体检/机器人代推
│   ├── import_batches.php  # 导入批次 / 去重 / 整批撤销
│   ├── totp.php            # TOTP（RFC 6238）纯 PHP 实现
│   ├── twofa.php           # 两步验证接口 / 登录挑战
│   ├── import_export.php   # Excel 导入导出 / 图片上传
│   ├── report.php          # 报表与 PDF 导出（支持冻结快照）
│   ├── logs.php            # 操作日志
│   ├── security.php        # 安全分析 / 回收站
│   ├── upgrade.php         # 远程升级
│   ├── tfpdf.php           # PDF 生成库（tFPDF，支持中文）
│   ├── helpers.php         # CSRF / 限流 / 校验 / 工具
│   └── font/unifont/       # 中文字体与字体指标
├── tools/
│   ├── qqgw/               # QQ 官方机器人 WebSocket 网关（零依赖 Node）
│   ├── smoke.php           # 纯函数冒烟测试
│   ├── reset_2fa.php       # 2FA 应急恢复 CLI
│   └── check-js.js         # 前端 JS 语法检查
├── tests/                  # MySQL 集成测试（CI 使用）
├── src-js/app.js           # app.js 可读源码（esbuild 构建）
├── package.json            # 前端构建脚本（esbuild / prettier）
├── deploy.sh               # Linux 部署脚本
├── deploy.bat              # Windows 部署脚本
└── uploads/                # 凭证图片（自动创建）
```

---

## 🛡 安全设计

本项目将安全作为一等公民，内置多层防护：

| 防护层 | 实现方式 |
|:---:|----------|
| 🔐 CSRF 防护 | 每次会话生成随机 Token，写操作强制校验 |
| 👤 会话安全 | 登录后重新生成 Session ID（防会话固定攻击） |
| ⏱️ 速率限制 | 登录接口每 IP 每分钟限 5 次，文件锁原子计数 |
| 🧹 XSS 防护 | 前端内容统一转义（含引号）+ 图片 URL 协议白名单，后端输入校验与长度限制 |
| 📜 操作日志 | 应用层**永不执行** UPDATE/DELETE，审计不可篡改 |
| 🖥️ 登录审计 | 每次登录尝试均记录 IP / UA / 浏览器指纹 / 失败原因 |
| 🔍 异常检测 | 同指纹多账号、同账号多 IP、同 IP 多账号、暴力破解统计 |
| 🚫 IP 黑名单 | 一键封禁可疑 IP，API 层强制拦截；失败原因分布与安全事件流 |
| 🧾 安全事件 | CSRF 失败 / 非法上传 / 被拦截访问等落库留痕，面板可查 |
| 📊 安全审计 | 24h/7天/30天/全部筛选 · 用户安全画像 · 登录时段分布 · 新 IP 登录 · 登录明细分页下钻 · CSV 导出 |
| 🧬 设备指纹 | 浏览器/系统/分辨率/时区/核心数哈希指纹（24 位十六进制），用于同指纹多账号检测 |
| 🗑️ 软删除 | 收支记录删除进回收站，可恢复，防误删 |
| 🖼️ 上传安全 | 图片按 MIME 签名校验，扩展名白名单，目录禁执行脚本 |
| 🚫 权限矩阵 | 10+ 项细粒度权限，多角色取最高优先级 |
| 📦 升级安全 | 升级包来源白名单 + 可选 sha256 完整性校验 + 下载/解压体积上限 |
| 🔒 敏感文件 | `.htaccess` 随仓库发布并封禁 `db_config.json` / `config.php` / `schema.sql` / `backup_*` |
| 🔐 两步验证 | TOTP（RFC 6238）动态码 + 8 个一次性备用码；密码通过后仍需第二因子 |
| 🔑 初始密码 | 新建/导入学生随机生成初始密码，首次登录强制修改；管理员重置同样强制改密 |
| 🧊 归档冻结 | 已归档学期的收支/导入只读；在线缴费仍可入账但会标记快照过期，需重新结账 |
| 📥 导入批次 | 每次导入生成批次号，可在导入页整批撤销（软删除进回收站） |
| 💾 数据备份 | 数据库全部表 + uploads 打包为单个 zip；后台创建/下载/恢复；cron token 定时执行 |
| 🖼️ 凭证自愈 | 上传时去 EXIF/限尺寸/记 sha256；原文件丢失回退 Web 版或缩略图，不再 404 |
| 🔒 上传访问 | 建议 nginx 禁止 `/uploads/` 直连，图片统一走登录态 API；旧直连路径自动改写 |
| 🛡️ 2FA 策略 | 可要求管理员/班主任启用 2FA，设置宽限期；到期限制其他接口，提供 CLI 应急恢复 |
| 🧾 审计 Diff | 编辑收支记录时保存 before/after/changed，日志页可展开对比 |
| 🧪 集成测试 | GitHub Actions 起 MySQL，覆盖迁移、备份恢复、凭证、归档、2FA、cron |

### 🌐 nginx 部署必读（宝塔默认使用 nginx）

`.htaccess` 只对 Apache 生效。nginx 站点请务必在 **网站 → 配置文件** 的 `server {}` 中加入：

```nginx
# 禁止下载数据库配置与建表脚本，禁止访问升级备份目录
location ~* (db_config\.json|config\.php|schema\.sql)$ { return 404; }
location ^~ /backup_ { return 404; }
# 凭证原图只允许通过登录态 api.php?action=tx_image 读取
location ^~ /uploads/ { deny all; }
```

否则 `db_config.json`（含数据库账号密码）可能被直接下载。

---

## ⚙️ 配置管理

管理员登录后侧边栏的 **⚙️ 配置管理**（与原「安全分析」分离）集中了系统级配置，含三个二级页签：

| 页签 | 内容 |
|---|---|
| 💳 支付通道 | 在线支付开关、核销模式、支付方式与多通道（易支付 / V免签），密钥仅存服务器 |
| 📣 群机器人 | 催缴/通知推送通道：企业微信 / 飞书 / 钉钉 / QQ 官方机器人 / 自定义；QQ Webhook 互动查询回调地址。界面内已内置各平台的获取步骤与官方文档链接 |
| 🔄 远程升级 | 检查更新、一键升级 |

各机器人获取地址（界面内可点击）：

- 企业微信：[群机器人配置说明](https://developer.work.weixin.qq.com/document/path/91770)
- 飞书：[自定义机器人使用指南](https://open.feishu.cn/document/client-docs/bot-v3/add-custom-bot)
- 钉钉：[自定义机器人接入](https://open.dingtalk.com/document/orgapp/custom-robot-access)
- QQ 官方机器人：[QQ 开放平台](https://q.qq.com) · [官方文档](https://bot.qq.com/wiki/)
- 自定义：任意可接收 `POST JSON` 的 https 地址

> 仅管理员（`viewSecurity`）可见；非管理员的页面响应中不包含该分类。

---

## 📦 学期结转与归档

在 **学期报表 → 学期管理** 中使用（班主任 / 管理员）：

- 点击 **📦 归档并结转**：系统冻结该学期的期初/期末余额、收支笔数、班费收缴与分类/月度报表快照，并可选**同时创建下一学期**，把期末余额作为下一学期的期初余额（`opening_balance`）。
- **不生成额外的「结转收入」账目**：余额通过期初快照承接，避免总账重复计钱。
- 归档后该学期的收支**只读**：新增/编辑/删除/导入都会被拒绝；如确需修改，先 **♻️ 取消归档**。
- 归档后仍可能收到在线缴费（晚交）。系统允许入账，但会把该学期标记为 **快照待刷新**；在「数据体检」点 **🔄 重新结账** 即可重算快照。
- 历史报表使用归档快照，不会因后续数据变化而漂移；未归档学期仍按日期实时计算。

## 🩺 数据体检 / 对账中心

侧边栏 **🩺 数据体检**（班主任 / 管理员 / 财务可见，修复仅班主任 / 管理员）：

| 检查组 | 典型问题 |
|---|---|
| 学期与结转 | 学期日期重叠、多个进行中、归档快照过期、流水未归入学期 |
| 班费轮次 | 轮次金额与缴费名单不一致、缺少每人应缴、学生已缴超过应缴、在线缴费未归属 |
| 在线支付 | 已支付未核销、超 24h 未支付、核销记录找不到订单/指向已删账目 |
| 凭证图片 | 孤儿图片、指向不存在账目、原图文件缺失（可回退/仅缩略图） |
| 备份恢复 | 无备份、备份过期、备份包损坏 |
| 账目与账号 | 金额/日期异常、疑似重复、用户绑定了失效花名册学生 |
| 账号安全 | 未完成首次改密、管理员未启用两步验证、封禁账号 |

可安全处理的项提供一键修复（重新结账、回填学期归属/每人应缴、核销已支付订单、关闭超时订单、清理孤儿凭证、修复凭证路径/标记仅缩略图、立即备份、修复花名册绑定），修复前自动整站备份，全部写入操作日志。

## 🔐 两步验证与账号安全

侧边栏 **🔐 账号安全**（任何登录用户）：

- **启用两步验证**：生成 TOTP 密钥与 `otpauth://` 链接，输入验证器 6 位码确认；确认后显示 **8 个一次性备用码**（只显示一次，可复制/下载）。
- 登录时密码通过后仍需输入动态码或备用码；备用码使用后即失效。
- 支持 **重新生成备用码**、**关闭两步验证**（均需当前密码 + 验证码）。
- 管理员在「用户管理」重置密码后，用户下次登录会被要求修改密码；新建/导入学生使用随机初始密码，首次登录强制修改。
- 两步验证为纯 PHP 实现（RFC 6238），不依赖第三方服务，密钥只存服务器数据库。

## 📥 导入批次与整批撤销

- 每次 Excel / JSON 导入都会生成批次号，记录来源、文件、行数、金额与导入人。
- 导入时自动跳过**疑似重复**（同类型 + 同日期 + 同金额 + 同描述）与**已归档学期**的行，并在结果中说明。
- 在 **导入数据 → 导入批次** 中可对整批执行 **撤销整批**：记录软删除进入回收站，可逐条恢复；包含已归档学期记录的批次需先取消归档。

---

## 💳 在线支付（可选，支持易支付 / V免签，可多通道）

支持两种支付协议，可在管理界面为每个通道**自选类型**，并配置多个通道：

| 类型（driver） | 协议 | 需要的配置 |
|---|---|---|
| `epay`（易支付） | 彩虹易支付 `mapi.php`，MD5 签名 | 网关 / 商户ID / 商户密钥 |
| `vmqfox`（V免签） | V免签Fox `/api/order/create`，HMAC-SHA256 签名 | 网关 / 通讯密钥（不需要商户ID） |

流程：学生选择姓名与轮次 → 按支付方式自动选择可用通道 → 生成支付二维码/跳转链接 → 支付成功**异步回调自动核销**并生成账目。

**部署配置（推荐：管理员界面直接设置）：**

登录管理员账号 → **⚙️ 配置管理 → 💳 支付通道** → 「添加通道」，选择**类型**（易支付 / V免签），填写网关与密钥，勾选「启用此通道」并保存即可（密钥保存后仅回显后 4 位）。

也可手动配置文件：
```bash
cp pay_config.example.php pay_config.php
# 编辑 pay_config.php 的 channels[]：每个通道填 id/name/driver/gateway/pid/key/types/enabled
```

| 配置项 | 说明 |
|---|---|
| `driver` | 通道类型：`epay` 易支付 / `vmqfox` V免签（省略时按 `epay` 处理） |
| `gateway` | 网关地址（不带末尾斜杠） |
| `pid` / `key` | 商户 ID / 密钥（V免签不需要 `pid`，`key` 填通讯密钥） |
| `types` | 该通道支持的支付方式，如 `['alipay','wxpay']` |
| `settle_mode` | `auto` 支付成功自动核销；`manual` 班委手动确认核销 |
| `default_type` | 默认渠道 `alipay` / `wxpay` / `qqpay` |
| 回调地址 | 默认 `https://你的域名/api.php?action=pay_notify`（免登录、按通道类型强制验签） |

> ⚠️ `pay_config.php` 含商户密钥，已在 `.gitignore` 中，**请勿提交或写入数据库**。
> 未配置时「在线缴费」按钮自动禁用，不影响原有记账/缴费追踪功能。
> 核销后**不再单独生成「线上缴费」账目**：款项直接并入它所缴的那一轮「班费收缴」（指定轮次并入该轮；通用缴费按待缴余额从旧到新依次并入），该生同时计入该轮缴费名单，因此轮次明细、缴费追踪与总账三处口径一致。
> 仅当款项无法归属到任何轮次（例如已缴清后又收到一笔款）时，才回退生成一条「线上缴费」收支记录，保证总账不丢钱。

---

## 📣 催缴通知

在 **缴费情况 → 📣 催缴通知** 页签：

- **一键生成未缴名单**：口径与缴费追踪一致（每人应缴 = 各轮 `per_person` 相加，减已缴）。
- **逐人催缴**：每个学生可复制专属文案，或复制**专属缴费链接** `?pay=<学生ID>`；学生打开后自动进入「在线缴费」并预选本人姓名。
- **复制全班文案**：一条汇总消息（未缴人数 / 合计待缴 / 名单 / 缴费入口），可直接发到班级群。
- **推送群机器人**（管理员在 **⚙️ 配置管理 → 📣 群机器人** 配置）：支持企业微信 / 飞书 / 钉钉 / **QQ 官方机器人** / 自定义 Webhook，一键把汇总推送到群。
- **每日自动催缴**：配置页勾选「每日自动催缴」，由 `cron.php?task=all`（或 `task=remind`）每天首次执行时推送一次；当天已发过不会重复。

| 机器人类型 | 需要的配置 | 发送方式 |
|---|---|---|
| 企业微信 | 群机器人 Webhook | `POST {``msgtype:markdown``}` |
| 飞书 | 群机器人 Webhook | `POST {``msg_type:text``}` |
| 钉钉 | 群机器人 Webhook | `POST {``msgtype:text``}` |
| **QQ 官方机器人** | BotAppID / AppSecret / **多个推送目标**（每个可选方向：QQ用户单聊 `openid` / QQ群 `group_openid` / 频道 `channel_id`），可添加多个同时推送 | `getAppAccessToken` 换 token，`Authorization: QQBot <token>`，`POST /v2/groups/{group_openid}/messages`（单聊 `/v2/users/{openid}/messages`，频道 `/channels/{channel_id}/messages`） |
| 自定义 | Webhook | `POST {``content, text``}` |

> Webhook 地址与 QQ AppSecret 仅存服务器数据库（`system_meta`），保存后不明文回显；未配置时不影响列表与复制功能。
> QQ 官方机器人需在 [QQ 开放平台](https://q.qq.com) 创建并配置，且机器人需保持在 WebSocket 网关在线；QQ用户/QQ群填对应 `openid`、频道填 `channel_id`，主动消息可能有频次限制。
> 配置页会**自动显示 Webhook 回调地址**（`https://你的域名/api.php?action=qq_webhook`）并可一键复制，把它填到 QQ 开放平台的「Webhook 回调」即可。

### 📨 自定义消息推送

催缴页签下方可输入任意内容（通知 / 公告），点「发送到群」即推送到当前配置的群机器人；企业微信 / 飞书 / 钉钉 / 自定义均可用（QQ 官方对**主动**消息有限制，QQ 群更适合用下面的被动回复）。

### 🤖 QQ 机器人互动查询（被动回复）

在 QQ 开放平台把机器人的 **Webhook 回调地址**设为：

```
https://你的域名/api.php?action=qq_webhook
```

- 回调校验（`op=13`）与事件签名均使用 **Ed25519**：私钥种子由 AppSecret 重复/截断到 32 字节派生，签名内容为 `timestamp + body`（校验用）或 `event_ts + plain_token`（回调校验）。
- **配置顺序很重要**：先在网站 **⚙️ 配置管理 → 📣 群机器人 → QQ 官方机器人** 保存 **AppID / AppSecret**，再去 QQ 开放平台填回调地址并点「校验」。否则平台回调会因拿不到 AppSecret 无法签名而返回 **400**，校验不通过。
- 回调地址所在卡片底部有 **「QQ 回调协议监测」**：记录 QQ 平台的每次回调（时间 / 来源 IP / UA / op / 事件类型 / 是否带签名 / 结果），可点「🔄 刷新」查看，便于排查校验失败。
- 仅需机器人**被动回复**（收到 @ 后回复），不受主动消息限制。
- 支持的指令：

| 指令 | 说明 |
|---|---|
| `帮助` / `菜单` / `指令` / `?` | 显示功能菜单（@机器人 不发内容也返回菜单） |
| `绑定 姓名` | 把当前 QQ（群成员/单聊/频道用户）绑定到花名册学生 |
| `解绑` | 解除当前绑定 |
| `查班费` / `我的班费` / `班费` | 回复该生累计应缴 / 已缴 / 待缴 + 专属缴费链接（永久免缴会提示） |
| `我的明细` / `缴费明细` / `明细` | 逐轮列出我的缴纳情况（已缴 / 待缴 / 免缴）与合计 |
| `我的链接` / `缴费链接` | 只回复我的专属在线缴费链接 |
| `缴费` / `交费` / `我要缴费` | 绑定学生后直接回复本人专属缴费链接（v1.18.1） |
| `本轮班费` / `最新一轮` | 最新一轮的日期、说明、每人应缴与全班应收 |
| `班级概况` / `结余` / `余额` | 总收入 / 总支出 / 结余 / 未缴人数与合计 |
| `收缴进度` / `进度` / `收缴率` | 应缴人数、已缴清、未缴清与收缴率 |
| `最近收支` / `最近记录` | 最近 10 笔收支记录（日期 / 金额 / 摘要） |
| `绑定管理员 验证码` | 绑定为管理员（验证码在网页「配置管理 → 群机器人 → 管理员绑定」生成，10 分钟有效、一次性） |
| `管理员指令` | 列出管理员可用指令 |
| `未缴名单` | 未缴清学生与金额（管理员） |
| `催缴` | 生成催缴名单；群里会对已绑定的未缴同学 @ 提示（管理员） |
| `私信催缴` | 给有单聊记录（已知 user_openid）的未缴同学发催缴私信（管理员） |
| `发通知 内容` | 推送到已配置的推送目标（管理员） |

> 未识别的指令会自动回复功能菜单。`查班费`、`我的明细`、`我的链接`、`缴费` 需先 `绑定 姓名`。

> **管理员绑定**与学生绑定互不影响（管理员用独立绑定键），可同时既是管理员又是学生。

> **接入方式**：QQ 开放平台选 **Webhook** 时，平台会把事件 POST 到回调地址；选 **WebSocket** 时由网关接收。本项目两种都支持，按平台实际配置即可。

> 群消息按 `群openid + 成员openid` 绑定，单聊按用户 openid，频道按 `channel_id + 用户`。

**指令面板与菜单配置**（**⚙️ 配置管理 → 📣 群机器人**，选 QQ 官方机器人后显示）：

- **指令面板**：以复选框列出全部指令，可逐条启用 / 停用（`帮助` 始终开启）；停用的指令机器人不再响应，也不会出现在菜单里。
- **菜单标题 / 菜单底部**：自定义「帮助」菜单的第一行与最后一行文案。
- **自定义关键词回复**：最多 20 条「关键词 → 回复」，用户消息包含该关键词时机器人回复对应内容（在内置指令之后匹配，不会覆盖内置指令）。
- **菜单预览**：实时展示机器人将发送的完整菜单文本。

### 🔌 保持机器人在线（WebSocket 网关，应用自动托管）

QQ 官方要求机器人**保持 WebSocket 网关在线**，后台才显示「已连接」，也才能**发送**消息（催缴 / 通知 / 被动回复）。
Webhook 只能接收事件，无法让机器人在线。

项目在 `tools/qqgw/` 内置了零依赖的 Node 网关客户端，**由应用自动托管，无需单独设置**：

- 在后台保存 **AppID / AppSecret** 后，系统自动把 `tools/qqgw/qqgw.js` 复制到运行目录并后台拉起；
  访问配置页、保存配置、发送推送、收到平台回调时都会自动检查并拉起（自愈）。
- 运行目录解析：`.qqgw_dir` → 环境变量 `QQGW_DIR` → 站点目录下 `.qqgw/`（默认，且必须在网站目录内以符合 PHP `open_basedir`）→ 临时目录。
- 客户端连接网关、维持心跳，并把事件转发给 `api.php?action=qq_webhook`（复用本页全部指令逻辑）；
  配置变更自动重连，内置**单实例锁**（`gateway.pid`）避免重复运行。
- 配置页「网关连接」显示 ✅ 已连接 / ❌ 未连接（含 Node 检测与错误原因）。
- 仅需 **Node.js 18+**（推荐 20/22）。若希望 systemd 常驻/开机自启，可另行执行 `sudo sh tools/qqgw/install.sh`（可选）。
- 自定义安装位置也可用环境变量 `QQGW_DIR` 指定，但**必须位于网站目录内**：面板 `disable_functions` 若禁用了 `exec`，应用无法自动拉起，请改用 `sudo sh tools/qqgw/install.sh` + systemd 常驻。
- 客户端转发事件固定走本地回环 HTTP 并显式携带站点 `Host` 头（`fetch` 不能设置 `Host`，会命中 nginx 默认站点导致 404）。

详见 `tools/qqgw/README.md`。

### 🔌 微信/QQ 插件 API（v1.18）

除 QQ 官方机器人外，也支持外部微信/QQ 插件通过 **`?bot_api=1`** 对接：

- **远端绑定**：`action=bind&user=<远端ID>&name=张三`，绑定关系存 `bot_bindings`，不再依赖 JSON 文件。
- **缴费**：`action=paylink` 返回 `?pay=<学生ID>#page-pay`；`action=pay` 可直接为已绑定学生创建支付订单。
- **通知**：支付成功、账目金额变动、插件自定义通知写入 `bot_notifications`；插件用 `action=poll` 拉取、`action=ack` 确认。
- **群推送**：配置群机器人后，站点会在请求结束时直接推送金额变动/到账通知（`push_status=sent`）；也可由插件轮询后自行推送，按 `push_status` 去重。
- **Token**：在「配置管理 → 📣 群机器人 → 机器人插件接口」查看/轮换；支持 `token` 参数或 `X-Bot-Token` 头。

完整端点、字段和验收清单见 [BOT_PLUGIN_API.md](BOT_PLUGIN_API.md)。

---

## 🔄 远程升级

系统内置一键升级：管理员在 **⚙️ 配置管理 → 🔄 远程升级 → 🔍 检查更新 → 🚀 立即升级**，自动完成：

```text
GitHub Release → 下载 ZIP → 全站自动备份 → 解压覆盖 → 完成
```

- ✅ 升级前自动备份到 `backup_日期/`，出问题随时回滚
- ✅ 不影响 `uploads/` 凭证图片与 `db_config.json` 数据库配置
- ✅ 升级包来源白名单校验（仅允许本仓库 Releases），并在 `version.json` 提供 `sha256` 时强制校验完整性
- ✅ 下载/解压均有体积上限，防止内存耗尽与 zip 炸弹

📖 详细发布教程见 [UPGRADE.md](UPGRADE.md)

---

## 🛠️ 开发与测试

> Node.js 仅用于前端构建与测试，线上运行不依赖 Node。

```bash
php tools/smoke.php                 # 纯函数冒烟
node tools/check-js.js              # 全部 JS 语法
npm ci && npm run build:app         # 修改 src-js/app.js 后重新构建 assets/js/app.js
bash tests/run.sh                   # MySQL 集成测试（68+ 断言，含机器人联调）
```

- **不要直接编辑** `assets/js/app.js`：它是 `src-js/app.js` 经 esbuild 压缩的产物；改源码后执行 `npm run build:app`。
- 集成测试默认创建临时库 `classfund_test`，需要 MySQL 客户端；CI 使用 MySQL 8.0 service，详见 `.github/workflows/ci.yml`。
- CI 会校验「重新构建后的 app.js 与提交一致」，防止源码与产物漂移。

## 📌 版本历史

| 版本 | 说明 |
|:---:|------|
| **v1.18.1** | 🤖 绑定后发送「缴费/交费」自动回本人缴费链接 · 🔔 每日自动催缴提醒（cron，一次/天）· 🧭 指令开关自动保存并提示 |
| **v1.18.0** | 🤖 机器人插件 API：远端绑定 / 插件缴费 / 通知队列（poll+ack）/ 支付成功与金额变动通知 / 群推送 · 🔗 缴费页 ?pay/?student 自动选学生 · 🧪 62+ MySQL 集成测试通过 |
| **v1.13.0** | ✨ 数据库 + uploads 一键备份/恢复/定时任务 · 🖼️ 凭证自愈（Web 回退/哈希/去 EXIF/缩略图回退）· 🩺 体检忽略/预演/修复前备份/定时告警 · 🛡️ 管理员 2FA 强制策略 · 🧾 审计 Diff + 归档行锁定 · 🧪 MySQL 集成测试进 CI · ⚙️ app.js 可读源码 + esbuild 构建 |
| **v1.12.0** | ✨ 真学期结转（归档快照 / 期初承接 / 冻结 / 重新结账）· 🩺 数据体检/对账中心 · 🔐 TOTP 两步验证与强制改密 · 📥 导入批次/去重/撤销 · 🧰 统一费用账本、拆分支付模块、同步 schema、回收站资源清理、凭证与代缴权限收紧 |
| **v1.11.4** | 🐛 班费收缴轮次支持「先建轮次、0 人 0 元」；进入收缴默认全部缴纳；校验提示改为明确原因（不再笼统报「无效金额」） |
| **v1.11.3** | 🐛 修复「绑定管理员 验证码」被学生绑定指令抢匹配（提示"未找到学生「管理员 xxx」"）；无验证码时给出用法提示 |
| **v1.11.2** | 🐛 更新检查改用 GitHub API（避免代理缓存旧版本）；升级包改用带 tag 的固定地址（避免 /latest/ 被缓存） |
| **v1.11.1** | 🐛 修复远程升级后仍提示「发现新版本」：升级完成后以远端版本号为准回写；升级包内不再携带过期 sha256；升级后自动重新检查 |
| **v1.11.0** | ✨ 在线支付（易支付 / 多通道）+ 催缴通知 + 自定义消息推送 + QQ 官方机器人互动查询 / 管理员指令 · ⚙️ 配置管理 · 🛡️ 会话与安全加固 |
| **v1.10.1** | 🐛 在线缴费核销并入对应班费轮次，不再单独生成账目 |
| **v1.10.0** | ✨ 多支付通道（易支付 / V免签）· 修复易支付 clientip 缺失 |
| **v1.9.0** | ✨ 在线支付 + 缴费追踪 + 对账 |
| **v1.8.0** | ✨ 安全分析面板增强 |
| **v1.7.3** | 🛡️ 安全加固：敏感文件防护/升级包校验/前端 XSS 修复 · PDF 中文修复 · 迁移与会话修复 |
| **v1.7.2** | 🐛 修复 undefined array key 警告 |
| **v1.7.1** | 🐛 修复升级权限问题 |
| **v1.7.0** | ✨ 新功能：多图凭证 / 单次免缴 / 学期报表 PDF / 数据看板 |
| **v1.6.2** | 🚀 Cloudflare 缓存加速 · 界面视觉升级 + 多设备响应式 · API no-store · 前端错误重试 |
| **v1.6.1** | 🔧 修复与体验优化 |
| **v1.6** | ✨ 新功能版本 |
| **v1.0** | 🎉 首个正式版本 |

> 📦 完整更新日志见 [Releases](https://github.com/Momo8715/ClassFundManagementSystem/releases)

---

## 🤝 贡献

欢迎提交 [Issue](https://github.com/Momo8715/ClassFundManagementSystem/issues) 与 [Pull Request](https://github.com/Momo8715/ClassFundManagementSystem/pulls)！

1. Fork 本仓库
2. 创建功能分支：`git checkout -b feat/your-feature`
3. 提交改动：`git commit -m "feat: 添加xxx功能"`
4. 推送分支并创建 PR

---

## 📄 许可证

本项目为个人开源项目，**保留所有权利**。使用请保留原作者信息。

---

<div align="center">

📒 班级班费管理系统 · 让班费管理更简单、更透明

⭐ 如果这个项目对你有帮助，欢迎点亮 Star！

</div>