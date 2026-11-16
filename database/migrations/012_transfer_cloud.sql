-- havoc:foreign-keys-off
-- Cloud backup targets (Google Drive, OneDrive, Dropbox) and site transfer between panels.
CREATE TABLE backup_targets_new(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type TEXT NOT NULL CHECK(type IN('local','s3','ftp','sftp','gdrive','onedrive','dropbox')), config_enc TEXT);
INSERT INTO backup_targets_new (id, user_id, type, config_enc) SELECT id, user_id, type, config_enc FROM backup_targets;
DROP TABLE backup_targets;
ALTER TABLE backup_targets_new RENAME TO backup_targets;

-- a certificate copied from the old server: replaced by Let's Encrypt once DNS points here
ALTER TABLE domains ADD COLUMN ssl_transferred INTEGER NOT NULL DEFAULT 0;

-- source side: keys that let another panel pull sites, and the export packages built for it
CREATE TABLE transfer_keys(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL DEFAULT '',
  token_hash TEXT NOT NULL UNIQUE,
  domains TEXT,                      -- JSON list of domain ids, NULL = all
  allowed_ip TEXT,                   -- NULL = any address
  expires_at INTEGER NOT NULL,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used TEXT, last_ip TEXT,
  revoked INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE transfer_exports(
  id TEXT PRIMARY KEY,               -- random, used in URLs
  key_id INTEGER NOT NULL REFERENCES transfer_keys(id) ON DELETE CASCADE,
  domain_id INTEGER REFERENCES domains(id) ON DELETE CASCADE,
  options TEXT NOT NULL DEFAULT '{}',
  status TEXT NOT NULL DEFAULT 'running',   -- running, ready, failed
  files TEXT,                        -- JSON [{name, size, sha256}]
  manifest TEXT,
  error TEXT,
  created_at INTEGER NOT NULL
);

-- destination side
CREATE TABLE transfers(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_url TEXT NOT NULL,
  key_enc TEXT NOT NULL,
  fingerprint TEXT,                  -- pinned certificate (when the source certificate is not publicly trusted)
  source_info TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE transfer_items(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transfer_id INTEGER NOT NULL REFERENCES transfers(id) ON DELETE CASCADE,
  domain TEXT NOT NULL,
  options TEXT NOT NULL DEFAULT '{}',
  status TEXT NOT NULL DEFAULT 'queued',    -- queued, running, done, failed
  step TEXT NOT NULL DEFAULT '',
  progress INTEGER NOT NULL DEFAULT 0,
  log TEXT NOT NULL DEFAULT '',
  result TEXT,
  new_domain_id INTEGER REFERENCES domains(id) ON DELETE SET NULL,
  started_at TEXT, finished_at TEXT
);
