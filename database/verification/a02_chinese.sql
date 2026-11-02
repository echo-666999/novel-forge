-- zhparser 安装配置后执行；若正式采用 pg_jieba，使用命令的 --parser=pg_jieba 路径验证。
-- 本脚本不创建业务数据，不修改全局默认全文配置。
BEGIN;
SELECT * FROM ts_debug('public.nf_zh_cn', '小说主角在北京学习武功。NovelForge 2026');
SELECT to_tsvector('public.nf_zh_cn', '玄霄宗主角在北京学习武功。NovelForge 2026');
DO $a02$
DECLARE
    document tsvector;
BEGIN
    document := to_tsvector('public.nf_zh_cn', '玄霄宗主角在北京学习武功。NovelForge 2026');
    IF NOT (document @@ plainto_tsquery('public.nf_zh_cn', '北京'))
        OR document @@ plainto_tsquery('public.nf_zh_cn', '火星')
        OR NOT ('玄霄宗' = ANY(tsvector_to_array(document)))
        OR NOT ('novelforge' = ANY(tsvector_to_array(document)))
        OR to_tsvector('public.nf_zh_cn', '') <> ''::tsvector THEN
        RAISE EXCEPTION '中文、专有词、混合文本或空输入验收失败';
    END IF;
END
$a02$;
ROLLBACK;
