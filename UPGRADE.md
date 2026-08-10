# 远程升级发布教程

## 原理

系统内置了远程升级功能，所有部署了「班级班费管理系统」的站点，管理员在后台点"检查更新"→"立即升级"，即可自动从你的 GitHub 仓库下载最新版本并覆盖安装（升级前会自动备份全站文件）。

**升级服务器地址已写死在代码中，其他人无法篡改。**

---

## 发布新版本流程

### 版本号命名规范

- **新功能发布**：使用两段格式 `1.x`（如 `1.2`、`1.3`），tag 为 `v1.2`、`v1.3`
- **Bug 修复**：使用三段格式 `1.x.x`（如 `1.1.1`、`1.2.1`），tag 为 `v1.1.1`、`v1.2.1`

> 通过版本号可直接识别发布类型：两段 = 新功能，三段 = bug 修复。

### 第一步：修改代码

在你的本地项目中完成代码修改，测试无误。

### 第二步：打包 ZIP

将项目文件打包为 `update.zip`：

- **不要包含** `uploads/`、`backup_*`、`.reasonix/` 目录
- **包含** `install.php`（新用户下载后需要安装向导；已安装站点因 `db_config.json` 存在会自动跳过安装流程，不受影响）
- 只需包含 `.php`、`.js`、`.css`、`.sql`、`.htaccess` 等核心文件

打包命令（在项目根目录执行）：

```bash
zip -r update.zip . -x "uploads/*" "backup_*/*" ".reasonix/*" ".git/*" "*.zip"
```

> 💡 **推荐：使用 GitHub Actions 自动打包发布**
>
> 仓库已内置自动化工作流（`.github/workflows/release.yml`），推送 `v*` 格式的 tag 后会自动完成：更新 `version.json` → 打包 `update.zip` → 创建 Release → 上传附件 → 同步 `version.json` 回 main。**无需手动打包和上传**，只需执行：
>
> ```bash
> git add -A && git commit -m "v1.2" && git push
> git tag v1.2 && git push origin v1.2
> ```
>
> 升级地址仍指向 `releases/latest/download/update.zip`，自动发布后即可直接使用。

### 第三步：在 GitHub 创建 Release

1. 打开 https://github.com/Momo8715/ClassFundManagementSystem/releases
2. 点击 **Draft a new release**
3. 填写版本号，例如 `v1.2`（新功能）或 `v1.1.1`（bug 修复），Tag 会自动创建
4. 填写更新说明
5. 点击 **Attach binaries** 上传 `update.zip`
6. 点击 **Publish release**

### 第四步：更新 version.json

修改仓库根目录的 `version.json`，填写新的版本号、更新说明：

```json
{
    "version": "1.2",
    "notes": "修复xxx问题，新增xxx功能",
    "url": "https://github.com/Momo8715/ClassFundManagementSystem/releases/latest/download/update.zip"
}
```

然后提交到 GitHub：

```bash
git add version.json
git commit -m "发布 v1.2"
git push
```

### 第五步：通知用户

告诉部署了系统的管理员，在后台「安全分析」页面点击 **🔍 检查更新** → **🚀 立即升级** 即可。

---

## 升级过程（用户视角）

1. 管理员登录后台 → 安全分析页面
2. 点击「🔍 检查更新」
3. 如果发现新版本，显示版本号和更新说明
4. 点击「🚀 立即升级」
5. 系统自动完成：
   - 下载 ZIP 包
   - 备份当前所有文件到 `backup_20250101_120000/`
   - 解压覆盖新文件
   - 提示升级完成

---

## 回滚

如果升级出现问题，将 `backup_日期时间/` 目录下的文件覆盖回项目根目录即可。

---

## 注意事项

- `install.php` 不会被打包覆盖，各站点的数据库配置不受影响
- `uploads/` 目录下的凭证图片不会被覆盖
- 升级前自动备份，出问题可随时回滚
- 升级地址 `https://raw.githubusercontent.com/Momo8715/ClassFundManagementSystem/main/version.json` 已硬编码，无法篡改
