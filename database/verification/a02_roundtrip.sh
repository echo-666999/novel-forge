#!/usr/bin/env bash
# A02 中文配置与样本的实际导出、恢复复验，仅操作带用途标记的专用测试库。
set -euo pipefail
if [[ "${PGDATABASE:-}" != 'novel_forge_app_a02_test' ]]; then
    echo 'PGDATABASE 必须为 novel_forge_app_a02_test，拒绝操作其他数据库。' >&2
    exit 1
fi
for a02_tool in psql pg_dump pg_restore diff; do command -v "$a02_tool" >/dev/null; done
# Linux 使用 sha256sum，macOS 可使用系统自带的 shasum。
if command -v sha256sum >/dev/null; then
    a02_hash_command=(sha256sum)
elif command -v shasum >/dev/null; then
    a02_hash_command=(shasum -a 256)
else
    echo '缺少 SHA-256 摘要工具。' >&2
    exit 1
fi
# 沿用 libpq 的 PGPASSFILE/.pgpass 或交互认证，不将密码写入命令或证据文件。
a02_identity="$(psql -XAt -v ON_ERROR_STOP=1 -c "SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname=current_database()")"
[[ "$a02_identity" = 'novel-forge A02 isolated tests' ]] || { echo '数据库用途标记不符。' >&2; exit 1; }
a02_config="${A02_TEXT_CONFIG:-public.nf_zh_cn}"
case "$a02_config" in public.nf_zh_cn|public.jiebacfg) ;; *) echo '不支持的中文配置。' >&2; exit 1;; esac
a02_output="${1:?请指定新的证据输出目录}"
umask 077
# 不复用已有目录，避免覆盖之前的证据。
mkdir "$a02_output"
psql -X -v ON_ERROR_STOP=1 -v a02_config="$a02_config" <<'SQL'
-- 所有测试对象限定在独立 schema；已有同名 schema 时停止，不自动删除。
BEGIN;
CREATE SCHEMA a02_roundtrip;
COMMENT ON SCHEMA a02_roundtrip IS 'A02恢复验收样本';
CREATE TEXT SEARCH CONFIGURATION a02_roundtrip.zh_cn (COPY = :a02_config);
COMMENT ON TEXT SEARCH CONFIGURATION a02_roundtrip.zh_cn IS '恢复验收中文配置';
CREATE TABLE a02_roundtrip.samples (id bigint PRIMARY KEY, body text NOT NULL, document tsvector NOT NULL, embedding public.vector(3) NOT NULL);
COMMENT ON TABLE a02_roundtrip.samples IS '恢复验收样本';
COMMENT ON COLUMN a02_roundtrip.samples.id IS '样本编号';
COMMENT ON COLUMN a02_roundtrip.samples.body IS '中文样本';
COMMENT ON COLUMN a02_roundtrip.samples.document IS '中文分词向量';
COMMENT ON COLUMN a02_roundtrip.samples.embedding IS '三维样本向量';
INSERT INTO a02_roundtrip.samples VALUES (1, '玄霄宗主角在北京学习武功。', to_tsvector('a02_roundtrip.zh_cn', '玄霄宗主角在北京学习武功。'), '[1,0,0]');
COMMIT;
SQL
# 记录实际分词、查询和向量输出，恢复后按同一顺序逐字比较。
a02_query="SELECT id, body, document, embedding, to_tsvector('a02_roundtrip.zh_cn', body), document @@ plainto_tsquery('a02_roundtrip.zh_cn', '北京') FROM a02_roundtrip.samples ORDER BY id"
psql -XAt -v ON_ERROR_STOP=1 -c "$a02_query" > "$a02_output/before.txt"
pg_dump --format=custom --schema=a02_roundtrip --file="$a02_output/roundtrip.dump"
pg_restore --list "$a02_output/roundtrip.dump" > "$a02_output/archive-list.txt"
# 此 schema 刚由本脚本创建且只含固定验收样本，删除后立即从归档恢复。
psql -X -v ON_ERROR_STOP=1 -c 'DROP SCHEMA a02_roundtrip CASCADE'
pg_restore --exit-on-error --no-owner --no-privileges --dbname="$PGDATABASE" "$a02_output/roundtrip.dump"
psql -XAt -v ON_ERROR_STOP=1 -c "$a02_query" > "$a02_output/after.txt"
diff -u "$a02_output/before.txt" "$a02_output/after.txt"
psql -X -v ON_ERROR_STOP=1 <<'SQL'
DO $a02$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM a02_roundtrip.samples WHERE document @@ plainto_tsquery('a02_roundtrip.zh_cn', '北京'))
        OR NOT EXISTS (SELECT 1 FROM a02_roundtrip.samples WHERE '玄霄宗' = ANY(tsvector_to_array(to_tsvector('a02_roundtrip.zh_cn', body)))) THEN
        RAISE EXCEPTION '恢复后中文命中或固定词典校验失败';
    END IF;
END
$a02$;
DROP SCHEMA a02_roundtrip CASCADE;
SQL
"${a02_hash_command[@]}" "$a02_output/roundtrip.dump" > "$a02_output/SHA256SUMS"
echo '实际恢复及分词复验通过；证据已保留，验收 schema 已清理。'
