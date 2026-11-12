ALTER TABLE domains ADD COLUMN php_ini TEXT;                   -- JSON {directive: value}, whitelisted for users
ALTER TABLE domains ADD COLUMN php_ini_admin TEXT;             -- extra lines, admin only
ALTER TABLE domains ADD COLUMN nginx_custom TEXT;              -- per-site Nginx include
ALTER TABLE domains ADD COLUMN nginx_custom_trusted INTEGER NOT NULL DEFAULT 0;  -- last saved by an admin
