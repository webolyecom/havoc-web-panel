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
| REST API + OpenAPI | ✅ domains, me, stats |
| Resellers, customers, packages (limits pushed to FPM cgroups), white-label (name, colour, logo, panel domain) | ✅ implemented |
| File manager: CodeMirror editor (syntax highlighting, Ctrl+S), drag-and-drop multi upload in chunks (up to 2 GB), streaming download of any size, search, sort, copy/move, rename, bulk and recursive permissions, zip and tar.gz create/extract (unsafe archives refused), image preview; isolated to the docroot, runs as the tenant's Linux user | ✅ implemented |
| MariaDB: create/drop DB + user, password reset, remote access per DB, global bind toggle; one-click phpMyAdmin (latest release, SHA-256 checked, behind the panel login, own system user and PHP-FPM); server-wide performance tuning with RAM-based profiles and automatic rollback | ✅ implemented |
| Web server choice: Nginx, OpenLiteSpeed or LiteSpeed Enterprise for the sites; one-click install (LiteSpeed repo / official Enterprise tarball with trial or serial license) and activation; all sites re-written for the target, automatic return to the previous server if the new one does not answer on port 80 | 🟡 implemented; LiteSpeed paths not yet run on a real server |
| Per-site configuration: php.ini directives (whitelist for users, extra lines for admins, php-fpm -t before restart), Nginx include (directive whitelist for users, free for admins, nginx -t before reload, can replace "location /") | ✅ implemented (Nginx) |
| Self-update | 🟡 script ready; needs `HAVOC_UPDATE_URL` + published `.sha256` |
| Cron jobs: presets (1/2/5/10/15/30 min, hourly, every N hours, daily, weekly, monthly), schedule builder, range-checked parser, plain-language description and next run times, names, output logging (~/logs/cron, 1 MB per job), run now (as the tenant, 40 s), view log; subdomain / alias (parked) / addon domains, redirects | ✅ implemented |
| Backups: files + databases (tar.gz), manual and scheduled (daily/weekly, retention), local + S3/FTP/SFTP via rclone (credentials encrypted with libsodium), one-click restore (files / databases / both) | ✅ implemented |
| FTP (Pure-FTPd virtual users, TLS) and SFTP (OpenSSH chroot) accounts: per-domain/sub-folder jail, FTP quota, IP allow-list, password reset | ✅ implemented |
| DNS (BIND9): zones per main/addon domain, A/AAAA/CNAME/MX/TXT/SRV/CAA, strict validation + `named-checkzone` before every reload, default records, admin nameserver settings | ✅ implemented |
| Redis (activate/deactivate, maxmemory, eviction policy, AOF, password) and Meilisearch (one-click install from the official release binary with SHA-256 check / uninstall, production mode with master key on 127.0.0.1:7700, indexing memory, show/rotate master key) | ✅ implemented (shared, server-wide) |
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

## Redis / Meilisearch / firewall notes
* Redis and Meilisearch are single shared instances bound to localhost; every hosting account can reach localhost, so keep the Redis password and the Meilisearch master key set. Per-account isolation (Redis ACL users, per-tenant indexes) is not implemented.
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
* Per-site Nginx rules live in `/etc/nginx/havoc-custom/<domain>.conf`, included in the site's server block. User rules are parsed and limited to a directive whitelist, because the web server (www-data) belongs to every hosting group and directives like `root`, `alias` or `include` could read other customers' files.
* MariaDB tuning is written to `/etc/mysql/mariadb.conf.d/90-havoc-tuning.cnf`; a failed restart restores the previous file.

## WAF notes
* Global mode is the master switch (written to `/etc/nginx/modsec/main.conf`; `Off` loads no rules). Domains opt in via the `modsecurity on` vhost directive. Start with *Detection only* and review the log before *On*.
* Rules are structured, never free text: CRS rule-ID exclusions (runtime `ctl:ruleRemoveById` per domain, `SecRuleRemoveById` globally) and "block when URI / User-Agent / parameter contains X". Tenants can only create rules scoped to their own domains; global rules are admin-only.
* Log: JSON audit log `/var/log/havoc/waf/audit.log`, filtered per tenant by Host header. Debian packages used: `libnginx-mod-http-modsecurity`, `modsecurity-crs` (CRS paths are auto-detected; Debian 12 ships CRS 3.x, Debian 13 CRS 4.x, so rule IDs/exclusions may differ).
* OpenLiteSpeed/LiteSpeed WAF is not wired yet.

## Limits / traffic notes
* `havoc-usage.timer` (every 30 min) runs `havoc usage:scan`: `du` per account (bytes + inodes, symlinks never followed), database sizes, then an incremental parse of `/var/log/havoc/domains/*.access.log` (byte offset per domain, rotation-aware). Enforcement is off by default (Limits page, admin).
* Disk quota = files + databases of the account; backups are not counted. Bandwidth = response body bytes in the access logs for the current UTC month (no FTP/mail traffic, no headers). Domains with logs turned off collect no statistics.
* This is accounting, not a hard filesystem quota: an account can exceed its disk limit between scans. Hard limits would need `usrquota` on the filesystem.
* Over-quota accounts: sites return 503 (Nginx `return 503`, OLS rewrite) and are restored automatically once usage drops below 100 %.

## Dev
`npm install && npm run build` rebuilds `public/assets/app.css`.
CLI: `runuser -u havoc -- php /opt/havoc/bin/havoc admin:reset admin`.
