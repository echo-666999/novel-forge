-- A02 向量类型、距离和近邻验证；调用方须在事务内执行。
-- 表、索引及样本仅存在于当前会话，提交或回滚后不保留。
CREATE TEMP TABLE a02_vector_probe (
    id bigint PRIMARY KEY,
    label text NOT NULL,
    embedding public.vector(3) NOT NULL
) ON COMMIT DROP;
COMMENT ON TABLE a02_vector_probe IS '向量验收样本';
COMMENT ON COLUMN a02_vector_probe.id IS '样本编号';
COMMENT ON COLUMN a02_vector_probe.label IS '样本名称';
COMMENT ON COLUMN a02_vector_probe.embedding IS '三维向量';
INSERT INTO a02_vector_probe VALUES
    (1, '最近', '[1,0,0]'), (2, '中间', '[0,1,0]'), (3, '最远', '[-1,0,0]');
CREATE INDEX a02_vector_probe_hnsw ON a02_vector_probe USING hnsw (embedding public.vector_l2_ops);
DO $a02$
BEGIN
    IF (SELECT array_agg(id ORDER BY embedding <-> '[1,0,0]'::public.vector) FROM a02_vector_probe) <> ARRAY[1,2,3]::bigint[] THEN
        RAISE EXCEPTION '向量近邻排序不符合预期';
    END IF;
    IF abs((SELECT embedding <-> '[1,0,0]'::public.vector FROM a02_vector_probe WHERE id = 3) - 2) > 0.000001 THEN
        RAISE EXCEPTION '向量距离不符合预期';
    END IF;
    IF EXISTS (SELECT 1 FROM a02_vector_probe WHERE vector_dims(embedding) <> 3) THEN
        RAISE EXCEPTION '向量维度不符合预期';
    END IF;
END
$a02$;
SELECT id, label, embedding <-> '[1,0,0]'::public.vector AS distance
FROM a02_vector_probe ORDER BY embedding <-> '[1,0,0]'::public.vector;
EXPLAIN SELECT id FROM a02_vector_probe ORDER BY embedding <-> '[1,0,0]'::public.vector LIMIT 2;
