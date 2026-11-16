-- Databases belong to a site (optional); Meilisearch index prefix and API keys per site.
ALTER TABLE db_instances ADD COLUMN domain_id INTEGER REFERENCES domains(id) ON DELETE SET NULL;
CREATE TABLE meili_sites(
  domain_id INTEGER PRIMARY KEY REFERENCES domains(id) ON DELETE CASCADE,
  prefix TEXT NOT NULL UNIQUE,          -- index names of the site start with this (example_com__)
  admin_uid TEXT NOT NULL,              -- Meilisearch key uids (the key values are read from Meilisearch)
  search_uid TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
