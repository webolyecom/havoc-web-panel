ALTER TABLE domains ADD COLUMN ssl_auto TEXT NOT NULL DEFAULT '';          -- '', running, pending, issued, failed
ALTER TABLE domains ADD COLUMN ssl_auto_note TEXT;
ALTER TABLE domains ADD COLUMN ssl_auto_attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE domains ADD COLUMN ssl_auto_last TEXT;
ALTER TABLE cron_jobs ADD COLUMN label TEXT NOT NULL DEFAULT '';
ALTER TABLE cron_jobs ADD COLUMN log_output INTEGER NOT NULL DEFAULT 0;
