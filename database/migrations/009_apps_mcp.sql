-- One-click applications and the Claude connector (MCP over OAuth 2.1)
CREATE TABLE apps(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  domain_id INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  app TEXT NOT NULL,                                  -- wordpress
  status TEXT NOT NULL DEFAULT 'installing',          -- installing | installed | failed
  version TEXT, db_name TEXT, admin_user TEXT, admin_url TEXT, note TEXT,
  secret_enc TEXT,                                    -- install-time secrets, wiped when the install ends
  via TEXT NOT NULL DEFAULT 'panel',                  -- panel | claude
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT
);
CREATE INDEX apps_domain ON apps(domain_id);

CREATE TABLE oauth_clients(
  client_id TEXT PRIMARY KEY, name TEXT NOT NULL, redirect_uris TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE oauth_codes(
  code_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  redirect_uri TEXT NOT NULL, challenge TEXT NOT NULL, scope TEXT NOT NULL, resource TEXT, expires INTEGER NOT NULL
);
CREATE TABLE oauth_tokens(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  client_id TEXT NOT NULL, client_name TEXT NOT NULL, scope TEXT NOT NULL,
  access_hash TEXT NOT NULL UNIQUE, access_expires INTEGER NOT NULL,
  refresh_hash TEXT UNIQUE, refresh_expires INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used TEXT, last_ip TEXT
);
CREATE INDEX oauth_tokens_user ON oauth_tokens(user_id);
