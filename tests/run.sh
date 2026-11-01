#!/usr/bin/env bash
set -euo pipefail

# 集成测试：临时站点 + MySQL + PHP 内置服务器 + Node API 断言
# 依赖：php(pdo_mysql/mbstring/gd/zip/intl/fileinfo)、mysql 客户端、node 18+、curl

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
SITE="$TMP/site"
PORT="${TEST_PORT:-18098}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-classfund_test}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
PHP_BIN="${PHP_BIN:-php}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then kill "$SERVER_PID" 2>/dev/null || true; fi
  rm -rf "$TMP"
}
trap cleanup EXIT

mkdir -p "$SITE"
tar -C "$ROOT" --exclude='./.git' --exclude='./node_modules' --exclude='./db_config.json' -cf - . | tar -C "$SITE" -xf -

# 临时站点数据库配置（无 BOM）
printf '{"DB_HOST":"%s","DB_PORT":"%s","DB_NAME":"%s","DB_USER":"%s","DB_PASS":"%s","DB_CHARSET":"utf8mb4"}' \
  "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS" > "$SITE/db_config.json"

export MYSQL_PWD="$DB_PASS"
MYSQL=("$MYSQL_BIN" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER")

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
"${MYSQL[@]}" "$DB_NAME" < "$SITE/schema.sql"

# 模拟 v1.12 旧库：去掉 1.13 新增列/表，让首次 API 请求触发 autoMigrate
"${MYSQL[@]}" "$DB_NAME" -e "
ALTER TABLE tx_images DROP COLUMN web_mime, DROP COLUMN web, DROP COLUMN px_height, DROP COLUMN px_width, DROP COLUMN byte_size, DROP COLUMN sha256;
DROP TABLE IF EXISTS health_ack;
DROP TABLE IF EXISTS backup_runs;
DELETE FROM system_meta WHERE meta_key='schema_version';
"

"$PHP_BIN" "$SITE/tests/seed.php"

"$PHP_BIN" -d display_errors=0 -S "127.0.0.1:$PORT" -t "$SITE" > "$TMP/php.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do
  CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api.php?action=me" || true)"
  if [ "$CODE" = "401" ] || [ "$CODE" = "200" ]; then break; fi
  sleep 0.5
done

API_BASE="http://127.0.0.1:$PORT/api.php" \
APP_ROOT="$SITE" \
DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
MYSQL_BIN="$MYSQL_BIN" \
node "$SITE/tests/api.test.js"

echo "集成测试通过"
