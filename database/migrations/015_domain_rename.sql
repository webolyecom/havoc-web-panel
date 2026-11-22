-- Parked names that answer with a 301 to their parent domain (the old name kept after "Change domain name")
ALTER TABLE domains ADD COLUMN alias_redirect INTEGER NOT NULL DEFAULT 0;
