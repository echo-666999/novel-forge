-- 由管理员或具备所需权限的账号在已确认目标库执行，不修改既有版本，不卸载扩展。
CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;
CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;
