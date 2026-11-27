# Havoc WEB Panel – Administrator & Technical Guide

> Back to the [project README](../README.md).

Hosting control panel for **Debian 12 / 13**, written from scratch in PHP 8.2+ (no Laravel or other framework),
SQLite for panel state, Tailwind CSS UI, EN/TR (default EN), dark/light theme.

## Install (one command)
```bash
# from a checkout
sudo bash install/install.sh
# or from a hosted release tarball
curl -fsSL https://YOUR-HOST/install.sh | sudo HAVOC_TARBALL=https://YOUR-HOST/havoc-panel.tar.gz bash
```
Options: `HAVOC_WEBSERVER=nginx|ols|lsws` (with `HAVOC_LSWS_LICENSE=<serial>`, default trial), `HAVOC_PHP_VERSIONS="8.1 8.2 8.3 8.4"`, `HAVOC_PORT=2087`.
The installer prints the panel URL and a random admin password. Panel: `https://SERVER_IP:2087`.

### Claude connector (MCP) and one-click WordPress
* `/mcp` (Streamable HTTP, JSON responses) with OAuth 2.1: metadata at `/.well-known/oauth-protected-resource[/mcp]` and `/.well-known/oauth-authorization-server`, dynamic client registration at `/oauth/register` (redirect URIs limited to `https://claude.ai|claude.com/api/mcp/auth_callback` and loopback), consent at `/oauth/authorize` (panel login + 2FA), tokens at `/oauth/token` (PKCE S256 only, access 1 h, refresh 30 days, rotated). Codes and tokens are stored as SHA-256 hashes. These endpoints never start a session.
* A token acts as the approving user through `Scope`, exactly like the web interface; the "write" scope is optional at consent. Tools: `src/Services/McpTools.php`; file operations run in `fsworker` as the hosting account (`ctl/lib/FsCode.php`). No delete tools. Every write is logged as `claude.<tool>` in the audit log.
* File history: before Claude changes or replaces a file, its content is copied to `<site folder>/.havoc-history/` (next to `public_html`, mode 0700, not reachable from the web), 20 versions per file, files over 50 MB are not versioned. It counts towards the account's disk usage and is included in backups.
* Upload links (`/upload/<token>`, `UploadService`): 48 hex characters, stored as SHA-256, one site, one file, 1 hour, single use; chunks of 8 MB in order, max 2 GB, archive extensions only; stored as `public_html/.havoc-incoming/<date>-<name>` (hidden paths are refused by Nginx and LiteSpeed), removed after extraction or after 7 days (`usage:scan`). The page and endpoint need no session; they are the only panel paths a visitor without login can write to.
* `download_file` resolves the host first and refuses any private, loopback or reserved address; the connection is pinned to the checked address and every redirect is checked again. https only, max 1 GB.
* Users see and end their connections under Settings → Claude connector; *Panel settings → Claude connector* switches the connector off (ending all connections).
* Claude's servers connect from `160.79.104.0/21` on port 443: with a panel host name, the sites' Nginx serves `https://<host>/` and forwards to the panel port (`/etc/nginx/conf.d/havoc-001-panel.conf`, mode 0640; it sends `X-Real-IP`, `X-Forwarded-Port` and the secret from `/etc/havoc/proxy-secret`, which the panel checks before trusting them). Requirements: with a trusted certificate (*Panel address and certificate*: Let's Encrypt over the Nginx catch-all server on port 80, copied to `/etc/havoc/ssl/panel.{crt,key}`, deploy hook `/etc/letsencrypt/renewal-hooks/deploy/havoc-panel`).
* WordPress: `AppInstaller` (root) downloads WP-CLI (SHA-512 checked, `/usr/local/lib/havoc/wp-cli.phar`) and runs every step as the hosting account inside the site folder; passwords go through stdin. The site folder must be empty except the panel's welcome page. The panel creates the database (`hvN_wp`), keeps the install secrets encrypted only until the background job (`bin/havoc app:install <id>`) ends, and shows the admin password once.

### Self-update
* Source: the GitHub repository in `/etc/havoc/update.conf` (`HAVOC_UPDATE_REPO=webolyecom/havoc-web-panel`, root-owned, so the panel user cannot point updates elsewhere). Only **published releases** (tags `vX.Y.Z`) are installed, never branches.
* The panel checks every 6 hours (`havoc-usage.timer` → `UpdateService::checkDue`) through the GitHub API, falling back to the `releases/latest` redirect when the API rate limit (60 requests/hour per IP) is reached. `php /opt/havoc/bin/havoc update:check` checks from the shell.
* `bin/havoc-update` (root, started as the transient unit `havoc-update`) downloads `archive/refs/tags/<tag>.tar.gz` over HTTPS, checks that the archive is complete, that `VERSION` matches the tag and that every PHP file passes `php -l`, then runs the release's own `install/upgrade.sh`. If that fails, `/opt/havoc.old` is moved back. Log: `/var/log/havoc/update.log`, result: `/var/log/havoc/update.status`.
* Automatic updates (off by default) try each release once; a failed one stays on the previous version until it is installed by hand.

### Updating an existing server
```bash
tar xzf havoc-panel.tar.gz && cd havoc
sudo bash install/upgrade.sh
```
Keeps `/etc/havoc` (config, encryption key, certificates), the panel database and all sites; runs migrations, re-applies every site and cron table. **Never re-run `install.sh` on a running server**: it generates a new encryption key, and stored backup credentials can no longer be decrypted.

## Architecture (security model)
```
browser ──HTTPS──> havoc-panel-nginx ──> havoc-panel-fpm (user: havoc, unprivileged)
                                              │  sudo -n /opt/havoc/ctl/havocctl <action>  (JSON on stdin)
                                              ▼
                                        havocctl (root, whitelisted actions, strict validators)
                                              ├─ web server driver (nginx | OpenLiteSpeed | LiteSpeed)
                                              └─ per-domain PHP-FPM master (systemd: havoc-fpm-<domain id>)
```
* Code in `/opt/havoc` is root-owned: a compromised panel process cannot modify it.
* Every domain runs its **own PHP-FPM master** → own PHP version, own extension set (`PHP_INI_SCAN_DIR`),
  own OPcache, own `open_basedir`, cgroup `MemoryMax`/`CPUQuota` from the package.
* One Linux user per panel user (`hv<id>`), home mode 0710, so tenants cannot read each other.
* Tenant isolation in the panel: all data access goes through `Core\Scope` (admin / reseller / user); out-of-scope IDs return 404.
* CSRF tokens, strict CSP with nonces, prepared statements only, `e()` escaping, Argon2id, TOTP 2FA, DB-backed login throttling + nginx `limit_req`, audit log, token API (SHA-256 hashed tokens).

## Layout
`public/` entry + assets · `src/Core` framework · `src/Controllers` · `src/Services` · `views/` · `lang/` · `ctl/` privileged helper
· `database/schema.sql` · `bin/havoc` CLI · `bin/havoc-update` self-update · `docs/openapi.json`

## Status
| Module | State |
|---|---|
| Auth, roles (admin/reseller/user), 2FA, brute-force protection, audit log | ✅ implemented |
| Sidebar grouped into collapsible sections; panel HTTPS port changeable from the panel (two-step with automatic revert after 5 minutes) | ✅ implemented |
| Domains (create/delete), PHP selector, per-domain extensions, OPcache, FPM isolation | ✅ implemented (Nginx) |
| Logs: every site's access / error / PHP log in one list, live viewer (filter, highlighting, line limit), download, clear one/selected/all sites incl. rotated copies, latest errors across all sites, server logs for admins (Nginx, panel, MariaDB, slow log, WAF) | ✅ implemented |
| SSL: automatic Let's Encrypt for new domains (background job, HTTP pre-check of every name incl. www and aliases, retry with back-off while DNS is not pointed yet, optional auto HTTPS), manual issue/renew, custom, Cloudflare Origin, force HTTPS, HSTS | ✅ implemented (Nginx) |
| Server monitor, service control | ✅ implemented |
| Dashboard server overview, process list (end account processes) | ✅ implemented |
| REST API + OpenAPI | ✅ domains, me, stats |
| Resellers, customers, packages (limits pushed to FPM cgroups), white-label (name, colour, logo, panel domain) | ✅ implemented |
| File manager: CodeMirror editor (syntax highlighting, Ctrl+S), drag-and-drop multi upload in chunks (up to 2 GB), streaming download of any size, search, sort, copy/move, rename, bulk and recursive permissions, zip and tar.gz create/extract (unsafe archives refused), image preview; isolated to the docroot, runs as the tenant's Linux user | ✅ implemented |
| MariaDB: create/drop DB + user, password reset, remote access per DB, global bind toggle; one-click phpMyAdmin (latest release, SHA-256 checked, behind the panel login, own system user and PHP-FPM); server-wide performance tuning with RAM-based profiles and automatic rollback | ✅ implemented |
| Web server choice: Nginx, OpenLiteSpeed or LiteSpeed Enterprise for the sites; one-click install (LiteSpeed repo / official Enterprise tarball with trial or serial license) and activation; all sites re-written for the target, automatic return to the previous server if the new one does not answer on port 80 | 🟡 implemented; LiteSpeed paths not yet run on a real server |
| Per-site configuration: php.ini directives (whitelist for users, extra lines for admins, php-fpm -t before restart), Nginx include (directive whitelist for users, free for admins, nginx -t before reload, can replace "location /") | ✅ implemented (Nginx) |
| Self-update from GitHub Releases (manual or automatic, checked and rolled back on failure) | ✅ |
| One-click WordPress | ✅ |
| Claude connector (MCP + OAuth 2.1) | ✅ |
| Panel host name with Let's Encrypt | ✅ (needs Nginx as the site web server) |
| Cron jobs: presets (1/2/5/10/15/30 min, hourly, every N hours, daily, weekly, monthly), schedule builder, range-checked parser, plain-language description and next run times, names, output logging (~/logs/cron, 1 MB per job), run now (as the tenant, 40 s), view log; subdomain / alias (parked) / addon domains, redirects | ✅ implemented |
| Backups: files + databases (tar.gz), manual and scheduled (daily/weekly, retention), local + S3/FTP/SFTP via rclone (credentials encrypted with libsodium), one-click restore (files / databases / both) | ✅ implemented |
| FTP (Pure-FTPd virtual users, TLS) and SFTP (OpenSSH chroot) accounts: per-domain/sub-folder jail, FTP quota, IP allow-list, password reset | ✅ implemented |
| DNS (BIND9): zones per main/addon domain, A/AAAA/CNAME/MX/TXT/SRV/CAA, strict validation + `named-checkzone` before every reload, default records, admin nameserver settings | ✅ implemented |
| Redis (one-click install / uninstall from the Debian packages with the PHP extension, random password by default, show password, used memory / keys / clients, activate/deactivate, maxmemory, eviction policy, AOF, password) and Meilisearch (one-click install from the official release binary with SHA-256 check / uninstall, production mode with master key on 127.0.0.1:7700, indexing memory, show/rotate master key) | ✅ implemented (shared, server-wide) |
| Memcached (one-click install / uninstall with the PHP extension, localhost only, UDP off, cache size, connections, statistics, empty cache), Fail2ban (SSH/SFTP, FTP, panel login and repeat-offender jails, rules, whitelist, banned list with unban, manual ban), Node.js (22 / 24 / 26 LTS from NodeSource, version switch, global pm2 / yarn / pnpm) — all under Add-ons | ✅ implemented |
| Notifications by e-mail (own SMTP client) and Telegram: disk, memory, load, stopped services, SSL expiry, failed backups, new release; confirmed on the next check, reminders, resolved messages | ✅ implemented (havoc-alerts.timer, every 5 minutes) |
| Backups to Google Drive, OneDrive and Dropbox (connected with rclone's own authorization, no app secret in the panel; refreshed tokens are saved), optional rclone crypt encryption for every remote target, free space per target | ✅ implemented |
| Site transfer between Havoc panels (transfer key = address + secret + certificate pin; files, databases with password hashes, settings, aliases, redirects, cron, SSL; resumable verified download; rollback on failure) | ✅ implemented |
| Firewall (ufw): open ports/ranges, IP block, whitelist, presets, delete; lock-out protection | ✅ implemented |
| WAF (ModSecurity v3 + OWASP CRS, Nginx): global off/detect/on, per-domain switch, CRS rule exclusions (global or per domain), structured block rules, tenant-scoped WAF log viewer, one-click component install | ✅ implemented |
| Resource limits: disk + database size, inodes, monthly bandwidth per account (30-min scanner), 80 % warning banner, optional enforcement (sites answer 503 while over quota); live RAM/CPU per domain; CPU/RAM enforced by cgroups on the FPM units | ✅ implemented |
| Traffic statistics from access logs: per-domain daily bandwidth/requests/status classes, SVG chart, 7/30/90/365 days, CSV export | ✅ implemented |

**Testing status**: OpenLiteSpeed and LiteSpeed Enterprise could not be installed in the development environment (LiteSpeed's servers were not reachable from it); their configuration was generated and checked for structure (OLS syntax by hand, Enterprise XML well-formed, switch job with a fake helper), but has not been served by a real LiteSpeed yet. Version 0.1 was installed on a clean Debian 13 server and its pages worked. Version 0.2 was checked with `php -l`, with a local smoke test that drives every page as admin and as a normal user against a fake privileged helper, in headless Chrome (file manager, editor, logs, cron), and with real nginx + PHP-FPM for the panel/phpMyAdmin gate and the generated vhosts (`nginx -t`). The privileged helper actions that change the system (certbot, crontab, mariadb restart, port change, phpMyAdmin/Meilisearch installers) still need a run on a real server: try them on a test VM before production.

## Backup security notes
* Archives: `/var/backups/havoc/<account>/` (root:account 0750, files 0640): readable by their owner, not modifiable/deletable by the tenant.
* Anything that interprets archive content (tar extract, SQL import) runs as the tenant; SQL is imported with a throw-away MySQL user limited to that one database.
* Remote hosts are checked against loopback/link-local/reserved ranges (SSRF guard). SFTP host keys are not pinned yet.
* Jobs run detached (`setsid`) from the panel and from `havoc-backup.timer` (hourly check).

## FTP / SFTP notes
* Each account is jailed in `/srv/havoc-jail/<login>/files`, a bind mount created once (and written to `/etc/fstab`, `nofail`). The tenant cannot redirect the chroot later with symlinks.
* SFTP users are system users sharing the hosting account's uid (`useradd -o`) in group `havoc-sftp`; `/etc/ssh/sshd_config.d/50-havoc-sftp.conf` forces `internal-sftp` + chroot. IP restrictions use per-user `Match User ... Address` files. Needs `Include /etc/ssh/sshd_config.d/*.conf` (default on Debian 12/13).
* FTP: `HAVOC_FTP_TLS=1` makes TLS optional at install time (default 2 = required). Passive ports 40000-40100.
* FTP quotas count FTP uploads only; SFTP has no quota (disk quotas come with the quota module).

## DNS notes
* BIND9 runs authoritative-only (`recursion no`, no zone transfers). Zone files live in `/etc/bind/havoc/`, regenerated from the panel DB on every change; `named-checkzone` must pass before the file is swapped in.
* Admin sets nameservers and the default server IP under DNS. Customers delegate their domain to those nameservers at the registrar.
* A host name containing a dot is treated as fully qualified, a single label is relative to the zone, `@` is the zone apex.
* Not included yet: DNSSEC, secondary/slave zones, NS records for sub-delegation, import/export of zone files.

## Site transfer notes
* The source panel answers `/transfer/v1/*` only with a valid, unexpired, unrevoked key (optionally locked to one IP address); it can list and export only the sites chosen for the key. Packages are built in `/var/backups/havoc/transfer/<id>/` (files by tar running as the account, `mariadb-dump --single-transaction`) and removed after the download or after 24 hours.
* The destination downloads into `/var/backups/havoc/incoming/<id>/` in 32 MB ranges, verifies SHA-256, then imports as the receiving account: tar extracts only the domain's folder, the SQL dump (DEFINER clauses removed) is imported with the site's own database user, then the user gets its old password hash back. Database names get this server's prefix (`hv<old>_x` → `hv<new>_x`); config files (wp-config.php, .env, …) are updated, and WordPress paths are fixed with WP-CLI.
* The copied certificate is marked `ssl_transferred`; Let's Encrypt replaces it as soon as DNS points to the new server (no attempt limit).
* A panel reached under an address other panels cannot use can set `'transfer' => ['base_url' => 'https://panel.example.com']` in config.php.

## Web server settings
* Values are stored in `/etc/havoc/webserver-tuning.json` (one section per server type + `sites`: domain id → page cache seconds). The drivers read it whenever they write a configuration, so the values survive site changes and server switches.
* Nginx:
  * `worker_processes`, `worker_rlimit_nofile` (2 × connections, at least 8192) and `events { worker_connections }` are set in `/etc/nginx/nginx.conf`.
  * Everything else goes into `/etc/nginx/conf.d/havoc-00-tuning.conf`. The same directives left active in nginx.conf are commented out (`# [moved to conf.d/havoc-00-tuning.conf]`).
  * Saving is checked with `nginx -t` and rolled back on failure.
  * The cache zone `havoc` (`/var/cache/havoc-nginx`, owner www-data) is always defined; sites use it only when their page cache is on.
  * A change of the zone line restarts Nginx, because a reload keeps the old zone.
  * "Empty cache" deletes the files and reloads Nginx: the open file cache would otherwise keep serving deleted entries for a while.
* OpenLiteSpeed: the tuning values are written into the generated `httpd_config.conf`, together with `module cache { ... }` (storage `/usr/local/lsws/cachedata`, `respCookieCache 0`) and, when browser caching is on, `expires { ... }`.
* LiteSpeed Enterprise:
  * only `<tuning>` (connections, timeouts, keep-alive, request size, gzip) and `<expires>` are set in `httpd_config.xml`, and only after the page has been saved once;
  * the cache engine and its storage stay LiteSpeed's own settings (WebAdmin > Server > Cache);
  * "Empty cache" is not offered.
* Page cache on LiteSpeed uses the documented "LSCache without a plugin" rewrite rules in the vhost: `[E=cache-control:max-age=N]` for cacheable requests and `[E=Cache-Control:no-cache]` for the excluded ones. For WordPress the LiteSpeed Cache plugin is the better choice.
* Excluded from the page cache:
  * requests other than GET/HEAD;
  * cookies matching `wordpress_logged_in|wp-postpass|comment_author|woocommerce_*|wp_woocommerce_session|PHPSESSID|laravel_session|XSRF-TOKEN|joomla_user_state|SESS`;
  * URIs containing `/wp-admin/`, `/wp-login.php`, `/xmlrpc.php`, `/wp-json/`, `/wp-cron.php`, `/cart`, `/checkout`, `/my-account`, `/admin`, `/login`, `/logout`, `/register`, `ajax.php`, `preview=true`, `/feed`;
  * responses with `Set-Cookie`.

## MySQL queries
* Configuration → MariaDB → Live queries reads `information_schema.PROCESSLIST` as root (helper action `db.processlist`, query text up to 8000 characters) and stops statements with `KILL QUERY <id>` or connections with `KILL CONNECTION <id>` (`db.kill`). Rules are evaluated by the helper at the moment of the click: "long" = `Query` threads running at least N seconds (optionally in one database), "sleep" = idle connections at least N seconds.
* Threads of `system user`, `event_scheduler` and the commands Daemon, Binlog Dump, Slave_* and Connect are never stopped. Each stop is in the audit log (`mysql.kill_query` / `mysql.kill_connection`) with the first 200 characters of each query.

## Panel sessions
* A session ends 24 hours after its last request. Open panel tabs send a heartbeat (`GET /session/ping`) every 5 minutes, so a session does not end while a tab is open. The `havoc_sid` cookie carries the same expiry (renewed at most every 5 minutes), so the browser deletes it 24 hours after the last tab was closed.
* "Remember me for 30 days" (login page, also with 2FA): the session and cookie are valid for 30 days from the login.
* Session files in `/var/lib/havoc/sessions` are removed hourly (`havoc backup:due`) once their session has ended. The old `session.lifetime` value in `/etc/havoc/config.php` is ignored.

## Backup restore notes
* Databases are restored as the database's own user (its password is set to a temporary one for the import and the original hash is put back), with DEFINER clauses removed, so triggers, views and routines are recreated and keep working. The extraction and import run as the hosting account. Before 0.9.1 restoring from the panel failed (wrong helper call) and databases with triggers could not be restored.

## Cloud backup notes
* Google Drive uses the `drive.file` scope: the panel sees only the files it uploaded. Connecting runs `rclone authorize` as the panel user on 127.0.0.1:53682 for at most 15 minutes; the user pastes the address the browser ended on.
* With an encryption password the archive is encrypted by rclone crypt before upload (file names stay readable, content is not). Without the password a backup cannot be restored.

## Redis / Meilisearch / firewall notes
* Add-ons are installed in the background by `bin/havoc-redis-job`, `bin/havoc-meili-install` and `bin/havoc-addon-job` (systemd-run units `havoc-redis-job`, `havoc-meili-install`, `havoc-addon-<name>`); `journalctl -u <unit>` shows the output.
* Fail2ban settings are written to `/etc/fail2ban/jail.d/havoc.conf` (managed file) and the panel filter to `/etc/fail2ban/filter.d/havoc-panel.conf`. Failed panel logins go to the journal with the identifier `havoc-panel` (`journalctl -t havoc-panel`). Add your own address to the whitelist before testing.
* Memcached has no authentication: like Redis without a password, every hosting account can reach it on localhost.
* Redis and Meilisearch are single shared instances bound to localhost; every hosting account can reach localhost, so keep the Redis password and the Meilisearch master key set. Redis has no per-account isolation (no ACL users).
* Per-site search: a site's indexes are named `<domain with . → _>__…` (e.g. `shop_example_com__products`; domains cannot contain `_`, so prefixes never overlap). The panel creates two Meilisearch API keys limited to `<prefix>*`: an admin key (documents, indexes, settings, tasks, stats; cannot create keys or touch other indexes) and a search key. Key values derive from the master key, so a new master key changes every site's keys (the new values are shown on the site page). Backups (with databases) and transfers carry each index's settings and documents (`meili/<index>/settings.json`, `documents.ndjson`), read and written through the Meilisearch API by root; restoring replaces the site's indexes.
* Meilisearch is installed by `bin/havoc-meili-install` (systemd-run job): latest GitHub release, SHA-256 compared with the digest GitHub publishes for the asset (the install aborts without one), then a hardened `meilisearch.service` (own user, `ProtectSystem=strict`). The vendor's apt repository is not used because it is unsigned (`[trusted=yes]`). Updating Meilisearch to a new version may need a dump/import of the indexes.
* Firewall: ufw only (no CSF). `fw.enable` always opens SSH ports (from sshd_config), the panel port, 80 and 443 first; those rules can't be deleted from the panel, and the helper rejects banning the admin's own address.

## Web servers
* Nginx is always installed: the panel runs on its own Nginx instance (`havoc-panel-nginx`), independent of the site server. `/etc/havoc/webserver` records which server serves the sites (`nginx`, `ols` or `lsws`); only `webserver.activate` changes it.
* PHP never changes with the web server: every site keeps its own PHP-FPM master; OpenLiteSpeed and LiteSpeed Enterprise reach it as an external FastCGI app over the site's socket, and run as `www-data` like Nginx.
* OpenLiteSpeed: the panel regenerates `conf/httpd_config.conf` completely (original kept as `.orig`) and writes `conf/vhosts/havoc-<domain>/vhconf.conf`. LiteSpeed Enterprise (XML): `httpd_config.xml` is edited with DOM, only the panel's listeners (`havoc-http`/`havoc-https`, anything else on 80/443 is removed) and `havoc-*` virtual hosts are touched; license, WebAdmin and other settings stay.
* OpenLiteSpeed and LiteSpeed Enterprise share `/usr/local/lsws`; installing one removes the other, and only while Nginx is active.
* With LiteSpeed active, the panel's per-site Nginx rules and the ModSecurity WAF are not used (they stay stored and come back when Nginx is activated again); rewrite rules go into `.htaccess`. Certificates, redirects, Force HTTPS, HSTS, ACME challenges, logs and traffic statistics work with all three.
* Switching (`bin/havoc webserver:switch`, started from the panel) aborts before touching anything if a site cannot be written, and the helper starts the previous server again if the new one is not active and answering on port 80 within ~10 s.

## Panel port, phpMyAdmin, config editors
* Port change: the panel nginx listens on old + new port, ufw opens the new one, and `havoc-port-revert` (systemd timer, 5 min) switches back unless the change is confirmed from a request that arrived on the new port.
* phpMyAdmin: `/phpmyadmin/` on the panel port; nginx `auth_request` against the panel session, then phpMyAdmin cookie login with a database user (root not allowed). Files in `/opt/havoc-pma` (root-owned), runs as `havoc-pma` with its own PHP-FPM (prefers PHP 8.3).
* Sessions: phpMyAdmin's inactivity limit is 24 hours; PHP's session GC is off for phpMyAdmin (`session.gc_probability = 0`; files are cleaned up as described below) and `session.gc_maxlifetime = 1800` only makes phpMyAdmin's keep-alive request run every ~30 minutes. Each phpMyAdmin request passes the panel's session check (`auth_request`), which keeps the panel session alive; `auth_request_set` + `add_header Set-Cookie` pass the panel's renewed session cookie on to the browser.
* One-click login ("phpMyAdmin" button at a database or on the site page): the panel asks the helper for a ticket; root creates a temporary MySQL user `hvpma_…` with rights on that one database only (kept while phpMyAdmin uses it, dropped 24 hours after its last use and at most 30 days after it was made; the last use is read from the mtime of the phpMyAdmin session files that name it) and writes a single-use ticket (60 seconds) to `/var/lib/havoc-pma/tickets/`. `/phpmyadmin/havoc-signon.php` (behind the same panel-session check) redeems it into phpMyAdmin's signon server (server 2, session `HavocPmaSSO`). Unused users, tickets and phpMyAdmin session files older than 24 hours are removed hourly (backup timer) and before each new ticket. Existing phpMyAdmin installs get the signon server on upgrade (`havocctl pma.sso_setup`).
* Per-site Nginx rules live in `/etc/nginx/havoc-custom/<domain>.conf`, included in the site's server block. User rules are parsed and limited to a directive whitelist, because the web server (www-data) belongs to every hosting group and directives like `root`, `alias` or `include` could read other customers' files.
* MariaDB tuning is written to `/etc/mysql/mariadb.conf.d/90-havoc-tuning.cnf`; a failed restart restores the previous file.

## WAF notes
* Global mode is the master switch (written to `/etc/nginx/modsec/main.conf`; `Off` loads no rules). Domains opt in via the `modsecurity on` vhost directive. Start with *Detection only* and review the log before *On*.
* Rules are structured, never free text: CRS rule-ID exclusions (runtime `ctl:ruleRemoveById` per domain, `SecRuleRemoveById` globally) and "block when URI / User-Agent / parameter contains X". Tenants can only create rules scoped to their own domains; global rules are admin-only.
* Log: JSON audit log `/var/log/havoc/waf/audit.log`, filtered per tenant by Host header. Debian packages used: `libnginx-mod-http-modsecurity`, `modsecurity-crs` (CRS paths are auto-detected; Debian 12 ships CRS 3.x, Debian 13 CRS 4.x, so rule IDs/exclusions may differ).
* OpenLiteSpeed/LiteSpeed WAF is not wired yet.

## PHP versions
* Configuration → PHP versions. Helper actions `php.overview` (`dpkg-query php*-fpm`, `apt-cache pkgnames php`, the panel's version from `havoc-panel-fpm.service`, the CLI default from `/usr/bin/php`), `php.install` / `php.remove` → `havoc-os-upgrade php-install|php-remove X.Y` (unit `havoc-os-update`, log `/var/log/havoc/os-update.log`). Supported versions: `Validator::PHP_VERSIONS` (7.4, 8.0–8.6); support dates: `PhpVersionService::SUPPORT`.
* Removal is refused for the panel's version, the CLI default, the last installed version, versions any site uses (also suspended ones; checked in the panel, the helper and the script via `havoc-fpm-*.service`), and when `apt-get -s purge php X.Y-*` would remove any other package.

## Applications
* Websites → Applications (admins). Service `WebAppService` (panel) + root helper `ctl/lib/Apps.php` (actions `app.*`). Tables `web_apps`, `web_app_releases` (migration 016).
* Layout: `/opt/havoc-apps/<name>/releases/<UTC ts>/`, `current -> releases/..` (atomic `ln -sfn`), `shared/<path>` (kept across deploys), `/etc/havoc-apps/<name>.env` (0600 root:havoc-<name>, `PORT`/`ADDR` written automatically). System user `havoc-<name>` (`useradd --system --no-create-home --shell /usr/sbin/nologin`). Unit `havoc-app-<name>.service` runs as that user with `ProtectSystem=strict`, `ReadWritePaths=<base>/shared`, `KillSignal=SIGTERM`, `TimeoutStopSec=20`; enabled so it starts on boot.
* Port 20000–29999 (unique, `127.0.0.1` only, never opened in ufw). The domain's vhost (`NginxDriver`, when `web_apps` has a row for it) becomes `upstream havoc_app_<name> { server 127.0.0.1:<port>; keepalive 32; }` + `location /` and one `location <ws_path>` (with `proxy_buffering off`) carrying the WebSocket upgrade headers; `map $http_upgrade $connection_upgrade` is defined once in `conf.d/havoc-01-ws.conf`.
* Deploy: archive from an upload on the app's domain → safe extract (reject symlinks, absolute and `..` paths; `tar --no-same-owner`) into a staging dir → single-top-folder flattened → `chmod +x` the start binary → shared paths seeded on first deploy then symlinked (never overwritten) → `chown -R havoc-<name>` → `current` swapped → `systemctl restart` → health poll `http://127.0.0.1:<port><health_path>` for 20s → on failure, swap back to the previous release and return the last 100 log lines. Keeps the last 5 releases.
* `app.exec` runs only the app's own binary (from the unit's ExecStart) with array args as the app user (`setpriv ... env -C current`), ≤60s. `app.http` is `curl` to `127.0.0.1:<port>` (≤64 KB). `app.env` returns key names only. `app.destroy` (panel only) stops/disables the unit, removes the base dir, env file and user.

## Live site preview
* `https://<panel host>:<panel port>/preview/<domain>/<path>` → 302 to the preview port (`/etc/havoc/preview-port`, panel port + 1). Files: panel Nginx `servers.d/preview.conf` (server on the preview port, contains the preview secret, 0640) and `conf.d/preview.conf` (the forward), written by helper action `preview.ensure` (install and upgrade; opens the port with ufw when it is active). Sites' Nginx: `/etc/nginx/conf.d/havoc-00-preview.conf` (0640) maps the secret header `X-Havoc-Preview` to `HTTPS=on` / `REQUEST_SCHEME=https` for PHP and to a page-cache bypass; the header itself is not passed to PHP.
* Entry `/preview/<domain>/` (only names with a dot): panel PHP (`HAVOC_PREVIEW=enter`, `PreviewService`) reads the panel session without locking it (`Session::peekUser`), checks `Scope::domain` (aliases open their site), sets the cookie `havoc_pv` and redirects to the path. Other requests: `auth_request` → `HAVOC_PREVIEW=auth` (204 + `X-Pv-Host` / `X-Pv-Scheme`, 401 → panel login, 403 → notice), cached 20 s per `havoc_sid|havoc_pv`.
* GET/HEAD/OPTIONS are proxied to `127.0.0.1` (HTTPS with SNI when the site has a certificate or the web server is LiteSpeed, else HTTP + secret header) with `Host: <domain>`, the panel cookies removed from `Cookie`, Origin/Referer set to the domain, `sub_filter` rewriting `http(s)://(www.)<domain>`, `https:\/\/`, `https%3A%2F%2F`, `//<domain>` to the preview address and `proxy_redirect` making redirects relative. Other methods go to `@havoc_pv_relay` (`HAVOC_PREVIEW=relay`): the body (raw up to 20 MB, larger streamed unchanged; multipart rebuilt from `$_POST`/`$_FILES`) and headers get the preview address turned back into `https://<domain>`, then curl to the site; Location made relative, cookie Domain removed.
* Never set `PHP_VALUE` in the panel's FastCGI locations: PHP-FPM keeps such values for later requests of the same worker.

## System updates
* Monitoring → System updates. Helper actions `os.check` (`apt-get update` + `apt list --upgradable`, saved to `/var/lib/havoc/os-updates.json`), `os.status` (that file, job state from `/var/log/havoc/os-update.status`, log, `/var/run/reboot-required`) and `os.upgrade` (`systemd-run --unit=havoc-os-update /opt/havoc/bin/havoc-os-upgrade security|all`).
* Security = a `*-security` suite; PHP = `php8.x-*`, `php-*`, `libapache2-mod-php*` (sury.org has no separate security suite). Mode `security` runs `apt-get install --only-upgrade` for exactly those packages, mode `all` runs `apt-get upgrade`; both with `--force-confdef --force-confold` and a 300 s dpkg lock wait. After PHP updates `havoc-fpm-*`, `php*-fpm` and `havoc-panel-fpm` are restarted.
* Software list (`OsUpdates::software`, saved with the check): apt components (Nginx, OpenLiteSpeed, MariaDB, Redis, Memcached, Fail2ban, Node.js and one row per `/usr/sbin/php-fpm8.x`) with the upgradable packages that belong to them; LiteSpeed Enterprise (`/usr/local/lsws/VERSION`, latest from update.litespeedtech.com), Meilisearch (`meilisearch --version`, latest GitHub release), phpMyAdmin (`/opt/havoc-pma/RELEASE-DATE-*`, phpmyadmin.net `version.txt`). `os.upgrade` with `mode=component` runs `havoc-os-upgrade packages <names>` (names taken from the saved list, never from the browser) or `lsws` / `meilisearch` / `phpmyadmin`.
* Meilisearch update: needs free disk space for a copy of `/var/lib/meilisearch/data`; copies it to `data.pre-update` and the binary to `meilisearch.pre-update`, adds the drop-in `meilisearch.service.d/havoc-upgrade.conf` (`MEILI_UPGRADE_DB=true`, and the pre-1.51 name `MEILI_EXPERIMENTAL_DUMPLESS_UPGRADE=true`), runs `havoc-meili-install`, waits up to 10 minutes for `/health` and the `upgradeDatabase` task (gives up after 3 automatic restarts); on failure restores both. Manual restore: stop `meilisearch`, remove the drop-in, copy `meilisearch.pre-update` over the binary, replace `data` with `data.pre-update`, `daemon-reload`, start. Versions before 1.12 cannot be upgraded in place (dump + import).
* The periodic job (`usage:scan`) checks every 12 hours (`SystemUpdateService::checkDue`) and notifies once per new set of security / PHP packages (setting `os.updates_notified`).
* Hardening: each site's scan folder gets `98-havoc-security.ini` (`session.upload_progress.enabled = 0`, written by `domain.apply`, so `domains:reapply` during the upgrade covers existing sites); the panel's pool gets `php_admin_flag[session.upload_progress.enabled] = off`. A site's `.user.ini` may switch it on again (PHP_INI_PERDIR).

## Changing a domain name
* Domain → Change domain name (admins, main/addon domains; subdomains `x.<old>` follow). Service `RenameService`, helper actions `domain.rename` (moves `/home/<acct>/domains/<old>` as the account with `mv -n -T`, refuses a symlinked or existing target; renames `/var/log/havoc/domains/<old>.{access,error,php-error}.log*`; removes the old name's configuration from every installed web server; moves `/etc/havoc/ssl/<old>` only when `openssl x509 -checkhost <new>` matches) and `domain.rename_addresses`.
* Order: database backup (only when the WordPress option is on and the account has databases; on failure nothing changes) → move → panel records (`name`, `docroot`, SSL fields reset unless the certificate was kept) → `domain.apply` for the site and its subdomains. A failure up to here restores everything. Then, reported as warnings when they fail: FTP re-mounts (`ftp.update`), cron, WordPress admin link, DNS zone (`dns.delete` old, `dns.apply` new; record names/contents naming the old domain follow), WAF, addresses, Let's Encrypt queue.
* Address replacement: one case-insensitive regex `(?<=//|\/\/|%2F%2F|/home/<acct>/domains/)((?:www.|<sub>.)?)<old>(?![a-z0-9-]|\.[a-z0-9])` → `${1}<new>` in the configuration files (`Transfer::CONFIG_FILES`, as the account) and, with WP-CLI `search-replace --regex --all-tables-with-prefix --skip-columns=guid --skip-plugins --skip-themes`, in WordPress' database. `old.com.tr` never matches `old.com`; e-mail addresses are not touched.
* Kept old names are alias rows with `alias_redirect = 1` (migration 015). `domain.apply` gets them as `redirect_hosts` (they stay in `aliases` for LiteSpeed listener mapping and for the certificate): Nginx renders a separate server block returning `301 http(s)://<new>$request_uri`, LiteSpeed a `RewriteCond %{HTTP_HOST}` rule. Deleting the alias row removes the redirect.
* Backups taken before the rename restore into `domains/<old>` (the paths inside the archive); restore files after renaming back, or restore only the databases.

## PHP workers and resource limits
* Each site runs its own PHP-FPM master as the systemd service `havoc-fpm-<id>` (pool `/etc/havoc/fpm/<id>.conf`). Its unit carries `MemoryMax` and `CPUQuota`; when the memory limit is reached the kernel stops a process of that site only.
* Per-site values are stored in `domains.php_pool` (JSON). `ram_mb` / `cpu_percent`: missing = package value (no package: 1024 MB / 100 %), `0` = unlimited (`MemoryMax=infinity`, no `CPUQuota`). `pm` defaults to `ondemand`; `max_children` missing = RAM limit ÷ 64 MB within 2–50 (unlimited: server RAM ÷ 64, max 100). For `dynamic`, missing start / spare values are derived from max_children (min = max/8, max = max/4, start between).
* Only admins can change them (domain → PHP → "PHP workers and limits", or Monitoring → Resource usage). Saving rewrites the pool and unit and restarts only that site's PHP.
* Monitoring → Resource usage (helper action `server.resources`) reads `/proc/meminfo` and, for every service under `/sys/fs/cgroup/system.slice`, `memory.stat` (memory = anon + kernel + shmem; cache = file − shmem), `memory.max`, `memory.swap.current`, `pids.current` and `cpu.stat` (two samples 1 s apart). "Used by programs" is MemTotal − MemAvailable, the same number the dashboard shows. Without cgroup v2 it falls back to `ps` grouped by program name.
* Big memory users are usually MariaDB (`innodb_buffer_pool_size`, Databases → MariaDB settings) and Meilisearch (its index is memory-mapped; most of it shows as cache and is reclaimable).

## Limits / traffic notes
* `havoc-usage.timer` (every 30 min) runs `havoc usage:scan`: `du` per account (bytes + inodes, symlinks never followed), database sizes, then an incremental parse of `/var/log/havoc/domains/*.access.log` (byte offset per domain, rotation-aware). Enforcement is off by default (Limits page, admin).
* Disk quota = files + databases of the account; backups are not counted. Bandwidth = response body bytes in the access logs for the current UTC month (no FTP/mail traffic, no headers). Domains with logs turned off collect no statistics.
* This is accounting, not a hard filesystem quota: an account can exceed its disk limit between scans. Hard limits would need `usrquota` on the filesystem.
* Over-quota accounts: sites return 503 (Nginx `return 503`, OLS rewrite) and are restored automatically once usage drops below 100 %.

## Dev
`npm install && npm run build` rebuilds `public/assets/app.css`.
CLI: `runuser -u havoc -- php /opt/havoc/bin/havoc admin:reset admin`.
