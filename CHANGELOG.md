# Changelog

All notable changes to Havoc WEB Panel. The project uses [semantic versioning](https://semver.org/); while in 0.x, minor versions may change behaviour.

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
