# Changelog

All notable changes to Havoc WEB Panel. The project uses [semantic versioning](https://semver.org/); while in 0.x, minor versions may change behaviour.

## [0.12.3] – 2026-11-28
### Added
- **`app_proxy_log`** (connector): the application domain's nginx vhost access or error log — the HTTP layer in front of the app, separate from `app_logs` (the app's own journald output). The path is read from `conf.d/havoc-<domain>.conf` and constrained to `/var/log`.
- **`app_http` `via_proxy`**: send the request through nginx (`https://<domain><path>`, pinned to this server with `--resolve <domain>:443:127.0.0.1`, correct SNI and Host) instead of straight to `127.0.0.1:<port>`, so the reverse-proxy layer can be tested separately from the app.
- **`app_get`** now returns the vhost file content (`proxy.content`), with any credential-looking values in custom rules masked.

## [0.12.2] – 2026-11-27
### Fixed
- **An application's domain kept serving the static site instead of reverse-proxying to the app.** The vhost builder on the root-helper side dropped the proxy spec the panel passed, so `nginx` was always rendered with the plain site template (static files / PHP-FPM) even though the app was running and healthy on `127.0.0.1`. The proxy spec is now carried through and validated, so the domain renders the reverse-proxy variant: `location /` and each `ws_path` proxy to the app with the WebSocket upgrade headers, `X-Real-IP`/`X-Forwarded-For`/`X-Forwarded-Proto`, and 3600s read/send timeouts, while the ACME challenge and the SSL/HTTPS redirect are preserved. Because the proxy spec travels with every `domain.apply`, an SSL renewal or PHP-version change can no longer overwrite it.
- **The vhost is re-applied after every deploy** (not only at create), and the config is `nginx -t`-tested and reloaded; on a test failure the previous vhost is restored and the error is returned.
- **`app_status` mis-reported the runtime** (`active_state` showed the PID, `pid`/`uptime_sec` were null, health looked down though it was up): `systemctl show` output is now parsed as `KEY=VALUE` instead of relying on positional `--value` order, which systemd does not keep in the requested order.
### Added
- `app_get`/`app_status` now include `proxy`: the vhost file, whether it is currently the reverse-proxy variant, and when it was last written.

## [0.12.1] – 2026-11-27
### Fixed
- **`app_deploy` rejected the path `list_uploads` returns** (`Invalid deploy source`): the deploy helper was never told the application's web root, so every upload looked invalid. The upload path is now resolved against the app domain's `public_html`, normalised with `realpath`, and accepted when it stays inside that root (so the hidden `.havoc-incoming` upload folder is allowed; traversal is caught by the realpath-in-root check, not by a hidden-folder ban). Supported extensions are `.zip`, `.tar.gz`, `.tgz`, `.tar`; errors say exactly what is wrong (not found, outside the root, unsupported type, archive could not be opened). After a successful deploy the archive is removed from the web root.

## [0.12.0] – 2026-11-27
### Added
- **Applications** (Websites → Applications, admins): run a long-lived process bound to a site's domain and reverse-proxied by nginx — for example a Go game server using WebSocket.
  - Each app gets its own system user (`havoc-<name>`), a hardened systemd service (`NoNewPrivileges`, `ProtectSystem=strict`, `ProtectHome`, `PrivateTmp`, `ReadWritePaths` limited to its shared folder), and a private port on `127.0.0.1` (never opened in the firewall).
  - The site's vhost becomes a reverse proxy with WebSocket upgrade on each configured `ws_path` (`proxy_buffering off`, 1-hour read timeout); the Let's Encrypt challenge and the SSL/HTTPS redirect are preserved. The config is tested with `nginx -t` and rolled back on failure.
  - **Versioned deploys**: upload a `.zip`/`.tar.gz`, it is safely extracted (no symlinks or path escapes) into `releases/<ts>`, shared folders are seeded on the first deploy and never overwritten after, `current` is swapped atomically, the service restarts, and a 20-second health check decides success — a failed deploy **rolls back automatically** to the previous release. The last 5 releases are kept.
  - Panel UI: status, start/stop/restart, environment editor (values stored server-side at `0600`, only key names shown), live logs, release history with rollback, and removal (panel-only).
  - **15 connector tools** so a remote assistant can deploy and test without SSH: `app_list/get/status/logs/create/deploy/rollback/start/stop/restart/set_env/http/exec/read_file/write_file`. `app_http` calls the app on `127.0.0.1` (test endpoints, `/admin/reload`); `app_exec` runs the app's own binary with array arguments (no shell). Secrets are never returned; every operation is audit-logged. Deleting an application is panel-only.
