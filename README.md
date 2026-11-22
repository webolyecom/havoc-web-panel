<div align="center">

<img src="public/assets/favicon.svg" width="72" height="72" alt="Havoc WEB Panel logo">

# Havoc WEB Panel

**A modern, secure and completely free web hosting control panel for Debian 12 & 13.**

Manage websites, PHP versions, SSL, databases, files, backups, DNS, FTP/SFTP, firewall, add-ons and alerts
from one clean interface, or simply ask Claude to do it. No license fees, ever.

[![License: MIT](https://img.shields.io/badge/license-MIT-0b7fa0.svg)](LICENSE)
![Version](https://img.shields.io/badge/version-0.9.9-0b7fa0.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)
![Debian](https://img.shields.io/badge/Debian-12%20%7C%2013-a81d33.svg)
![Status](https://img.shields.io/badge/status-beta-f59e0b.svg)
[![PRs welcome](https://img.shields.io/badge/PRs-welcome-22c55e.svg)](CONTRIBUTING.md)

[Website](https://webolye.com/havocwebpanel/) ·
[Features](#-features) ·
[Screenshots](#-screenshots) ·
[Installation](#-installation) ·
[First steps](#-first-steps) ·
[API](#-rest-api) ·
[Documentation](docs/ADMIN-GUIDE.md) ·
[Contributing](CONTRIBUTING.md)

<br>

<img src="docs/screenshots/dashboard.png" alt="Havoc WEB Panel dashboard" width="100%">

</div>

---

## 💚 Free and open source, for everyone

Havoc WEB Panel is developed by **[Webolye](https://webolye.com/havocwebpanel/)** and released under the **[MIT License](LICENSE)**.

- ✅ **100% free**: no paid edition, no license keys, no per-server or per-domain fees, no telemetry.
- ✅ **Use it anywhere**: on your own servers, for your customers, in your company or hosting business, commercially or not.
- ✅ **Change it as you like**: fork it, re-brand it, add or remove modules, adapt it to your own workflow.
- ✅ **Share it**: redistribute the original or your modified version.

The only condition of the MIT License is that the copyright and license notice stay in copies of the software.
Contributions back to the project are very welcome, but never required.

---

## ✨ Features

<table>
<tr>
<td width="50%" valign="top">

### 🤖 Manage your sites by talking to Claude
- Built-in **Claude connector** (MCP): add it in Claude and say *"add example.com and install WordPress"* or *"publish this page on my site"*
- Claude adds sites, installs WordPress, writes and edits files, requests SSL, creates databases and reads logs, with your panel login and rights, and never deletes anything
- **One-time upload links** for big or private code: the zip goes straight from your browser to the server, never through Claude
- Every change keeps the previous file version; every action is in the audit log

### 🌐 Websites
- Domains, subdomains, addon domains, aliases (parked) and redirects
- **One-click WordPress** (own database, admin account, language of your choice)
- **Per-site PHP version** (8.1 – 8.4), extensions, OPcache and `php.ini` settings
- Every site runs in its **own PHP-FPM process** under its own Linux user
- **Automatic Let's Encrypt SSL** when a domain is added, plus renewals, custom certificates and Cloudflare Origin certificates
- Force HTTPS, HSTS, per-site Nginx rules with validation

### 🖥️ Web server of your choice
- **Nginx**, **OpenLiteSpeed** or **LiteSpeed Enterprise**
- One-click install and activation; all sites are rewritten for the new server
- Automatic rollback if the new server does not answer

### 📁 Files & logs
- **File manager** with a CodeMirror code editor, drag-and-drop chunked uploads (up to 2 GB), zip / tar.gz, search, bulk permissions
- **Log center**: access, error and PHP logs of every site, live viewer with filters, download and cleanup

### ⏱️ Automation & backups
- **Cron jobs** with presets, a visual builder, human-readable description and next run times, output logs and *Run now*
- Scheduled **backups** (files + databases) with retention, one-click restore, and copies on **Google Drive, OneDrive, Dropbox**, S3 (Cloudflare R2, Backblaze B2…), FTP or SFTP; optional client-side **encryption**

### 🚚 Site transfer
- Move sites **from another Havoc server**: paste a transfer key, pick the sites, done
- Files, databases (users keep their passwords), PHP and site settings, aliases, redirects, cron jobs and the SSL certificate; the owner is created with the same panel password
- Resumable, checksum-verified download; failed sites are rolled back and can be retried; nothing changes on the old server

</td>
<td width="50%" valign="top">

### 🧩 Add-ons (one-click install / uninstall)
- **Redis**: memory limit, eviction policy, persistence, random password, PHP extension
- **Meilisearch**: official release with checksum check, master key, indexing memory; **per-site search**: each site gets its own index prefix and two keys that only work for its indexes, included in backups and transfers
- **Memcached**: cache size, connections, statistics, empty cache, PHP extension
- **Fail2ban**: protects SSH/SFTP, FTP and the panel login; rules, whitelist, ban / unban from the panel
- **Node.js**: 22 / 24 / 26 LTS from NodeSource, version switch, pm2 / yarn / pnpm

### 🔔 Monitoring & notifications
- **Dashboard** with the server's IP addresses, OS, live CPU, load, network, memory and free disk
- **Processes** page: live list with per-user totals; end runaway processes of hosting accounts
- **Alerts by e-mail (SMTP) and Telegram**: full disks, high memory or load, stopped services, expiring SSL certificates, failed backups, new panel versions; reminders and "resolved" messages
- Server monitor, service control, resource limits, traffic statistics with CSV export

### 🗄️ Databases, DNS & FTP
- MariaDB databases and users, remote access per database, **databases assigned to sites** (also detected automatically from config files)
- **Web server settings**: performance, gzip, browser cache and a per-site **page cache** (Nginx FastCGI cache / LSCache) for Nginx, OpenLiteSpeed and LiteSpeed Enterprise
- **MySQL queries**: live list of running queries and connections; stop long queries one by one, selected or by rule
- **Resource usage**: memory and CPU per service (MariaDB, Meilisearch, Redis, web server…) and per site, with reclaimable file cache shown apart; change a site's RAM / CPU limits and PHP workers right there
- **PHP workers per site**: pm mode (ondemand / dynamic / static), max_children, idle time, max requests, and RAM / CPU limits (package value, own value or unlimited)
- **Change domain name**: move a site to a new name in one step: folder, web server and PHP configuration, logs, subdomains, FTP, cron, DNS zone and SSL follow; the old name can stay as a 301 redirect; WordPress addresses are replaced in the database after an automatic backup
- **One-click phpMyAdmin**: opens a site's database without a password, with a temporary login for that database only
- **MariaDB performance tuning** with RAM-based profiles and automatic rollback
- Authoritative **DNS** (BIND9) with record validation
- **FTP** (Pure-FTPd, TLS) and **SFTP** (chrooted) accounts

### 🔐 Security
- Admin / reseller / user roles with strict tenant isolation
- **Two-factor authentication (TOTP)**, brute-force protection, audit log
- CSRF protection, strict Content-Security-Policy, prepared statements, Argon2id
- **ModSecurity WAF** with OWASP CRS, **firewall** (ufw) with lock-out protection
- Privileged actions run through a small, whitelisted root helper; the web panel itself runs unprivileged

### 🏢 For hosting companies
- **Resellers**, hosting packages with CPU / RAM / disk / bandwidth limits
- **White-label**: your own name, logo, colour and panel domain
- **[REST API](#-rest-api)** with token access and an OpenAPI description
- **One-click self-update** from GitHub Releases, optionally automatic
- English & Turkish interface, light & dark theme, mobile friendly

</td>
</tr>
</table>

---

## 📸 Screenshots

<table>
<tr>
<td width="50%"><img src="docs/screenshots/domain.png" alt="Domain settings"><br><sub><b>Site page</b>: a summary and one icon per settings page (files, logs, WordPress, PHP, SSL, security, Nginx rules, aliases, redirects, databases, search).</sub></td>
<td width="50%"><img src="docs/screenshots/domain-dark.png" alt="Dark theme"><br><sub><b>Dark theme</b>: every page, remembered per user (here: a site's PHP page with the page switcher).</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/site-transfer.png" alt="Site transfer"><br><sub><b>Site transfer</b>: paste the other panel's transfer key, pick sites, their databases and search indexes, and move them.</sub></td>
<td width="50%"><img src="docs/screenshots/cloud-backup.png" alt="Cloud backups"><br><sub><b>Cloud backups</b>: Google Drive, OneDrive, Dropbox, SFTP, FTP or S3, with optional encryption.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/databases.png" alt="Databases"><br><sub><b>Databases</b>: each database belongs to a site (or is assigned automatically) and opens in phpMyAdmin with one click, no password.</sub></td>
<td width="50%"><img src="docs/screenshots/site-search.png" alt="Per-site search"><br><sub><b>Per-site search</b>: Meilisearch indexes and keys that only work for that site, included in backups and transfers.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/addons.png" alt="Add-ons"><br><sub><b>Add-ons</b>: Redis, Meilisearch, Memcached, Fail2ban and Node.js, installed and removed with one click.</sub></td>
<td width="50%"><img src="docs/screenshots/fail2ban.png" alt="Fail2ban"><br><sub><b>Fail2ban</b>: protection for SSH, FTP and the panel login, with ban list, unban and whitelist.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/notifications.png" alt="Notifications"><br><sub><b>Notifications</b>: e-mail and Telegram alerts for disks, services, SSL, backups and updates.</sub></td>
<td width="50%"><img src="docs/screenshots/processes.png" alt="Processes"><br><sub><b>Processes</b>: live process list with per-user totals; end runaway account processes.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/claude-connector.png" alt="Claude connector"><br><sub><b>Claude connector</b>: approve Claude once, then manage your sites by chatting.</sub></td>
<td width="50%"><img src="docs/screenshots/redis.png" alt="Add-on settings"><br><sub><b>Add-on settings</b>: every add-on has its own page with status and settings.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/file-manager.png" alt="File manager"><br><sub><b>File manager</b>: upload, edit, compress, extract and set permissions.</sub></td>
<td width="50%"><img src="docs/screenshots/code-editor.png" alt="Code editor"><br><sub><b>Code editor</b>: syntax highlighting, <kbd>Ctrl</kbd>+<kbd>S</kbd> to save.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/web-server.png" alt="Web server selection"><br><sub><b>Web server selection</b>: Nginx, OpenLiteSpeed or LiteSpeed Enterprise.</sub></td>
<td width="50%"><img src="docs/screenshots/log-viewer.png" alt="Live log viewer"><br><sub><b>Live log viewer</b>: access, error and PHP logs with filters.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/screenshots/cron.png" alt="Cron jobs"><br><sub><b>Cron jobs</b>: presets, schedule builder and next run times.</sub></td>
<td width="50%"><img src="docs/screenshots/mariadb-tuning.png" alt="MariaDB tuning"><br><sub><b>MariaDB tuning</b>: profiles calculated from the server's memory.</sub></td>
</tr>
</table>

---

## 🤖 Claude connector

Havoc WEB Panel is also an [MCP](https://modelcontextprotocol.io) server, so Claude can manage your websites for you:

> *"Add shop.example.com, install WordPress in Turkish and send me the admin login."*
> *"Publish this landing page at example.com/kampanya."*
> *"Why does example.com show an error? Check the logs."*

**Setup (once):**

1. In the panel: **Panel settings → Panel address and certificate**: give the panel a host name (e.g. `panel.example.com`, A record pointing to the server) and click *Get certificate*. Claude only connects to a trusted certificate. Ports 80 and 443 must be reachable (the sites' web server must be Nginx).
2. In Claude: **Settings → Connectors → Add custom connector**, enter `https://panel.example.com/mcp` (shown in the panel; the panel is also served on port 443 under its host name), choose *Sign in now* and *Register automatically*, and click *Connect*.
3. Log in to the panel (with 2FA) and approve. You choose whether Claude may make changes or only read.

**What Claude can do:** list and inspect sites, search code, read files (or just some lines), edit files by changing only the relevant text, write one or many files, extract an uploaded zip / tar.gz, download a file from an https link into a site, undo its own changes (file history), read logs, add domains and subdomains, install WordPress, request SSL, change PHP version / HTTPS redirect, create databases (also for a given site) and see which site each database belongs to.

**Keeping it cheap:** for large or private code, ask Claude for an *upload link*: you drop the zip into that page in your browser, it goes straight to the server, and Claude extracts it. The files never pass through Claude. For changes, Claude searches, reads only the lines it needs and edits just those; every changed file keeps its previous version.

**What Claude cannot do:** delete websites, databases or files. It acts with the rights of the panel user who approved it, so a customer's connection only reaches that customer's sites. Every action is written to the audit log; connections are listed and can be ended under **Settings → Claude connector**, and the administrator can switch the connector off for the whole panel.

Technically: Streamable HTTP MCP endpoint at `/mcp`, OAuth 2.1 with dynamic client registration and PKCE (S256), rotating refresh tokens, tokens stored as hashes.

---

## 🔌 REST API

A small JSON API for scripts and other tools. It acts with the rights of the user who created the token, so a customer's token only reaches that customer's sites.

1. In the panel: **Settings → API**, enter a name and create a token (`hvc_…`). It is shown only once; the panel stores only its hash. Delete it there to revoke it.
2. Send it as a Bearer token:

```bash
TOKEN=hvc_your_token
PANEL=https://panel.example.com:2087

curl -H "Authorization: Bearer $TOKEN" $PANEL/api/v1/me
curl -H "Authorization: Bearer $TOKEN" $PANEL/api/v1/domains
curl -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"name":"shop.example.com","php_version":"8.4"}' $PANEL/api/v1/domains
```

| Method | Path | What it does |
|---|---|---|
| `GET` | `/api/v1/me` | The token's user (id, username, role, e-mail) |
| `GET` | `/api/v1/domains` | Sites the user can see: name, owner, PHP version, SSL, HTTPS redirect, WAF, status |
| `POST` | `/api/v1/domains` | Add a site: `name`, optional `php_version`, `user_id` (admins and resellers) → `201` |
| `GET` | `/api/v1/domains/{id}` | One site |
| `DELETE` | `/api/v1/domains/{id}` | Delete a site; body `{"purge": true}` also deletes its files → `204` |
| `GET` | `/api/v1/server/stats` | CPU, load, memory, swap, disk, network, uptime |

Errors come back as JSON with a status code: `401` (missing or wrong token), `404` (not found or not yours), `422` (invalid input or quota reached). The full description is in [`docs/openapi.json`](docs/openapi.json) (also served at `/api/v1/openapi.json`) and can be opened in Swagger UI, Postman or Insomnia. For everything else, use the panel or the Claude connector.

---

## 📋 Requirements

| | |
|---|---|
| **Operating system** | A fresh **Debian 12 (Bookworm)** or **Debian 13 (Trixie)** server |
| **Access** | `root` (or a user with `sudo`) |
| **Hardware** | 64-bit x86_64 (arm64 is expected to work but is less tested). 1 GB RAM minimum, 2 GB or more recommended |
| **Network** | A public IPv4 address. Ports 80 and 443 (websites, and the panel under its own host name) and 2087 (panel) must be reachable |

> ⚠️ Install the panel on a **clean server**. It configures Nginx, PHP-FPM, MariaDB, BIND9, Pure-FTPd, ufw and other services and is not meant to be added to a server that already hosts websites with another panel.

---

## 🚀 Installation

```bash
apt-get update && apt-get install -y git
git clone https://github.com/webolyecom/havoc-web-panel.git
cd havoc-web-panel
sudo bash install/install.sh
```

When the installer finishes it prints the panel address and the login data of the `admin` user:

```
Havoc WEB Panel is ready.
  URL:      https://YOUR_SERVER_IP:2087  (self-signed certificate)
  ...
```

Log in, change the password and turn on two-factor authentication under **Settings**.

### Installer options

Options are passed as environment variables:

```bash
sudo HAVOC_WEBSERVER=ols HAVOC_PORT=8443 HAVOC_PHP_VERSIONS="8.3 8.4" bash install/install.sh
```

| Variable | Default | Description |
|---|---|---|
| `HAVOC_WEBSERVER` | `nginx` | Web server for the sites: `nginx`, `ols` (OpenLiteSpeed) or `lsws` (LiteSpeed Enterprise). It can be changed later from the panel. |
| `HAVOC_LSWS_LICENSE` | `TRIAL` | LiteSpeed Enterprise serial number (only with `lsws`) |
| `HAVOC_PHP_VERSIONS` | `8.1 8.2 8.3 8.4` | PHP versions offered to the websites |
| `HAVOC_PORT` | `2087` | HTTPS port of the panel (can be changed later from the panel) |

### Updating

**From the panel (recommended):** open **Panel settings → Panel updates**. The panel checks this repository's
[GitHub Releases](../../releases) every 6 hours, shows what is new and installs a new release with one click, or on its own
if you turn on *Install new releases automatically*. The release is downloaded and checked first, the previous version is
kept, and it is restored automatically if anything goes wrong. Websites keep running during the update.

From the command line, the same update:

```bash
sudo /opt/havoc/bin/havoc-update            # latest release
sudo /opt/havoc/bin/havoc-update v0.4.0     # a specific release
```

Or manually from a checkout:

```bash
cd havoc-web-panel
git pull
sudo bash install/upgrade.sh
```

Every update keeps your configuration, encryption key, certificates, database and websites, and runs the database migrations.

> ❗ Never run `install.sh` again on a server that is already in use: it creates a new encryption key, and stored backup credentials could no longer be decrypted. Always update with `upgrade.sh`.

---

## 👣 First steps

After logging in for the first time:

1. **Secure your account**: *Settings* → change the password and turn on **two-factor authentication**.
2. **Give the panel a name**: *Panel settings → Panel address and certificate*: enter a host name such as `panel.example.com` (A record pointing to the server) and click *Get certificate*. The panel is then reachable at `https://panel.example.com` with a trusted certificate (needed for the Claude connector).
3. **Add a website**: *Domains → Add domain*. Point the domain's DNS to the server; a Let's Encrypt certificate is requested automatically. On the domain page choose the PHP version or click **Install WordPress**.
4. **Upload your files**: with the *File Manager*, FTP/SFTP accounts or a one-time *Upload link*.
5. **Protect the server**: *Add-ons → Fail2ban → Install*, then add your own IP address to the whitelist.
6. **Get alerts**: *Server → Notifications*: enter an SMTP account and/or a Telegram bot, click *Send test*, choose what to report.
7. **Set up backups**: *Backups*: connect Google Drive, OneDrive or Dropbox (or S3 / FTP / SFTP), then schedule daily backups and choose how many to keep.
8. **Moving from another Havoc server?** On the old server: *Site transfer → Create transfer key*. On the new one: *Site transfer*, paste the key, pick the sites.
9. **Optional**: install Redis or Memcached for caching, Meilisearch for search, Node.js for build tools, and connect Claude (see above).

---

## 🏗️ How it works

```
Browser ──HTTPS──▶ havoc-panel-nginx ──▶ havoc-panel-fpm  (user "havoc", unprivileged)
                                              │
                                              │  sudo -n havocctl <action>   (JSON, whitelisted actions)
                                              ▼
                                         havocctl (root helper, strict validation)
                                              ├── web server driver: Nginx │ OpenLiteSpeed │ LiteSpeed
                                              ├── PHP-FPM master per site (own Linux user, cgroup limits)
                                              └── MariaDB, BIND9, Pure-FTPd, ufw, certbot, add-ons, …

havoc-alerts.timer (every 5 min, user "havoc") ──▶ server checks ──▶ e-mail / Telegram
```

- The panel is plain **PHP 8.2+ without a framework**, with **SQLite** for its own data and **Tailwind CSS** for the interface.
- The panel runs as an unprivileged user on its own Nginx instance. Only a small root helper with a fixed list of validated actions can change the system.
- Each hosting account is a separate Linux user, and each site has its own PHP-FPM master with its own `open_basedir`, OPcache and resource limits.

More details: **[Administrator & technical guide](docs/ADMIN-GUIDE.md)** · API description: [`docs/openapi.json`](docs/openapi.json)

---

## 🧭 Project status

Havoc WEB Panel is **beta software (0.x)**. The panel has been installed and used on Debian 13, and every release is checked with automated page tests and a headless browser. Please:

- try it on a **test server** before using it in production,
- keep **backups**, and
- [report problems](../../issues) you run into.

Known limitations:
- OpenLiteSpeed and LiteSpeed Enterprise support is new and has not yet been tested widely on real servers.
- No email server (SMTP/IMAP) module yet.

See the [changelog](CHANGELOG.md) for what changed in each version.

---

## 🛠️ Development

```
public/      entry point and compiled assets      src/Core         own mini framework (router, session, CSRF, i18n…)
src/         controllers and services              views/           PHP templates (Tailwind CSS)
ctl/         privileged root helper (havocctl)     lang/            translations (en, tr)
install/     installer and upgrade scripts         database/        SQLite schema and migrations
bin/         CLI and helper scripts                docs/            documentation, screenshots, OpenAPI
```

Rebuild the stylesheet or the editor bundle after changing templates:

```bash
npm install
npm run build          # public/assets/app.css
npm run build:editor   # public/assets/editor.js (CodeMirror)
```

New languages are welcome: copy `lang/en.php`, translate the values and add the code to `I18n::SUPPORTED`.

---

## 🤝 Contributing

Bug reports, ideas, translations and pull requests are all welcome. Please read **[CONTRIBUTING.md](CONTRIBUTING.md)** first.

🔒 **Found a security issue?** Please do not open a public issue. Follow **[SECURITY.md](SECURITY.md)** instead.

---

## 📄 License

Havoc WEB Panel is free software released under the **[MIT License](LICENSE)**.
Copyright © 2026 [Webolye](https://webolye.com/havocwebpanel/).

Third-party components keep their own licenses. See [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

This software is provided "as is", without warranty of any kind. You are responsible for the servers you run it on.

---

<div align="center">

Made with ❤️ by **[Webolye](https://webolye.com/havocwebpanel/)**

If Havoc WEB Panel is useful to you, please ⭐ **star the repository** and tell others about it!

</div>
