# NovelForge

## PostgreSQL 服务器扩展安装与验收

本文整理 2026-11-02 已完成的 A02 安装流程。macOS 运行 Laravel 应用，192.168.44.131 服务器运行 PostgreSQL；**SCWS、zhparser 和词典安装在数据库服务器上，Laravel 环境变量配置在应用机上**。现有服务器已安装并验收通过，无需重复安装或重置数据库。

| 项目 | 本次采用的版本或路径 |
| --- | --- |
| 服务器 | Ubuntu 24.04.5 LTS，aarch64，宝塔原生安装 |
| PostgreSQL | 18.6，`/www/server/pgsql` |
| vector / pg_trgm / zhparser | 0.8.1 / 1.6 / 2.4 |
| SCWS | 1.2.3，`/opt/novel-forge/scws-1.2.3` |
| 中文全文配置 | `public.nf_zh_cn` |
| 专用测试库 / 开发库 | `novel_forge_app_a02_test` / `novel_forge_app` |

vector 和 pg_trgm 本次已由服务器提供，以下记录其数据库启用方式；没有将未经执行的编译流程列为已验证步骤。PHP 包 `pgvector/pgvector` 不等于服务器 vector 扩展，也无需安装 PHP SCWS 扩展。详细来源和验收证据见 [A02 安装与验收记录](docs/A02_PostgreSQL扩展安装与验收.md)及[固定源码与词典清单](database/verification/a02_sources.json)。

### 1. 准备工作与只读预检（131 服务器）

将项目的 `database/verification/` **整个目录内容**复制到服务器工作目录。本次使用 `/www/wwwroot/work/verification`，其中应包含 SQL、Shell 脚本、自定义词典和 `a02_scws_automake.patch`。新安装时使用没有同名源码子目录的工作目录；已完成的步骤直接跳过。

```bash
cd /www/wwwroot/work/verification &&
export A02_PG_CONFIG=/www/server/pgsql/bin/pg_config &&
"$A02_PG_CONFIG" --version &&
bash a02_server_preflight.sh
```

输出必须是目标 PostgreSQL 18，并检查服务器头文件、PGXS 和编译工具。仍需确认该路径对应正在监听 5432 的实例。不要使用 PATH 中其他大版本的 pg_config，也不要使用 macOS 的工具链。缺少工具时按实际系统补齐；PostgreSQL 开发文件必须来自同一宝塔安装。

下面各段中的 `&&` 会在失败时停止后续命令，**失败后先检查日志，不继续下一段**。不要在交互式 SSH 登录 shell 中设置 `set -euo pipefail`，此前失败命令曾因此直接退出登录 shell；现有 Shell 脚本通过 `bash 文件名` 在独立进程运行。

### 2. 构建并安装 SCWS（仅首次安装，131 服务器）

在同一终端设置路径。重新登录后变量会丢失，需要重新赋值；`read -p '提示文字' 变量名` 不会把提示文字自动赋给变量。

```bash
export A02_PG_CONFIG=/www/server/pgsql/bin/pg_config
a02_verification_dir=/www/wwwroot/work/verification
a02_scws_prefix=/opt/novel-forge/scws-1.2.3
```

```bash
cd "$a02_verification_dir" &&
git clone https://github.com/hightman/scws.git a02-scws &&
cd a02-scws &&
git checkout --detach b5ae33f6a71ecbf5442a2e485703af4d372f3341 &&
git rev-parse HEAD &&
git apply --check "$a02_verification_dir/a02_scws_automake.patch" &&
git apply "$a02_verification_dir/a02_scws_automake.patch" &&
git diff -- Makefile.am > a02-scws-automake.patch &&
autoreconf --force --install --verbose > a02-bootstrap.log 2>&1 &&
test -x configure &&
test -s Makefile.in &&
test -s libscws/Makefile.in &&
test -s cli/Makefile.in &&
test -s etc/Makefile.in &&
./configure --prefix="$a02_scws_prefix" > a02-configure.log 2>&1 &&
make &&
sudo make install
```

