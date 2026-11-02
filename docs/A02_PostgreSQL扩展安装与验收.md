# A02 PostgreSQL 扩展安装与验收

## 当前状态与边界

**A02 已完成（2026-11-02）。** 测试库 `novel_forge_app_a02_test` 和开发库 `novel_forge_app` 均实测通过 vector 0.8.1、pg_trgm 1.6、zhparser 2.4；独立测试 schema 的实际导出、删除、恢复和中文复验通过。全套 PHPUnit 34 项测试、349 个断言通过，无 incomplete。

服务器为 192.168.44.131，PostgreSQL 18.6、Linux aarch64。用户终端记录显示 Ubuntu 24.04.5 LTS，宝塔原生安装路径 `/www/server/pgsql`。编译安装步骤来自用户记录；代理实时验证了数据库版本、扩展行为及三份服务器词典文件的 SHA-256，未独立重建服务器二进制。**已安装部分无需重复执行。**

A02 专用库所有者为 `novel_forge_app`，用途备注为 `novel-forge A02 isolated tests`。验收不重置该库，不执行业务迁移。开发库账号 1 条、设置 8 条的完整内容摘要在变更前后相同；A04 三张业务表仍为 0 条记录，9 项迁移均 Ran。

PHP 包 `pgvector/pgvector 0.2.2` 是适配库，不是服务器扩展版本。本次使用合成向量验证距离、排序和索引能力，不调用模型，不代表语义检索质量或 C05 混合检索已完成。

## 1. 宝塔 PostgreSQL 18 预检

在 131 服务器运行；将 `database/verification/` 内文件复制到工作目录，例如用户本次使用的 `/www/wwwroot/work/verification`。从该目录执行：

```bash
export A02_PG_CONFIG=/www/server/pgsql/bin/pg_config
"$A02_PG_CONFIG" --version
bash a02_server_preflight.sh
```

`read -r -p '提示文字' 变量名` 只显示提示并等待输入，不会把提示文字赋给变量。这里直接使用上面的 `export 名称=值`。不要依赖 PATH 中可能指向 PostgreSQL 16 的 pg_config。

预检核对工具、服务器头文件、PGXS、库与共享目录，不安装软件。仍需确认该安装对应正在监听 5432 的实例。本次用户输出为 `/www/server/pgsql/lib`、`/www/server/pgsql/share`、`/www/server/pgsql/include/server`、`/www/server/pgsql/lib/pgxs/src/makefiles/pgxs.mk`，全部只读检查通过。不得使用 macOS 工具链或不同 PostgreSQL 大版本的开发文件替代。

## 2. 固定源码及 SCWS 构建兼容处理

来源、版本、许可及摘要见 `database/verification/a02_sources.json`。保留 zhparser 的 `COPYRIGHT` 和 SCWS 的 `COPYING`；无需安装 PHP SCWS 扩展。

以下用于新安装或复现，不要覆盖当前已成功安装的源码目录。**不要在交互式 SSH 登录 shell 中执行 `set -euo pipefail`**，否则失败命令可能直接退出 shell 并断开终端。每段使用 `&&`，任一步失败即停止该段；检查日志后再继续下一段。

从上述 verification 工作目录执行：

