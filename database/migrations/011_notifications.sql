-- Notifications (Telegram / e-mail): open problems and what was sent
CREATE TABLE alert_state(
  k TEXT PRIMARY KEY,           -- e.g. disk:/, service:nginx, ssl:example.com
  message TEXT NOT NULL,
  since INTEGER NOT NULL,
  last_sent INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE notification_log(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  channel TEXT NOT NULL,        -- email | telegram
  subject TEXT NOT NULL,
  ok INTEGER NOT NULL,
  error TEXT
);
