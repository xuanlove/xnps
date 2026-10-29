#!/bin/sh
#
# xnps 容器入口（I3）：用环境变量覆盖最常用的 conf 项后启动。
# 其余配置请挂载/编辑 /conf/nps.conf（持久化卷）。

set -e

CONF=/conf/nps.conf
if [ -f "$CONF" ]; then
    if [ -n "${NPS_AUTH_KEY:-}" ]; then
        # 兼容默认模板中的注释行 #auth_key=test
        sed -i "s|^#\{0,1\}auth_key=.*|auth_key=${NPS_AUTH_KEY}|" "$CONF"
    fi
    if [ -n "${NPS_WEB_PASSWORD:-}" ]; then
        sed -i "s|^web_password=.*|web_password=${NPS_WEB_PASSWORD}|" "$CONF"
    fi
    if [ -n "${NPS_WEB_PORT:-}" ]; then
        sed -i "s|^web_port *=.*|web_port = ${NPS_WEB_PORT}|" "$CONF"
    fi
fi

exec /xnps "$@"