兼容补丁只删除 `Makefile.am` 规则内已注释的旧 unison 命令，解决现代 automake 对 TAB 开头注释的报错，不改分词逻辑。已经手工删除过该行时不要重复 apply；核对 `git diff -- Makefile.am` 后从 autoreconf 继续。

保留源码中的 `COPYING`、提交号、兼容补丁、`a02-bootstrap.log` 和 `a02-configure.log`。仅出现 configure 文件不能证明引导成功，必须生成上述 Makefile.in，再成功运行 configure 生成 Makefile。

### 3. 构建 zhparser 并安装词典（仅首次安装，131 服务器）

沿用第 2 步中的三个变量：

```bash
cd "$a02_verification_dir" &&
git clone https://github.com/amutu/zhparser.git a02-zhparser &&
cd a02-zhparser &&
git checkout --detach 2e995c4df672563992b4d7a147b8fa2d0d4cda6c &&
git rev-parse HEAD &&
make PG_CONFIG="$A02_PG_CONFIG" SCWS_HOME="$a02_scws_prefix" &&
sudo make PG_CONFIG="$A02_PG_CONFIG" SCWS_HOME="$a02_scws_prefix" install
```

检查动态库及内附词典，保留 zhparser 的 `COPYRIGHT` 和构建输出：

```bash
ldd "$("$A02_PG_CONFIG" --pkglibdir)/zhparser.so"
sha256sum dict.utf8.xdb rules.utf8.ini
```

`libscws.so.1` 应指向 `/opt/novel-forge/scws-1.2.3/lib/libscws.so.1`，不得出现 `not found`。安装输出将 zhparser.so 放入宝塔 lib、control/SQL 放入 share/extension、词典放入 share/tsearch_data，这是成功输出，无需重复编译。

安装项目固定专有词“玄霄宗”的自定义词典：

```bash
cd "$a02_verification_dir" &&
sha256sum a02_custom_dict.txt &&
sudo install -m 0644 a02_custom_dict.txt "$("$A02_PG_CONFIG" --sharedir)/tsearch_data/a02_custom_dict.txt" &&
sha256sum "$("$A02_PG_CONFIG" --sharedir)/tsearch_data/a02_custom_dict.txt"
```

固定摘要如下；不一致时先停止验收并核对来源：

| 文件 | SHA-256 |
| --- | --- |
| dict.utf8.xdb | fd76a689f996c4e68ce53325a6a24dc652a678b1e7f676e4c5ca76265609a3e2 |
| rules.utf8.ini | 45395f794226581fb1cc02b44ae1481804152511fc59fa677e2f7b755440479c |
| a02_custom_dict.txt | cfdcbe35976ee9b67be463237b10b4466afe253fc3aa89aae57c020f4dd19fde |

### 4. 启用测试库扩展与中文配置（131 服务器）

先使用独立测试库，成功后再处理开发库。当前测试库已存在，不要重建。新环境中，应用角色 `novel_forge_app` 须已创建；仅当测试库尚不存在时，由管理员在 postgres 等维护库执行：

```sql
CREATE DATABASE novel_forge_app_a02_test OWNER novel_forge_app;
COMMENT ON DATABASE novel_forge_app_a02_test IS 'novel-forge A02 isolated tests';
```

已存在的库应核对所有者和用途备注，不能覆盖不明用途的同名库。以下主机、端口和管理员账号为服务器本机连接示例，按已有管理员连接调整。密码使用交互认证或已有 libpq 认证，不写入命令、SQL 或仓库。

```bash
export PGHOST=127.0.0.1
export PGPORT=5432
export PGUSER=postgres
export PGDATABASE=novel_forge_app_a02_test
```

在 verification 工作目录，由管理员启用三项扩展：

```bash
cd "$a02_verification_dir" &&
/www/server/pgsql/bin/psql -X -v ON_ERROR_STOP=1 --single-transaction -f a02_base_extensions.sql &&
/www/server/pgsql/bin/psql -X -v ON_ERROR_STOP=1 -c 'CREATE EXTENSION IF NOT EXISTS zhparser WITH SCHEMA public;'
```

