# 远程升级发布教程

## 原理

系统内置了远程升级功能，所有部署了「班级班费管理系统」的站点，管理员在后台点"检查更新"→"立即升级"，即可自动从你的 GitHub 仓库下载最新版本并覆盖安装（升级前会自动备份全站文件）。

**升级服务器地址已写死在代码中，其他人无法篡改。**

---

## 发布新版本流程

### 第一步：修改代码

在你的本地项目中完成代码修改，测试无误。

### 第二步：打包 ZIP

将项目文件打包为 `update.zip`：

- **不要包含** `uploads/`、`backup_*`、`.reasonix/` 目录
- **不要包含** `install.php`（防止覆盖已安装站点的配置）
- 只需包含 `.php`、`.js`、`.css`、`.sql`、`.htaccess` 等核心文件

打包命令（在项目根目录执行）：

```bash
zip -r update.zip . -x "uploads/*" "backup_*/*" ".reasonix/*" "install.php" ".git/*" "*.zip"
```

### 第三步：在 GitHub 创建 Release

1. 打开 https://github.com/Momo8715/ClassFundManagementSystem/releases
2. 点击 **Draft a new release**
3. 填写版本号，例如 `v1.0.1`（Tag 会自动创建）
4. 填写更新说明
5. 点击 **Attach binaries** 上传 `update.zip`
6. 点击 **Publish release**

### 第四步：更新 version.json

修改仓库根目录的 `version.json`，填写新的版本号、更新说明：

```json
{
    "version": "1.0.1",
    "notes": "修复xxx问题，新增xxx功能",
    "url": "https://github.com/Momo8715/ClassFundManagementSystem/releases/latest/download/update.zip"
}
```

然后提交到 GitHub：

```bash
git add version.json
git commit -m "发布 v1.0.1"
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
