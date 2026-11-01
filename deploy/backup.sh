#!/usr/bin/env bash
#
# xnps 备份（I3）：数据库 + 制品库（storage/app）+ 可选 nps conf / .env。
#
# 用法：
#   APP_DIR=/var/www/xnps-admin BACKUP_DIR=/var/backups/xnps ./deploy/backup.sh
#   NPS_CONF_DIR=/opt/xnps/conf INCLUDE_ENV=1 ./deploy/backup.sh   # 额外备份 nps conf 与 .env
#
# 产物：$BACKUP_DIR/<UTC 时间戳>/ 下 db.* / storage.tar.gz / conf.tar.gz / sha256sums.txt
# 保留：RETENTION_DAYS（默认 14）天，自动清理更早目录。

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/xnps-admin}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/xnps}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="$BACKUP_DIR/$STAMP"

log() { printf '[backup] %s\n' "$*"; }

[[ -f "$APP_DIR/.env" ]] || { log "missing $APP_DIR/.env"; exit 1; }

# 读取 Laravel .env（忽略注释/空行）
set -a
# shellcheck disable=SC1090
source <(grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$APP_DIR/.env" | sed 's/^/export /')
set +a

mkdir -p "$TARGET"
umask 077

# ---- 1. 数据库 ----
case "${DB_CONNECTION:-sqlite}" in
  mysql|mariadb)
    : "${DB_DATABASE:?DB_DATABASE required}"
    CNF="$(mktemp)"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
      "${DB_USERNAME:-root}" "${DB_PASSWORD:-}" "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" >"$CNF"
    log "dumping mysql $DB_DATABASE"
    mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --events \
      "$DB_DATABASE" | gzip -c >"$TARGET/db.sql.gz"
    rm -f "$CNF"
    ;;
  sqlite)
    DB_FILE="${DB_DATABASE:-$APP_DIR/database/database.sqlite}"
    [[ "$DB_FILE" = /* ]] || DB_FILE="$APP_DIR/$DB_FILE"
    log "copying sqlite $DB_FILE"
    sqlite3 "$DB_FILE" ".backup '$TARGET/db.sqlite'" 2>/dev/null || cp "$DB_FILE" "$TARGET/db.sqlite"
    gzip -c "$TARGET/db.sqlite" >"$TARGET/db.sqlite.gz" && rm -f "$TARGET/db.sqlite"
    ;;
  *)
    log "unsupported DB_CONNECTION=$DB_CONNECTION"; exit 1
    ;;
esac

# ---- 2. 制品库 ----
if [[ -d "$APP_DIR/storage/app" ]]; then
  log "archiving storage/app"
  tar -czf "$TARGET/storage.tar.gz" -C "$APP_DIR/storage/app" .
fi

# ---- 3. 可选：nps conf / 管理端 .env ----
if [[ -n "${NPS_CONF_DIR:-}" && -d "$NPS_CONF_DIR" ]]; then
  log "archiving nps conf $NPS_CONF_DIR"
  tar -czf "$TARGET/conf.tar.gz" -C "$NPS_CONF_DIR" .
fi
if [[ "${INCLUDE_ENV:-0}" == "1" ]]; then
  log "archiving admin .env"
  cp "$APP_DIR/.env" "$TARGET/admin.env"
fi

# ---- 4. 校验和 ----
( cd "$TARGET" && find . -maxdepth 1 -type f ! -name sha256sums.txt -printf '%f\n' | sort | xargs -r sha256sum >sha256sums.txt )

# ---- 5. 清理 ----
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -exec rm -rf {} +

log "done: $TARGET ($(du -sh "$TARGET" | cut -f1))"