### Security
- Application operations are administrator-only, in the panel and through the connector. All commands run through argv (never a shell); file and process actions run as the per-app user, never root.

## [0.11.1] – unreleased
### Fixed
- **"A restart of the server is needed" stayed on the dashboard** after the server was restarted: the warning used the saved result of the last check (refreshed every 12 hours). Whether a restart is needed is now read live (`/run/reboot-required`, emptied by a restart).
- The saved list of updates is treated as out of date when packages changed (also installed outside the panel) or the server restarted after the check: the dashboard does not warn from it, the next periodic job checks again, and opening Monitoring → System updates checks right away.
### Added
- The dashboard warning about system updates can be closed (×); it comes back when new updates appear or another restart is needed.
- **PHP versions** (Configuration → PHP versions, admins): installed versions with their support state and dates (actively supported / security fixes only / ends within 90 days / ended) and the sites using each one.
  - **Install** a version the package sources offer (7.4 and 8.0–8.6) with the usual extensions (MySQL, cURL, GD, mbstring, XML, ZIP, intl, BCMath, OPcache, Redis; packages a version does not have are skipped); its stock FPM pool stays off.
  - **Remove** a version no site uses. Never the one the panel itself runs on (8.2 on Debian 12, 8.4 on Debian 13), the command-line default (`php`, used by cron jobs and WP-CLI) or the last one. Before purging, apt's plan is checked: if anything other than that version's own packages would be removed, it stops and names them.
  - Both run as the update job with a live log.
  - Sites on a version whose security support ended or ends within 90 days: a notice on the site's PHP page, the support state next to each version in its selector, and a dashboard notice that can be closed until the list changes.
### Changed
- Saving a site's PHP version (also through the Claude connector) only accepts versions installed on this server.

