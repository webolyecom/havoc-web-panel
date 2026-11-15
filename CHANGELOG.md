# Changelog

All notable changes to Havoc WEB Panel. The project uses [semantic versioning](https://semver.org/); while in 0.x, minor versions may change behaviour.

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
