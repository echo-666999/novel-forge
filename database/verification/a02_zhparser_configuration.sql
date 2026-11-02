-- zhparser.extra_dicts 须由客户端启动参数传入；ALTER DATABASE SET 在本版本无效。
-- Laravel 使用 DB_ZHPARSER_EXTRA_DICTS；psql 使用 PGOPTIONS，均须新建连接。
-- 服务器安装固定版本的 zhparser 和 SCWS 后，由管理员执行。
-- 不更改 default_text_search_config，不覆盖已有同名但用途不同的配置。
CREATE EXTENSION IF NOT EXISTS zhparser WITH SCHEMA public;
DO $a02$
DECLARE
    actual_parser text;
BEGIN
    -- 直接查询系统目录；PostgreSQL 没有 to_regconfig() 函数。
    IF NOT EXISTS (
        SELECT 1 FROM pg_ts_config c JOIN pg_namespace n ON n.oid = c.cfgnamespace
        WHERE n.nspname = 'public' AND c.cfgname = 'nf_zh_cn'
    ) THEN
        CREATE TEXT SEARCH CONFIGURATION public.nf_zh_cn (PARSER = public.zhparser);
    END IF;
    SELECT pn.nspname || '.' || p.prsname INTO actual_parser FROM pg_ts_config c
        JOIN pg_namespace n ON n.oid = c.cfgnamespace
        JOIN pg_ts_parser p ON p.oid = c.cfgparser
        JOIN pg_namespace pn ON pn.oid = p.prsnamespace
        WHERE n.nspname = 'public' AND c.cfgname = 'nf_zh_cn';
    IF actual_parser IS DISTINCT FROM 'public.zhparser' THEN
        RAISE EXCEPTION '已有 nf_zh_cn 未使用 zhparser，拒绝覆盖';
    END IF;
END
$a02$;
-- 词性映射按固定的 zhparser 上游配置，英文类型 e 同时参与检索。
ALTER TEXT SEARCH CONFIGURATION public.nf_zh_cn DROP MAPPING IF EXISTS FOR n,v,a,i,e,l;
ALTER TEXT SEARCH CONFIGURATION public.nf_zh_cn ADD MAPPING FOR n,v,a,i,e,l WITH pg_catalog.simple;
COMMENT ON TEXT SEARCH CONFIGURATION public.nf_zh_cn IS '小说中文分词验收配置';