```bash
export A02_PG_CONFIG=/www/server/pgsql/bin/pg_config
a02_verification_dir="$(pwd)"
a02_scws_prefix=/opt/novel-forge/scws-1.2.3

git clone https://github.com/hightman/scws.git a02-scws &&
cd a02-scws &&
git checkout --detach b5ae33f6a71ecbf5442a2e485703af4d372f3341 &&
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

补丁只删除 `Makefile.am` 中 TAB 开头、已经注释的旧 unison 命令，解决现代 automake 的 `'#' comment at start of rule is unportable`，不改分词逻辑。补丁 SHA-256 为 `5294eba7e63db44e3cbda9e1b18ee33aff45e297053f720c4de5169e350db885`。已经手工删除过该行的目录不能重复 apply；用 `git diff` 确认差异后从 autoreconf 继续。

仅有 configure 不表示引导成功。此前 `sh acprep` 未阻止后续执行，最终出现 `cannot find input file: Makefile.in`；没有成功生成 Makefile 就不能 make。失败时查看 `tail -n 80 a02-bootstrap.log` 或 `tail -n 60 a02-configure.log`。

确认 SCWS 安装成功后，回到 verification 目录构建 zhparser：

```bash
cd "$a02_verification_dir" &&
git clone https://github.com/amutu/zhparser.git a02-zhparser &&
cd a02-zhparser &&
git checkout --detach 2e995c4df672563992b4d7a147b8fa2d0d4cda6c &&
make PG_CONFIG="$A02_PG_CONFIG" SCWS_HOME="$a02_scws_prefix" &&
sudo make PG_CONFIG="$A02_PG_CONFIG" SCWS_HOME="$a02_scws_prefix" install
```

```bash
ldd "$("$A02_PG_CONFIG" --pkglibdir)/zhparser.so"
sha256sum dict.utf8.xdb rules.utf8.ini
```

用户实际输出已显示 zhparser.so 安装到宝塔 lib 目录，control/SQL 安装到 share/extension，内附词典安装到 share/tsearch_data；ldd 的 libscws.so.1 指向 `/opt/novel-forge/scws-1.2.3/lib/libscws.so.1`，没有 not found。这是成功安装输出。

安装固定词典：

```bash
cd "$a02_verification_dir" &&
sha256sum a02_custom_dict.txt &&
sudo install -m 0644 a02_custom_dict.txt "$("$A02_PG_CONFIG" --sharedir)/tsearch_data/a02_custom_dict.txt" &&
sha256sum "$("$A02_PG_CONFIG" --sharedir)/tsearch_data/a02_custom_dict.txt"
```

三份服务器文件已实时核对一致：

| 文件 | SHA-256 |
| --- | --- |
| dict.utf8.xdb | fd76a689f996c4e68ce53325a6a24dc652a678b1e7f676e4c5ca76265609a3e2 |
| rules.utf8.ini | 45395f794226581fb1cc02b44ae1481804152511fc59fa677e2f7b755440479c |
| a02_custom_dict.txt | cfdcbe35976ee9b67be463237b10b4466afe253fc3aa89aae57c020f4dd19fde |

## 3. 数据库配置与连接启动参数

管理员在 **A02 专用测试库** 执行以下两个 SQL 文件。可在 DBX 选中该库后执行文件内的纯 SQL，也可登录 psql 后用 `\i`，或从终端执行：

```bash
# PGHOST/PGPORT/PGUSER 使用现有管理员连接；密码使用交互认证或已有 libpq 认证。
export PGDATABASE=novel_forge_app_a02_test
/www/server/pgsql/bin/psql -X -v ON_ERROR_STOP=1 --single-transaction -f a02_base_extensions.sql
/www/server/pgsql/bin/psql -X -v ON_ERROR_STOP=1 --single-transaction -f a02_zhparser_configuration.sql
```

`\i`、psql 参数、export 等不是 SQL，不能粘贴到 DBX 的 SQL 编辑器执行。配置文件创建 `public.nf_zh_cn`，只映射指定词性，不改变 default_text_search_config。它通过系统目录检查同名配置及解析器 schema，遇到其他解析器会拒绝覆盖；PostgreSQL 没有 `to_regconfig()`，此前错误已修正。

**词典必须在客户端连接启动时传入。** 本次实测：`ALTER DATABASE SET zhparser.extra_dicts` 在解析器加载前看似有值，加载后出现 `cannot be set after connection start` 并变空；session_preload_libraries 也未解决。本轮尝试的两个测试库默认参数已撤销，最终没有修改 PostgreSQL 全局参数或配置预加载，也未重启服务器。

本项目 Laravel 13.39.0 的 PostgresConnector 原生支持 `server_options`。`config/database.php` 已接入可选环境变量；当前本地 `.env` 为：

```dotenv
DB_ZHPARSER_EXTRA_DICTS=a02_custom_dict.txt
```

`.env.example` 该值留空，安装服务器文件后再设置。修改后重建已有配置缓存并重连；本次没有配置缓存。长期运行的队列消费者需在采用新配置时正常重启。不要把参数改成连接后执行 SET。

psql、pg_dump、pg_restore 使用 libpq 启动参数：

```bash
export PGOPTIONS='-c zhparser.extra_dicts=a02_custom_dict.txt'
```

DBX 手动复验自定义词时，也必须通过其连接的启动参数传入，不能在已建立连接中执行 SET；仅执行 CREATE EXTENSION/配置 SQL 不依赖自定义词典已加载。

## 4. SQL 与应用验收

psql 在专用测试库、带上述 PGOPTIONS 的新连接执行：

```bash
/www/server/pgsql/bin/psql -X -v ON_ERROR_STOP=1 --single-transaction -f a02_vector.sql
/www/server/pgsql/bin/psql -X -v ON_ERROR_STOP=1 --single-transaction -f a02_trigram.sql
/www/server/pgsql/bin/psql -X -v ON_ERROR_STOP=1 -f a02_chinese.sql
```

在 macOS 项目根目录执行：

```bash
php artisan app:verify-postgres-extensions --database=novel_forge_app_a02_test --parser=zhparser --json --no-interaction
php artisan app:verify-postgres-extensions --parser=zhparser --json --no-interaction
vendor/bin/phpunit tests/Feature/A02ExtensionsTest.php tests/Feature/VerifyPostgresExtensionsTest.php
```

命令不安装或升级扩展，任一失败或 blocked 时退出码 1；全部通过为 0。测试缺失中文扩展会明确 incomplete，不能仅凭测试进程退出码 0 宣称完成。本轮专项 9 项测试、42 个断言通过，全套 34 项、349 个断言通过，无 incomplete。回归覆盖配置缺失、重复配置、重连后词典保持生效和嵌套事务保留。

人工审阅实际词项包含“北京”“武功”“novelforge”，专有词输入“玄霄宗修炼”得到“玄霄宗”“修炼”。SQL ts_debug 中介词及标点未映射，符合指定词性策略；空输入、命中、未命中检查通过。临时样本均回滚，表和全部字段有中文备注。

命令同时保存自然计划与关闭顺序扫描后的索引能力计划；HNSW、三元组 GIN 和中文 GIN 均能使用。能力计划只证明索引支持，不表示小样本下性能优于顺序扫描。

## 5. 实际导出与恢复

在 libpq 认证已配置、工具大版本兼容的客户端执行；macOS 本次使用 PostgreSQL 18.6 客户端：

```bash
export PGDATABASE=novel_forge_app_a02_test
export PGOPTIONS='-c zhparser.extra_dicts=a02_custom_dict.txt'
bash database/verification/a02_roundtrip.sh "/tmp/a02-roundtrip-$(date +%Y%m%d-%H%M%S)"
```

脚本检查库名和用途标记，新建独立 `a02_roundtrip` schema，复制中文配置并写入固定样本，导出 custom 归档，删除本次 schema，实际 pg_restore，再逐字比较输出并复验中文命中及专有词。已有同名 schema 立即停止，不自动删除。成功后清理测试 schema，保留证据；摘要工具支持 Linux sha256sum 和 macOS shasum。

本次实际恢复证据位于 `storage/app/private/a02-evidence/roundtrip-20261102-211646/`，归档 SHA-256 为 `0c233749b4c411f2364f8f73616d988d18a60cc8fd4b50ea8d6b30c9996a48e8`。before.txt 与 after.txt 相同，恢复后“北京”命中、自定义词“玄霄宗”保持完整，验收 schema 残留为 0。

数据库归档不包含服务器动态库和外部词典，且 schema 归档不包含客户端连接启动参数。异机恢复须先安装同版本软件及三份相同词典，再传入相同启动参数并恢复；`pg_restore --list` 只能验证归档目录可读，不能替代实际恢复。

## 6. 开发库收口证据

测试和恢复通过后，已先备份开发库，再通过管理员启用 zhparser，由应用账号创建中文配置，最后复验：

- 开发库变更前备份：`storage/app/private/backups/a02-before-extensions-20261102-211721.dump`，40,681 字节，SHA-256 `dcb2fd87dd84423fad4a5ddf682139a4ae553c61fea35779b8d68b7d43fad714`，归档目录可读；同目录 integrity.json 记录用户与设置摘要。
- 最终测试库与开发库完整报告：`storage/app/private/a02-evidence/test-final-20261102-212652.json`、`development-final-20261102-212652.json`，包含版本、词项及自然/能力执行计划，均 passed=true。
- 三份预置 SQL 的实际输出保存在同目录 `a02_vector.sql.output.txt`、`a02_trigram.sql.output.txt`、`a02_chinese.sql.output.txt`。历史 blocked 报告保留，不能当作当前结果。
- 用户和设置的完整内容摘要一致，三张业务表仍为空，9 项迁移无 Pending；没有新增 migration、业务向量字段或真实模型请求。

zhparser 已在 PostgreSQL 18.6 下实测通过，未启用 pg_jieba。其备用来源仅记录于来源清单，不声称兼容性或验收通过。A02 完成记录已同步到《任务拆分 V1.0》和《任务执行顺序 V1.0》。

参考：[zhparser 固定源码](https://github.com/amutu/zhparser/tree/2e995c4df672563992b4d7a147b8fa2d0d4cda6c)、[SCWS 固定源码](https://github.com/hightman/scws/tree/b5ae33f6a71ecbf5442a2e485703af4d372f3341)、[PostgreSQL 18 启动参数校验实现](https://github.com/postgres/postgres/blob/REL_18_STABLE/src/backend/utils/misc/guc.c)、[libpq PGOPTIONS](https://www.postgresql.org/docs/18/libpq-envars.html)。
