# Security policy

Havoc WEB Panel controls web servers, so security reports are taken seriously. Thank you for helping keep users safe.

## Supported versions

Security fixes are made for the **latest release**. Please update (`git pull && sudo bash install/upgrade.sh`) before reporting.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately instead:

1. **GitHub private vulnerability reporting** (preferred): open the repository's **Security** tab → **Report a vulnerability**.
2. Or contact the developer through **https://webolye.com/havocwebpanel/**.

Please include:
- the affected version and Debian version,
- a description of the problem and its impact (for example: privilege escalation, one customer reaching another customer's files, authentication bypass),
- steps to reproduce or a proof of concept,
- whether the issue is already public.

You will get an answer as soon as possible. Please give us reasonable time to release a fix before you publish details; you will be credited in the release notes unless you prefer otherwise.

## Scope

In scope: the panel code in this repository (web panel, `ctl/` root helper, installer and upgrade scripts).

Out of scope: vulnerabilities in third-party software installed on the server (Nginx, LiteSpeed, PHP, MariaDB, phpMyAdmin…), please report those to their projects; and problems that need an already compromised administrator account or root access.

## Hardening tips for operators

- Enable two-factor authentication for every administrator and reseller.
- Change the initial admin password immediately after installation.
- Restrict access to the panel port in the firewall to your own IP addresses when possible.
- Keep Debian updated (`apt upgrade`) and update the panel regularly.
- Keep backups on a remote target (S3 / SFTP), not only on the same server.
