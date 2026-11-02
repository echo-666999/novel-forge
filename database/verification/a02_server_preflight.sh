#!/usr/bin/env bash
# A02 服务器只读检查；不安装软件、不修改 PostgreSQL 配置。
set -euo pipefail

# 构建目标为 Linux 数据库服务器，禁止误用本机 macOS 工具链。
if [[ "$(uname -s)" != Linux ]]; then
    echo '请在 Linux 数据库服务器运行此检查，不要在 macOS 应用机运行。' >&2
    exit 1
fi

# 指向运行中 PostgreSQL 18 对应的 pg_config，不使用猜测的安装路径。
a02_pg_config="${A02_PG_CONFIG:-$(command -v pg_config || true)}"
if [[ -z "$a02_pg_config" || ! -x "$a02_pg_config" ]]; then
    echo '未找到 pg_config，请设置 A02_PG_CONFIG 为运行中 PostgreSQL 18 的绝对路径。' >&2
    exit 1
fi
[[ -f /etc/os-release ]] && cat /etc/os-release
uname -sm
"$a02_pg_config" --version
if ! "$a02_pg_config" --version | grep -Eq '^PostgreSQL 18([.[:space:]]|$)'; then
    echo '目标不是 PostgreSQL 18，停止；不得通过降级服务器解决。' >&2
    exit 1
fi
for a02_tool in cc make git autoconf automake aclocal autoheader libtoolize pkg-config sha256sum; do
    command -v "$a02_tool" || { echo "缺少构建工具：$a02_tool" >&2; exit 1; }
done
for a02_option in --pkglibdir --sharedir --includedir-server --pgxs; do
    "$a02_pg_config" "$a02_option"
done
test -f "$("$a02_pg_config" --includedir-server)/postgres.h"
test -f "$("$a02_pg_config" --pgxs)"
echo '只读检查通过；仍需人工确认该 pg_config 对应正在监听 5432 的实例。'