再由应用账号执行中文配置文件，使配置归应用账号所有，便于后续重复配置验收：

```bash
/www/server/pgsql/bin/psql -X --username=novel_forge_app -v ON_ERROR_STOP=1 --single-transaction -f a02_zhparser_configuration.sql
```

该文件创建 `public.nf_zh_cn`，映射指定词性，并检查同名配置的解析器；遇到其他解析器会停止，不修改默认全文配置。若已有配置由其他账号创建，先核对所有者及用途，不能直接删除重建。

也可在 DBX 中使用对应账号、选中目标数据库后执行文件内的**纯 SQL**。登录 psql 后则可用 `\i /www/wwwroot/work/verification/a02_zhparser_configuration.sql`。`export`、psql 命令行参数及 `\i` 不是 SQL，不能粘贴到 DBX 的 SQL 编辑器。

### 5. 设置词典连接启动参数

本次固定版本的 `zhparser.extra_dicts` 必须在**连接启动时**传入。实测 `ALTER DATABASE SET` 会在解析器加载时失效，连接后执行 SET 也不可用；session_preload_libraries 未解决此问题。已验证方案不要求修改服务器全局配置或重启 PostgreSQL。

**macOS 应用机，项目根目录：** 在 `.env` 设置以下值；现有 `config/database.php` 已通过 Laravel 原生 `server_options` 传入，无需修改代码。

```dotenv
DB_ZHPARSER_EXTRA_DICTS=a02_custom_dict.txt
```

若部署使用配置缓存，修改后运行 `php artisan config:cache --no-interaction` 重建；未使用配置缓存的本地环境无需为此额外启用缓存。重新建立数据库连接，长期运行的消费者按现有运行方式重启后采用新配置。

**psql / pg_dump / pg_restore 客户端：** 在运行这些命令的终端设置，然后新建连接：

```bash
export PGOPTIONS='-c zhparser.extra_dicts=a02_custom_dict.txt'
```

DBX 如需手动复验自定义词，也须在连接的启动参数中传入相同值并重连，不能用连接后的 SET 替代。仅创建扩展和中文配置不要求自定义词已加载。

### 6. 验收与实际恢复测试

**131 服务器，verification 工作目录：** 沿用目标测试库和 PGOPTIONS，以应用账号运行预置 SQL：

```bash
cd "$a02_verification_dir" &&
/www/server/pgsql/bin/psql -X --username=novel_forge_app -v ON_ERROR_STOP=1 --single-transaction -f a02_vector.sql &&
/www/server/pgsql/bin/psql -X --username=novel_forge_app -v ON_ERROR_STOP=1 --single-transaction -f a02_trigram.sql &&
/www/server/pgsql/bin/psql -X --username=novel_forge_app -v ON_ERROR_STOP=1 -f a02_chinese.sql
```

**macOS 应用机，项目根目录：** 使用应用现有数据库连接配置验收：

```bash
php artisan app:verify-postgres-extensions --database=novel_forge_app_a02_test --parser=zhparser --json --no-interaction
vendor/bin/phpunit tests/Feature/A02ExtensionsTest.php tests/Feature/VerifyPostgresExtensionsTest.php
```

三项扩展必须均为 `passed`，命令退出码为 0；任何失败或 blocked 都返回 1。实际词项应包含“北京”“武功”“novelforge”，输入“玄霄宗修炼”应保留完整词项“玄霄宗”。缺少中文条件的测试会标记 incomplete，不能仅凭测试进程退出码 0 判断完成。索引能力计划不代表小样本性能优势。

**131 服务器，verification 工作目录：** libpq 认证可用后执行实际恢复测试；脚本调用 PATH 中的 psql、pg_dump 和 pg_restore，因此显式使用同一宝塔工具目录：

```bash
export PATH="/www/server/pgsql/bin:$PATH"
export PGUSER=novel_forge_app
export PGDATABASE=novel_forge_app_a02_test
export PGOPTIONS='-c zhparser.extra_dicts=a02_custom_dict.txt'

cd "$a02_verification_dir" &&
bash a02_roundtrip.sh "/tmp/a02-roundtrip-$(date +%Y%m%d-%H%M%S)"
```

