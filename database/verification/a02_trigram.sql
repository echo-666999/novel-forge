-- A02 三元组相似度验收；通过 psql --single-transaction 或验证命令执行。
CREATE TEMP TABLE a02_trigram_probe (
    id bigint PRIMARY KEY,
    title text NOT NULL
) ON COMMIT DROP;
COMMENT ON TABLE a02_trigram_probe IS '模糊检索验收样本';
COMMENT ON COLUMN a02_trigram_probe.id IS '样本编号';
COMMENT ON COLUMN a02_trigram_probe.title IS '待检索名称';
INSERT INTO a02_trigram_probe VALUES (1, '江湖风云'), (2, '江湖风雨'), (3, '星际远航');
CREATE INDEX a02_trigram_probe_gin ON a02_trigram_probe USING gin (title public.gin_trgm_ops);
DO $a02$
BEGIN
    IF public.similarity('江湖风云', '江湖风云') <> 1 THEN
        RAISE EXCEPTION '相同文本相似度应为一';
    END IF;
    IF public.similarity('江湖风云', '江湖风雨') <= public.similarity('江湖风云', '星际远航') THEN
        RAISE EXCEPTION '相近名称未获得更高相似度';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM a02_trigram_probe WHERE title % '江湖风云') THEN
        RAISE EXCEPTION '相似查询未命中相同名称';
    END IF;
END
$a02$;
SELECT id, title, public.similarity(title, '江湖风云') AS similarity
FROM a02_trigram_probe ORDER BY similarity DESC, id;
EXPLAIN SELECT id FROM a02_trigram_probe WHERE title % '江湖风云';