## [0.11.0] – 2026-11-22
### Added
- **Live site preview**: `https://<panel>:<panel port>/preview/<domain>/<path>` opens a site as it runs on this server, also while its domain still points to another server (moving a site): pages, logging in to its admin and working in it. "Preview" buttons on the domain page and in the sites list.
  - Runs on its own port (panel port + 1, default 2088), never on the panel's origin, so a site's JavaScript cannot read panel pages; the panel port forwards `/preview/…` there. The port is opened in the firewall when it is on (also in a cloud provider's firewall if one is used).
  - Only logged-in panel users who manage the site; the panel login is checked on every request (answer cached for 20 s) and the panel's cookies are never passed to the site.
  - The site runs with its own paths (the chosen site is kept in a cookie). Its address in pages, CSS, JS, JSON (also `https:\/\/` and URL-encoded) and redirects becomes the preview address; Origin / Referer reach the site as its own address.
  - Requests that send data (forms, editors, AJAX/REST) go through a relay that turns the preview address back into `https://<domain>`, so saving a setting or a post never stores the preview address in the site.
  - HTTPS: sites with a certificate are reached over HTTPS; a site without one (DNS not pointed yet) gets a secret header that makes its Nginx report HTTPS to PHP, so WordPress and similar do not redirect in a loop (OpenLiteSpeed / LiteSpeed answer every site on 443 anyway).
  - Limits: files uploaded through the preview are limited by the panel's PHP (8 MB per file); addresses saved through it are stored as `https://<domain>` (an `http://` or `www.` address the page showed is saved in that form).

## [0.10.3] – 2026-11-22
### Fixed
- **Meilisearch update** (Monitoring → System updates): the in-place database upgrade option is named `MEILI_UPGRADE_DB` since Meilisearch 1.51 (`MEILI_EXPERIMENTAL_DUMPLESS_UPGRADE` before); only the old name was set, so the new version refused the existing data and the update waited 10 minutes before putting the previous version back. Both names are set now.
- The update no longer waits silently: it gives up as soon as the new version keeps stopping (3 restarts), shows Meilisearch's last log lines (without the master key), puts the previous version and data back and says whether it answers again; while waiting it prints its progress every 15 seconds.

## [0.10.2] – 2026-11-22
### Added
- **Software updates** on Monitoring → System updates: a *Software* table with installed and available version of Nginx, OpenLiteSpeed, LiteSpeed Enterprise, MariaDB, every installed PHP version, Redis, Memcached, Fail2ban, Node.js, Meilisearch and phpMyAdmin, each with its own *Update* button.
  - Debian packages: only that software's packages are updated (e.g. `nginx` + `nginx-common`, all `php8.x-*`); OpenLiteSpeed is restarted gracefully afterwards, PHP-FPM of every site after PHP updates.
  - LiteSpeed Enterprise: its own `lsup.sh` installs the latest release, then a graceful restart.
  - Meilisearch: stopped, its data and binary copied, the latest GitHub release installed and started with `MEILI_EXPERIMENTAL_DUMPLESS_UPGRADE` (database upgraded in place, 1.12 and later); if it does not come up or the upgrade task fails, the previous version and data are put back. The copy of the data stays until the next update; the update refuses to start without enough free disk space.
  - phpMyAdmin: the latest release from phpmyadmin.net replaces the installed one in place (configuration kept).
  - When the latest version of Meilisearch, phpMyAdmin or LiteSpeed Enterprise cannot be looked up, the table says so instead of "up to date", and the update can still be started (it looks the version up again).

## [0.10.1] – 2026-11-22
### Added
- **System updates** (Monitoring → System updates, admins): the server's upgradable packages, marked *Security* (Debian security suites) or *PHP* (sury.org PHP releases carry PHP's security fixes, so every PHP update counts as important).
  - Checked twice a day (periodic job) and on demand; the dashboard warns while security / PHP updates wait or a restart of the server is needed.
  - Administrators get one notification (e-mail / Telegram, in the notification language) for each new set of security / PHP updates.
  - One click installs only the security and PHP updates, or all updates (`apt-get upgrade`: never removes packages, keeps changed configuration files). Runs as its own unit (`havoc-os-update`, script `bin/havoc-os-upgrade`) with a live log; after PHP updates every PHP-FPM is restarted (each site's own master, stock pools, the panel), then the list is refreshed.
### Security
- **PHP upload progress switched off** for every site (`98-havoc-security.ini` in each site's PHP scan folder) and for the panel's own PHP: it stores visitor-shaped data in session files and is used by CVE-2026-126714 ("phpAfterShell") and CVE-2026-115560. A site that needs it can switch it on in its `.user.ini`. This reduces the risk; installing the fixed PHP versions (8.2.36, 8.3.37, 8.4.28, 8.5.13 or sury's 8.1.34-14) is still needed.

## [0.10.0] – 2026-11-22
### Changed
- **Sidebar regrouped.** Site work on top, server administration (admins only) below a "Server administration" divider:
  - *Websites*: Domains, File manager, FTP / SFTP, DNS, Cron jobs
  - *Databases & backups*: Databases, phpMyAdmin, Backups
  - *Statistics & logs*: Traffic, Logs, Usage & limits (was "Resource limits")
  - *Security*: WAF, Firewall, Fail2ban
  - *Accounts*: Users, Packages, Branding
  - *Monitoring*: Server monitor, Resource usage, Processes, Services, Notifications
  - *Configuration*: Web server, MariaDB
  - *Add-ons*: All add-ons, plus an entry for each installed one (Redis, Meilisearch, Memcached, Node.js)
  - *Panel*: Site transfer, Panel settings
- **Pages sharing one menu entry get tabs:** Web server = *Server* + *Performance & cache* (was "Web server settings"); MariaDB = *Settings* + *Live queries* (was "MariaDB settings" and "MySQL queries"). Addresses are unchanged, and the menu search still finds the old names.
- Menu entries of add-ons that are not installed are hidden; they are installed from Add-ons → All.
- The most specific matching menu entry is highlighted across all groups (e.g. only "Web server" on its settings tab, not "Server monitor" too).

## [0.9.9] – 2026-11-22
### Added
- **Change domain name** (domain → Change domain name, admins; main and addon domains): the site keeps its id, files, databases, FTP accounts, PHP settings, cron jobs and backups, and moves to the new name.
  - Step 1 shows what will change and whether the new name (also www. and subdomains) already points to this server; step 2 applies it.
  - Site folder `domains/<old>` → `domains/<new>`, log files (also rotated ones), web server (Nginx / OpenLiteSpeed / LiteSpeed Enterprise) and PHP-FPM configuration; subdomains `x.old` → `x.new` the same way.
  - FTP/SFTP accounts are mounted on the new folder, cron commands using the old folder (`/home/…/domains/old`, `~/domains/old`) or address are updated, the DNS zone is recreated under the new name, WAF rules follow.
  - SSL: an own certificate stays when it also covers the new name; otherwise a Let's Encrypt certificate is requested at once (retried until the new name points here).
  - Optional: the old names (also www. and the old subdomains) stay as parked names that answer with a **301 to the same page on the new name** (Nginx server block / LiteSpeed rewrite; Let's Encrypt checks still answered). Shown as "301 → new" under Aliases.
  - Optional: the old address is replaced in the **WordPress database** with WP-CLI (serialized and JSON-escaped values, www. and subdomains included; e-mail addresses and GUIDs stay), after a backup of the account's databases. The old folder path and address are always replaced in the site's configuration files (wp-config.php, .env, .htaccess, .user.ini …).
  - If the new configuration cannot be applied, folders, logs, records and configuration are put back. Renaming back to the old name takes its 301 names back.

## [0.9.8] – 2026-11-20
### Added
- **Resource usage** (Server → Resource usage, admins): where the server's memory and CPU go.
  - Memory bar: used by programs, file cache (given back automatically when a program needs memory), completely free, swap. A note explains why "free" is small on a healthy server; a hint appears when there is no swap.
  - **Services**: every systemd service with its real memory (anon + kernel + shmem from cgroup v2), its file cache, CPU % over 1 second and process count, with a link to its settings page (MariaDB tuning, Meilisearch, Redis, web server).
  - **Sites**: memory and CPU of each site's PHP against its limits, running workers against max workers; RAM limit, CPU limit, max workers and pm can be changed on the row and are applied at once (only that site's PHP restarts). A warning appears when a site uses 85 % of its memory limit.
  - Without cgroup v2 the biggest programs are listed instead.
- **PHP workers and limits** on the domain's PHP page (admins change, others see): RAM and CPU limit (empty = package value, own value, or unlimited), process manager (ondemand / dynamic / static), max workers (pm.max_children), idle time, requests per worker, and for dynamic the start / min / max idle workers. Values are checked before saving; a warning shows when max workers × memory_limit is more than the RAM limit.
### Changed
- Sites without a package keep their defaults (1024 MB RAM, 100 % CPU, ondemand, max workers = RAM ÷ 64 MB within 2–50); nothing changes for existing sites until a value is saved.

## [0.9.7] – 2026-11-20
### Added
- **Web server settings** (Server → Web server settings): performance, compression, browser cache and page cache of the active web server. Nginx, OpenLiteSpeed and LiteSpeed Enterprise each have their own values; only the active server's are shown. Every field shows its allowed range and default.
  - **Nginx:**
    - performance: worker processes, connections per worker (open-files limit raised to match), keep-alive, client and PHP response timeouts, max upload size, open file cache;
    - compression: gzip and its level;
    - browser cache for static files (days);
    - **page cache (FastCGI cache)** per site with its own time: key memory, disk size and cleanup of unused entries, "Empty cache" button, `X-Havoc-Cache` header (HIT / MISS / BYPASS / STALE).
    - Saving: tested with `nginx -t` first; on failure nothing changes. The values replace the same directives in nginx.conf instead of duplicating them. When the cache size changes Nginx is restarted (a reload would keep the old cache zone).
  - **OpenLiteSpeed:** connections, HTTPS connections, timeouts, keep-alive, request size, PHP connections per site, HTTP/3, gzip (also for PHP output), browser cache, the **LSCache module** (also needed by the LiteSpeed Cache plugins) and page cache per site.
  - **LiteSpeed Enterprise:** the same tuning values in httpd_config.xml (only these elements; LiteSpeed's other settings stay) and page cache rules per site. The cache engine itself stays a WebAdmin setting. Nothing is changed until the page is saved once.
  - **Page cache rules:** only GET/HEAD; never for visitors with login or session cookies (WordPress, WooCommerce, PHP sessions, Laravel…), admin, login, cart, checkout and account pages, or answers that set a cookie.
- After saving, every site's web server configuration is rewritten; PHP keeps running.
### Fixed
- Since 0.9.4 the panel logged `session_set_cookie_params(): ... unrecognized key "expires"` on every request, and the session cookie PHP sends before login lacked HttpOnly / SameSite / Secure (after login the panel re-sent it with the flags).
- A button inside a form now asks its own confirmation question (e.g. "Empty cache" inside the settings form).

## [0.9.6] – 2026-11-19
### Added
- **MySQL queries** (Databases & backups → MySQL queries, admins): live list of MariaDB connections and the statements they run, refreshed every 3 seconds.
  - Each row shows the user, the site and panel user it belongs to, database, time, state and the full query.
  - Summary: connections / max_connections, running queries, longest query, slow queries.
  - Long queries are marked (10+ s yellow, 60+ s red).
  - **Stop** a query (`KILL QUERY`, the connection stays) or **close** a connection (`KILL`): one, selected, or by rule ("queries running longer than N seconds", optionally in one database; "connections idle for more than N seconds").
  - MariaDB's own system threads cannot be stopped. Every stop is written to the audit log with the queries.

## [0.9.5] – 2026-11-18
### Added
- Upload link page: a **Copy** button next to the "Uploaded to …" message, so it can be pasted to Claude in one step (shown only after a successful upload).
### Fixed
- PHP error logs of the sites filled with `Zend OPcache can't be temporary enabled (it may be only disabled till the end of request)` for every new PHP worker. The OPcache settings of a site (on/off, memory) were written as `php_admin_value` into its PHP-FPM pool; FPM applies those again in every worker at runtime, where OPcache may only be switched off. They are now in a startup file in the site's own PHP configuration folder (`/etc/havoc/php/<id>/conf.d/99-havoc-opcache.ini`), which has the same effect without the warning. Existing sites are rewritten by the update.

## [0.9.4] – 2026-11-17
### Changed
- **Panel sessions no longer end while a panel tab is open.** An open tab sends a small heartbeat every 5 minutes. A session now ends 24 hours after the last request (it was 1 hour), and the browser cookie has the same expiry, so it is deleted 24 hours after the last tab was closed.
- **"Remember me for 30 days"** on the login page (also with two-factor login): the session and its cookie stay valid for 30 days from the login.
- **phpMyAdmin no longer logs out while its tab is open.**
  - Its keep-alive request now runs about every 30 minutes; it also keeps the panel session alive, and the panel's renewed cookie is passed on to the browser.
  - The one-click login's temporary MySQL user was dropped 2 hours after it was made, even in the middle of work. It now stays while phpMyAdmin uses it and is dropped 24 hours after the last use (at most 30 days).
  - phpMyAdmin's inactivity limit for manual logins is 24 hours (it was 1 hour).
  - Session files older than 24 hours are removed hourly.
- Existing servers get the phpMyAdmin settings with the update; the old `session.lifetime` setting in /etc/havoc/config.php is no longer used.

## [0.9.3] – 2026-11-17
### Fixed
- **Uploads failed with "Unexpected token '<' … is not valid JSON" / HTTP 500** (upload link page, file manager, Claude connector), or site uploads larger than about 16 KB failed with 500, depending on which web server had started last. The panel's own Nginx and the sites' Nginx shared /var/lib/nginx for request bodies, and each takes that folder over on every start or reload (a new domain or certificate reloads the sites' Nginx). The panel's Nginx now has its own folders in /var/lib/havoc-panel-nginx; the upgrade adds them and reloads the sites' Nginx once.
- The upload link page shows "HTTP <code>" when a server in front returns an error page, instead of a JSON parse error.
- Backups → remote storage: opening one "add" card no longer stretches the cards next to it.
- REST API: `POST /api/v1/domains` returned `"owner": null`; it now returns the owner's user name.
### Documentation
- README: REST API section (tokens, examples, endpoints, errors), new screenshots (site transfer, cloud backups, databases, per-site search).

## [0.9.2] – 2026-11-16
### Changed
- **New site page**: clicking a domain opens an overview with a summary (PHP, SSL, applications, databases, document root) and icon tiles in groups (Site, PHP, Security, Domains, Data), like cPanel. Each tile opens its own page: Applications (WordPress), PHP version & extensions, PHP settings (php.ini), SSL / TLS, Security & logging, Nginx rules, Aliases, Redirects, Databases, Search and Delete site. Every page has a switcher to the other pages, and saving a form returns to the same page. The overview no longer calls the helper, so it opens faster.
- Deleting a site has its own page that lists everything that is removed.

## [0.9.1] – 2026-11-16
### Added
- **Per-site search (Meilisearch)**: on a site's page, "Enable search" gives the site its own index prefix (`shop_example_com__…`) and two API keys that only work for those indexes: an admin key for the site's server code and a search-only key for the browser. Index list with document counts, new keys, disable (optionally deleting the indexes). The indexes (settings and documents) are included in the account's backups (with databases), restored with them, and copied by site transfers (the site gets new keys on the new server). New alert rule: a site's search data above N MB.
- **Databases belong to sites**: assign a database to a site when creating it or later (Databases page: site column, filter by site, "Assign automatically" reads wp-config.php, .env and other config files). The site page shows its databases with size and tables, creates new ones for the site and attaches existing ones. WordPress installs assign their database. Transfers preselect the site's databases. The Claude connector shows the site of each database and can create one for a site.
- **One-click phpMyAdmin**: a "phpMyAdmin" button at every database (and on the site page) opens it without a password, using a temporary login that can only see that database (single-use ticket, user removed after 2 hours).
### Fixed
- Restoring a backup from the panel failed (wrong helper call for files and databases).
- Databases with triggers, views or routines could not be restored ("SUPER privilege needed"): they are now imported as the database's own user with DEFINER clauses removed.
### Changed
- The Meilisearch "new master key" warning mentions that the sites' search keys change too.

## [0.9.0] – 2026-11-16
### Added
- **Site transfer** (Server → Site transfer): copy sites from another server running Havoc WEB Panel. The old server creates a **transfer key** (one string with its address, a secret and its certificate's public-key pin; valid 1 h – 7 days, all or chosen sites, optionally one IP address, revocable). The new server pastes it, sees the sites with sizes, databases ("used by this site" detected from config files) and checks (domain already here, missing PHP version, disk space, subdomain parent), chooses the owner (same user name, created with the same panel password, or an existing user) and starts. Per site: export on the old server, resumable chunked download with SHA-256 verification, then the site with its PHP and site settings, files, databases (renamed to this account's prefix, users keep their password hashes, triggers and views keep working), config files and WordPress paths updated, aliases, redirects, cron jobs and the SSL certificate (replaced by Let's Encrypt automatically once DNS points here). A failed site is rolled back and can be retried. Nothing is changed or deleted on the old server.
- **Backups to Google Drive, OneDrive and Dropbox**: connect with your account (rclone's own authorization; paste the address the browser ends on), Google Drive limited to the files the panel creates. Refreshed tokens are saved. "Free space" per target.
- **Optional encryption** for every remote backup target (rclone crypt, password chosen by you).
### Changed
- Database migrations can rebuild tables safely (foreign keys paused and checked).

## [0.8.0] – 2026-11-16
### Added
- **Add-ons** menu: Redis, Meilisearch, Memcached, Fail2ban and Node.js in one place, with an overview (installed / running) and a page per add-on. Everything installs and uninstalls in the background with progress on the page.
- **Memcached**: Debian packages + PHP extension for every PHP version, localhost only with UDP off, cache size and connection settings (with rollback), statistics (memory, items, hit rate, clients), empty cache.
- **Fail2ban**: protects SSH/SFTP, FTP (Pure-FTPd) and the panel login (failed logins and 2FA codes are written to the journal as `havoc-panel`), optional repeat-offender jail; rules (attempts, time window, ban time), whitelist with "add my address", banned addresses per jail with unban, manual ban. Settings are checked with `fail2ban-client -t` before reloading.
- **Node.js**: 22, 24 or 26 LTS from the official NodeSource repository, version switch, optional global pm2 / yarn / pnpm.
- **Notifications** (Server → Notifications): e-mail through any SMTP server (STARTTLS / SSL, own client) and Telegram (bot token, chat ID found automatically). Reports full disks, high memory or load, stopped services (web server, MariaDB, panel, FTP, DNS, add-ons, each site's PHP), SSL certificates about to expire, failed backups and new panel versions. A problem is reported when it is still there on the next check, repeated every N hours and followed by a "resolved" message; test buttons, "check now" and a delivery log. Runs every 5 minutes (`havoc-alerts.timer`, as the panel user).
### Changed
- The Redis & Meilisearch page moved to Add-ons (old address redirects).
- Secrets for notifications (SMTP password, bot token) are stored encrypted.

## [0.7.2] – 2026-11-16
### Added
- **Redis install / uninstall** from the panel, like Meilisearch: installs the Debian packages in the background (progress shown on the page), sets a memory limit (1/8 of the RAM), LRU eviction and a random password, and the PHP extension (phpredis) for every installed PHP version. Uninstall keeps the configuration and data unless "also delete" is ticked; reinstalling keeps the existing password.
- Redis card: version, used memory, number of keys and clients, "Show password".
- New servers get the phpredis extension for every PHP version.

## [0.7.1] – 2026-11-16
### Added
- Dashboard server card: live CPU usage (with bar), load average (1 / 5 / 15 min) and network speed (download / upload per second, total since boot), refreshed every 3 seconds like the server monitor; polling pauses in a background tab.
### Changed
- Sidebar: the Accounts group now comes before the Server group.

## [0.7.0] – 2026-11-16
### Added
- **Server overview on the dashboard** (administrators): IP addresses, operating system and kernel, CPU model, cores and load, uptime and server time, free memory and free disk space per volume (with bars), web server, PHP and MariaDB versions, panel version.
- **Processes page** (Server → Processes): live list of running processes refreshed every 5 seconds (pauses in a background tab) with CPU, memory, run time, state and command; summary cards, per-user totals, search, user filter, optional kernel threads, sortable columns. Hosting account processes show the panel user and site they belong to and can be ended (TERM) or forced (KILL); system services, the panel and other users' processes cannot be signalled. Every signal is written to the activity log.

## [0.6.1] – 2026-11-15
### Added
- **One-time upload links**: the single, cheapest and fastest way to bring code into a site. Claude (`create_upload_link`) or the domain page creates a link valid for one hour and one file; anyone with the link uploads a .zip / .tar.gz / .tgz / .tar (up to 2 GB, 8 MB chunks with retries) in the browser, straight to the server, no login and nothing through Claude. The archive is stored in the site's hidden `.havoc-incoming` folder, `extract_archive` unpacks it and removes it; leftovers are deleted after 7 days. New connector tools: `create_upload_link`, `list_uploads`.
- Pages without a session (upload page) follow the browser language.
### Security
- LiteSpeed sites now refuse hidden files and folders (`.git`, `.env`, `.havoc-incoming` …), as Nginx sites already did.

## [0.6.0] – 2026-11-15
### Added (Claude connector)
- **edit_file**: changes only the given text of a file (exact match, all-or-nothing). Updating a few lines no longer means re-sending the whole file: far fewer tokens and much faster.
- **search_files** (`path:line: text` results) and **read_file with start_line / end_line** (max 800 lines per call), so Claude reads only what it needs.
- **write_files**: up to 200 files in one call, checked before anything is written.
- **extract_archive**: extracts a zip / tar.gz already in the site (e.g. uploaded in the file manager) without passing the files through Claude; refuses to overwrite unless asked, refuses links and unsafe paths.
- **download_file**: fetches an https:// link (e.g. a private, time-limited link) straight into the site; private and internal addresses are refused.
- **File history**: every file Claude changes keeps its previous version outside the public folder (20 per file); **list_file_history** and **restore_file** undo a change.
### Changed
- Tool answers are compact JSON sent once (about 65% shorter), and the connector tells Claude to prefer the cheap path (search → read lines → edit).
- write_file and create_directory create missing folders in one step.

## [0.5.2] – 2026-11-15
### Changed
- **The panel is also served on `https://<panel host>/` (port 443)** once it has a host name with a certificate, so the Claude connector address is `https://<panel host>/mcp`. Claude's connector service could not reach panels on port 2087 ("No server responded at this URL"). The sites' Nginx forwards to the panel port; the client address and port are trusted only from the loopback address with a secret that hosting accounts cannot read. Kept in sync when the panel port or the web server changes.
- The MCP endpoint no longer refuses requests that carry an `Origin` header (Claude's check may send one).

## [0.5.1] – 2026-11-15
### Fixed
- **Claude connector "Couldn't reach":** the OAuth discovery addresses left out the panel port when the browser or Claude sent the host name without it (HTTP/2), so Claude looked for the panel on port 443. The port now comes from the port the panel actually listens on.

## [0.5.0] – 2026-11-15
### Added
- **Claude connector (MCP):** Claude (claude.ai, Claude Desktop, Claude Code) can manage websites through the panel: list and inspect sites, read and write files (text and binary), read logs, add domains and subdomains, install WordPress, request SSL, change PHP version / HTTPS redirect, create databases. No delete operations. OAuth 2.1 login with the user's own panel account and 2FA, optional read-only access, connections listed and revocable per user, global on/off switch for administrators, every action in the audit log.
- **One-click WordPress** on the domain page: latest WordPress with its own database, administrator account, chosen language and pretty permalinks, installed as the site's own user; the admin password is shown once.
- **Panel address and certificate:** a Let's Encrypt certificate for the panel's own host name, renewed automatically.

## [0.4.0] – 2026-11-15
### Added
- **Self-update from GitHub Releases:** *Panel settings → Panel updates* shows the installed and the latest version with its release notes, installs a new release with one click and shows the progress log. Optional automatic installation; a banner on the dashboard and a badge in the menu tell administrators when a release is available.
- `bin/havoc-update` installs a release from the command line (`havoc-update [vX.Y.Z] [--force]`); `bin/havoc update:check`.
### Changed
- Releases are checked before installing (complete archive, matching version, PHP syntax), the release's own `upgrade.sh` does the update, and the previous version is restored automatically on failure. The old `HAVOC_UPDATE_URL` setting is replaced by `HAVOC_UPDATE_REPO` in `/etc/havoc/update.conf` (set by the installer / upgrade script).

## [0.3.3] – 2026-11-15
### Fixed
- **Wrong site shown:** Nginx had no catch-all server, so every host name that no site claimed (HTTPS for a site that has no certificate yet, Cloudflare connecting to the origin, the server's IP, a domain not added yet) was answered by the first site. A default server now shows a neutral "no website" page on port 80 and refuses TLS for unknown names on port 443.
- **Let's Encrypt behind Cloudflare:** the pre-check now recognises Cloudflare proxy addresses and explains what to change (DNS only until issued, or a Cloudflare Origin Certificate with SSL mode Full (strict)) instead of "the domain does not point to this server".

## [0.3.2] – 2026-11-12
### Security
- **Per-site Nginx rules:** the rules parser now reads quotes exactly like Nginx does. Before, a hosting user could hide forbidden directives (e.g. `alias`, `fastcgi_pass`) behind a quote trick and read other customers' files or run PHP as another customer. `..` is no longer accepted in user rules either.
- **Symbolic links:** the web server only follows a symlink when the link and its target have the same owner (`disable_symlinks if_not_owner` on Nginx, "If Owner Match" on OpenLiteSpeed / LiteSpeed). Before, a symlink to another customer's file could be served by the web server.
- **Redirects:** quotes, `;` and line breaks are refused in redirect targets; all anchored input checks now reject a trailing line break.
- **Backups:** archives are built by `tar` running as the hosting user instead of root.
- **Deleting a site with its files** removes them as the hosting user instead of root.

## [0.3.1] – 2026-11-12
### Changed
- Redesigned sidebar: dark theme in both modes, menu search (<kbd>Ctrl</kbd>+<kbd>K</kbd>), compact icon rail, mobile drawer, user card with settings and logout.
- New default colour scheme (teal). Reseller white-label colours are unchanged.

## [0.3.0] – 2026-11-12
### Added
- Web server selection for the websites: **Nginx, OpenLiteSpeed or LiteSpeed Enterprise**, with one-click installation (trial or serial license for Enterprise), activation for all sites and automatic rollback.

## [0.2.0]
### Added
- Meilisearch with one-click install / uninstall (replaces Elasticsearch).
- Automatic Let's Encrypt certificates when a domain is added (with DNS pre-check and retries).
- Advanced cron jobs: presets, schedule builder, next run times, output logs, *Run now*.
- Grouped sidebar menu.
- Advanced file manager: code editor, chunked uploads up to 2 GB, search, copy/move, archives, bulk permissions.
- Central log management for all sites (access, error, PHP), live viewer, download and cleanup.
- Change the panel port from the panel (with automatic revert).
- One-click phpMyAdmin behind the panel login.
- Per-site `php.ini` and Nginx rules editors; MariaDB performance tuning.
- `install/upgrade.sh` for safe updates.

## [0.1.0]
### Added
- First release: domains, PHP 8.1–8.4 per site, SSL, databases, FTP/SFTP, DNS, backups, monitoring, services, Redis, cron, limits, firewall, WAF, traffic statistics, REST API, resellers and white-label, 2FA, audit log, EN/TR, dark/light theme.