该脚本仅接受名称和用途备注均匹配的 A02 测试库。它新建独立 schema，生成归档，删除本次样本 schema，实际恢复并比较前后结果、复验中文命中和专有词；成功后清理样本并保留证据。已有同名 schema 会停止，不应手工删除不明对象后重试。macOS 也可使用兼容的 PostgreSQL 客户端及项目内脚本执行，需另行设置该客户端的连接参数和 PATH。

### 7. 备份后配置开发库

测试和恢复验收全部通过后，再处理开发库。**已完成 A02 的现有开发库无需重复配置。** 新环境操作时，在具备认证及备份权限的终端先生成私有备份；以下沿用第 6 步的 PostgreSQL 工具 PATH，选择已有管理员连接：

```bash
export PGUSER=postgres
export PGDATABASE=novel_forge_app
a02_backup_dir="$HOME/novel-forge-backups"
a02_backup_file="$a02_backup_dir/before-extensions-$(date +%Y%m%d-%H%M%S).dump"

umask 077
mkdir -p "$a02_backup_dir" &&
pg_dump --format=custom --file="$a02_backup_file" &&
pg_restore --list "$a02_backup_file" > "$a02_backup_file.list" &&
sha256sum "$a02_backup_file"
```

确认备份成功，再在 `PGDATABASE=novel_forge_app` 下重复第 4 步的管理员启用和应用账号配置命令，复用第 5 步的应用启动参数。**不要在开发库运行恢复测试脚本。** 在 macOS 项目根目录复验：

```bash
php artisan app:verify-postgres-extensions --parser=zhparser --json --no-interaction
```

归档目录可读只证明 `pg_restore --list` 成功，不能替代实际恢复验收。数据库归档不含 SCWS/zhparser 动态库、外部词典及客户端启动参数，异机恢复前须准备相同软件、词典和连接参数。

2026-11-02 的 A02 记录为：测试库、开发库三项扩展通过，独立样本实际恢复通过；专项测试 9 项、42 个断言，全套测试 34 项、349 个断言，无 incomplete。本次 README 整理未重新安装或执行这些验收，历史证据位置见 [A02 详细记录](docs/A02_PostgreSQL扩展安装与验收.md)。

### 常见问题

| 现象 | 原因与处理 |
| --- | --- |
| pg_config 显示其他大版本 | 显式设置 `export A02_PG_CONFIG=/www/server/pgsql/bin/pg_config`，核对后重新预检。 |
| 变量为空、出现 `Command '' not found` | `read -p` 的提示文字不是赋值；直接执行本文的 `export 名称=值`，重新登录后重新设置变量。 |
| `make: No targets specified and no makefile found` | configure 尚未成功生成 Makefile；先检查 bootstrap 与 configure 日志，再完成第 2 步。 |
| `cannot find input file: Makefile.in` | 引导过程失败，即使 configure 存在也不能继续；修复 automake 问题并重新 autoreconf。 |
| `'#' comment at start of rule is unportable` | 使用已有 SCWS 兼容补丁；已手工修过的目录不要重复 apply。 |
| SSH 终端执行失败命令后直接退出 | 交互 shell 启用了失败即退出；重连后按本文的分段 `&&` 流程执行。 |
| ldd 显示 libscws 的完整路径 | 正常解析依赖；出现 `not found` 才表示缺失，先修复后验收。 |
| `to_regconfig()` 不存在 | 使用项目当前配置 SQL，其查询系统目录，不再调用此函数。 |
| `玄霄宗` 被拆开或启动参数报错 | 核对词典路径和摘要，通过应用 `server_options` 或客户端 PGOPTIONS 在新连接启动时传入；不要连接后 SET。 |
| 终端提示找不到 psql | 使用 `/www/server/pgsql/bin/psql`；恢复脚本需同时将该 bin 目录加入 PATH。 |

