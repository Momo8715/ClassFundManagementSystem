<div align="center">

# 📒 班级班费管理系统

**简单 · 安全 · 开箱即用** 的班级收支管理工具

> 上传即用 · 无需命令行 · 宝塔面板友好 · 支持远程一键升级

[![版本](https://img.shields.io/badge/版本-v1.6.2-6366f1?style=for-the-badge)](https://github.com/Momo8715/ClassFundManagementSystem/releases)
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
</table>

---

## 🚀 快速开始

> ⏱️ 全程约 **3 分钟**，无需任何命令行操作

```bash
# 1️⃣ 将项目文件上传到网站根目录（如 /www/wwwroot/classfund.陌沫.cn）
# 2️⃣ 浏览器访问你的域名
# 3️⃣ 自动跳转安装向导 → 填写数据库信息 → 一键建表
# 4️⃣ 创建管理员账号 → ✅ 完成！
```

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
class-fund-system/
├── index.php              # 前端入口（SPA 单页应用）
├── api.php                # API 路由器（全部请求入口）
├── install.php            # 安装向导（部署后可删除）
├── config.php             # 配置 + 数据库迁移 + 权限矩阵
├── schema.sql             # 数据库结构
├── version.json           # 版本信息（远程升级用）
├── assets/
│   ├── css/style.css      # 样式（含暗色主题 + 响应式）
│   └── js/app.js          # 前端逻辑
├── src/
│   ├── auth.php           # 登录 / 访客 / 修改密码
│   ├── transactions.php   # 收支 CRUD + 仪表盘
│   ├── users.php          # 用户管理 / 封禁
│   ├── payments.php       # 花名册 + 缴费追踪
│   ├── import_export.php  # Excel 导入导出 / 图片上传
│   ├── logs.php           # 操作日志
│   ├── security.php       # 安全分析 / 回收站
│   ├── upgrade.php        # 远程升级
│   └── helpers.php        # CSRF / 速率限制 / XSS 防护
└── uploads/               # 凭证图片（自动创建）
```

---

## 🛡 安全设计

本项目将安全作为一等公民，内置多层防护：

| 防护层 | 实现方式 |
|:---:|----------|
| 🔐 CSRF 防护 | 每次会话生成随机 Token，写操作强制校验 |
| 👤 会话安全 | 登录后重新生成 Session ID（防会话固定攻击） |
| ⏱️ 速率限制 | 登录接口每 IP 每分钟限 5 次，文件锁原子计数 |
| 🧹 XSS 防护 | 前端统一 `escapeHtml`，后端存储前校验 |
| 📜 操作日志 | 应用层**永不执行** UPDATE/DELETE，审计不可篡改 |
| 🖥️ 登录审计 | 每次登录尝试均记录 IP / UA / 浏览器指纹 / 失败原因 |
| 🔍 异常检测 | 同指纹多账号、同账号多 IP、暴力破解统计 |
| 🗑️ 软删除 | 收支记录删除进回收站，可恢复，防误删 |
| 🖼️ 上传安全 | 图片按 MIME 签名校验，扩展名白名单，目录禁执行脚本 |
| 🚫 权限矩阵 | 10+ 项细粒度权限，多角色取最高优先级 |

---

## 🔄 远程升级

系统内置一键升级：管理员在 **🛡️ 安全分析 → 🔍 检查更新 → 🚀 立即升级**，自动完成：

```text
GitHub Release → 下载 ZIP → 全站自动备份 → 解压覆盖 → 完成
```

- ✅ 升级前自动备份到 `backup_日期/`，出问题随时回滚
- ✅ 不影响 `uploads/` 凭证图片与 `db_config.json` 数据库配置
- ✅ 升级地址硬编码，无法被篡改劫持

📖 详细发布教程见 [UPGRADE.md](UPGRADE.md)

---

## 📌 版本历史

| 版本 | 说明 |
|:---:|------|
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