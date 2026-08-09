#!/usr/bin/env bash
# ============================================
# 一键部署脚本：GitHub 发布 + 宝塔面板自动上传
#
# 用法：
#   ./deploy.sh v1.1.0          # 发布并部署到宝塔
#   ./deploy.sh v1.1.0 --bt     # 只上传到宝塔（跳过 GitHub 发布）
#
# 流程：
#   git 提交推送 → 打 tag 触发 Actions → 等待 Release 生成
#   → 下载 update.zip → 宝塔 API 上传 → 自动解压覆盖
# ============================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ---------- 加载配置 ----------
if [ -f ".env.deploy" ]; then
  set -a
  # shellcheck disable=SC1091
  source .env.deploy
  set +a
else
  echo "❌ 未找到 .env.deploy"
  echo "   请先执行: cp .env.deploy.example .env.deploy  并填写配置"
  exit 1
fi

VERSION="${1:-}"
SKIP_GITHUB=false
[ "${2:-}" = "--bt" ] && SKIP_GITHUB=true

if [ -z "$VERSION" ]; then
  echo "❌ 用法: ./deploy.sh <版本号> [--bt]"
  echo "   示例: ./deploy.sh v1.1.0"
  exit 1
fi

# ---------- 1. Git 提交推送 ----------
if ! $SKIP_GITHUB; then
  echo "📦 [1/5] 提交并推送代码..."
  git add -A
  if git diff --cached --quiet; then
    echo "   无改动，跳过提交"
  else
    git commit -m "$VERSION" || true
  fi
  git push

  # ---------- 2. 打 tag 触发 GitHub Actions ----------
  echo "🏷️  [2/5] 创建并推送 tag $VERSION..."
  if git rev-parse -q --verify "refs/tags/$VERSION" >/dev/null; then
    echo "   tag 已存在: $VERSION"
  else
    git tag "$VERSION"
  fi
  git push origin "$VERSION"
fi

# ---------- 3. 等待 GitHub Actions 生成 Release ----------
echo "⏳ [3/5] 等待 GitHub Actions 自动打包发布..."
RELEASE_API="https://api.github.com/repos/${GITHUB_REPO}/releases/tags/${VERSION}"
AUTH=()
[ -n "${GITHUB_TOKEN:-}" ] && AUTH=(-H "Authorization: token ${GITHUB_TOKEN}")
for i in $(seq 1 60); do
  # shellcheck disable=SC2207
  STATUS=$(curl -s -o /tmp/bt_release.json -w "%{http_code}" "${AUTH[@]}" "$RELEASE_API" || echo 000)
  if [ "$STATUS" = "200" ]; then
    ZIP_URL=$(python3 -c "import json;print(json.load(open('/tmp/bt_release.json'))['assets'][0]['browser_download_url'])" 2>/dev/null || echo "")
    [ -n "$ZIP_URL" ] && break
  fi
  printf "   ."
  sleep 10
done
echo ""
if [ -z "${ZIP_URL:-}" ]; then
  echo "❌ 等待超时（10分钟），请到 GitHub Actions 页面检查任务状态"
  exit 1
fi
echo "✅ Release 已生成: $ZIP_URL"

# ---------- 4. 下载 update.zip ----------
echo "📥 [4/5] 下载 update.zip..."
curl -sL -o /tmp/update.zip "$ZIP_URL"
ls -lh /tmp/update.zip

# ---------- 5. 宝塔 API 上传并解压 ----------
echo "🚀 [5/5] 通过宝塔 API 上传到 ${BT_SITE_PATH} ..."

# 宝塔 API 签名（新版：get_panel_info 换取 request_time/request_token）
bt_sign() {
  local info
  info=$(curl -sk -m 15 "$BT_PANEL_URL/api/panel/get_panel_info") || {
    echo "❌ 无法连接宝塔面板: $BT_PANEL_URL"
    exit 1
  }
  local rt rtk
  rt=$(echo "$info" | python3 -c "import sys,json;print(json.load(sys.stdin)['request_time'])" 2>/dev/null || echo "")
  rtk=$(echo "$info" | python3 -c "import sys,json;print(json.load(sys.stdin)['request_token'])" 2>/dev/null || echo "")
  if [ -z "$rt" ] || [ -z "$rtk" ]; then
    echo "❌ 获取面板签名失败，请检查 BT_PANEL_URL 和 BT_API_KEY"
    echo "   返回: $info"
    exit 1
  fi
  echo -n "${BT_API_KEY}${rt}${rtk}" | md5sum | awk '{print $1}'
}

SIGN=$(bt_sign)

echo "   ⬆️ 上传 update.zip..."
UP_RES=$(curl -sk -m 120 -X POST "$BT_PANEL_URL/files?action=UploadFile" \
  -H "X-BT-Panel-Key: $BT_API_KEY" \
  -H "X-BT-Panel-Sign: $SIGN" \
  -F "f_name=update.zip" \
  -F "f_path=$BT_SITE_PATH" \
  -F "file=@/tmp/update.zip")
echo "   上传结果: $UP_RES"

echo "   📂 解压覆盖到站点目录..."
UN_RES=$(curl -sk -m 300 -X POST "$BT_PANEL_URL/files?action=UnZip" \
  -H "X-BT-Panel-Key: $BT_API_KEY" \
  -H "X-BT-Panel-Sign: $SIGN" \
  --data-urlencode "sfile=$BT_SITE_PATH/update.zip" \
  --data-urlencode "dfile=$BT_SITE_PATH" \
  --data-urlencode "type=zip")
echo "   解压结果: $UN_RES"

rm -f /tmp/update.zip

echo ""
echo "🎉 部署完成！"
echo "   - GitHub: https://github.com/${GITHUB_REPO}/releases/tag/${VERSION}"
echo "   - 宝塔站点: $BT_SITE_PATH"
echo "   - 升级包: $BT_SITE_PATH/update.zip（可删除）"
