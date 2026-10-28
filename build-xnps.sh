#!/usr/bin/env bash
#
# build-xnps.sh —— xnps 全平台构建打包脚本
#
# 产物：dist/{server,client}-{版本}-{GOOS}-{GOARCH}[-vN].tar.gz + sha256sums.txt
#
# 用法：
#   ./build-xnps.sh                    # 全平台矩阵
#   ./build-xnps.sh linux amd64        # 单平台（可选第三参数 GOARM）
#
# 说明：
#   - 与上游 build.sh 一致，使用显式文件列表构建（./cmd/nps/nps.go、
#     ./cmd/npc/npc.go），规避 cmd/npc/sdk.go 的 c-shared 重复 main；
#   - 纯静态编译（CGO_ENABLED=0），产物无任何动态库依赖；
#   - 打包资产直接取自工作区（conf/web/LICENSE），构建前请保持工作区整洁。

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

# ---------------------------------------------------------------- 版本探测
CORE_VERSION=$(grep 'const VERSION' lib/version/version.go | awk -F'"' '{print $2}')
EXT_VERSION=$(grep 'const ExtVersion' server/ext/service.go | awk -F'"' '{print $2}')
RELEASE="${CORE_VERSION}-ext${EXT_VERSION}"

DIST="dist"
STAGE_ROOT="$DIST/stage"
mkdir -p "$DIST" "$STAGE_ROOT"

LDFLAGS="-s -w"
TARGETS=()

if [[ $# -ge 2 ]]; then
    GOARM="${3:-}"
    TARGETS+=("$1|$2|${GOARM}")
else
    # os|arch|goarm（goarm 为空表示不设置）
    TARGETS+=( \
        "linux|amd64|" \
        "linux|386|" \
        "linux|arm64|" \
        "linux|arm|7" \
        "linux|arm|6" \
        "linux|mips64le|" \
        "linux|mipsle|" \
        "freebsd|amd64|" \
        "freebsd|arm64|" \
        "darwin|amd64|" \
        "darwin|arm64|" \
        "windows|amd64|" \
        "windows|386|" \
        "windows|arm64|" \
    )
fi

# ---------------------------------------------------------------- 打包资产
write_release_notes() {
    cat > "$1/RELEASE.md" <<EOF
# xnps ${RELEASE}（${2}）

xnps 是 ehang-io/nps（GPL-3.0）的衍生分支，本包为 ${3}。

## 本版本（ext ${EXT_VERSION}）要点
- 内置 ACME：nps 侧 HTTP-01 签发（新增 /ext/acme/issue 与挑战应答）
- 管理控制台 /admin（Vue 3）+ 支付宝 / 微信支付 v3 + 证书/升级/告警
- /client/edit 改为"显式传入才修改"语义；conf 0600；升级本地双份备份
- changelog 并发乱序修复；Web 品牌 XNPS

## 快速开始（服务端）
1. 解压后编辑 conf/nps.conf（务必修改 auth_key / web_username / web_password）
2. sudo ./xnps start   # 或前台运行 sudo ./xnps
3. Web 管理界面: http://<ip>:\${web_port}；WebAPI /ext/* 供集中管理端使用

完整文档与修改记录见项目仓库 README.md（GPL-3.0，致敬原作者 ehang-io）。
EOF
}

package_target() {
    local os="$1" arch="$2" goarm="$3"
    local exe="" tag=""
    [[ "$os" == "windows" ]] && exe=".exe"
    [[ -n "$goarm" ]] && tag="-v${goarm}"
    local label="${os}-${arch}${tag}"
    local srv_dir="$STAGE_ROOT/server-${RELEASE}-${label}"
    local cli_dir="$STAGE_ROOT/client-${RELEASE}-${label}"

    # ---- server 包 ----
    rm -rf "$srv_dir" && mkdir -p "$srv_dir"
    cp "dist/bin/xnps-${os}-${arch}${tag}${exe}" "$srv_dir/xnps${exe}"
    cp -r conf "$srv_dir/conf"
    rm -f "$srv_dir"/conf/*.bak.* "$srv_dir"/conf/npc.conf
    mkdir -p "$srv_dir/web"
    cp -r web/views "$srv_dir/web/views"
    cp -r web/static "$srv_dir/web/static"
    cp LICENSE "$srv_dir/"
    write_release_notes "$srv_dir" "$label" "服务端包（xnps + 默认配置 + Web 界面）"

    # ---- client 包 ----
    rm -rf "$cli_dir" && mkdir -p "$cli_dir/conf"
    cp "dist/bin/xnpc-${os}-${arch}${tag}${exe}" "$cli_dir/xnpc${exe}"
    cp conf/npc.conf conf/multi_account.conf "$cli_dir/conf/"
    cp LICENSE "$cli_dir/"
    write_release_notes "$cli_dir" "$label" "客户端包（xnpc，配置与服务端同源）"

    # ---- 压缩 ----
    tar -czf "$DIST/server-${RELEASE}-${label}.tar.gz" -C "$STAGE_ROOT" "$(basename "$srv_dir")"
    tar -czf "$DIST/client-${RELEASE}-${label}.tar.gz" -C "$STAGE_ROOT" "$(basename "$cli_dir")"
    rm -rf "$srv_dir" "$cli_dir"
    echo "packaged: $label"
}

# ---------------------------------------------------------------- 构建
echo "== xnps ${RELEASE} build =="
mkdir -p "$DIST/bin"

for t in "${TARGETS[@]}"; do
    IFS='|' read -r os arch goarm <<<"$t"
    exe=""; [[ "$os" == "windows" ]] && exe=".exe"
    tag=""; [[ -n "$goarm" ]] && tag="-v${goarm}"
    bin="dist/bin/xnps-${os}-${arch}${tag}${exe}"
    clin="dist/bin/xnpc-${os}-${arch}${tag}${exe}"

    echo "-- building ${os}/${arch}${tag} ..."
    env CGO_ENABLED=0 GOOS="$os" GOARCH="$arch" ${goarm:+GOARM="$goarm"} \
        go build -trimpath -ldflags "$LDFLAGS" -o "$bin" ./cmd/nps/nps.go
    env CGO_ENABLED=0 GOOS="$os" GOARCH="$arch" ${goarm:+GOARM="$goarm"} \
        go build -trimpath -ldflags "$LDFLAGS" -o "$clin" ./cmd/npc/npc.go
done

# ---------------------------------------------------------------- 打包
echo "== packaging =="
for t in "${TARGETS[@]}"; do
    IFS='|' read -r os arch goarm <<<"$t"
    package_target "$os" "$arch" "$goarm"
done

# ---------------------------------------------------------------- 校验和
cd "$DIST"
find . -maxdepth 1 -name '*.tar.gz' -printf '%f\n' | sort | xargs sha256sum > sha256sums.txt
cd "$REPO_ROOT"

echo "== done =="
echo "release : ${RELEASE}"
echo "packages: $DIST/*.tar.gz ($(ls "$DIST"/*.tar.gz 2>/dev/null | wc -l) archives)"
echo "checksum: $DIST/sha256sums.txt"
