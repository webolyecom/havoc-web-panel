-- Per-site PHP-FPM pool and resource limits (JSON, see PoolService): RAM/CPU limit (null = package, 0 = unlimited),
-- process manager mode and its numbers (null = automatic)
ALTER TABLE domains ADD COLUMN php_pool TEXT;
