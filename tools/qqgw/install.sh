#!/bin/sh
# ClassFund 班级班费管理系统 - QQ 官方机器人 WebSocket 网关客户端 安装脚本
#
# 作用：常驻连接 QQ 网关，让官方后台显示机器人「已连接」，并保持在线以便收发消息。
#       （QQ 官方要求机器人保持 WebSocket 网关在线才能发送消息）
#
# 用法：
#   sudo sh install.sh [安装目录]
#   缺省安装目录：<应用根目录>/.qqgw（即网站目录下的隐藏目录）
#
# 说明：本系统默认「应用自动托管」网关——在后台保存 AppID/AppSecret 即会自动安装并启动，
#       通常无需运行本脚本。仅当你希望用 systemd 常驻/开机自启时才需要执行。
#
# 重要：安装目录必须放在**网站目录内**（如 <应用根目录>/.qqgw）。
#       - PHP 的 open_basedir 默认只允许网站目录，放到 /www/server/... 会导致后台读取失败；
#       - 该目录名以 . 开头，nginx 默认规则已返回 404，web 无法访问其中的 AppSecret。
#       另外后台「应用自动托管」需要 PHP 未被 disable_functions 禁用 exec，
#       若禁用请改用本脚本 + systemd 常驻（本脚本不依赖 PHP 执行命令）。
#
# 依赖：Node.js 18+（推荐 20/22，内置全局 WebSocket 与 fetch）
set -e

SRC="$(cd "$(dirname "$0")" && pwd)"
APP_ROOT="$(cd "$SRC/../.." && pwd)"
DIR="${1:-$APP_ROOT/.qqgw}"
RUN_USER="${QQGW_USER:-www}"

NODE_BIN="$(command -v node || true)"
if [ -z "$NODE_BIN" ]; then
  echo "❌ 未找到 node，请先安装 Node.js 18+ 后重试。" >&2
  exit 1
fi
echo "✅ node: $NODE_BIN ($("$NODE_BIN" -v))"

mkdir -p "$DIR"
cp "$SRC/qqgw.js" "$DIR/qqgw.js"
chmod 644 "$DIR/qqgw.js"
if id "$RUN_USER" >/dev/null 2>&1; then chown -R "$RUN_USER" "$DIR" 2>/dev/null || true; fi
# 记录安装目录，供网站后台读写 config.json / status.json
printf '%s' "$DIR" > "$APP_ROOT/.qqgw_dir"
echo "✅ 客户端已安装到 $DIR"

SVC=/etc/systemd/system/classfund-qqgw.service
if [ -d /run/systemd/system ] && [ -w /etc/systemd/system ]; then
  cat > "$SVC" <<EOF
[Unit]
Description=ClassFund QQ Bot Gateway (WebSocket)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$RUN_USER
Group=$RUN_USER
WorkingDirectory=$DIR
ExecStart=$NODE_BIN $DIR/qqgw.js
Restart=on-failure
RestartSec=5
StandardOutput=append:$DIR/qqgw.out
StandardError=append:$DIR/qqgw.err

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable --now classfund-qqgw
  echo "✅ systemd 服务 classfund-qqgw 已启动（开机自启）"
  systemctl --no-pager --lines=0 status classfund-qqgw || true
else
  echo "ℹ️ 未检测到可用的 systemd，请手动常驻运行："
  echo "   cd $DIR && nohup node qqgw.js > qqgw.out 2>&1 &"
  echo "   或使用 pm2： pm2 start $DIR/qqgw.js --name classfund-qqgw && pm2 save"
fi

echo ""
echo "完成。接下来：网站「⚙️ 配置管理 → 📣 群机器人 → QQ 官方机器人」保存 AppID/AppSecret，"
echo "系统会自动写入 $DIR/config.json 并连接，页面「网关连接」会显示 ✅ 已连接。"
