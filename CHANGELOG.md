# Changelog

All notable changes to Havoc WEB Panel. The project uses [semantic versioning](https://semver.org/); while in 0.x, minor versions may change behaviour.

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
