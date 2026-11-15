-- One-time upload links: a browser upload straight into a site (no login, no Claude in between)
CREATE TABLE upload_links(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL UNIQUE,
  domain_id INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  file_name TEXT, received INTEGER NOT NULL DEFAULT 0, done_at TEXT,
  via TEXT NOT NULL DEFAULT 'panel',
  expires INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
