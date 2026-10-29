#!/usr/bin/env bash
#
# xnps 恢复（I3）：从 deploy/backup.sh 产物目录恢复数据库 + 制品库（+ 可选 nps conf）。
#
# 用法：
#   RESTORE_CONFIRM=yes APP_DIR=/var/www/xnps-admin ./deploy/restore.sh /var/backups/xnps/20261029T000000Z
#   RESTORE_NPS_CONF_DIR=/opt/xnps/conf RESTORE_CONFIRM=yes ./deploy/restore.sh <backup-dir>
#
# 注意：恢复会覆盖数据库与 storage/app；请先停掉 queue/scheduler，并再次备份当前数据。

set -euo pipefail

SRC="${1:-}"
APP_DIR="${APP_DIR:-/var/www/xnps-admin}"

log() { printf '[restore] %s\n' "$*"; }

[[ -n "$SRC" && -d "$SRC" ]] || { log "usage: restore.sh <backup-dir> (RESTORE_CONFIRM=yes)"; exit 1; }
[[ "${RESTORE_CONFIRM:-}" == "yes" ]] || { log "refusing to overwrite without RESTORE_CONFIRM=yes"; exit 1; }
[[ -f "$APP_DIR/.env" ]] || { log "missing $APP_DIR/.env"; exit 1; }

# 校验和（存在则强校验）
if [[ -f "$SRC/sha256sums.txt" ]]; then
  ( cd "$SRC" && sha256sum -c sha256sums.txt )
fi

set -a
# shellcheck disable=SC1090
source <(grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$APP_DIR/.env" | sed 's/^/export /')
set +a

# ---- 1. 数据库 ----
case "${DB_CONNECTION:-sqlite}" in
  mysql|mariadb)
    [[ -f "$SRC/db.sql.gz" ]] || { log "missing db.sql.gz"; exit 1; }
    CNF="$(mktemp)"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
      "${DB_USERNAME:-root}" "${DB_PASSWORD:-}" "${DB_HOST:-127.0.0.1}" "${DB_PORT:-3306}" >"$CNF"
    log "restoring mysql $DB_DATABASE"
    gunzip -c "$SRC/db.sql.gz" | mysql --defaults-extra-file="$CNF" "$DB_DATABASE"
    rm -f "$CNF"
    ;;
  sqlite)
    [[ -f "$SRC/db.sqlite.gz" ]] || { log "missing db.sqlite.gz"; exit 1; }
    DB_FILE="${DB_DATABASE:-$APP_DIR/database/database.sqlite}"
    [[ "$DB_FILE" = /* ]] || DB_FILE="$APP_DIR/$DB_FILE"
    log "restoring sqlite $DB_FILE"
    gunzip -c "$SRC/db.sqlite.gz" >"$DB_FILE"
    ;;
  *)
    log "unsupported DB_CONNECTION=$DB_CONNECTION"; exit 1
    ;;
esac

# ---- 2. 制品库 ----
if [[ -f "$SRC/storage.tar.gz" ]]; then
  log "restoring storage/app"
  mkdir -p "$APP_DIR/storage/app"
  tar -xzf "$SRC/storage.tar.gz" -C "$APP_DIR/storage/app"
fi

# ---- 3. 可选 nps conf ----
if [[ -f "$SRC/conf.tar.gz" && -n "${RESTORE_NPS_CONF_DIR:-}" ]]; then
  log "restoring nps conf to $RESTORE_NPS_CONF_DIR"
  mkdir -p "$RESTORE_NPS_CONF_DIR"
  tar -xzf "$SRC/conf.tar.gz" -C "$RESTORE_NPS_CONF_DIR"
fi

log "done; run: php artisan migrate --force && php artisan queue:restart"
