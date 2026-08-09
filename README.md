<p align="center">
  <img src="https://img.shields.io/badge/版本-v1.0-4f6ef7?style=for-the-badge" alt="版本">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/Status-稳定-22c55e?style=for-the-badge" alt="状态">
  <img src="https://img.shields.io/badge/更新-远程一键升级-f59e0b?style=for-the-badge&logo=github&logoColor=white" alt="远程升级">
</p>

<h1 align="center">📒 班级班费管理系统</h1>

<p align="center">
  <b>简单 · 安全 · 开箱即用</b> 的班级收支管理工具
  <br>
  上传即用 · 无需命令行 · 宝塔面板友好
</p>

<p align="center">
  <a href="#-功能特性">功能特性</a> ·
  <a href="#-快速开始">快速开始</a> ·
  <a href="#-系统要求">系统要求</a> ·
  <a href="#-项目结构">项目结构</a> ·
  <a href="#-安全设计">安全设计</a> ·
  <a href="#-远程升级">远程升级</a>
</p>

---

## ✨ 功能特性

| 💰 收支管理 | 👥 多角色权限 | 📋 花名册 & 缴费追踪 |
|:---:|:---:|:---:|
| 收入/支出分类管理<br>子分类 + 来源信息<br>凭证图片上传<br>Excel 一键导入导出 | 班主任 / 管理员 / 班长<br>副班长 / 财务委员 / 同学<br>细粒度权限矩阵<br>多角色自动取最高权限 | 班级名单一键导入<br>免缴学生设置<br>多轮缴费明细追踪<br>欠费名单导出 |

| 📜 操作日志 | 🛡️ 安全分析 | 🌗 主题 & 体验 |
|:---:|:---:|:---:|
| 全操作留痕<br>不可删除、不可修改<br>记录 IP / UA / 浏览器指纹 | 同指纹多账号检测<br>同账号多 IP 告警<br>登录失败统计<br>登录历史审计 | 暗色 / 亮色自动切换<br>QQ / 微信内打开友好<br>移动端响应式布局 |

| 🔄 远程升级 | 🗑️ 回收站 | 📊 数据仪表盘 |
|:---:|:---:|:---:|
| 后台一键检查更新<br>自动备份后升级<br>可随时回滚 | 软删除保护<br>误删可恢复<br>彻底删除需二次确认 | 收支趋势图表<br>支出分类占比<br>最近收支速览 |

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
|------|------|
| 1 | 宝塔面板 → 网站 → 添加站点（PHP 版本选 **8.2**） |
| 2 | 将项目文件上传至站点根目录 |
| 3 | 宝塔 → 数据库 → 创建数据库（记下库名 / 用户名 / 密码） |
| 4 | 访问站点域名，跟随安装向导完成部署 |
| 5 | 🛡️ 建议删除 `install.php` 并开启 SSL |

### ☁️ 其他环境

- **虚拟主机**：上传至 `public_html`，在主机面板创建 MySQL 数据库后访问安装向导
- **Docker**：使用 `php:8.2-apache` 或 `php:8.2-fpm` + MySQL 8 镜像，挂载项目目录

---

## 🔧 系统要求

| 依赖 | 版本 | 说明 |
|------|------|------|
| PHP | ≥ 8.0 | 需要 `pdo_mysql`、`json`、`mbstring` 扩展 |
| MySQL | ≥ 5.7 | 建议 8.0，字符集 `utf8mb4` |
| ZipArchive | — | Excel 导入导出需要 |

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
│   ├── css/style.css      # 样式（含暗色主题）
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

## 🛡️ 安全设计

本项目将安全作为一等公民，内置多层防护：

| 防护层 | 实现方式 |
|--------|----------|
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

```
GitHub Release → 下载 ZIP → 全站自动备份 → 解压覆盖 → 完成
```

- ✅ 升级前自动备份到 `backup_日期/`，出问题随时回滚
- ✅ 不影响 `uploads/` 凭证图片与 `db_config.json` 数据库配置
- ✅ 升级地址硬编码，无法被篡改劫持

📖 详细发布教程见 [UPGRADE.md](UPGRADE.md)

---

## 🤝 贡献

欢迎提交 [Issue](https://github.com/Momo8715/ClassFundManagementSystem/issues) 与 [Pull Request](https://github.com/Momo8715/ClassFundManagementSystem/pulls)！

1. Fork 本仓库
2. 创建功能分支：`git checkout -b feat/your-feature`
3. 提交改动：`git commit -m "feat: 添加xxx功能"`
4. 推送分支并创建 PR

---

## 📄 许可证

本项目为个人开源项目，保留所有权利。使用请保留原作者信息。

---

<p align="center">
  <sub>📒 班级班费管理系统 · 让班费管理更简单、更透明</sub>
</p>
