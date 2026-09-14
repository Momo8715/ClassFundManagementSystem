# QQ 官方机器人 WebSocket 网关客户端（qqgw）

QQ 官方机器人要求**保持 WebSocket 网关在线**，后台才显示「已连接」，也才能发送消息。
本目录是随项目一起分发的零依赖 Node 客户端：连接网关、维持心跳，并把收到的事件转发给现有 PHP
接口 `api.php?action=qq_webhook` 处理（无需改动业务逻辑）。

## 安装（通常不需要）

**本系统默认由应用自动托管网关**：在后台 **⚙️ 配置管理 → 📣 群机器人 → QQ 官方机器人** 保存
AppID/AppSecret 后，系统会自动把本目录的 `qqgw.js` 复制到运行目录并后台拉起，**无需任何单独安装步骤**。
访问配置页、保存配置、发送推送、收到平台回调时都会自动检查并拉起（自愈）。

仅当希望用 **systemd 常驻 / 开机自启** 时才需要手动执行：

```sh
# 在网站根目录下执行（需要 root）
sudo sh tools/qqgw/install.sh
# 自定义安装目录（默认 <网站根目录>/.qqgw）与运行用户（默认 www）
sudo sh tools/qqgw/install.sh /www/wwwroot/example.com/.qqgw
sudo QQGW_USER=www sh tools/qqgw/install.sh
```

⚠️ **安装目录必须放在网站目录内**（如 `<网站根目录>/.qqgw`），原因：

1. PHP 的 `open_basedir` 通常只允许网站目录，放到 `/www/server/...` 会让后台读取 `config.json`/`status.json` 报错；
2. 该目录以 `.` 开头，nginx 默认已对点目录返回 404，Web 无法访问其中的 **AppSecret**。

另外，「应用自动托管」依赖 PHP 的 `exec` 未被禁用；若面板 `disable_functions` 禁用了 `exec`，
请改用本脚本 + systemd 常驻（脚本与网关本身都**不依赖** PHP 执行命令）。

- 依赖 **Node.js 18+**（推荐 20/22，内置全局 `WebSocket` 与 `fetch`）。
- 安装脚本会把安装目录写入网站根目录的 `.qqgw_dir`，后台据此读写 `config.json` / `status.json`。
- 应用自动拉起与 systemd 可共存：客户端内置**单实例锁**（`gateway.pid`），不会重复运行。

## 配置

无需手动编辑：在网站 **⚙️ 配置管理 → 📣 群机器人 → QQ 官方机器人** 保存 **AppID / AppSecret** 后，
系统会自动写入 `config.json`（`{appid, secret, sandbox, host}`），客户端检测到变更会自动重连。

也可通过环境变量 `QQGW_DIR` 指定安装目录（优先于 `.qqgw_dir` 与默认路径）。

> 事件转发走本地回环 HTTP：客户端用 `http.request` 显式携带站点 `Host` 头 POST 到
> `http://127.0.0.1/api.php?action=qq_webhook`（可用 `QQGW_FORWARD` 覆盖）。
> 不能用 `fetch` —— 它禁止设置 `Host`，请求会命中 nginx 默认站点而 404。

## 状态与排错

- `status.json`：客户端写入，后台「网关连接」显示 `connected / phase / error`。
- `qqgw.out` / `qqgw.err`：运行日志。
- `config.json`：含 AppSecret，权限 0640，**切勿提交到仓库**。

```sh
systemctl status classfund-qqgw      # 查看服务
journalctl -u classfund-qqgw -n 50   # 查看日志
```
