-- Applications module: a long-running process (e.g. a Go game server) behind a site's domain, reverse-proxied by nginx.
-- The WordPress "apps" table is unrelated; these are "web_apps".
CREATE TABLE web_apps (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL UNIQUE,                      -- slug ^[a-z][a-z0-9-]{1,30}$; also the system user havoc-<name>
    domain_id     INTEGER NOT NULL UNIQUE REFERENCES domains(id) ON DELETE CASCADE,
    port          INTEGER NOT NULL UNIQUE,                   -- 20000-29999, listens only on 127.0.0.1
    start_command TEXT NOT NULL,                             -- relative to current/, e.g. "./starminer-server"
    health_path   TEXT NOT NULL DEFAULT '/healthz',
    ws_paths      TEXT NOT NULL DEFAULT '[]',                -- JSON array, e.g. ["/ws"]
    shared_paths  TEXT NOT NULL DEFAULT '[]',                -- JSON array, e.g. ["data","packs","var"]
    status        TEXT NOT NULL DEFAULT 'new',               -- new | deploying | running | stopped | failed
    note          TEXT,                                      -- last error / health detail
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT
);

CREATE TABLE web_app_releases (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id     INTEGER NOT NULL REFERENCES web_apps(id) ON DELETE CASCADE,
    ref        TEXT NOT NULL,                                -- release dir name (UTC timestamp)
    active     INTEGER NOT NULL DEFAULT 0,
    health     TEXT,                                         -- ok | failed | ''
    note       TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_web_app_releases_app ON web_app_releases(app_id, id DESC);
